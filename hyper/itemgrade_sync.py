#!/usr/bin/env python3
"""
Item Grade Sync - Python rewrite of transferpicklist_itemgrade_sync.php's
"Sync All" / "Sync Failed" path.

Mirrors the PHP version's current (fixed) logic exactly:
  - Workbook/view are looked up by name (not hardcoded IDs), same as the PHP.
  - Outlet list source: first run copies from transferpicklist_past3months_status,
    subsequent runs read from transferpicklist_itemgrade_status itself.
  - New-calendar-month rollover resets every outlet's status back to 0.
  - Each outlet's rows are DELETE'd then INSERT IGNORE'd fresh (not a table-wide
    truncate), so a failed outlet doesn't wipe out other outlets' data.
  - Duplicate item codes within one outlet's CSV are deduped (first one wins),
    matching the PHP's $seen[] guard.
  - Fetches run concurrently (ThreadPoolExecutor, same idea as PHP's curl_multi
    with CONCURRENCY=8), then failed outlets get a 3x retry pass with backoff.
  - A mid-batch 401 triggers one re-auth + retry of just the affected outlets.

Usage:
  python itemgrade_sync.py                  # sync only pending/failed outlets (status=0)
  python itemgrade_sync.py --sync-all        # force re-sync of every outlet
"""

import argparse
import csv
import io
import logging
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path

import pymysql
import requests

from csv_archive import save_raw  # TEMPORARY raw-CSV evidence archive

# ============================================================
# CONFIG - matches transferpicklist_itemgrade_sync.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # None = use system trust store (same as past3m_sync.py)

WORKBOOK_ITEMGRADE = "OM-InterbranchTransferPharmacyOutletSlowMovingDataSupply_CheeMeng_1_4_2026"
VIEW_NAME_ITEMGRADE = "OutletItemgrade"

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

CONCURRENCY = 8
BATCH_SIZE = 500
MAX_RETRIES = 3
REQUEST_TIMEOUT = 300

LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "itemgrade_sync.log")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("itemgrade")


# ============================================================
# TABLEAU AUTH / VIEW LOOKUP
# ============================================================
def tableau_auth():
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/auth/signin"
    payload = {
        "credentials": {
            "name": TABLEAU_API_USER,
            "password": TABLEAU_API_PASS,
            "site": {"contentUrl": ""},
        }
    }
    resp = requests.post(url, json=payload, headers={"Accept": "application/json"},
                          verify=CA_CERT_PATH or True, timeout=30)
    data = resp.json()
    creds = data.get("credentials")
    if not creds or "token" not in creds:
        raise RuntimeError(f"Tableau auth failed: {resp.text}")
    return creds["token"], creds["site"]["id"]


def find_view_id(token, site_id):
    page = 1
    fetched = 0
    workbook_id = None
    while True:
        url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/workbooks?pageSize=100&pageNumber={page}"
        resp = requests.get(url, headers={"X-Tableau-Auth": token, "Accept": "application/json"},
                             verify=CA_CERT_PATH or True, timeout=30)
        j = resp.json()
        batch = j.get("workbooks", {}).get("workbook")
        if not batch:
            break
        fetched += len(batch)
        total = int(j.get("pagination", {}).get("totalAvailable", 0))
        for wb in batch:
            if wb.get("contentUrl") == WORKBOOK_ITEMGRADE:
                workbook_id = wb["id"]
                break
        if workbook_id or fetched >= total:
            break
        page += 1

    if not workbook_id:
        raise RuntimeError(f"Workbook not found: {WORKBOOK_ITEMGRADE}")

    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/workbooks/{workbook_id}/views"
    resp = requests.get(url, headers={"X-Tableau-Auth": token, "Accept": "application/json"},
                         verify=CA_CERT_PATH or True, timeout=30)
    j = resp.json()
    for view in j.get("views", {}).get("view", []):
        content_url = view.get("contentUrl", "")
        vname = content_url.split("/")[-1] if content_url else content_url
        if vname == VIEW_NAME_ITEMGRADE:
            return view["id"]

    raise RuntimeError(f"View not found: {VIEW_NAME_ITEMGRADE}")


# ============================================================
# FETCH + PARSE ONE OUTLET
# ============================================================
def fetch_csv(view_id, token, site_id, acmm_id_padded):
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{view_id}/data"
    params = {"vf_Store": acmm_id_padded}
    headers = {"X-Tableau-Auth": token}
    return requests.get(url, headers=headers, params=params,
                         verify=CA_CERT_PATH or True, timeout=REQUEST_TIMEOUT)


_CLEAN_RE = re.compile(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]")


def _clean(val):
    return _CLEAN_RE.sub("", (val or "").strip())


def parse_csv(csv_text, outlet_id, timestamp):
    """Returns list of (outlet_id, item_code, grade, model, timestamp), deduped by item_code."""
    if csv_text.startswith("﻿"):
        csv_text = csv_text[1:]
    reader = csv.DictReader(io.StringIO(csv_text))
    if reader.fieldnames is None:
        return []

    item_col = grade_col = model_col = None
    for hdr in reader.fieldnames:
        hl = hdr.strip().lower()
        if item_col is None and "item code" in hl:
            item_col = hdr
        if grade_col is None and "grade" in hl:
            grade_col = hdr
        if model_col is None and "model" in hl:
            model_col = hdr

    seen = set()
    rows = []
    for row in reader:
        item_code = _clean(row.get(item_col)) if item_col else ""
        if not item_code or item_code in seen:
            continue
        seen.add(item_code)
        grade = (row.get(grade_col) or "").strip() if grade_col else ""
        model = (row.get(model_col) or "").strip() if model_col else ""
        rows.append((outlet_id, item_code, grade, model, timestamp))
    return rows


def check_tableau_error(text):
    """Returns '401_REAUTH', an error summary string, or None if not an error body."""
    if "<error" not in text:
        return None
    if "401" in text or "Unauthorized" in text:
        return "401_REAUTH"
    m = re.search(r"<summary>(.*?)</summary>", text)
    return m.group(1) if m else "Tableau error"


# ============================================================
# DB HELPERS
# ============================================================
def get_conn():
    return pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS,
                            database=DB_NAME, charset="utf8mb4", autocommit=False)


def save_outlet_rows(conn, outlet_id, rows):
    """DELETE + INSERT IGNORE, wrapped in a transaction, for one outlet."""
    with conn.cursor() as cur:
        cur.execute("DELETE FROM transferpicklist_itemgrade WHERE outlet_id = %s", (outlet_id,))
        saved = 0
        if rows:
            sql = """
                INSERT IGNORE INTO transferpicklist_itemgrade
                    (outlet_id, item_code, item_grade, item_model, timestamp)
                VALUES (%s, %s, %s, %s, %s)
            """
            for i in range(0, len(rows), BATCH_SIZE):
                chunk = rows[i:i + BATCH_SIZE]
                saved += cur.executemany(sql, chunk) or 0
    conn.commit()
    return saved


def update_status(conn, outlet_id, outlet_code, status, error_msg, timestamp):
    sql = """
        INSERT INTO transferpicklist_itemgrade_status
            (outlet_id, outlet_code, status, last_sync, error_msg)
        VALUES (%s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            outlet_id = VALUES(outlet_id), status = VALUES(status),
            last_sync = VALUES(last_sync), error_msg = VALUES(error_msg)
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id, outlet_code, status, timestamp, error_msg))
    conn.commit()


def register_new_outlets(conn):
    """INSERT any outlet present in transferpicklist_past3months_status (the
    Tableau-verified root outlet list) but missing from itemgrade_status —
    e.g. a newly opened store that past3m_sync.py's own register_new_outlets()
    already picked up. Without this, a new outlet is silently skipped by this
    sync forever, since load_outlets() previously only ever copied from
    past3months_status on the very first run ever (when itemgrade_status was
    completely empty), never again after that.
    Deliberately sourced from past3months_status, never the raw `outlet` table
    directly — that table also contains ~190 non-outlet codes (HQ, admin,
    franchise, etc.) that were never real Tableau stores."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("""
            SELECT o.id AS outlet_id, o.code AS outlet_code
            FROM transferpicklist_past3months_status s
            INNER JOIN outlet o ON o.id = s.outlet_id AND o.recycle = 0
            LEFT JOIN transferpicklist_itemgrade_status ig ON ig.outlet_id = s.outlet_id
            WHERE ig.outlet_id IS NULL
        """)
        new_outlets = cur.fetchall()

    if not new_outlets:
        return

    with conn.cursor() as cur:
        for row in new_outlets:
            cur.execute("""
                INSERT INTO transferpicklist_itemgrade_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (row["outlet_id"], row["outlet_code"]))
    conn.commit()
    codes_list = ", ".join(r["outlet_code"] for r in new_outlets)
    log.info(f"[NewOutlet] Registered {len(new_outlets)} new outlet(s): {codes_list}")


def load_outlets(conn, sync_all, single_outlet=None):
    if single_outlet:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute(
                "SELECT id AS outlet_id, code AS outlet_code, acmm_id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1",
                (single_outlet,),
            )
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            return [row]

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT COUNT(*) AS cnt FROM transferpicklist_itemgrade_status")
        status_count = cur.fetchone()["cnt"]

        if status_count == 0:
            log.info("First run — populating itemgrade_status from past3months_status...")
            cur.execute("""
                SELECT s.outlet_id, s.outlet_code
                FROM transferpicklist_past3months_status s
                INNER JOIN outlet o ON o.id = s.outlet_id AND o.recycle = 0
                ORDER BY s.outlet_code
            """)
            for row in cur.fetchall():
                cur.execute("""
                    INSERT INTO transferpicklist_itemgrade_status (outlet_id, outlet_code, status)
                    VALUES (%s, %s, 0)
                    ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
                """, (row["outlet_id"], row["outlet_code"]))
            conn.commit()

        # New-calendar-month rollover — reset every outlet's status
        cur.execute("SELECT MAX(last_sync) AS mx FROM transferpicklist_itemgrade_status WHERE last_sync IS NOT NULL")
        last_sync = cur.fetchone()["mx"]
        last_month = last_sync.strftime("%Y-%m") if last_sync else None
        cur_month = datetime.now().strftime("%Y-%m")
        if last_month and cur_month > last_month:
            cur.execute("UPDATE transferpicklist_itemgrade_status SET status = 0")
            conn.commit()
            log.info("New calendar month — reset all outlet statuses.")

        where = "" if sync_all else "WHERE s.status = 0"
        cur.execute(f"""
            SELECT o.id AS outlet_id, s.outlet_code, o.acmm_id
            FROM transferpicklist_itemgrade_status s
            INNER JOIN outlet o ON o.code = s.outlet_code AND o.recycle = 0
            {where}
            ORDER BY s.outlet_code
        """)
        return cur.fetchall()


# ============================================================
# MAIN
# ============================================================
def sync_one(view_id, token, site_id, outlet_id, acmm_id_padded, code=None):
    try:
        resp = fetch_csv(view_id, token, site_id, acmm_id_padded)
    except Exception as e:
        return None, str(e)
    if resp.status_code != 401:
        save_raw("itemgrade", code or acmm_id_padded, resp.text)
    if not resp.text:
        return None, "Empty response"
    err = check_tableau_error(resp.text)
    if err:
        return None, err
    return parse_csv(resp.text, outlet_id, datetime.now().strftime("%Y-%m-%d %H:%M:%S")), None


def sync(sync_all=False, single_outlet=None):
    start = time.time()
    log.info("=" * 50)
    log.info(f"Item Grade Sync (Python) - {datetime.now()}")
    log.info("=" * 50)

    conn = get_conn()
    if not single_outlet:
        register_new_outlets(conn)
    outlets = load_outlets(conn, sync_all, single_outlet=single_outlet)
    if not outlets:
        log.info("No outlets to sync. All up to date!")
        conn.close()
        return
    log.info(f"Syncing {len(outlets)} outlet(s).")

    token, site_id = tableau_auth()
    view_id = find_view_id(token, site_id)
    log.info(f"View ID: {view_id}")
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    def outlet_info(row):
        acmm = str(row["acmm_id"]).strip() if row["acmm_id"] else "0"
        return row["outlet_code"], row["outlet_id"], acmm.zfill(4)

    processed = 0
    total_saved = 0
    failed = []  # (outlet_code, outlet_id, acmm_id_padded)
    total = len(outlets)

    def run_batch(items, tok):
        results = {}
        with ThreadPoolExecutor(max_workers=CONCURRENCY) as pool:
            futures = {
                pool.submit(sync_one, view_id, tok, site_id, oid, acmm, code): code
                for code, oid, acmm in items
            }
            for fut in as_completed(futures):
                code = futures[fut]
                results[code] = fut.result()
        return results

    items = [outlet_info(r) for r in outlets]
    results = run_batch(items, token)

    reauth_needed = [c for c in items if results[c[0]][1] == "401_REAUTH"]
    if reauth_needed:
        log.info(f"Token expired mid-batch — re-authenticating and retrying {len(reauth_needed)} outlet(s)...")
        token, site_id = tableau_auth()
        retry_results = run_batch(reauth_needed, token)
        results.update(retry_results)

    for code, oid, acmm in items:
        processed += 1
        rows, err = results[code]
        if err:
            update_status(conn, oid, code, 0, err, timestamp)
            log.warning(f"  [{processed}/{total}] {code} ({acmm}) — FAILED: {err}")
            failed.append((code, oid, acmm))
        else:
            saved = save_outlet_rows(conn, oid, rows)
            total_saved += saved
            update_status(conn, oid, code, 1, None, timestamp)
            log.info(f"  [{processed}/{total}] {code} ({acmm}) — {saved} records.")

    if failed:
        log.info(f"Retrying {len(failed)} failed outlet(s)...")
        for code, oid, acmm in failed:
            success = False
            for attempt in range(1, MAX_RETRIES + 1):
                wait = 5 * attempt
                log.info(f"  [Retry {attempt}/{MAX_RETRIES}] {code} (wait {wait}s)...")
                time.sleep(wait)
                rows, err = sync_one(view_id, token, site_id, oid, acmm, code)
                if err == "401_REAUTH":
                    token, site_id = tableau_auth()
                    rows, err = sync_one(view_id, token, site_id, oid, acmm, code)
                if not err:
                    saved = save_outlet_rows(conn, oid, rows)
                    total_saved += saved
                    update_status(conn, oid, code, 1, None, timestamp)
                    log.info(f"    Retry {attempt} OK — {saved} records.")
                    success = True
                    break
                log.warning(f"    Retry {attempt} failed: {err}")
            if not success:
                update_status(conn, oid, code, 0, f"Failed after {MAX_RETRIES} retries", timestamp)
                log.error(f"  {code} — GAVE UP after {MAX_RETRIES} retries.")

    conn.close()
    elapsed = time.time() - start
    log.info("=" * 50)
    log.info(f"Item Grade Sync — DONE. {processed} outlets, {total_saved} records, "
              f"{len(failed)} failed. Took {elapsed:.1f}s.")
    log.info("=" * 50)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--outlet", help="Sync a single outlet code only")
    parser.add_argument("--sync-all", action="store_true", help="Force re-sync of every outlet")
    args = parser.parse_args()
    sync(sync_all=args.sync_all, single_outlet=args.outlet)

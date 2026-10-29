#!/usr/bin/env python3
"""
Past 3 Months Sync - Python rewrite of transferpicklist_past3months_sync.php

Same data source and logic as the PHP version, but:
  1. Fetches outlets in parallel (a modest worker pool - see MAX_WORKERS)
  2. Saves each outlet's rows immediately after it's fetched and parsed
     (same per-outlet-commit pattern the PHP used, and the same pattern
     every other script in hyper/ uses) - NOT batched into memory and
     bulk-inserted at the very end. A crash/kill only loses whatever
     outlet was in flight, not the whole run's progress.
  3. Runs as a plain script via Task Scheduler / cron - no web request,
     no PHP timeout, no set_time_limit tricks needed

Usage:
  python past3m_sync.py                  # sync all outlets with status=0 (or first run: all)
  python past3m_sync.py --sync-all       # TRUNCATE all old data, then re-sync every outlet fresh from Tableau
  python past3m_sync.py --outlet NS2BA2  # sync a single outlet only
"""

import argparse
import csv
import io
import logging
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path

import pymysql
import requests

from csv_archive import save_raw  # TEMPORARY raw-CSV evidence archive

# ============================================================
# CONFIG - move these to a separate config.py / .env in production,
# same way the PHP used config/tableau_config.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # set to a path if you need to pin the CA cert like cacert.pem; None = use system trust store

WORKBOOK_CONTENT_URL = "OM-PharmacyOutletInterbranchTransferDataSupply_CheeMeng_1_4_2026"
OUTLET_LIST_VIEW_ID = "3615cb7d-4729-4ba3-8042-b2d8ab4854ae"
DATA_VIEW_ID = "ee4aff9f-11eb-4ec3-9067-67218369a889"

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

MAX_WORKERS = 4           # PHP used parallelSize = 3. 12 was tried and overwhelmed the
                          # Tableau server (near-simultaneous timeouts/502/503 across
                          # almost every outlet on the first pass) - keep this modest.
REQUEST_TIMEOUT = 60      # seconds per outlet request
MAX_RETRIES = 5

# Anchored to this script's own location (transferpicklistpy/hyper/../logs),
# not the process's current working directory — Task Scheduler / cron don't
# reliably run with cwd set to this folder, unlike the old PHP wrapper which
# pinned it via proc_open.
LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "past3months_sync.log")

# ============================================================
# LOGGING
# ============================================================
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("past3m")


# ============================================================
# TABLEAU AUTH
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
    # FIX: PHP explicitly sent Accept: application/json (and Content-Type) —
    # this was missing here. Some Tableau Server configs respond with an
    # empty body instead of JSON when Accept isn't set explicitly.
    headers = {"Content-Type": "application/json", "Accept": "application/json"}
    resp = requests.post(url, json=payload, headers=headers, verify=CA_CERT_PATH or True, timeout=30)
    resp.raise_for_status()

    if not resp.text.strip():
        raise RuntimeError(
            f"Tableau auth returned an empty body (status {resp.status_code}). "
            f"Check TABLEAU_SERVER/API_VERSION are correct and reachable from this machine."
        )
    try:
        data = resp.json()
    except ValueError:
        raise RuntimeError(
            f"Tableau auth did not return JSON (status {resp.status_code}). "
            f"First 300 chars of response: {resp.text[:300]!r}"
        )

    creds = data.get("credentials")
    if not creds or "token" not in creds:
        raise RuntimeError(f"Tableau auth failed: {resp.text}")
    return creds["token"], creds["site"]["id"]


def fetch_view_csv(view_id, token, site_id, store_code=None, timeout=REQUEST_TIMEOUT):
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{view_id}/data"
    params = {}
    if store_code:
        params["vf_Store Short Name"] = store_code
    headers = {"X-Tableau-Auth": token}
    resp = requests.get(url, headers=headers, params=params, verify=CA_CERT_PATH or True, timeout=timeout)
    return resp


# ============================================================
# CSV PARSING - same pivoted "Measure Names / Measure Values" logic
# as processOutletCsv() in the PHP version
# ============================================================
def parse_outlet_csv(csv_text, outlet_id):
    """Returns list of dicts: outlet_id, item_code, month_year, last_month_closing, qty_stock"""
    reader = csv.DictReader(io.StringIO(csv_text))
    if reader.fieldnames is None:
        return []

    month_col = None
    item_col = None
    for hdr in reader.fieldnames:
        hl = hdr.lower().strip()
        if month_col is None and "month" in hl and "date" in hl:
            month_col = hdr
        if month_col is None and "month" in hl:
            month_col = hdr
        if item_col is None and "item code" in hl:
            item_col = hdr

    grouped = {}
    for row in reader:
        item_code = (row.get(item_col) or "").strip() if item_col else ""
        month_raw = (row.get(month_col) or "").strip() if month_col else ""
        month_year = _parse_month(month_raw)
        if not item_code or not month_year:
            continue

        measure = row.get("Measure Names")
        value_raw = row.get("Measure Values")
        if measure is None or value_raw is None:
            continue
        value = _clean_num(value_raw)

        key = (outlet_id, item_code, month_year)
        if key not in grouped:
            grouped[key] = {
                "outlet_id": outlet_id,
                "item_code": item_code,
                "month_year": month_year,
                "last_month_closing": 0,
                "qty_stock": 0,
            }
        if measure == "Last Month Closing Stock QTY":
            grouped[key]["last_month_closing"] = value or 0
        elif measure == "Quantity Sold":
            grouped[key]["qty_stock"] = value or 0

    return list(grouped.values())


def _parse_month(raw):
    for fmt in ("%B %Y", "%b %Y", "%Y-%m"):
        try:
            return datetime.strptime(raw, fmt).strftime("%Y-%m")
        except ValueError:
            continue
    return None


def _clean_num(raw):
    cleaned = raw.strip().replace(",", "")
    if cleaned == "":
        return 0
    try:
        return float(cleaned)
    except ValueError:
        return 0


# ============================================================
# DB HELPERS
# ============================================================
def get_conn():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS, database=DB_NAME,
        charset="utf8mb4", autocommit=False,
    )


def register_new_outlets(conn):
    """Cross-check Tableau's live outlet list against the status table and
    INSERT any outlet that's ODB-active + confirmed present in Tableau but has
    no status row yet — e.g. a newly opened store. Without this, a new outlet
    is silently skipped by every future sync forever, since load_outlets() only
    re-checks Tableau on the very first run ever (when the status table is
    completely empty), never again after that.
    Only compares against outlets Tableau itself confirms exist — never the raw
    `outlet` table alone, which also contains ~190 non-outlet codes (HQ, admin,
    franchise, etc.) that were never real Tableau stores and would otherwise
    get registered as permanently-failing entries."""
    # Optional housekeeping step — a slow/unreachable Tableau here must NOT
    # crash the whole sync. Retry with a growing timeout, then skip.
    resp = None
    for attempt, timeout in enumerate((120, 300, 600), start=1):
        try:
            token, site_id = tableau_auth()
            resp = fetch_view_csv(OUTLET_LIST_VIEW_ID, token, site_id, timeout=timeout)
            resp.raise_for_status()
            break
        except requests.RequestException as e:
            resp = None
            log.warning(f"[NewOutlet] Outlet list request failed (attempt {attempt}/3, timeout {timeout}s): {e}")
    if resp is None:
        log.warning("[NewOutlet] Could not load Tableau outlet list — skipping new-outlet check this run.")
        return
    reader = csv.DictReader(io.StringIO(resp.text))
    codes = set()
    for row in reader:
        code = (row.get("Store Short Name") or row.get("Store Short Name ") or next(iter(row.values()), "")).strip()
        if code:
            codes.add(code)
    if not codes:
        log.warning("[NewOutlet] Tableau outlet list came back empty — skipping new-outlet check this run.")
        return

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        fmt = ",".join(["%s"] * len(codes))
        cur.execute(f"""
            SELECT o.id, o.code
            FROM outlet o
            LEFT JOIN transferpicklist_past3months_status s ON s.outlet_id = o.id
            WHERE o.recycle = 0 AND o.code IN ({fmt}) AND s.outlet_id IS NULL
        """, tuple(codes))
        new_outlets = cur.fetchall()

    if not new_outlets:
        return

    with conn.cursor() as cur:
        for row in new_outlets:
            cur.execute("""
                INSERT INTO transferpicklist_past3months_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (row["id"], row["code"]))
    conn.commit()
    codes_list = ', '.join(r["code"] for r in new_outlets)
    log.info(f"[NewOutlet] Registered {len(new_outlets)} new outlet(s): {codes_list}")


def load_outlets(conn, single_outlet=None, sync_all=False):
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        if single_outlet:
            cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (single_outlet,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            return {single_outlet: row["id"]}

        # AUTO-TRUNCATE (parity with PHP lines 310-317 and every sibling *_sync.py
        # in this module — itemgrade_sync.py, nonmoving_sync.py, slowmoving_sync.py
        # all reset status=0 on calendar-month rollover). Without this, once every
        # outlet reaches status=1 the non-`--sync-all` cron run (`WHERE status=0`
        # below) permanently finds zero outlets and the table silently goes stale
        # month after month instead of refreshing.
        cur.execute("SELECT MAX(last_sync) AS mx FROM transferpicklist_past3months_status")
        last_sync_row = cur.fetchone()
        last_sync = last_sync_row["mx"] if last_sync_row else None
        last_month = last_sync.strftime("%Y-%m") if last_sync else None
        cur_month = datetime.now().strftime("%Y-%m")
        if last_month is not None and cur_month > last_month:
            cur.execute("UPDATE transferpicklist_past3months_status SET status=0")
            conn.commit()
            log.info("New calendar month — reset all outlet statuses.")

        cur.execute("SELECT COUNT(*) AS cnt FROM transferpicklist_past3months_status")
        has_status = cur.fetchone()["cnt"] > 0

        if has_status:
            if sync_all:
                cur.execute("SELECT outlet_id, outlet_code FROM transferpicklist_past3months_status")
            else:
                cur.execute("SELECT outlet_id, outlet_code FROM transferpicklist_past3months_status WHERE status=0")
            return {r["outlet_code"]: r["outlet_id"] for r in cur.fetchall()}

    # First run: pull outlet list from Tableau, then match against DB
    token, site_id = tableau_auth()
    resp = fetch_view_csv(OUTLET_LIST_VIEW_ID, token, site_id, timeout=120)
    resp.raise_for_status()
    reader = csv.DictReader(io.StringIO(resp.text))
    codes = set()
    for row in reader:
        code = (row.get("Store Short Name") or row.get("Store Short Name ") or next(iter(row.values()), "")).strip()
        if code:
            codes.add(code)
    if not codes:
        raise RuntimeError("No outlet codes found in Tableau outlet list.")

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        fmt = ",".join(["%s"] * len(codes))
        cur.execute(f"SELECT id, code FROM outlet WHERE recycle=0 AND code IN ({fmt})", tuple(codes))
        return {r["code"]: r["id"] for r in cur.fetchall()}


def save_outlet_rows(conn, outlet_id, outlet_code, rows, timestamp):
    """Immediately upserts one outlet's rows and marks it synced, committed
    right away — a crash/kill only loses the current outlet, not the whole run."""
    with conn.cursor() as cur:
        if rows:
            sql = """
                INSERT INTO transferpicklist_past3months
                    (outlet_id, month_year, item_code, last_month_closing, qty_stock)
                VALUES (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    last_month_closing = VALUES(last_month_closing),
                    qty_stock = VALUES(qty_stock),
                    updated_at = CURRENT_TIMESTAMP
            """
            values = [
                (r["outlet_id"], r["month_year"], r["item_code"], r["last_month_closing"], r["qty_stock"])
                for r in rows
            ]
            cur.executemany(sql, values)
        cur.execute("""
            INSERT INTO transferpicklist_past3months_status
                (outlet_id, outlet_code, status, last_sync, error_msg)
            VALUES (%s, %s, 1, %s, NULL)
            ON DUPLICATE KEY UPDATE status = 1, last_sync = VALUES(last_sync), error_msg = NULL
        """, (outlet_id, outlet_code, timestamp))
    conn.commit()


def mark_outlet_failed(conn, outlet_id, outlet_code, error_msg, timestamp):
    sql = """
        INSERT INTO transferpicklist_past3months_status
            (outlet_id, outlet_code, status, last_sync, error_msg)
        VALUES (%s, %s, 0, %s, %s)
        ON DUPLICATE KEY UPDATE status = 0, last_sync = VALUES(last_sync), error_msg = VALUES(error_msg)
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id, outlet_code, timestamp, error_msg))
    conn.commit()


def truncate_for_sync_all(conn):
    """--sync-all = full refresh: wipe every previously-synced month and reset
    all statuses, so the table ends up holding exactly what Tableau returns
    this run (no leftover older months for avg calculations to pick up)."""
    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE transferpicklist_past3months")
        cur.execute("UPDATE transferpicklist_past3months_status SET status=0")
    conn.commit()
    log.info("[SyncAll] transferpicklist_past3months truncated, statuses reset — inserting fresh Tableau data.")


def apply_rolling_window(conn, global_max_month):
    if global_max_month is None:
        return
    with conn.cursor() as cur:
        cur.execute("SELECT MAX(month_year) FROM transferpicklist_past3months")
        db_max = cur.fetchone()[0]
        if db_max is not None and global_max_month > db_max:
            cur.execute("SELECT MIN(month_year) FROM transferpicklist_past3months")
            oldest = cur.fetchone()[0]
            if oldest:
                cur.execute("DELETE FROM transferpicklist_past3months WHERE month_year=%s", (oldest,))
                conn.commit()
                log.info(f"Rolling window: removed oldest month {oldest} (new max: {global_max_month})")


# ============================================================
# MAIN
# ============================================================
def fetch_one(store_code, outlet_id, token, site_id):
    resp = fetch_view_csv(DATA_VIEW_ID, token, site_id, store_code=store_code)
    return store_code, outlet_id, resp


def sync(single_outlet=None, sync_all=False):
    start = time.time()
    log.info("=" * 40)
    log.info(f"Past3Months Sync (Python) - {datetime.now()}")
    log.info("=" * 40)

    conn = get_conn()
    if not single_outlet:
        register_new_outlets(conn)
    outlets = load_outlets(conn, single_outlet=single_outlet, sync_all=sync_all)
    if not outlets:
        log.info("No outlets to sync.")
        return
    log.info(f"Syncing {len(outlets)} outlet(s).")

    token, site_id = tableau_auth()
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Only after Tableau auth succeeded — if Tableau is down, keep the old data
    # instead of leaving an empty table. Never for a single-outlet sync.
    if sync_all and not single_outlet:
        truncate_for_sync_all(conn)

    total_rows_saved = 0
    global_max_month = None
    to_fetch = list(outlets.items())
    failed = []

    def run_batch(items, token_ref):
        results = []
        total = len(items)
        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
            futures = {
                pool.submit(fetch_one, code, oid, token_ref[0], site_id): (code, oid)
                for code, oid in items
            }
            done = 0
            for fut in as_completed(futures):
                code, oid = futures[fut]
                done += 1
                try:
                    _, _, resp = fut.result()
                    results.append((code, oid, resp))
                except Exception as e:
                    results.append((code, oid, e))
                # Progress feedback as each request lands — without this the whole
                # batch fetches silently before any line is printed, which with
                # MAX_WORKERS=4 and a 60s timeout can look "stuck" for minutes.
                log.info(f"  [{done}/{total}] {code} fetched")
        return results

    token_ref = [token]  # mutable box so re-auth can update it for the retry pass
    results = run_batch(to_fetch, token_ref)

    unauthorized = []
    for code, oid, resp_or_err in results:
        if isinstance(resp_or_err, Exception):
            log.warning(f"{code} — request error: {resp_or_err} (will retry)")
            failed.append((code, oid))
            continue
        resp = resp_or_err
        if resp.status_code == 401:
            unauthorized.append((code, oid))
            continue
        # FIX: check the full body for "<error", not just the first 200 chars —
        # PHP checked the whole string. Also catch an empty body (PHP's !$csvData).
        if not resp.text or resp.status_code != 200 or "<error" in resp.text:
            log.warning(f"{code} — bad response ({resp.status_code}) (will retry)")
            failed.append((code, oid))
            continue

        save_raw("past3months", code, resp.text)
        rows = parse_outlet_csv(resp.text, oid)
        save_outlet_rows(conn, oid, code, rows, timestamp)
        total_rows_saved += len(rows)
        for r in rows:
            if global_max_month is None or r["month_year"] > global_max_month:
                global_max_month = r["month_year"]
        log.info(f"{code} — {len(rows)} records.")

    # Re-auth once, retry all 401s together (same idea as PHP FIX #6)
    if unauthorized:
        log.info(f"Token expired — re-authenticating and retrying {len(unauthorized)} outlet(s)...")
        try:
            token_ref[0], site_id = tableau_auth()
            retry_results = run_batch(unauthorized, token_ref)
            for code, oid, resp_or_err in retry_results:
                if (isinstance(resp_or_err, Exception)
                        or resp_or_err.status_code != 200
                        or not resp_or_err.text
                        or "<error" in resp_or_err.text):
                    failed.append((code, oid))
                    continue
                save_raw("past3months", code, resp_or_err.text)
                rows = parse_outlet_csv(resp_or_err.text, oid)
                save_outlet_rows(conn, oid, code, rows, timestamp)
                total_rows_saved += len(rows)
                for r in rows:
                    if global_max_month is None or r["month_year"] > global_max_month:
                        global_max_month = r["month_year"]
        except Exception as e:
            log.error(f"Re-auth failed: {e}")
            failed.extend(unauthorized)

    # Retry loop for anything still failed, with backoff (same idea as PHP FIX #8)
    for attempt in range(1, MAX_RETRIES + 1):
        if not failed:
            break
        log.info(f"Retry {attempt}/{MAX_RETRIES} for {len(failed)} outlet(s)...")
        time.sleep(5 * attempt)
        still_failed = []
        retry_results = run_batch(failed, token_ref)

        # FIX (parity with PHP retry loop): re-auth mid-retry if token expired again
        needs_reauth = any(
            (not isinstance(r, Exception)) and r.status_code == 401
            for _, _, r in retry_results
        )
        if needs_reauth:
            log.info("Token expired again during retry — re-authenticating...")
            try:
                token_ref[0], site_id = tableau_auth()
                retry_results = run_batch(failed, token_ref)
            except Exception as e:
                log.error(f"Re-auth failed during retry: {e}")

        for code, oid, resp_or_err in retry_results:
            if (isinstance(resp_or_err, Exception)
                    or resp_or_err.status_code != 200
                    or not resp_or_err.text
                    or "<error" in resp_or_err.text):
                still_failed.append((code, oid))
                continue
            save_raw("past3months", code, resp_or_err.text)
            rows = parse_outlet_csv(resp_or_err.text, oid)
            save_outlet_rows(conn, oid, code, rows, timestamp)
            total_rows_saved += len(rows)
            # FIX: this was missing — global_max_month must be updated on every
            # successful parse, including retries, or the rolling-window check
            # can miss a month that only showed up in a retried outlet
            for r in rows:
                if global_max_month is None or r["month_year"] > global_max_month:
                    global_max_month = r["month_year"]
        failed = still_failed

    for code, oid in failed:
        mark_outlet_failed(conn, oid, code, "Failed after retries — sync manually", timestamp)
        log.error(f"{code} — failed after all retries.")

    apply_rolling_window(conn, global_max_month)

    conn.close()
    elapsed = time.time() - start
    log.info(f"Done. {len(outlets)} outlets, {total_rows_saved} rows, {len(failed)} failed. "
              f"Took {elapsed:.1f}s.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--outlet", help="Sync a single outlet code only")
    parser.add_argument("--sync-all", action="store_true", help="Truncate all old data, then re-sync every outlet")
    args = parser.parse_args()

    sync(single_outlet=args.outlet, sync_all=args.sync_all)
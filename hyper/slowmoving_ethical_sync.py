#!/usr/bin/env python3
"""
Slow Moving Ethical Sync - Python rewrite of
transferpicklist_slowmoving_ethical_sync.php's automated sync path (NOT the
manual single-outlet form, NOT HTML chrome).

Differences from the plain slowmoving_sync.py port (verified against this
file's own PHP source, not assumed from the non-ethical sibling):
  - View IDs are NOT hardcoded — both are looked up by workbook content-url
    ('OM-InterbranchTransferPharmacyOutletSlowMovingDataSupply_CheeMeng_1_4_2026')
    + view content-url ('OutletSlowMovingQty2_5FlatDownEthicalCategory_1' /
    'OutletSlowMovingQty2_5UpEthicalCategory'), same lookup-by-name pattern
    as itemgrade_sync.py. (The non-ethical slowmoving_sync.php defines this
    lookup helper too but never calls it — the ethical one actually does.)
  - Outlet list: if transferpicklist_slowmoving_ethical_status already has
    rows, read from it as usual (sync-all vs status=0). On first run there is
    NO Tableau outlet-list download at all — it copies the outlet list
    straight from transferpicklist_nonmoving_status ("same outlets as other
    syncs"), unlike the non-ethical slowmoving script's Tableau-download
    first-run path.
  - NO excluded-items filtering — the PHP explicitly comments "Exclusion
    applied at generate stage, not sync — sync all ethical data from
    Tableau as-is".
  - Retry loop starts at timeout=120s (not 600s) — matched exactly to this
    PHP file's own $curlTimeout value, not copied from the sibling.
    MAX_RETRIES=3, same as the non-ethical script.
  - No sleep(3) before the per-outlet loop (the non-ethical script has one
    after its pre-loop re-auth; this file's PHP does not).
  - Ensures its two tables exist (CREATE TABLE IF NOT EXISTS) on startup,
    since the PHP does this inline.
  - avg_monthly backfill (only where 0/NULL) and NEW-grade removal timing
    are otherwise identical to the non-ethical script.

Usage:
  python slowmoving_ethical_sync.py                 # sync only pending/failed outlets (status=0)
  python slowmoving_ethical_sync.py --sync-all       # force re-sync of every outlet
"""

import argparse
import csv
import io
import logging
import re
import sys
import time
from datetime import datetime
from pathlib import Path

import pymysql
import requests

from csv_archive import save_raw  # TEMPORARY raw-CSV evidence archive

# ============================================================
# CONFIG - matches transferpicklist_slowmoving_ethical_sync.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # None = use system trust store (consistent with sibling ports)

WORKBOOK_CONTENT_URL = "OM-InterbranchTransferPharmacyOutletSlowMovingDataSupply_CheeMeng_1_4_2026"
VIEW_FLAT_DOWN_NAME = "OutletSlowMovingQty2_5FlatDownEthicalCategory_1"
VIEW_UP_NAME = "OutletSlowMovingQty2_5UpEthicalCategory"

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

BATCH_SIZE = 500
MAX_RETRIES = 3
REQUEST_TIMEOUT_INITIAL = 120  # seconds; grows by 300 per timeout retry

LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "slowmoving_ethical_sync.log")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("slowmoving_ethical")


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


def _find_view_id(token, site_id, view_content_url):
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
            if wb.get("contentUrl") == WORKBOOK_CONTENT_URL:
                workbook_id = wb["id"]
                break
        if workbook_id or fetched >= total:
            break
        page += 1

    if not workbook_id:
        raise RuntimeError(f"Workbook not found: {WORKBOOK_CONTENT_URL}")

    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/workbooks/{workbook_id}/views"
    resp = requests.get(url, headers={"X-Tableau-Auth": token, "Accept": "application/json"},
                         verify=CA_CERT_PATH or True, timeout=30)
    j = resp.json()
    for view in j.get("views", {}).get("view", []):
        content_url = view.get("contentUrl", "")
        vname = content_url.split("/")[-1] if content_url else content_url
        if vname == view_content_url:
            return view["id"]

    raise RuntimeError(f"View '{view_content_url}' not found in workbook '{WORKBOOK_CONTENT_URL}'.")


def find_view_ids(token, site_id):
    flat_down = _find_view_id(token, site_id, VIEW_FLAT_DOWN_NAME)
    up = _find_view_id(token, site_id, VIEW_UP_NAME)
    return flat_down, up


# ============================================================
# CSV PARSING - identical shape handling to slowmoving_sync.py's parser
# ============================================================
_CLEAN_RE = re.compile(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]")


def _clean(val):
    return _CLEAN_RE.sub("", (val or "").strip())


def _to_float(raw):
    cleaned = (raw or "").replace(",", "").strip()
    if cleaned == "":
        return 0.0
    try:
        return float(cleaned)
    except ValueError:
        return 0.0


def parse_csv_rows(csv_text, outlet_id, trend_type, timestamp):
    if not csv_text or "<error" in csv_text:
        return []

    text = csv_text.replace("\r\n", "\n").replace("\r", "\n").strip()
    lines = list(csv.reader(io.StringIO(text)))
    if len(lines) < 2:
        return []
    hdr = lines[0]
    data_lines = lines[1:]

    item_col = measure_name_col = measure_val_col = None
    for col in hdr:
        cl = col.strip().lower()
        if item_col is None and "item code" in cl:
            item_col = col
        if measure_name_col is None and "measure names" in cl:
            measure_name_col = col
        if measure_val_col is None and "measure values" in cl:
            measure_val_col = col

    if not item_col:
        log.warning(f"[ParseError:{trend_type}] No 'Item Code' column. Headers: {' | '.join(hdr)}")
        return []

    # PIVOT FORMAT: Measure Names + Measure Values, one record per item.
    if measure_name_col and measure_val_col:
        item_data = {}
        for row in data_lines:
            if len(row) != len(hdr):
                continue
            data = dict(zip(hdr, row))
            item_code = _clean(data.get(item_col)).strip()
            if item_code == "":
                continue
            measure_name = (data.get(measure_name_col) or "").strip().lower()
            measure_val = _to_float(data.get(measure_val_col))

            d = item_data.setdefault(item_code, {"closing": 0, "avg": 0, "moi": 0, "adjovk": 0})
            if "closing" in measure_name:
                d["closing"] = measure_val
            elif "moi" in measure_name:
                d["moi"] = measure_val
            elif "adjusted" in measure_name:
                d["adjovk"] = measure_val
            elif "average" in measure_name:
                d["avg"] = measure_val

        rows = []
        for item_code, d in item_data.items():
            rows.append({
                "outlet_id": outlet_id,
                "item_code": item_code,
                "qty": d["adjovk"],
                "trend_type": trend_type,
                "timestamp": timestamp,
                "last_month_closing": d["closing"],
                "avg_monthly": d["avg"],
                "moi": d["moi"],
                "adjusted_overstock_qty": d["adjovk"],
            })
        return rows

    # FLAT FORMAT fallback: separate column per measure.
    qty_col = closing_col = avg_col = moi_col = adj_ovk_col = None
    for col in hdr:
        cl = col.strip().lower()
        if qty_col is None and "skus count" in cl:
            qty_col = col
        if closing_col is None and "closing" in cl:
            closing_col = col
        if moi_col is None and "moi" in cl:
            moi_col = col
        if adj_ovk_col is None and "adjusted" in cl:
            adj_ovk_col = col
        if avg_col is None and "average" in cl and "moi" not in cl:
            avg_col = col

    rows = []
    for row in data_lines:
        if len(row) != len(hdr):
            continue
        data = dict(zip(hdr, row))
        item_code = _clean(data.get(item_col)).strip()
        if item_code == "":
            continue
        rows.append({
            "outlet_id": outlet_id,
            "item_code": item_code,
            "qty": _to_float(data.get(qty_col)) if qty_col else 0.0,
            "trend_type": trend_type,
            "timestamp": timestamp,
            "last_month_closing": _to_float(data.get(closing_col)) if closing_col else 0.0,
            "avg_monthly": _to_float(data.get(avg_col)) if avg_col else 0.0,
            "moi": _to_float(data.get(moi_col)) if moi_col else 0.0,
            "adjusted_overstock_qty": _to_float(data.get(adj_ovk_col)) if adj_ovk_col else 0.0,
        })
    return rows


# ============================================================
# DB HELPERS
# ============================================================
def get_conn():
    return pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS,
                            database=DB_NAME, charset="utf8mb4", autocommit=False)


def ensure_tables(conn):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS transferpicklist_slowmoving_ethical (
                id                     INT AUTO_INCREMENT PRIMARY KEY,
                outlet_id              INT NOT NULL,
                item_code              VARCHAR(50) NOT NULL,
                qty                    FLOAT NOT NULL DEFAULT 0,
                trend_type             VARCHAR(20) NOT NULL DEFAULT '',
                timestamp              DATETIME NOT NULL,
                last_month_closing     FLOAT NOT NULL DEFAULT 0,
                avg_monthly            FLOAT NOT NULL DEFAULT 0,
                moi                    FLOAT NOT NULL DEFAULT 0,
                adjusted_overstock_qty FLOAT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_outlet_item_trend (outlet_id, item_code, trend_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        """)
        cur.execute("""
            CREATE TABLE IF NOT EXISTS transferpicklist_slowmoving_ethical_status (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                outlet_id   INT NOT NULL,
                outlet_code VARCHAR(20) NOT NULL,
                status      TINYINT(1) NOT NULL DEFAULT 0,
                last_sync   DATETIME DEFAULT NULL,
                error_msg   TEXT DEFAULT NULL,
                UNIQUE KEY uq_outlet (outlet_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        """)
    conn.commit()


def check_month_rollover(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT MAX(timestamp) AS mx FROM transferpicklist_slowmoving_ethical")
        row = cur.fetchone()
        max_ts = row[0] if row else None
        if max_ts is None:
            return
        max_month = max_ts.strftime("%Y-%m") if hasattr(max_ts, "strftime") else str(max_ts)[:7]
        cur_month = datetime.now().strftime("%Y-%m")
        if cur_month <= max_month:
            return
        cur.execute("TRUNCATE TABLE transferpicklist_slowmoving_ethical")
        cur.execute("UPDATE transferpicklist_slowmoving_ethical_status SET status = 0")
    conn.commit()
    log.info("[AutoTruncate] New month detected — data cleared, status reset.")


def register_new_outlets(conn):
    """INSERT any outlet present in transferpicklist_nonmoving_status (same
    outlet universe as the other syncs) but missing from
    slowmoving_ethical_status — e.g. a newly opened store. Without this, a new
    outlet is silently skipped by this sync forever, since load_outlets()
    previously only ever copied from nonmoving_status on the very first run
    ever (when slowmoving_ethical_status was completely empty), never again
    after that.
    Deliberately sourced from nonmoving_status, never the raw `outlet` table
    directly — that table also contains ~190 non-outlet codes (HQ, admin,
    franchise, etc.) that were never real Tableau stores."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("""
            SELECT o.id AS db_id, o.code AS outlet_code
            FROM transferpicklist_nonmoving_status ns
            INNER JOIN outlet o ON o.code = ns.outlet_code AND o.recycle = 0
            LEFT JOIN transferpicklist_slowmoving_ethical_status ses ON ses.outlet_id = o.id
            WHERE ses.outlet_id IS NULL
        """)
        new_outlets = cur.fetchall()

    if not new_outlets:
        return

    with conn.cursor() as cur:
        for row in new_outlets:
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_ethical_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (row["db_id"], row["outlet_code"]))
    conn.commit()
    codes_list = ", ".join(r["outlet_code"] for r in new_outlets)
    log.info(f"[NewOutlet] Registered {len(new_outlets)} new outlet(s): {codes_list}")


def load_outlets(conn, sync_all, single_outlet=None):
    if single_outlet:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (single_outlet,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            oid = row["id"]
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_ethical_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (oid, single_outlet))
        conn.commit()
        return {single_outlet: oid}

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT COUNT(*) AS cnt FROM transferpicklist_slowmoving_ethical_status")
        has_status = cur.fetchone()["cnt"] > 0

    if has_status:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            if sync_all:
                cur.execute("""
                    SELECT o.code AS outlet_code, o.id AS db_id
                    FROM transferpicklist_slowmoving_ethical_status s
                    JOIN outlet o ON o.code = s.outlet_code AND o.recycle = 0
                """)
            else:
                cur.execute("""
                    SELECT o.code AS outlet_code, o.id AS db_id
                    FROM transferpicklist_slowmoving_ethical_status s
                    JOIN outlet o ON o.code = s.outlet_code AND o.recycle = 0
                    WHERE s.status = 0
                """)
            rows = cur.fetchall()
        outlets = {r["outlet_code"]: r["db_id"] for r in rows}
        log.info(f"[OutletList] Loaded {len(outlets)} ethical outlet(s) from status table.")
        return outlets

    # First run — copy outlet list from the non-ethical non-moving sync's status
    # table (same universe of outlets as the other syncs). No Tableau download here.
    log.info("First run — copying outlet list from non-moving sync...")
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("""
            SELECT o.id AS db_id, o.code AS outlet_code
            FROM transferpicklist_nonmoving_status ns
            JOIN outlet o ON o.code = ns.outlet_code AND o.recycle = 0
        """)
        rows = cur.fetchall()
    outlets = {r["outlet_code"].strip(): r["db_id"] for r in rows}

    if not outlets:
        raise RuntimeError("No outlets in non-moving status table. Run Non-Moving sync first.")

    log.info(f"Copied {len(outlets)} outlet(s) from non-moving list.")
    with conn.cursor() as cur:
        for code, oid in outlets.items():
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_ethical_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (oid, code))
    conn.commit()
    return outlets


def insert_batch(conn, rows):
    """No excluded-items filtering here — ethical data is synced as-is."""
    if not rows:
        return 0
    sql = """
        INSERT INTO transferpicklist_slowmoving_ethical
            (outlet_id, item_code, qty, trend_type, timestamp, last_month_closing, avg_monthly, moi, adjusted_overstock_qty)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            qty = VALUES(qty), trend_type = VALUES(trend_type), timestamp = VALUES(timestamp),
            last_month_closing = VALUES(last_month_closing), avg_monthly = VALUES(avg_monthly),
            moi = VALUES(moi), adjusted_overstock_qty = VALUES(adjusted_overstock_qty)
    """
    values = [(r["outlet_id"], r["item_code"], r["qty"], r["trend_type"], r["timestamp"],
               r["last_month_closing"], r["avg_monthly"], r["moi"], r["adjusted_overstock_qty"])
              for r in rows]
    affected = 0
    with conn.cursor() as cur:
        for i in range(0, len(values), BATCH_SIZE):
            chunk = values[i:i + BATCH_SIZE]
            cur.executemany(sql, chunk)
            affected += cur.rowcount
    conn.commit()
    return affected


def fill_avg_monthly(conn, outlet_id):
    sql = """
        UPDATE transferpicklist_slowmoving_ethical s
        JOIN (SELECT item_code, AVG(qty_stock) AS avg_q FROM transferpicklist_past3months
              WHERE outlet_id = %s GROUP BY item_code) p ON p.item_code = s.item_code
        SET s.avg_monthly = ROUND(p.avg_q, 2)
        WHERE s.outlet_id = %s AND (s.avg_monthly = 0 OR s.avg_monthly IS NULL)
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id, outlet_id))
    conn.commit()


def remove_new_grade_items(conn, outlet_id):
    sql = """
        DELETE s FROM transferpicklist_slowmoving_ethical s
        JOIN transferpicklist_itemgrade ig ON ig.item_code = s.item_code AND ig.outlet_id = s.outlet_id
        WHERE s.outlet_id = %s AND UPPER(ig.item_grade) = 'NEW'
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id,))
        deleted = cur.rowcount
    conn.commit()
    return deleted


def update_status(conn, outlet_id, outlet_code, status, error_msg, timestamp):
    sql = """
        INSERT INTO transferpicklist_slowmoving_ethical_status
            (outlet_id, outlet_code, status, last_sync, error_msg)
        VALUES (%s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            outlet_id = VALUES(outlet_id), status = VALUES(status),
            last_sync = VALUES(last_sync), error_msg = VALUES(error_msg)
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id, outlet_code, status, timestamp, error_msg))
    conn.commit()


# ============================================================
# PER-OUTLET FETCH (sequential, inline retry - no concurrency in the PHP)
# ============================================================
def fetch_view_with_retry(trend_type, view_id, store_code, token, site_id, processed, total):
    retry_count = 0
    timeout = REQUEST_TIMEOUT_INITIAL
    csv_text = None
    curl_err = None

    while retry_count <= MAX_RETRIES:
        if retry_count > 0:
            is_timeout = curl_err is not None and "timed out" in curl_err.lower()
            is_401 = csv_text is not None and "<error" in csv_text and "401" in csv_text
            if is_timeout:
                timeout += 300
            else:
                time.sleep(retry_count * 10)
            if is_401 or is_timeout:
                token, site_id = tableau_auth()

        curl_err = None
        url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{view_id}/data"
        try:
            resp = requests.get(url, headers={"X-Tableau-Auth": token},
                                 params={"vf_Store Short Name": store_code},
                                 verify=CA_CERT_PATH or True, timeout=(30, timeout))
            csv_text = resp.text
        except requests.exceptions.RequestException as e:
            curl_err = str(e)
            csv_text = None
            retry_count += 1
            continue

        if "<error" in csv_text and "401" in csv_text:
            retry_count += 1
            continue

        return csv_text, None, token, site_id

    err_msg = f"[{trend_type}] Failed after {MAX_RETRIES} retries" + (f": {curl_err}" if curl_err else "")
    return None, err_msg, token, site_id


def sync_outlet(store_code, outlet_id, view_flat_down, view_up, token, site_id, timestamp, processed, total):
    outlet_rows = []
    err_messages = []

    for trend_type, view_id in (("flat_down", view_flat_down), ("up", view_up)):
        csv_text, err, token, site_id = fetch_view_with_retry(
            trend_type, view_id, store_code, token, site_id, processed, total)

        if err:
            err_messages.append(err)
            log.error(f"  [{processed}/{total}] {store_code} ({trend_type}) — "
                       f"FAILED after {MAX_RETRIES} retries.")
            continue

        save_raw("slowmoving_ethical", store_code, csv_text, part=trend_type)

        if "<error" in csv_text:
            m = re.search(r"<summary>(.*?)</summary>", csv_text)
            err_text = m.group(1) if m else "Tableau error"
            if trend_type == "up" and "bad request" in err_text.lower():
                log.info(f"  [{processed}/{total}] {store_code} ({trend_type}) — no data (Bad Request).")
                continue
            err_messages.append(f"{trend_type}: {err_text}")
            log.error(f"  [{processed}/{total}] {store_code} ({trend_type}) — {err_text}")
            continue

        outlet_rows.extend(parse_csv_rows(csv_text, outlet_id, trend_type, timestamp))

    return outlet_rows, err_messages, token, site_id


# ============================================================
# MAIN
# ============================================================
def sync(sync_all=False, single_outlet=None):
    start = time.time()
    log.info("=" * 50)
    log.info(f"Slow-Moving Ethical Sync (Python) - {datetime.now()}")
    log.info("=" * 50)

    conn = get_conn()
    ensure_tables(conn)

    if not single_outlet:
        register_new_outlets(conn)

    token, site_id = tableau_auth()
    log.info("[Auth] Tableau authentication OK.")
    view_flat_down, view_up = find_view_ids(token, site_id)
    log.info(f"[ViewIDs] flat_down={view_flat_down} | up={view_up}")

    if not single_outlet:
        check_month_rollover(conn)

    outlets = load_outlets(conn, sync_all, single_outlet=single_outlet)
    if not outlets:
        log.info("No outlets to sync.")
        conn.close()
        return
    total_outlets = len(outlets)
    log.info(f"Found {total_outlets} outlet(s) to sync.")

    # Re-authenticate before per-outlet sync (mirrors PHP's re-auth before the loop).
    token, site_id = tableau_auth()

    log.info("Starting per-outlet ethical sync (flat/down + up)...")
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    processed = 0
    total_saved = 0
    errors = 0
    new_grade_summary = {}

    for store_code, outlet_id in outlets.items():
        processed += 1

        if processed > 1 and (processed % 50) == 1:
            token, site_id = tableau_auth()
            log.info(f"[Auth] Re-authenticated at outlet {processed}")

        outlet_rows, err_messages, token, site_id = sync_outlet(
            store_code, outlet_id, view_flat_down, view_up, token, site_id, timestamp, processed, total_outlets)

        if err_messages:
            errors += 1
            err_str = "; ".join(err_messages)
            update_status(conn, outlet_id, store_code, 0, err_str, timestamp)
            log.warning(f"  [{processed}/{total_outlets}] {store_code} — FAILED: {err_str}")

        if outlet_rows:
            saved_this_outlet = insert_batch(conn, outlet_rows)
            fill_avg_monthly(conn, outlet_id)
            deleted_new = remove_new_grade_items(conn, outlet_id)
            kept = max(0, saved_this_outlet - deleted_new)
            total_saved += kept
            if deleted_new > 0:
                new_grade_summary[store_code] = deleted_new
            note = f" ({deleted_new} NEW-grade removed)" if deleted_new > 0 else ""
            log.info(f"  [{processed}/{total_outlets}] {store_code} — {kept} records{note}.")
            if not err_messages:
                update_status(conn, outlet_id, store_code, 1, None, timestamp)
        else:
            if not err_messages:
                log.info(f"  [{processed}/{total_outlets}] {store_code} — 0 records.")
                update_status(conn, outlet_id, store_code, 1, None, timestamp)

    conn.close()
    elapsed = time.time() - start
    log.info("")
    log.info(f"Done! Processed {processed} outlets. Total saved: {total_saved} records. "
              f"Errors: {errors} outlet(s). Took {elapsed:.1f}s.")

    if new_grade_summary:
        log.info("Outlets with NEW-grade items removed:")
        for code, cnt in sorted(new_grade_summary.items(), key=lambda kv: kv[1], reverse=True):
            log.info(f"  {code}: {cnt}")
    else:
        log.info("No NEW-grade items removed this sync.")
    log.info("=" * 50)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--sync-all", action="store_true", help="Force re-sync of every outlet")
    parser.add_argument("--outlet", help="Sync a single outlet code only")
    args = parser.parse_args()
    sync(sync_all=args.sync_all, single_outlet=args.outlet)

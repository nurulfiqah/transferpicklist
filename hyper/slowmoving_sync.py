#!/usr/bin/env python3
"""
Slow Moving Sync - Python rewrite of transferpicklist_slowmoving_sync.php's
"Sync All" / "Sync Failed" path (the automated $_GET['sync_all'] / scheduled
code path only - the manual single-outlet form and the manual "refresh
outlets" action are page-chrome/manual-trigger features and are out of scope
for this port).

Mirrors the PHP version's current (fixed) logic:
  - Workbook/view IDs are HARDCODED constants in the PHP (unlike itemgrade,
    which looks the view up by name) - the PHP defines a tableauFindViewIdSM()
    helper but never actually calls it for this sync, so the port does not
    call it either. Two trend types are fetched per outlet, each from its own
    fixed view id: flat_down (VIEW_ID_FLAT_DOWN) and up (VIEW_ID_UP).
  - Tableau filter param is `vf_Store Short Name` = the outlet's short code
    (same convention as past3m_sync.py), NOT the zero-padded acmm_id that
    itemgrade_sync.py uses.
  - Outlet list source: first run downloads the union of two Tableau outlet
    lists (VIEW_ID_OUTLET_FLAT_DOWN + VIEW_ID_OUTLET_UP) and seeds
    transferpicklist_slowmoving_status; subsequent runs read from that status
    table (all outlets with --sync-all, else only status=0).
  - New-calendar-month rollover: unlike itemgrade (which resets status only),
    the PHP TRUNCATEs the whole transferpicklist_slowmoving table AND resets
    every outlet's status to 0, keyed off MAX(timestamp) in that table (not a
    last_sync column).
  - Row writes are a plain per-outlet INSERT ... ON DUPLICATE KEY UPDATE
    (upsert) across both trend types combined - there is NO per-outlet DELETE
    step here (unlike itemgrade's DELETE+INSERT IGNORE pattern), and no
    table-wide truncate outside of the month-rollover case above.
  - An in-memory exclusion list (transferpicklist_excluded_items) is loaded
    once and rows for excluded item codes are dropped right before insert.
  - Processing is SEQUENTIAL, one outlet at a time - the PHP file has no
    curl_multi/concurrency anywhere in this sync path (unlike itemgrade's
    curl_multi / CONCURRENCY=8), so no ThreadPoolExecutor is used here.
    Retries instead happen inline, per Tableau view fetch, within the same
    per-outlet loop (up to 3 retries per view, with backoff/timeout-increase
    and re-auth-on-401-or-timeout) - there is no separate second retry pass
    after the main loop (unlike itemgrade's/past3m's trailing retry pass),
    because the PHP doesn't have one either.
  - A Tableau "Bad Request" error on the 'up' trend view is not a real error
    (it means the outlet has no up-trending items) and is treated as zero
    rows for that trend type, not a failure.
  - After a successful per-outlet insert: avg_monthly is backfilled from
    transferpicklist_past3months wherever Tableau didn't provide it, then any
    rows whose item now carries a 'NEW' item_grade (transferpicklist_itemgrade)
    are deleted back out again (new items must never appear in the slow-moving
    list), and the outlet's kept-row count is saved-minus-deleted.

Usage:
  python slowmoving_sync.py                  # sync only pending/failed outlets (status=0)
  python slowmoving_sync.py --sync-all        # force re-sync of every outlet
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
# CONFIG - matches transferpicklist_slowmoving_sync.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # None = use system trust store (PHP pins ../kpi/cacert.pem; see report)

# Hardcoded view IDs, exactly as the PHP declares them - NOT looked up by name.
# Workbook (for reference / re-discovery only, never queried at runtime):
#   OM-InterbranchTransferPharmacyOutletSlowMovingDataSupply_CheeMeng_1_4_2026
VIEW_ID_FLAT_DOWN = "d086e806-4253-4e9e-afbc-a96123efb93d"          # OutletSlowMovingQty2_5FlatDown
VIEW_ID_UP = "f35c6d3a-352d-4e5b-9d86-a21b11ee6303"                 # OutletSlowMovingQty2_5Up
VIEW_ID_OUTLET_FLAT_DOWN = "956d6af4-a6f0-4a17-950b-8df4e09d1b0b"
VIEW_ID_OUTLET_UP = "f35c6d3a-352d-4e5b-9d86-a21b11ee6303"          # same as VIEW_ID_UP - PHP notes the
                                                                     # dedicated outlet-list view is broken

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

BATCH_SIZE = 500
MAX_RETRIES = 3            # per-view retry count (matches PHP's $maxRetries = 3)
REQUEST_TIMEOUT_INITIAL = 600  # seconds; PHP starts at 600s and adds 300s per timeout retry
OUTLET_LIST_TIMEOUT = 600

LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "slowmoving_sync.log")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("slowmoving")


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
    resp = requests.post(url, json=payload, headers={"Accept": "application/json"},
                          verify=CA_CERT_PATH or True, timeout=30)
    data = resp.json()
    creds = data.get("credentials")
    if not creds or "token" not in creds:
        raise RuntimeError(f"Tableau auth failed: {resp.text}")
    return creds["token"], creds["site"]["id"]


# ============================================================
# OUTLET LIST DOWNLOAD (first-run only)
# ============================================================
def download_outlet_codes(view_id, token, site_id):
    """Mirrors downloadOutletCodesFromView(). Returns (codes_list, error_str)."""
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{view_id}/data"
    try:
        resp = requests.get(url, headers={"X-Tableau-Auth": token},
                             verify=CA_CERT_PATH or True, timeout=(30, OUTLET_LIST_TIMEOUT))
        text = resp.text
    except requests.exceptions.RequestException as e:
        return None, str(e)

    if not text or "<error" in text:
        stripped = re.sub(r"<[^>]+>", "", text or "")
        return None, stripped[:200]

    text = text.replace("\r\n", "\n").replace("\r", "\n").strip()
    lines = list(csv.reader(io.StringIO(text)))
    if not lines:
        return [], None
    hdr = lines[0]
    codes = set()
    for row in lines[1:]:
        if len(row) != len(hdr):
            continue
        data = dict(zip(hdr, row))
        code = data.get("Store Short Name")
        if code is None:
            code = data.get("Store Short Name ")
        if code is None:
            code = next(iter(data.values()), "")
        code = (code or "").strip()
        if code:
            codes.add(code)
    return list(codes), None


def register_new_outlets(conn, token, site_id):
    """Cross-check Tableau's live outlet list (union of the flat/down + up
    outlet-list views, same as first-run bootstrap) against the status table
    and INSERT any outlet that's ODB-active + confirmed present in Tableau but
    has no status row yet — e.g. a newly opened store. Without this, a new
    outlet is silently skipped by every future sync forever, since load_outlets()
    only downloads the Tableau outlet list on the very first run ever (when the
    status table is completely empty), never again after that.
    Only compares against outlets Tableau itself confirms exist — never the raw
    `outlet` table alone, which also contains ~190 non-outlet codes (HQ, admin,
    franchise, etc.) that were never real Tableau stores and would otherwise
    get registered as permanently-failing entries."""
    codes_flat, err_flat = download_outlet_codes(VIEW_ID_OUTLET_FLAT_DOWN, token, site_id)
    if err_flat:
        log.warning(f"[NewOutlet] Flat/Down outlet list failed: {err_flat}")
        codes_flat = []
    codes_up, err_up = download_outlet_codes(VIEW_ID_OUTLET_UP, token, site_id)
    if err_up:
        log.warning(f"[NewOutlet] Up outlet list failed: {err_up}")
        codes_up = []

    all_codes = list(set(codes_flat) | set(codes_up))
    if not all_codes:
        log.warning("[NewOutlet] Tableau outlet list came back empty — skipping new-outlet check this run.")
        return

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        fmt = ",".join(["%s"] * len(all_codes))
        cur.execute(f"""
            SELECT o.id, o.code
            FROM outlet o
            LEFT JOIN transferpicklist_slowmoving_status s ON s.outlet_id = o.id
            WHERE o.recycle = 0 AND o.code IN ({fmt}) AND s.outlet_id IS NULL
        """, tuple(all_codes))
        new_outlets = cur.fetchall()

    if not new_outlets:
        return

    with conn.cursor() as cur:
        for row in new_outlets:
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (row["id"], row["code"]))
    conn.commit()
    codes_list = ", ".join(r["code"] for r in new_outlets)
    log.info(f"[NewOutlet] Registered {len(new_outlets)} new outlet(s): {codes_list}")


# ============================================================
# CSV PARSING - mirrors parseSMCsvRows()
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
    """Returns list of row-dicts. Mirrors PHP's pivot-format-first, flat-format-fallback logic."""
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


def check_month_rollover(conn):
    """AUTO-TRUNCATE: new calendar month detected -> clear the whole table + reset status."""
    with conn.cursor() as cur:
        cur.execute("SELECT MAX(timestamp) AS mx FROM transferpicklist_slowmoving")
        row = cur.fetchone()
        max_ts = row[0] if row else None
        if max_ts is None:
            return
        max_month = max_ts.strftime("%Y-%m") if hasattr(max_ts, "strftime") else str(max_ts)[:7]
        cur_month = datetime.now().strftime("%Y-%m")
        if cur_month <= max_month:
            return
        cur.execute("TRUNCATE TABLE transferpicklist_slowmoving")
        cur.execute("UPDATE transferpicklist_slowmoving_status SET status = 0")
    conn.commit()
    log.info("[AutoTruncate] New month detected - data cleared, status reset.")


def load_excluded_items(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT item_code FROM transferpicklist_excluded_items")
        return {r[0] for r in cur.fetchall()}


def load_outlets(conn, token, site_id, sync_all, single_outlet=None):
    if single_outlet:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (single_outlet,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            oid = row["id"]
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (oid, single_outlet))
        conn.commit()
        return {single_outlet: oid}

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT COUNT(*) AS cnt FROM transferpicklist_slowmoving_status")
        has_status = cur.fetchone()["cnt"] > 0

    if has_status:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            if sync_all:
                cur.execute("""
                    SELECT o.code AS outlet_code, o.id AS db_id
                    FROM transferpicklist_slowmoving_status s
                    JOIN outlet o ON o.code = s.outlet_code AND o.recycle = 0
                """)
            else:
                cur.execute("""
                    SELECT o.code AS outlet_code, o.id AS db_id
                    FROM transferpicklist_slowmoving_status s
                    JOIN outlet o ON o.code = s.outlet_code AND o.recycle = 0
                    WHERE s.status = 0
                """)
            rows = cur.fetchall()
        outlets = {r["outlet_code"]: r["db_id"] for r in rows}
        log.info(f"[OutletList] Loaded {len(outlets)} outlet(s) from status table"
                  f" ({'sync-all' if sync_all else 'failed/unsynced only'}).")
        return outlets

    # First run - download outlet list from Tableau (union of flat/down + up outlet lists)
    log.info("First run - downloading outlet list from Tableau...")
    codes_flat, err_flat = download_outlet_codes(VIEW_ID_OUTLET_FLAT_DOWN, token, site_id)
    if err_flat:
        log.warning(f"Flat/Down outlet list failed: {err_flat}")
        codes_flat = []
    codes_up, err_up = download_outlet_codes(VIEW_ID_OUTLET_UP, token, site_id)
    if err_up:
        log.warning(f"Up outlet list failed: {err_up} - using flat/down list for up trend too.")
        codes_up = []

    all_codes = list(set(codes_flat) | set(codes_up))
    if not all_codes:
        raise RuntimeError("No outlet codes found in Tableau outlet lists.")

    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        fmt = ",".join(["%s"] * len(all_codes))
        cur.execute(f"SELECT id, code FROM outlet WHERE recycle = 0 AND code IN ({fmt})", tuple(all_codes))
        matched = cur.fetchall()
    outlets = {r["code"].strip(): r["id"] for r in matched}
    log.info(f"Outlet list: {len(outlets)} outlet(s) matched "
              f"(flat/down: {len(codes_flat)}, up: {len(codes_up)}).")

    with conn.cursor() as cur:
        for code, oid in outlets.items():
            cur.execute("""
                INSERT INTO transferpicklist_slowmoving_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (oid, code))
    conn.commit()
    return outlets


def insert_batch(conn, rows, excluded_items):
    """Per-outlet upsert (INSERT ... ON DUPLICATE KEY UPDATE), skipping excluded item codes."""
    filtered = [r for r in rows if r["item_code"] not in excluded_items]
    if not filtered:
        return 0
    sql = """
        INSERT INTO transferpicklist_slowmoving
            (outlet_id, item_code, qty, trend_type, timestamp, last_month_closing, avg_monthly, moi, adjusted_overstock_qty)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            qty = VALUES(qty), trend_type = VALUES(trend_type), timestamp = VALUES(timestamp),
            last_month_closing = VALUES(last_month_closing), avg_monthly = VALUES(avg_monthly),
            moi = VALUES(moi), adjusted_overstock_qty = VALUES(adjusted_overstock_qty)
    """
    values = [(r["outlet_id"], r["item_code"], r["qty"], r["trend_type"], r["timestamp"],
               r["last_month_closing"], r["avg_monthly"], r["moi"], r["adjusted_overstock_qty"])
              for r in filtered]
    affected = 0
    with conn.cursor() as cur:
        for i in range(0, len(values), BATCH_SIZE):
            chunk = values[i:i + BATCH_SIZE]
            cur.executemany(sql, chunk)
            affected += cur.rowcount
    conn.commit()
    return affected


def fill_avg_monthly(conn, outlet_id):
    """Fill avg_monthly from past3months only where Tableau did not provide it."""
    sql = """
        UPDATE transferpicklist_slowmoving s
        JOIN (SELECT item_code, AVG(qty_stock) AS avg_q FROM transferpicklist_past3months
              WHERE outlet_id = %s GROUP BY item_code) p ON p.item_code = s.item_code
        SET s.avg_monthly = ROUND(p.avg_q, 2)
        WHERE s.outlet_id = %s AND (s.avg_monthly = 0 OR s.avg_monthly IS NULL)
    """
    with conn.cursor() as cur:
        cur.execute(sql, (outlet_id, outlet_id))
    conn.commit()


def remove_new_grade_items(conn, outlet_id):
    """Remove NEW-grade items - new items must not appear in the slow moving list."""
    sql = """
        DELETE s FROM transferpicklist_slowmoving s
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
        INSERT INTO transferpicklist_slowmoving_status
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
    """Mirrors the PHP's per-view retry loop: up to MAX_RETRIES retries, backoff on
    generic failure, growing timeout + re-auth on timeout, re-auth on 401.
    Returns (csv_text_or_None, error_or_None, token, site_id)."""
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
                log.info(f"  [{processed}/{total}] {store_code} ({trend_type}) - "
                          f"Retry {retry_count}/{MAX_RETRIES}, timeout increased to {timeout}s.")
            else:
                wait = retry_count * 10
                log.info(f"  [{processed}/{total}] {store_code} ({trend_type}) - "
                          f"Retry {retry_count}/{MAX_RETRIES}, waiting {wait}s.")
                time.sleep(wait)
            if is_401 or is_timeout:
                token, site_id = tableau_auth()
                log.info(f"  [{processed}/{total}] {store_code} ({trend_type}) - "
                          f"Re-authenticated (reason: {'401' if is_401 else 'timeout'}).")

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

        # 401 token expired - retry (will re-auth at top of next iteration)
        if "<error" in csv_text and "401" in csv_text:
            retry_count += 1
            continue

        return csv_text, None, token, site_id

    err_msg = f"[{trend_type}] Failed after {MAX_RETRIES} retries" + (f": {curl_err}" if curl_err else "")
    return None, err_msg, token, site_id


def sync_outlet(store_code, outlet_id, token, site_id, timestamp, processed, total):
    """Fetches both trend types for one outlet. Returns (rows, err_messages, token, site_id)."""
    outlet_rows = []
    err_messages = []

    for trend_type, view_id in (("flat_down", VIEW_ID_FLAT_DOWN), ("up", VIEW_ID_UP)):
        csv_text, err, token, site_id = fetch_view_with_retry(
            trend_type, view_id, store_code, token, site_id, processed, total)

        if err:
            err_messages.append(err)
            log.error(f"  [{processed}/{total}] {store_code} ({trend_type}) - "
                       f"FAILED after {MAX_RETRIES} retries.")
            continue

        save_raw("slowmoving", store_code, csv_text, part=trend_type)

        # Bad Request on 'up' trend = outlet has no up-trending items - not a real error
        if "<error" in csv_text:
            m = re.search(r"<summary>(.*?)</summary>", csv_text)
            err_text = m.group(1) if m else "Tableau error"
            if trend_type == "up" and "bad request" in err_text.lower():
                log.info(f"  [{processed}/{total}] {store_code} ({trend_type}) - no data (Bad Request).")
                continue
            err_messages.append(f"{trend_type}: {err_text}")
            log.error(f"  [{processed}/{total}] {store_code} ({trend_type}) - {err_text}")
            continue

        outlet_rows.extend(parse_csv_rows(csv_text, outlet_id, trend_type, timestamp))

    return outlet_rows, err_messages, token, site_id


# ============================================================
# MAIN
# ============================================================
def sync(sync_all=False, single_outlet=None):
    start = time.time()
    log.info("=" * 50)
    log.info(f"Slow-Moving Sync (Python) - {datetime.now()}")
    log.info("=" * 50)

    conn = get_conn()
    if not single_outlet:
        check_month_rollover(conn)

    token, site_id = tableau_auth()
    log.info("[Auth] Tableau authentication OK.")

    if not single_outlet:
        register_new_outlets(conn, token, site_id)

    excluded = load_excluded_items(conn)
    log.info(f"[Exclusion] Loaded {len(excluded)} excluded item(s).")

    outlets = load_outlets(conn, token, site_id, sync_all, single_outlet=single_outlet)
    if not outlets:
        log.info("No outlets found. Nothing to do.")
        conn.close()
        return
    total_outlets = len(outlets)
    log.info(f"Found {total_outlets} outlet(s) to sync.")

    # Re-authenticate before per-outlet sync (mirrors PHP's re-auth + sleep(3))
    token, site_id = tableau_auth()
    time.sleep(3)

    log.info("Starting per-outlet sync (flat/down + up trend)...")
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    processed = 0
    total_saved = 0
    errors = 0
    new_grade_summary = {}  # outlet_code -> items removed

    for store_code, outlet_id in outlets.items():
        processed += 1

        # Re-authenticate every 50 outlets to avoid token expiry mid-sync
        if processed > 1 and (processed % 50) == 1:
            token, site_id = tableau_auth()
            log.info(f"[Auth] Re-authenticated at outlet {processed}")

        outlet_rows, err_messages, token, site_id = sync_outlet(
            store_code, outlet_id, token, site_id, timestamp, processed, total_outlets)

        if err_messages:
            errors += 1
            err_str = "; ".join(err_messages)
            update_status(conn, outlet_id, store_code, 0, err_str, timestamp)
            log.warning(f"  [{processed}/{total_outlets}] {store_code} - FAILED: {err_str}")

        if outlet_rows:
            saved_this_outlet = insert_batch(conn, outlet_rows, excluded)
            fill_avg_monthly(conn, outlet_id)
            deleted_new = remove_new_grade_items(conn, outlet_id)
            kept = max(0, saved_this_outlet - deleted_new)
            total_saved += kept
            if deleted_new > 0:
                new_grade_summary[store_code] = deleted_new
            note = f" ({deleted_new} NEW-grade removed)" if deleted_new > 0 else ""
            log.info(f"  [{processed}/{total_outlets}] {store_code} - {kept} records{note}.")
            if not err_messages:
                update_status(conn, outlet_id, store_code, 1, None, timestamp)
        else:
            if not err_messages:
                log.info(f"  [{processed}/{total_outlets}] {store_code} - 0 records.")
                update_status(conn, outlet_id, store_code, 1, None, timestamp)

    conn.close()
    elapsed = time.time() - start
    log.info("")
    log.info(f"Done! Processed {processed} outlets. Total saved: {total_saved} records. "
              f"Errors: {errors} outlets. Took {elapsed:.1f}s.")

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

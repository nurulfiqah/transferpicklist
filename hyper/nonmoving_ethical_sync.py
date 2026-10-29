#!/usr/bin/env python3
"""
Non-Moving Ethical Sync - Python rewrite of transferpicklist_nonmoving_ethical_sync.php's
automated sync path (NOT the single-outlet manual-pick form, NOT HTML chrome).

Differences from the plain nonmoving_sync.py port (verified against this file's
own PHP source, not assumed from the non-ethical sibling):
  - The view is NOT hardcoded — it's looked up by workbook content-url
    ('OM-InterbranchTransferPharmacyOutletNonMovingandSlowMovingDataSupply_CheeMeng_1_4_2026')
    + view content-url ('Outlet6MNonMovingStockQtyEthicalCategory'), same
    lookup-by-name pattern as itemgrade_sync.py.
  - Outlet list ALWAYS comes from transferpicklist_nonmoving_status (the
    non-ethical sync's own status table) — there is no --use-tableau mode
    and no first-run Tableau outlet-list download branch here at all.
    Resume-mode (skip already-synced) is still checked against THIS script's
    own transferpicklist_nonmoving_ethical_status table, and newly-seen
    outlets get registered into it (INSERT IGNORE) before the sync loop.
  - NO excluded-items filtering — the PHP explicitly comments "Exclusion
    applied at generate stage, not sync — sync all ethical data from
    Tableau as-is". Do not filter transferpicklist_excluded_items here.
  - Auto-truncate: --sync-all OR new-calendar-month rollover both truncate
    transferpicklist_nonmoving_ethical + reset status (same as non-ethical);
    an empty table just resets status, no truncate needed.
  - avg_monthly = Tableau's "Average Past 6 Months Quantity Sold", stored
    as-is (NOT recomputed from transferpicklist_past3months).
  - Retry loop: MAX_RETRIES=3, starting timeout=120s (smaller than the
    non-ethical nonmoving script's 5/600 — matched exactly to this PHP file's
    own $maxRetries/$curlTimeout values, not copied from the sibling).
  - Ensures its two tables exist (CREATE TABLE IF NOT EXISTS) on startup,
    since the PHP does this inline and the tables aren't guaranteed to
    pre-exist the way the non-ethical ones are.

Usage:
  python nonmoving_ethical_sync.py                 # sync only pending/failed outlets (status=0)
  python nonmoving_ethical_sync.py --sync-all       # force full TRUNCATE + re-sync of every outlet
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
# CONFIG - matches transferpicklist_nonmoving_ethical_sync.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # None = use system trust store (consistent with sibling ports)

WORKBOOK_CONTENT_URL = "OM-InterbranchTransferPharmacyOutletNonMovingandSlowMovingDataSupply_CheeMeng_1_4_2026"
VIEW_CONTENT_URL = "Outlet6MNonMovingStockQtyEthicalCategory"

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

BATCH_SIZE = 500
MAX_RETRIES = 3
FETCH_TIMEOUT = 120  # seconds, grows by 300 on each timeout retry
REAUTH_EVERY = 50

LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "nonmoving_ethical_sync.log")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("nonmoving_ethical")


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
        if vname == VIEW_CONTENT_URL:
            return view["id"]

    raise RuntimeError(f"View '{VIEW_CONTENT_URL}' not found in workbook.")


# ============================================================
# FETCH + PARSE ONE OUTLET
# ============================================================
def fetch_csv(view_id, token, site_id, store_code, timeout):
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{view_id}/data"
    params = {"vf_Store Short Name": store_code}
    headers = {"X-Tableau-Auth": token}
    resp = requests.get(url, headers=headers, params=params,
                         verify=CA_CERT_PATH or True, timeout=timeout)
    return resp.text


_CLEAN_RE = re.compile(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]")


def clean(val):
    return _CLEAN_RE.sub("", (val or "").strip())


def is_401(csv_text):
    return csv_text is not None and "<error" in csv_text and "401" in csv_text


def tableau_error_summary(csv_text):
    m = re.search(r"<summary>(.*?)</summary>", csv_text)
    return m.group(1) if m else "Tableau error"


def parse_csv(csv_text, outlet_id, timestamp):
    """Same shape (flat or pivoted) handling as nonmoving_sync.py's parser:
    closing_stock + avg_monthly (Tableau's "Average Past 6 Months Quantity
    Sold", used as-is) merged into one row per item."""
    text = csv_text.replace("\r\n", "\n").replace("\r", "\n").strip()
    lines = text.split("\n")
    if len(lines) < 2:
        return [], None

    reader = csv.reader(io.StringIO(lines[0]))
    hdr = next(reader)

    item_col = measure_nm_col = measure_val_col = closing_col = avg_col = None
    for col in hdr:
        cl = col.strip().lower()
        if item_col is None and "item code" in cl:
            item_col = col
        if measure_nm_col is None and "measure names" in cl:
            measure_nm_col = col
        if measure_val_col is None and "measure values" in cl:
            measure_val_col = col
        if closing_col is None and ("closing" in cl or "stock qty" in cl):
            closing_col = col
        if avg_col is None and "average" in cl:
            avg_col = col

    if not item_col:
        return None, f"Cannot detect Item Code column. Headers: {', '.join(hdr)}"

    is_pivot = measure_nm_col is not None and measure_val_col is not None

    def num(raw):
        raw = (raw or "").strip().replace(",", "")
        return float(raw) if raw else 0.0

    items = {}  # item_code -> row, keeps Tableau's order
    for line in lines[1:]:
        if line.strip() == "":
            continue
        row = next(csv.reader(io.StringIO(line)))
        if len(row) != len(hdr):
            continue
        data = dict(zip(hdr, row))
        item_code = clean(data.get(item_col, "")).strip()
        if item_code == "":
            continue

        r = items.setdefault(item_code, {
            "outlet_id": outlet_id,
            "item_code": item_code,
            "closing_stock": 0.0,
            "avg_monthly": 0.0,
            "timestamp": timestamp,
        })
        if is_pivot:
            measure_name = (data.get(measure_nm_col) or "").strip().lower()
            if "average" in measure_name:
                r["avg_monthly"] = num(data.get(measure_val_col))
            elif "closing" in measure_name or "last month" in measure_name:
                r["closing_stock"] = num(data.get(measure_val_col))
        else:
            if closing_col:
                r["closing_stock"] = num(data.get(closing_col))
            if avg_col:
                r["avg_monthly"] = num(data.get(avg_col))

    return list(items.values()), None


# ============================================================
# DB HELPERS
# ============================================================
def get_conn():
    return pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS,
                            database=DB_NAME, charset="utf8mb4", autocommit=False)


def ensure_tables(conn):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS transferpicklist_nonmoving_ethical (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                outlet_id     INT NOT NULL,
                item_code     VARCHAR(50) NOT NULL,
                closing_stock FLOAT NOT NULL DEFAULT 0,
                avg_monthly   FLOAT NOT NULL DEFAULT 0,
                timestamp     DATETIME NOT NULL,
                UNIQUE KEY uq_outlet_item (outlet_id, item_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        """)
        cur.execute("""
            CREATE TABLE IF NOT EXISTS transferpicklist_nonmoving_ethical_status (
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


def auto_truncate(conn, sync_all):
    """Returns True if transferpicklist_nonmoving_ethical was freshly truncated this run."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT MAX(timestamp) AS mx FROM transferpicklist_nonmoving_ethical")
        row = cur.fetchone()
        max_month = row["mx"].strftime("%Y-%m") if row and row["mx"] else None
        cur_month = datetime.now().strftime("%Y-%m")

        if sync_all:
            cur.execute("TRUNCATE TABLE transferpicklist_nonmoving_ethical")
            cur.execute("UPDATE transferpicklist_nonmoving_ethical_status SET status=0")
            conn.commit()
            log.info("[AutoTruncate] Sync All — data cleared, starting fresh.")
            return True
        elif max_month is not None and cur_month > max_month:
            cur.execute("TRUNCATE TABLE transferpicklist_nonmoving_ethical")
            cur.execute("UPDATE transferpicklist_nonmoving_ethical_status SET status=0")
            conn.commit()
            log.info("[AutoTruncate] New month detected — data cleared, status reset.")
            return True
        elif max_month is None:
            cur.execute("UPDATE transferpicklist_nonmoving_ethical_status SET status=0")
            conn.commit()
    return False


def load_outlets(conn, sync_all, single_outlet=None):
    """Always sourced from the non-ethical sync's status table (its own outlet universe),
    then filtered against this script's own ethical status table for resume mode."""
    if single_outlet:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (single_outlet,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            oid = int(row["id"])
        with conn.cursor() as cur:
            cur.execute(
                "INSERT IGNORE INTO transferpicklist_nonmoving_ethical_status (outlet_id, outlet_code, status) VALUES (%s, %s, 0)",
                (oid, single_outlet),
            )
        conn.commit()
        return {single_outlet: oid}

    outlets = {}
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("""
            SELECT s.outlet_id, s.outlet_code
            FROM transferpicklist_nonmoving_status s
            INNER JOIN outlet o ON o.id = s.outlet_id AND o.recycle = 0
            ORDER BY s.outlet_code
        """)
        for row in cur.fetchall():
            outlets[row["outlet_code"]] = int(row["outlet_id"])
    log.info(f"[OutletList] {len(outlets)} outlet(s).")

    if not sync_all and outlets:
        ids = list(outlets.values())
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            fmt = ",".join(["%s"] * len(ids))
            cur.execute(
                f"SELECT outlet_id FROM transferpicklist_nonmoving_ethical_status WHERE outlet_id IN ({fmt}) AND status=1",
                ids,
            )
            done_ids = {row["outlet_id"] for row in cur.fetchall()}
        outlets = {code: oid for code, oid in outlets.items() if oid not in done_ids}

    # Register any newly-seen outlets into the ethical status table.
    with conn.cursor() as cur:
        for code, oid in outlets.items():
            cur.execute(
                "INSERT IGNORE INTO transferpicklist_nonmoving_ethical_status (outlet_id, outlet_code, status) VALUES (%s, %s, 0)",
                (oid, code),
            )
    conn.commit()

    return outlets


def save_rows(conn, rows):
    """No excluded-items filtering here — ethical data is synced as-is."""
    if not rows:
        return 0
    sql = """
        INSERT INTO transferpicklist_nonmoving_ethical
            (outlet_id, item_code, closing_stock, avg_monthly, timestamp)
        VALUES (%s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            closing_stock = VALUES(closing_stock),
            avg_monthly   = VALUES(avg_monthly),
            timestamp     = VALUES(timestamp)
    """
    saved = 0
    with conn.cursor() as cur:
        for i in range(0, len(rows), BATCH_SIZE):
            chunk = rows[i:i + BATCH_SIZE]
            values = [(r["outlet_id"], r["item_code"], r["closing_stock"], r["avg_monthly"], r["timestamp"]) for r in chunk]
            saved += cur.executemany(sql, values) or 0
    conn.commit()
    return saved


def post_process_outlet(conn, outlet_id):
    """Delete rows whose item is graded NEW (Tableau lists "item grade = new" under
    "Requires Manual Exclusion"). avg_monthly is NOT touched here — it comes straight
    from Tableau's "Average Past 6 Months Quantity Sold" in parse_csv()."""
    with conn.cursor() as cur:
        cur.execute("""
            DELETE n FROM transferpicklist_nonmoving_ethical n
            JOIN transferpicklist_itemgrade ig ON ig.item_code = n.item_code AND ig.outlet_id = n.outlet_id
            WHERE n.outlet_id = %s AND UPPER(ig.item_grade) = 'NEW'
        """, (outlet_id,))
        deleted_new = cur.rowcount
    conn.commit()
    return deleted_new


def update_status(conn, outlet_id, outlet_code, status, error_msg, timestamp):
    sql = """
        INSERT INTO transferpicklist_nonmoving_ethical_status
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
# PER-OUTLET FETCH WITH INLINE RETRY (sequential, no concurrency in PHP)
# ============================================================
def fetch_outlet_with_retry(store_code, view_id, token, site_id):
    timeout = FETCH_TIMEOUT
    retry = 0
    last_err = None
    csv_text = None
    while retry <= MAX_RETRIES:
        if retry > 0:
            was_timeout = last_err is not None and "timed out" in last_err.lower()
            was_401 = is_401(csv_text)
            if was_timeout:
                timeout += 300
            else:
                time.sleep(retry * 10)
            if was_401 or was_timeout:
                token, site_id = tableau_auth()

        last_err = None
        try:
            csv_text = fetch_csv(view_id, token, site_id, store_code, timeout)
        except requests.exceptions.RequestException as e:
            last_err = str(e)
            csv_text = None
            retry += 1
            continue

        if "<error" in csv_text and "401" in csv_text:
            retry += 1
            continue

        return csv_text, None, token, site_id

    return None, f"Failed after {MAX_RETRIES} retries" + (f": {last_err}" if last_err else ""), token, site_id


# ============================================================
# MAIN
# ============================================================
def sync(sync_all=False, single_outlet=None):
    start = time.time()
    log.info("=" * 50)
    log.info(f"Non-Moving Ethical Sync (Python) - {datetime.now()}")
    log.info("=" * 50)

    conn = get_conn()
    ensure_tables(conn)

    token, site_id = tableau_auth()
    log.info("[Auth] OK.")
    view_id = find_view_id(token, site_id)
    log.info(f"[ViewID] {view_id}")

    if not single_outlet:
        auto_truncate(conn, sync_all)

    outlets = load_outlets(conn, sync_all, single_outlet=single_outlet)
    if not outlets:
        log.info("No outlets to sync.")
        conn.close()
        return

    total = len(outlets)
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Re-authenticate right before the per-outlet loop, same as the PHP.
    token, site_id = tableau_auth()

    processed = 0
    for store_code, outlet_id in outlets.items():
        processed += 1

        if processed > 1 and processed % REAUTH_EVERY == 1:
            token, site_id = tableau_auth()
            log.info(f"[Auth] Re-authenticated at outlet {processed}")

        csv_text, err, token, site_id = fetch_outlet_with_retry(store_code, view_id, token, site_id)

        if err:
            update_status(conn, outlet_id, store_code, 0, err, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — FAILED: {err}")
            continue

        save_raw("nonmoving_ethical", store_code, csv_text)

        if csv_text == "":
            update_status(conn, outlet_id, store_code, 1, None, timestamp)
            log.info(f"  [{processed}/{total}] {store_code} — 0 records (no data).")
            continue

        if "<error" in csv_text:
            err_msg = tableau_error_summary(csv_text)
            update_status(conn, outlet_id, store_code, 0, err_msg, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — {err_msg}")
            continue

        rows, parse_err = parse_csv(csv_text, outlet_id, timestamp)
        if parse_err:
            update_status(conn, outlet_id, store_code, 0, parse_err, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — {parse_err}")
            continue

        if rows:
            save_rows(conn, rows)
            deleted_new = post_process_outlet(conn, outlet_id)
            suffix = f" ({deleted_new} NEW-grade removed)" if deleted_new > 0 else ""
            log.info(f"  [{processed}/{total}] {store_code} — {len(rows)} records.{suffix}")
            update_status(conn, outlet_id, store_code, 1, None, timestamp)
        else:
            log.info(f"  [{processed}/{total}] {store_code} — 0 records.")
            update_status(conn, outlet_id, store_code, 1, None, timestamp)

    conn.close()
    elapsed = time.time() - start
    log.info("=" * 50)
    log.info(f"Non-Moving Ethical Sync — DONE. {processed} outlets. Took {elapsed:.1f}s.")
    log.info("=" * 50)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--sync-all", action="store_true",
                         help="Force full TRUNCATE + re-sync of every outlet")
    parser.add_argument("--outlet", help="Sync a single outlet code only")
    args = parser.parse_args()
    sync(sync_all=args.sync_all, single_outlet=args.outlet)

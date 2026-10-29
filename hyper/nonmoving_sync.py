#!/usr/bin/env python3
"""
Non-Moving Sync - Python rewrite of transferpicklist_nonmoving_sync.php's
"Sync All" / "Sync Pending" automated path (the scheduled/`sync_all` code
path — NOT the single-outlet manual-pick form, NOT the HTML page chrome).

Mirrors the PHP version's current (fixed) logic:
  - Always uses the "6M Non Moving Stock Qty" view via its HARDCODED view id
    (the PHP does NOT look this one up by workbook/view name the way
    itemgrade_sync.php does — it pins the id directly with the comment
    "Always use 6M Non Moving dashboard"). There is a separate dashboard-only
    label in transferpicklist_sync_status.php that rotates 6M/9M/12M by
    calendar month, but the sync script itself is NOT wired to that — it is
    hardcoded to 6M regardless of month. Replicated faithfully, not "fixed".
  - Filter param is `vf_Store Short Name` = the outlet CODE (e.g. "NS2GH2"),
    NOT a zero-padded acmm_id like itemgrade_sync.php uses.
  - Outlet list source (default): transferpicklist_nonmoving_status itself
    (NOT copied from transferpicklist_past3months_status like itemgrade's
    first-run bootstrap — nonmoving's status table is assumed pre-seeded).
    An optional --use-tableau mode instead downloads the outlet list from
    the "Non Moving Outlet List" Tableau view and cross-checks it against
    the local `outlet` table, skipping codes not found there.
  - Whole-table TRUNCATE (not per-outlet DELETE) of transferpicklist_nonmoving
    plus a full status reset, triggered by --sync-all, OR a new-calendar-month
    rollover (comparing MAX(timestamp)'s month against today), OR the table
    being empty (status-only reset in that last case, nothing to truncate).
  - INSERT dialect depends on whether Sync All truncated the table this run:
    after a truncate, plain INSERT IGNORE is safe; otherwise (resume/pending
    mode, table NOT truncated) INSERT ... ON DUPLICATE KEY UPDATE is used so
    a re-synced outlet's stale rows get overwritten.
  - Rows whose item_code is in transferpicklist_excluded_items are dropped
    before insert.
  - avg_monthly = Tableau's "Average Past 6 Months Quantity Sold", stored
    as-is (NOT recomputed from transferpicklist_past3months).
  - After saving an outlet's rows: delete any row whose item_code/outlet_id
    joins to an "NEW" grade in transferpicklist_itemgrade (item grade is
    synced separately by itemgrade_sync.py; this script only reads that table).
  - CSV can be a flat sheet ("closing"/"stock qty" + "average" columns) OR a
    pivoted Measure Names/Measure Values sheet, in which case the "closing"/
    "last month" and "average" measure rows are merged into one row per item.
  - Per-outlet fetch retry is SEQUENTIAL and INLINE (up to 5 retries), NOT
    a concurrent ThreadPoolExecutor batch like itemgrade_sync.py/past3m_sync.py
    use — the PHP source for this file has no curl_multi/concurrency at all,
    just a single curl_exec per outlet inside a retry while-loop, so that is
    what's replicated here (see NOTE in module docstring below for why this
    differs from the other two ports).
      - On a timeout error: increase the per-request timeout by 300s and
        retry immediately (no sleep).
      - On any other error: sleep (10 * retry_count) seconds before retrying.
      - Re-authenticate only when the failure was a 401 or a timeout, not on
        every retry.
  - Re-authenticates every 50 outlets processed (mid-loop), same idea as
    itemgrade's periodic re-auth but on a plain counter, not a 401 signal.

Usage:
  python nonmoving_sync.py                 # sync only pending/failed outlets (status=0)
  python nonmoving_sync.py --sync-all       # force full TRUNCATE + re-sync of every outlet
  python nonmoving_sync.py --use-tableau    # load the outlet list from Tableau instead of
                                             # the local status table (mirrors ?use_tableau=1)
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
# CONFIG - matches transferpicklist_nonmoving_sync.php
# ============================================================
TABLEAU_SERVER = "https://tableaualpro.qragoracloud.com"
API_VERSION = "3.1"
# Tableau login comes from transferpicklist/.env — see db_config.py
from db_config import TABLEAU_API_USER, TABLEAU_API_PASS
CA_CERT_PATH = None  # None = use system trust store (same as past3m_sync.py/itemgrade_sync.py)

# Hardcoded view id — PHP does NOT look this up by workbook/view name for
# nonmoving (unlike itemgrade). "6M Non Moving Stock Qty (Excluded New item
# filter)", permanent per the PHP's own comment.
VIEW_ID_NONMOVING = "5dec4cc0-2be8-40ab-aa8d-c002afd56648"
# "Non Moving Outlet List" view — only used in --use-tableau mode.
OUTLET_LIST_VIEW_ID = "e313eae8-1856-4235-8ad2-3cfbd2784b29"

# Local (Laragon) vs production DB is picked automatically - see db_config.py
from db_config import DB_ENV, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME

BATCH_SIZE = 500
MAX_RETRIES = 5
FETCH_TIMEOUT = 600  # seconds, grows by 300 on each timeout retry
REAUTH_EVERY = 50

LOG_DIR = Path(__file__).resolve().parent.parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(LOG_DIR / "nonmoving_sync.log")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("nonmoving")


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


def is_retryable_error(csv_text):
    """Mirrors the PHP's retry-worthy-error check (400/401/Unauthorized/Bad Request)."""
    if csv_text == "" or "<error" not in csv_text:
        return False
    return any(tok in csv_text for tok in ("400", "401", "Unauthorized", "Bad Request"))


def is_401(csv_text):
    return csv_text is not None and "401" in csv_text


def tableau_error_summary(csv_text):
    m = re.search(r"<summary>(.*?)</summary>", csv_text)
    if m:
        return m.group(1)
    stripped = re.sub(r"<[^>]+>", "", csv_text)
    return f"Tableau error: {stripped[:200]}"


def parse_csv(csv_text, outlet_id, timestamp):
    """
    Returns (rows, error). rows is a list of dicts:
      {outlet_id, item_code, closing_stock, avg_monthly, timestamp}
    avg_monthly is Tableau's own "Average Past 6 Months Quantity Sold" — used
    as-is, never recomputed from transferpicklist_past3months.
    Handles both a flat CSV ('closing'/'stock qty' + 'average' columns) and a
    pivoted Measure Names/Measure Values CSV (one row per measure per item,
    merged back into one row per item here).
    """
    text = csv_text.replace("\r\n", "\n").replace("\r", "\n").strip()
    lines = text.split("\n")
    if len(lines) < 2:
        return [], None  # 0 records, not an error

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


def load_excluded_items(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT item_code FROM transferpicklist_excluded_items")
        excluded = {row[0] for row in cur.fetchall()}
    log.info(f"[Exclusion] Loaded {len(excluded)} excluded item(s).")
    return excluded


def auto_truncate(conn, sync_all):
    """
    Mirrors the PHP's AUTO-TRUNCATE block. Returns True if the table was
    (or already is) freshly truncated this run — callers use this to pick
    INSERT IGNORE vs INSERT...ON DUPLICATE KEY UPDATE, same as the PHP's
    $GLOBALS['_isSyncAll'] flag driving insertNMBatch().
    """
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT MAX(timestamp) AS mx FROM transferpicklist_nonmoving")
        row = cur.fetchone()
        max_month = row["mx"].strftime("%Y-%m") if row and row["mx"] else None
        cur_month = datetime.now().strftime("%Y-%m")

        if sync_all:
            cur.execute("TRUNCATE TABLE transferpicklist_nonmoving")
            cur.execute("UPDATE transferpicklist_nonmoving_status SET status=0")
            conn.commit()
            log.info("[AutoTruncate] Sync All triggered — data cleared, status reset.")
            return True
        elif max_month is not None and cur_month > max_month:
            cur.execute("TRUNCATE TABLE transferpicklist_nonmoving")
            cur.execute("UPDATE transferpicklist_nonmoving_status SET status=0")
            conn.commit()
            log.info("[AutoTruncate] New month detected — data cleared, status reset.")
            return True
        elif max_month is None:
            cur.execute("UPDATE transferpicklist_nonmoving_status SET status=0")
            conn.commit()
            log.info("[AutoTruncate] Table empty — status reset for full re-sync.")
            return False
    return False


def register_new_outlets(conn, token, site_id):
    """Cross-check Tableau's live outlet list against the status table and
    INSERT any outlet that's ODB-active + confirmed present in Tableau but has
    no status row yet — e.g. a newly opened store. Without this, a new outlet
    is silently skipped by every future sync forever, since the default (non
    --use-tableau) mode only ever reads the existing status table.
    Only compares against outlets Tableau itself confirms exist — never the raw
    `outlet` table alone, which also contains ~190 non-outlet codes (HQ, admin,
    franchise, etc.) that were never real Tableau stores and would otherwise
    get registered as permanently-failing entries."""
    url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{OUTLET_LIST_VIEW_ID}/data"
    # Optional housekeeping step — a slow/unreachable Tableau here must NOT
    # crash the whole sync (it runs before auto_truncate, so the real sync
    # would never start). Retry with a growing timeout, then skip.
    outlet_csv = None
    for attempt, timeout in enumerate((120, 300, 600), start=1):
        try:
            resp = requests.get(url, headers={"X-Tableau-Auth": token},
                                verify=CA_CERT_PATH or True, timeout=timeout)
            outlet_csv = resp.text
            break
        except requests.RequestException as e:
            log.warning(f"[NewOutlet] Outlet list request failed (attempt {attempt}/3, timeout {timeout}s): {e}")
    if not outlet_csv or "<error" in outlet_csv:
        log.warning("[NewOutlet] Could not load Tableau outlet list — skipping new-outlet check this run.")
        return

    outlet_csv = outlet_csv.replace("\r\n", "\n").replace("\r", "\n").strip()
    lines = outlet_csv.split("\n")
    hdr = next(csv.reader(io.StringIO(lines[0])))
    codes = set()
    for line in lines[1:]:
        if line.strip() == "":
            continue
        row = next(csv.reader(io.StringIO(line)))
        if len(row) != len(hdr):
            continue
        data = dict(zip(hdr, row))
        code = ""
        for col in hdr:
            if "store short" in col.lower():
                code = data[col].strip()
                break
        if code == "":
            code = next(iter(data.values())).strip()
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
            LEFT JOIN transferpicklist_nonmoving_status s ON s.outlet_id = o.id
            WHERE o.recycle = 0 AND o.code IN ({fmt}) AND s.outlet_id IS NULL
        """, tuple(codes))
        new_outlets = cur.fetchall()

    if not new_outlets:
        return

    with conn.cursor() as cur:
        for row in new_outlets:
            cur.execute("""
                INSERT INTO transferpicklist_nonmoving_status (outlet_id, outlet_code, status)
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)
            """, (row["id"], row["code"]))
    conn.commit()
    codes_list = ", ".join(r["code"] for r in new_outlets)
    log.info(f"[NewOutlet] Registered {len(new_outlets)} new outlet(s): {codes_list}")


def load_outlets(conn, sync_all, use_tableau, token=None, site_id=None, single_outlet=None):
    if single_outlet:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (single_outlet,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError(f"Outlet {single_outlet} not found.")
            return {single_outlet: int(row["id"])}

    outlets = {}  # code -> outlet_id
    if use_tableau:
        log.info("[OutletList] Downloading outlet list from Tableau...")
        # Outlet-list view takes no store filter — plain GET.
        url = f"{TABLEAU_SERVER}/api/{API_VERSION}/sites/{site_id}/views/{OUTLET_LIST_VIEW_ID}/data"
        resp = requests.get(url, headers={"X-Tableau-Auth": token},
                             verify=CA_CERT_PATH or True, timeout=120)
        outlet_csv = resp.text
        if not outlet_csv or "<error" in outlet_csv:
            raise RuntimeError("Could not load outlet list from Tableau. Cannot proceed.")

        outlet_csv = outlet_csv.replace("\r\n", "\n").replace("\r", "\n").strip()
        lines = outlet_csv.split("\n")
        hdr = next(csv.reader(io.StringIO(lines[0])))
        tableau_codes = []
        seen = set()
        for line in lines[1:]:
            if line.strip() == "":
                continue
            row = next(csv.reader(io.StringIO(line)))
            if len(row) != len(hdr):
                continue
            data = dict(zip(hdr, row))
            code = ""
            for col in hdr:
                if "store short" in col.lower():
                    code = data[col].strip()
                    break
            if code == "":
                code = next(iter(data.values())).strip()
            if code and code not in seen:
                seen.add(code)
                tableau_codes.append(code)
        log.info(f"[OutletList] Tableau outlet list: {len(tableau_codes)} outlet(s).")

        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            for code in tableau_codes:
                cur.execute("SELECT id FROM outlet WHERE recycle=0 AND code=%s LIMIT 1", (code,))
                row = cur.fetchone()
                if not row:
                    log.info(f"[OutletList] Skipped {code} — not found in outlet table.")
                else:
                    outlets[code] = int(row["id"])
    else:
        log.info("[OutletList] Loading outlet list from status table...")
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("""
                SELECT s.outlet_id, s.outlet_code
                FROM transferpicklist_nonmoving_status s
                INNER JOIN outlet o ON o.id = s.outlet_id AND o.recycle = 0
                ORDER BY s.outlet_code
            """)
            for row in cur.fetchall():
                outlets[row["outlet_code"]] = int(row["outlet_id"])
        log.info(f"[OutletList] Loaded {len(outlets)} outlet(s) from status table.")

    # Resume mode: skip already-synced outlets unless sync_all.
    if not sync_all and outlets:
        ids = list(outlets.values())
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            fmt = ",".join(["%s"] * len(ids))
            cur.execute(
                f"SELECT outlet_id FROM transferpicklist_nonmoving_status WHERE outlet_id IN ({fmt}) AND status=1",
                ids,
            )
            done_ids = {row["outlet_id"] for row in cur.fetchall()}
        outlets = {code: oid for code, oid in outlets.items() if oid not in done_ids}

    return outlets


def load_outlet_maps(conn):
    """outlet.id -> acmm_id (unused for fetch here, kept for parity/debug)."""
    acmm_ids = {}
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT id, acmm_id, comp_name FROM outlet WHERE recycle=0")
        for row in cur.fetchall():
            acmm_ids[int(row["id"])] = int(row["acmm_id"]) if row["acmm_id"] else 0
    return acmm_ids


def save_rows(conn, outlet_id, rows, excluded, is_sync_all_truncated):
    """
    Filters excluded item codes, then batches INSERT IGNORE (fresh-truncated
    table) or INSERT ... ON DUPLICATE KEY UPDATE (resume/pending mode),
    exactly matching insertNMBatch()'s two dialects.
    """
    filtered = [r for r in rows if r["item_code"] not in excluded]
    if not filtered:
        return 0

    if is_sync_all_truncated:
        sql = """
            INSERT IGNORE INTO transferpicklist_nonmoving
                (outlet_id, item_code, closing_stock, avg_monthly, timestamp)
            VALUES (%s, %s, %s, %s, %s)
        """
    else:
        sql = """
            INSERT INTO transferpicklist_nonmoving
                (outlet_id, item_code, closing_stock, avg_monthly, timestamp)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                closing_stock = VALUES(closing_stock),
                avg_monthly   = VALUES(avg_monthly),
                timestamp     = VALUES(timestamp)
        """
    saved = 0
    with conn.cursor() as cur:
        for i in range(0, len(filtered), BATCH_SIZE):
            chunk = filtered[i:i + BATCH_SIZE]
            values = [(r["outlet_id"], r["item_code"], r["closing_stock"], r["avg_monthly"], r["timestamp"]) for r in chunk]
            saved += cur.executemany(sql, values) or 0
    conn.commit()
    return saved


def post_process_outlet(conn, outlet_id):
    """
    Delete any row whose item_code carries an 'NEW' item grade for this
    outlet in transferpicklist_itemgrade (Tableau's view lists "item grade =
    new" under "Requires Manual Exclusion"). Returns the number of NEW-grade
    rows removed. avg_monthly is NOT touched here — it comes straight from
    Tableau's "Average Past 6 Months Quantity Sold" in parse_csv().
    """
    with conn.cursor() as cur:
        cur.execute("""
            DELETE n FROM transferpicklist_nonmoving n
            JOIN transferpicklist_itemgrade ig ON ig.item_code = n.item_code AND ig.outlet_id = n.outlet_id
            WHERE n.outlet_id = %s AND UPPER(ig.item_grade) = 'NEW'
        """, (outlet_id,))
        deleted_new = cur.rowcount
    conn.commit()
    return deleted_new


def update_status(conn, outlet_id, outlet_code, status, error_msg, timestamp):
    sql = """
        INSERT INTO transferpicklist_nonmoving_status
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
# PER-OUTLET FETCH WITH INLINE RETRY (sequential — see module docstring)
# ============================================================
def fetch_outlet_with_retry(store_code, view_id, token, site_id):
    """
    Returns (csv_text_or_None, error_or_None, token, site_id).
    Mirrors the PHP's retry while-loop: up to MAX_RETRIES retries, timeout
    grows +300s on a timeout error, 10s*retry wait otherwise, re-auth only
    on 401/timeout.
    """
    timeout = FETCH_TIMEOUT
    retry = 0
    last_err = None
    csv_text = None
    while retry <= MAX_RETRIES:
        if retry > 0:
            was_timeout = last_err is not None and "timed out" in last_err.lower()
            was_401 = csv_text is not None and is_401(csv_text)
            if was_timeout:
                timeout += 300
                log.info(f"  {store_code} — Retry {retry}/{MAX_RETRIES}, timeout increased to {timeout}s.")
            else:
                wait = retry * 10
                log.info(f"  {store_code} — Retry {retry}/{MAX_RETRIES}, waiting {wait}s.")
                time.sleep(wait)
            if was_401 or was_timeout:
                token, site_id = tableau_auth()
                log.info(f"  {store_code} — Re-authenticated (reason: {'401' if was_401 else 'timeout'}).")

        last_err = None
        try:
            csv_text = fetch_csv(view_id, token, site_id, store_code, timeout)
        except requests.exceptions.RequestException as e:
            last_err = str(e)
            csv_text = None
            retry += 1
            continue

        if is_retryable_error(csv_text):
            retry += 1
            continue

        return csv_text, None, token, site_id

    return None, f"Failed after {MAX_RETRIES} retries: {last_err}", token, site_id


# ============================================================
# MAIN
# ============================================================
def sync(sync_all=False, use_tableau=False, single_outlet=None):
    start = time.time()
    log.info("=" * 50)
    log.info(f"Non-Moving Sync (Python) - {datetime.now()}")
    log.info("=" * 50)

    conn = get_conn()

    token, site_id = tableau_auth()
    log.info("[Auth] Tableau authentication OK. View: 6M Non Moving.")

    if not single_outlet:
        register_new_outlets(conn, token, site_id)

    excluded = load_excluded_items(conn)

    # Single-outlet mode must never truncate the shared table — that would
    # wipe every other outlet's data just to resync one.
    is_truncated = False if single_outlet else auto_truncate(conn, sync_all)

    outlets = load_outlets(conn, sync_all, use_tableau, token, site_id, single_outlet=single_outlet)
    if not outlets:
        log.info("No outlets to sync. All up to date!")
        conn.close()
        return

    load_outlet_maps(conn)  # parity with PHP's STEP 3 (not otherwise used — filter is by outlet CODE)

    total = len(outlets)
    log.info(f"Syncing {total} outlet(s).")
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Re-authenticate right before per-outlet loop, same as PHP's STEP 3.
    token, site_id = tableau_auth()

    processed = 0
    for store_code, outlet_id in outlets.items():
        processed += 1

        if processed > 1 and processed % REAUTH_EVERY == 1:
            token, site_id = tableau_auth()
            log.info(f"[Auth] Re-authenticated at outlet {processed}")

        csv_text, err, token, site_id = fetch_outlet_with_retry(store_code, VIEW_ID_NONMOVING, token, site_id)

        if err:
            update_status(conn, outlet_id, store_code, 0, err, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — FAILED: {err}")
            continue

        save_raw("nonmoving", store_code, csv_text)

        if csv_text == "":
            update_status(conn, outlet_id, store_code, 1, None, timestamp)
            log.info(f"  [{processed}/{total}] {store_code} — 0 records (no data in Tableau).")
            continue

        if "<error" in csv_text:
            err_msg = tableau_error_summary(csv_text)
            update_status(conn, outlet_id, store_code, 0, err_msg, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — Error: {err_msg}")
            continue

        rows, parse_err = parse_csv(csv_text, outlet_id, timestamp)
        if parse_err:
            update_status(conn, outlet_id, store_code, 0, parse_err, timestamp)
            log.warning(f"  [{processed}/{total}] {store_code} — {parse_err}")
            continue

        if rows:
            saved = save_rows(conn, outlet_id, rows, excluded, is_truncated)
            deleted_new = post_process_outlet(conn, outlet_id)
            suffix = f" ({deleted_new} NEW-grade removed)" if deleted_new > 0 else ""
            log.info(f"  [{processed}/{total}] {store_code} — {len(rows)} records.{suffix}")
            update_status(conn, outlet_id, store_code, 1, None, timestamp)
        else:
            log.info(f"  [{processed}/{total}] {store_code} — 0 records from Tableau.")
            update_status(conn, outlet_id, store_code, 1, "0 records from Tableau", timestamp)

    conn.close()
    elapsed = time.time() - start
    log.info("=" * 50)
    log.info(f"Non-Moving Sync — DONE. {processed} outlets. Took {elapsed:.1f}s.")
    log.info("=" * 50)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--sync-all", action="store_true",
                         help="Force full TRUNCATE + re-sync of every outlet")
    parser.add_argument("--use-tableau", action="store_true",
                         help="Load the outlet list from Tableau instead of the local status table")
    parser.add_argument("--outlet", help="Sync a single outlet code only")
    args = parser.parse_args()
    sync(sync_all=args.sync_all, use_tableau=args.use_tableau, single_outlet=args.outlet)

"""
TEMPORARY — raw Tableau CSV archive, kept as evidence because Tableau data
changes over time.

Every sync saves exactly what Tableau returned for each outlet, untouched:

  hyper/csv_archive/<dashboard>/<YYYYMMDD>/<outlet>[_<part>].csv

One folder per DAY. If the same outlet is synced again later the same day, the
earlier file is kept and the new one is saved as <outlet>[_<part>]_<HHMMSS>.csv
(HHMMSS = when that later sync started), so nothing is overwritten. A retry for
the same outlet within ONE sync run overwrites that run's own file (latest wins).

Older per-run folders (<YYYYMMDD_HHMMSS>, the previous layout) are merged into
their day folder automatically the next time that dashboard syncs.

Saving is best-effort: any error is logged as a warning and never breaks the sync.
To switch off: set ENABLED = False (or delete this file and the save_raw() calls).

Retention: when a run starts saving, day folders of that dashboard older than
KEEP_MONTHS calendar months (by the date in the folder name) are deleted.
Set KEEP_MONTHS = 0 to keep everything forever.
"""

import logging
import re
import shutil
from datetime import datetime
from pathlib import Path

ENABLED = True
KEEP_MONTHS = 5
ARCHIVE_ROOT = Path(__file__).resolve().parent / "csv_archive"

_run_dirs = {}    # dashboard -> this run's day folder
_run_stamp = {}   # dashboard -> HHMMSS this run started
_chosen = {}      # dashboard -> {base name: file name used by this run}


def _cutoff(now, months):
    """Same day/time `months` calendar months before `now` (day clamped to month end)."""
    idx = now.year * 12 + (now.month - 1) - months
    year, month = idx // 12, idx % 12 + 1
    if month == 12:
        last_day = 31
    else:
        last_day = (datetime(year, month + 1, 1) - datetime(year, month, 1)).days
    return now.replace(year=year, month=month, day=min(now.day, last_day))


def _merge_legacy_runs(dashboard):
    """Move files from old <YYYYMMDD_HHMMSS> run folders into their <YYYYMMDD> day folder."""
    log = logging.getLogger()
    try:
        dash_dir = ARCHIVE_ROOT / dashboard
        if not dash_dir.is_dir():
            return
        legacy = sorted(d for d in dash_dir.iterdir()
                        if d.is_dir() and re.fullmatch(r"\d{8}_\d{6}", d.name))
        for d in legacy:  # oldest first, so the earliest sync keeps the plain file name
            day, stamp = d.name[:8], d.name[9:]
            target = dash_dir / day
            target.mkdir(exist_ok=True)
            for f in sorted(d.glob("*.csv")):
                dest = target / f.name
                if dest.exists():
                    dest = target / f"{f.stem}_{stamp}.csv"
                shutil.move(str(f), str(dest))
            try:
                d.rmdir()
            except OSError:
                pass  # something else still in it — leave the folder alone
        if legacy:
            log.info(f"[CsvArchive] {dashboard}: merged {len(legacy)} old run folder(s) into day folders.")
    except Exception as e:
        log.warning(f"[CsvArchive] Merging old {dashboard} run folders failed: {e}")


def _purge_old_runs(dashboard):
    """Delete this dashboard's day folders older than KEEP_MONTHS months."""
    if KEEP_MONTHS <= 0:
        return
    log = logging.getLogger()
    try:
        cutoff = _cutoff(datetime.now(), KEEP_MONTHS).date()
        dash_dir = ARCHIVE_ROOT / dashboard
        if not dash_dir.is_dir():
            return
        removed = 0
        for d in dash_dir.iterdir():
            if not d.is_dir() or not re.fullmatch(r"\d{8}(_\d{6})?", d.name):
                continue  # only touch folders this module created
            try:
                day = datetime.strptime(d.name[:8], "%Y%m%d").date()
            except ValueError:
                continue
            if day < cutoff:
                shutil.rmtree(d, ignore_errors=True)
                removed += 1
        if removed:
            log.info(f"[CsvArchive] {dashboard}: deleted {removed} folder(s) older than "
                     f"{KEEP_MONTHS} months (before {cutoff:%Y-%m-%d}).")
    except Exception as e:
        log.warning(f"[CsvArchive] Cleanup of old {dashboard} folders failed: {e}")


def save_raw(dashboard, outlet, csv_text, part=None):
    if not ENABLED:
        return
    try:
        run_dir = _run_dirs.get(dashboard)
        if run_dir is None:
            # once per dashboard per sync run
            _purge_old_runs(dashboard)
            _merge_legacy_runs(dashboard)
            now = datetime.now()
            run_dir = ARCHIVE_ROOT / dashboard / now.strftime("%Y%m%d")
            run_dir.mkdir(parents=True, exist_ok=True)
            _run_dirs[dashboard] = run_dir
            _run_stamp[dashboard] = now.strftime("%H%M%S")
            _chosen[dashboard] = {}

        base = str(outlet) + (f"_{part}" if part else "")
        base = re.sub(r"[^A-Za-z0-9_.-]", "_", base)
        chosen = _chosen[dashboard]
        name = chosen.get(base)
        if name is None:
            name = base + ".csv"
            if (run_dir / name).exists():
                # an earlier sync today already saved this outlet — keep it
                name = f"{base}_{_run_stamp[dashboard]}.csv"
            chosen[base] = name
        # utf-8-sig so Excel opens item descriptions correctly
        (run_dir / name).write_text(csv_text or "", encoding="utf-8-sig", newline="")
    except Exception as e:
        logging.getLogger().warning(f"[CsvArchive] Could not save {dashboard}/{outlet}: {e}")

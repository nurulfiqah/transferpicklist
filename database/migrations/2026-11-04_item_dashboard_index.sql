-- ============================================================
-- 2026-11-04 — Covering index for the Dashboard (index.php)
--
-- The Dashboard's pick list table counts Items / Transferred / Confirm Return
-- per pick list over ALL of transferpicklist_item (1.99s of the 2.02s page on
-- production). Every column that count needs is in this index, so MariaDB
-- counts from the (much smaller) index instead of reading the full rows.
--
-- Run on: production odb, in phpMyAdmin → SQL tab. Takes a minute or so.
-- LOCK=NONE keeps the table readable and writable meanwhile, but do NOT run it
-- while a pick list is being generated.
-- ============================================================

-- STEP 1 — Add the index
ALTER TABLE transferpicklist_item
    ADD INDEX IF NOT EXISTS idx_dashboard_counts (picklist_id, status, priority_type, return_confirmed, recycle),
    ALGORITHM=INPLACE, LOCK=NONE;

-- STEP 2 — Check: idx_dashboard_counts should be listed (5 rows, one per column)
SHOW INDEX FROM transferpicklist_item WHERE Key_name = 'idx_dashboard_counts';

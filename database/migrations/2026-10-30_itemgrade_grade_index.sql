-- ============================================================
-- 2026-10-30 — Index on item grade for Item Grade Data
--
-- transferpicklist_itemgrade_list.php needs an index that starts with
-- item_grade, for:
--   * the Grade dropdown (was 50–75 seconds on production without it)
--   * the Grade filter
-- Local has it (idx_grade_outlet); production seems to be missing it.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- Run STEP 1 first and look at the result.
-- ============================================================

-- STEP 1 — Check: is there already an index whose Seq_in_index 1 is item_grade?
SHOW INDEX FROM transferpicklist_itemgrade;

-- STEP 2 — Only if STEP 1 shows NO index starting with item_grade.
-- The table has ~13 million rows, so this takes several minutes. LOCK=NONE keeps
-- the table readable and writable meanwhile, but do NOT run it while the
-- Item Grade sync (itemgrade_sync.py) is running.
ALTER TABLE transferpicklist_itemgrade
    ADD INDEX IF NOT EXISTS idx_grade_outlet (item_grade, outlet_id, item_code),
    ALGORITHM=INPLACE, LOCK=NONE;

-- STEP 3 — Check again: idx_grade_outlet should now be listed
SHOW INDEX FROM transferpicklist_itemgrade WHERE Key_name = 'idx_grade_outlet';

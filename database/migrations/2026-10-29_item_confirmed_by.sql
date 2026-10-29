-- ============================================================
-- 2026-10-29 — Who confirmed a pick list item, and when
--
-- My Outlet Tasks (transferpicklist_outlet.php) now saves the staff name
-- and time whenever an item is confirmed (single, page or Confirm All),
-- and shows it under "Confirmed" in the Status column.
-- Items confirmed before this change stay empty (no record of who/when).
--
-- Safe to run more than once (IF NOT EXISTS). The page also adds these
-- columns itself on first load if they are missing.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

ALTER TABLE transferpicklist_item
    ADD COLUMN IF NOT EXISTS confirmed_by VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS confirmed_at DATETIME     NULL;

-- Check
SHOW COLUMNS FROM transferpicklist_item LIKE 'confirmed%';

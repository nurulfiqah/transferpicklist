-- ============================================================
-- 2026-10-29 — Excluded Items (Per Outlet): record WHO excluded each pair
--
-- Adds `created_by` to transferpicklist_excluded, so the
-- Excluded Items (Per Outlet) page can show "Excluded At — by <name>"
-- (and the Excel export gets an "Excluded By" column).
-- Imports from that date onwards save the importer's name; pairs
-- excluded earlier keep created_by = NULL (shown without a name).
--
-- Safe to run more than once (IF NOT EXISTS).
-- Production is MariaDB, which supports ADD COLUMN IF NOT EXISTS.
-- (Local Laragon MySQL 8 does not — there, the page adds the column
--  by itself on first load, see transferpicklist_excluded.php.)
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

ALTER TABLE transferpicklist_excluded
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(100) NULL AFTER note;

-- Check (should list: id, item_code, outlet_code, note, created_by, created_at)
SHOW COLUMNS FROM transferpicklist_excluded;

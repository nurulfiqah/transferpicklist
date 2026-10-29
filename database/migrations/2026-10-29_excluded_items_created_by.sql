-- ============================================================
-- 2026-10-29 — Excluded Items (Global): record WHO excluded each item
--
-- Adds `created_by` to transferpicklist_excluded_items, so the
-- Excluded Items (Global) page can show "Excluded At — by <name>".
-- Imports from that date onwards save the importer's name; rows
-- excluded earlier keep created_by = NULL (shown without a name).
--
-- Safe to run more than once (IF NOT EXISTS).
-- Production is MariaDB, which supports ADD COLUMN IF NOT EXISTS.
-- (Local Laragon MySQL 8 does not — there, the page adds the column
--  by itself on first load, see transferpicklist_excluded_items.php.)
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

ALTER TABLE transferpicklist_excluded_items
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(100) NULL AFTER note;

-- Check (should list: id, item_code, note, created_by, created_at)
SHOW COLUMNS FROM transferpicklist_excluded_items;

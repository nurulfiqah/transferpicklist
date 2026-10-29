-- ============================================================
-- 2026-10-29 — Transfer Pick List access levels simplified
--
-- staff.transferpicklist now has only two values:
--   0 = no Transfer Pick List admin access
--   1 = Super Admin (full access)
-- Values 2 and 3 are no longer used by the code. Before this change
-- 3 was Super Admin and 1–2 were Admin.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

-- 1) Check first: who has which value today
SELECT id, transferpicklist FROM staff WHERE transferpicklist IN (1, 2, 3) ORDER BY transferpicklist DESC, id;

-- 2) Former Super Admins (3) keep full access as 1
UPDATE staff SET transferpicklist = 1 WHERE transferpicklist = 3;

-- 3) Value 2 — choose ONE line and remove the "-- " in front of it:
-- UPDATE staff SET transferpicklist = 1 WHERE transferpicklist = 2;   -- give them Super Admin
-- UPDATE staff SET transferpicklist = 0 WHERE transferpicklist = 2;   -- remove their access

-- ============================================================
-- 2026-10-29 — Admin Settings page (transferpicklist_admin.php)
--
-- Super Admins can grant / remove Super Admin (staff.transferpicklist = 1)
-- from the page. Every change is recorded here and shown as
-- "Recent Changes" on that page.
--
-- Safe to run more than once (IF NOT EXISTS). The page also creates this
-- table itself on first load if it is missing.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

CREATE TABLE IF NOT EXISTS transferpicklist_admin_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT          NOT NULL,              -- staff.id that was changed
    staff_name  VARCHAR(150) NULL,
    action      VARCHAR(10)  NOT NULL,              -- 'grant' | 'remove'
    by_user     VARCHAR(100) NULL,                  -- who made the change
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check
SHOW COLUMNS FROM transferpicklist_admin_log;

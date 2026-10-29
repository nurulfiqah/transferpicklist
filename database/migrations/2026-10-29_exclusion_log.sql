-- ============================================================
-- 2026-10-29 — Activity Log for the two exclusion pages
--
-- New table transferpicklist_exclusion_log, shown as "Activity Log"
-- at the bottom of:
--   Excluded Items (Global)      list_type = 'global'      (item codes)
--   Excluded Items (Per Outlet)  list_type = 'per_outlet'  ("ITEM × OUTLET" pairs)
-- Records ONLY items added (import) and removed (single remove / Clear All),
-- with who (by_user) and when (created_at).
--
-- Safe to run more than once (IF NOT EXISTS). The pages also create this
-- table themselves on first load if it is missing.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

CREATE TABLE IF NOT EXISTS transferpicklist_exclusion_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    list_type  VARCHAR(20)  NOT NULL,              -- 'global' | 'per_outlet'
    action     VARCHAR(20)  NOT NULL,              -- 'insert' | 'delete' | 'delete_all'
    item_count INT          NOT NULL DEFAULT 0,
    items      MEDIUMTEXT   NULL,                  -- comma-separated codes / pairs
    note       VARCHAR(255) NULL,                  -- note given on import
    by_user    VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_list_time (list_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check
SHOW COLUMNS FROM transferpicklist_exclusion_log;

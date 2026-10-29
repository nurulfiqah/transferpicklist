-- ============================================================
-- Transfer Pick List — MASTER database structure
-- All transferpicklist_* tables (structure only, no data).
--
-- Safe to run on an existing database: every table uses
-- CREATE TABLE IF NOT EXISTS and there is NO "DROP TABLE",
-- so existing tables and their data are never touched.
--
-- Shared tables used by this module but owned by the main
-- odb app (outlet, staff, simple) are NOT included.
-- Generated: 2026-10-29 08:43 from the local odb database
-- ============================================================

CREATE TABLE IF NOT EXISTS `transferpicklist_excluded` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) NOT NULL,
  `outlet_code` varchar(50) NOT NULL,
  `note` varchar(200) NOT NULL DEFAULT '',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pair` (`item_code`,`outlet_code`),
  KEY `idx_item_outlet` (`item_code`,`outlet_code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
CREATE TABLE IF NOT EXISTS `transferpicklist_exclusion_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `list_type` varchar(20) NOT NULL,
  `action` varchar(20) NOT NULL,
  `item_count` int NOT NULL DEFAULT '0',
  `items` mediumtext,
  `note` varchar(255) DEFAULT NULL,
  `by_user` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_list_time` (`list_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `transferpicklist_excluded_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS `transferpicklist_admin_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `staff_name` varchar(150) DEFAULT NULL,
  `action` varchar(10) NOT NULL,
  `by_user` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `transferpicklist_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `picklist_id` int unsigned NOT NULL,
  `from_outlet_id` int NOT NULL,
  `to_outlet_id` int NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `qty_transfer` decimal(15,2) NOT NULL DEFAULT '0.00',
  `priority_type` varchar(50) NOT NULL DEFAULT '',
  `status` enum('pending','transferred','cancelled') NOT NULL DEFAULT 'pending',
  `return_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  `qty_actual` decimal(10,2) DEFAULT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  `confirmed_by` varchar(100) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_from_outlet` (`from_outlet_id`),
  KEY `idx_to_outlet` (`to_outlet_id`),
  KEY `idx_picklist_id` (`picklist_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority_type`),
  KEY `idx_item_code` (`item_code`),
  KEY `idx_priority_type` (`priority_type`),
  KEY `idx_picklist_type_recycle` (`picklist_id`,`priority_type`,`recycle`),
  KEY `idx_picklist_priority_recycle` (`picklist_id`,`priority_type`,`recycle`),
  KEY `idx_to_outlet_id` (`to_outlet_id`),
  KEY `idx_from_outlet_id` (`from_outlet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_itemgrade` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `item_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `item_grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `item_model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_outlet_item` (`outlet_id`,`item_code`),
  KEY `idx_item_code` (`item_code`),
  KEY `idx_grade_outlet` (`item_grade`,`outlet_id`,`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS `transferpicklist_itemgrade_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL DEFAULT '0',
  `outlet_code` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `picklist_id` int unsigned NOT NULL,
  `action` text NOT NULL,
  `by_user` varchar(100) NOT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_nonmoving` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `closing_stock` decimal(10,2) NOT NULL DEFAULT '0.00',
  `avg_monthly` decimal(10,2) DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_outlet_item` (`outlet_id`,`item_code`),
  KEY `idx_item_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_nonmoving_ethical` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `closing_stock` float NOT NULL DEFAULT '0',
  `avg_monthly` float NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet_item` (`outlet_id`,`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE IF NOT EXISTS `transferpicklist_nonmoving_ethical_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE IF NOT EXISTS `transferpicklist_nonmoving_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_outlet_exclusion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(50) NOT NULL DEFAULT '',
  `excluded_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE IF NOT EXISTS `transferpicklist_past3months` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `month_year` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `item_code` varchar(50) NOT NULL,
  `last_month_closing` decimal(15,2) NOT NULL DEFAULT '0.00',
  `qty_stock` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet_month_item` (`outlet_id`,`month_year`,`item_code`),
  KEY `idx_outlet_id` (`outlet_id`),
  KEY `idx_month_year` (`month_year`),
  KEY `idx_item_code` (`item_code`),
  KEY `idx_item_outlet_month` (`item_code`,`outlet_id`,`month_year`),
  KEY `idx_p3m_outlet_item` (`outlet_id`,`item_code`,`month_year`),
  KEY `idx_p3m_item` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Monthly stock tracking per outlet per item';
CREATE TABLE IF NOT EXISTS `transferpicklist_past3months_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_picklist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `picklist_no` varchar(40) NOT NULL,
  `generated_by` varchar(100) NOT NULL,
  `status` enum('draft','published','completed') NOT NULL DEFAULT 'draft',
  `timestamp` datetime NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_pl_status` (`status`),
  KEY `idx_picklist_no` (`picklist_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `transferpicklist_slowmoving` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `item_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `qty` decimal(10,2) DEFAULT '0.00',
  `trend_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'flat_down or up',
  `timestamp` datetime DEFAULT NULL,
  `avg_monthly` decimal(10,2) DEFAULT NULL,
  `last_month_closing` decimal(10,2) DEFAULT '0.00',
  `moi` decimal(10,2) DEFAULT '0.00',
  `adjusted_overstock_qty` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_outlet_item_trend` (`outlet_id`,`item_code`,`trend_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS `transferpicklist_slowmoving_ethical` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `qty` float NOT NULL DEFAULT '0',
  `trend_type` varchar(20) NOT NULL DEFAULT '',
  `timestamp` datetime NOT NULL,
  `last_month_closing` float NOT NULL DEFAULT '0',
  `avg_monthly` float NOT NULL DEFAULT '0',
  `moi` float NOT NULL DEFAULT '0',
  `adjusted_overstock_qty` float NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet_item_trend` (`outlet_id`,`item_code`,`trend_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE IF NOT EXISTS `transferpicklist_slowmoving_ethical_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE IF NOT EXISTS `transferpicklist_slowmoving_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `outlet_id` int NOT NULL,
  `outlet_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `status` tinyint DEFAULT '0',
  `last_sync` datetime DEFAULT NULL,
  `error_msg` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlet` (`outlet_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- ============================================================
-- Fuel Station Management System - Database Setup Script
-- Run this in phpMyAdmin or MySQL CLI on XAMPP.
--   mysql -u root < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `fuel_station_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `fuel_station_db`;

-- Drop in FK-safe order
DROP TABLE IF EXISTS `supplier_payments`;
DROP TABLE IF EXISTS `supplier_purchases`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `market_sync_runs`;
DROP TABLE IF EXISTS `market_alerts`;
DROP TABLE IF EXISTS `market_sources`;
DROP TABLE IF EXISTS `market_prices`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `shifts`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `price_history`;
DROP TABLE IF EXISTS `fuel_inventory`;
DROP TABLE IF EXISTS `employees`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

-- ------------------------------------------------------------
-- Table: shifts
-- Cashier shifts for daily reconciliation. An operator opens a
-- shift with a starting cash float and closes it by counting the
-- drawer; expected_cash = opening_cash + cash sales.
-- ------------------------------------------------------------
CREATE TABLE `shifts` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED  NULL,
  `user_name`      VARCHAR(100)  NOT NULL DEFAULT 'Operator',
  `role`           VARCHAR(20)   NOT NULL DEFAULT 'operator',
  `opening_cash`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cash_sales`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `card_sales`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `credit_sales`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_sales`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tx_count`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `expected_cash`  DECIMAL(10,2) NULL,
  `closing_cash`   DECIMAL(10,2) NULL,
  `variance`       DECIMAL(10,2) NULL,
  `status`         VARCHAR(10)   NOT NULL DEFAULT 'open',
  `notes`          VARCHAR(255)  NULL,
  `opened_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at`      TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shift_user` (`user_id`),
  KEY `idx_shift_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: activity_logs
-- Audit trail of critical actions (logins, price changes,
-- refills, sales, voids, employee changes, shifts).
-- ------------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NULL,
  `user_name`    VARCHAR(100)  NOT NULL DEFAULT 'Guest',
  `role`         VARCHAR(20)   NOT NULL DEFAULT 'operator',
  `action`       VARCHAR(50)   NOT NULL,
  `description`  TEXT          NULL,
  `entity_type`  VARCHAR(40)   NULL,
  `entity_id`    INT UNSIGNED  NULL,
  `ip_address`   VARCHAR(45)   NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_action` (`action`),
  KEY `idx_log_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: fuel_inventory
-- Live tank status. `status` is OK / LOW / FULL.
-- Money columns use DECIMAL(10,2); volumes DECIMAL(12,2).
-- ------------------------------------------------------------
CREATE TABLE `fuel_inventory` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fuel_type`            VARCHAR(20)   NOT NULL,
  `tank_capacity_liters` DECIMAL(12,2) NOT NULL,
  `current_stock_liters` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `price_per_liter`      DECIMAL(10,2) NOT NULL,
  `status`               VARCHAR(10)   NOT NULL DEFAULT 'OK',
  `last_updated`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fuel_type` (`fuel_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: price_history
-- Audit log of every fuel price change (old -> new).
-- Append-only: rows are never updated or deleted, so an old sale
-- keeps the price it was sold at.
--   product_id    -> fuel_inventory.id of the fuel that changed
--   changed_by_id -> users.id of the admin who changed it
--   changed_by    -> their display name (kept for readability)
-- `source` = Manual | Market
-- ------------------------------------------------------------
CREATE TABLE `price_history` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fuel_type`    VARCHAR(20)   NOT NULL,
  `product_id`   INT UNSIGNED  DEFAULT NULL,
  `old_price`    DECIMAL(10,2) NOT NULL,
  `new_price`    DECIMAL(10,2) NOT NULL,
  `difference`   DECIMAL(10,2) NOT NULL,
  `changed_by`   VARCHAR(100)  NOT NULL DEFAULT 'System Admin',
  `changed_by_id` INT UNSIGNED DEFAULT NULL,
  `source`       VARCHAR(20)   NOT NULL DEFAULT 'Manual',
  `sync_run_id`  INT UNSIGNED  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fuel_type` (`fuel_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ph_product` (`product_id`),
  KEY `idx_ph_user` (`changed_by_id`),
  KEY `idx_ph_sync_run` (`sync_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: market_sync_runs
-- One row per Market Rate Sync run: the summary shown in the
-- UI (total changes, increases, decreases, unchanged, skipped).
-- ------------------------------------------------------------
CREATE TABLE `market_sync_runs` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `feed`            VARCHAR(255)  DEFAULT NULL,
  `status`          VARCHAR(12)   NOT NULL DEFAULT 'ok',
  `total_changes`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `increases`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `decreases`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `unchanged`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `skipped`         INT UNSIGNED  NOT NULL DEFAULT 0,
  `detail`          VARCHAR(500)  DEFAULT NULL,
  `error_message`   VARCHAR(255)  DEFAULT NULL,
  `triggered_by`    VARCHAR(100)  DEFAULT NULL,
  `triggered_by_id` INT UNSIGNED  DEFAULT NULL,
  `started_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`     TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY idx_sync_run_started (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: suppliers
-- Fuel suppliers: company details, contact info and notes.
-- ------------------------------------------------------------
CREATE TABLE `suppliers` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_name`   VARCHAR(120)  NOT NULL,
  `contact_person` VARCHAR(100)  DEFAULT NULL,
  `phone`          VARCHAR(30)   DEFAULT NULL,
  `email`          VARCHAR(150)  DEFAULT NULL,
  `address`        VARCHAR(255)  DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: supplier_purchases
-- Fuel bought from a supplier. Stock is added to the matched
-- tank only once a delivery is verified & confirmed (status =
-- 'confirmed', stock_applied = 1), which prevents duplicates.
-- ------------------------------------------------------------
CREATE TABLE `supplier_purchases` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `purchase_no`       VARCHAR(30)   DEFAULT NULL,
  `supplier_id`       INT UNSIGNED  NOT NULL,
  `fuel_inventory_id` INT UNSIGNED  DEFAULT NULL,
  `fuel_type`         VARCHAR(20)   NOT NULL,
  `liters`            DECIMAL(12,2) NOT NULL,
  `cost_per_liter`    DECIMAL(10,2) NOT NULL,
  `total_cost`        DECIMAL(10,2) NOT NULL,
  `invoice_no`        VARCHAR(60)   DEFAULT NULL,
  `delivery_date`     DATE          DEFAULT NULL,
  `payment_status`    VARCHAR(10)   NOT NULL DEFAULT 'Unpaid',
  `amount_paid`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tanker_no`         VARCHAR(40)   DEFAULT NULL,
  `driver_name`       VARCHAR(100)  DEFAULT NULL,
  `driver_phone`      VARCHAR(30)   DEFAULT NULL,
  `delivery_ref`      VARCHAR(60)   DEFAULT NULL,
  `status`            VARCHAR(12)   NOT NULL DEFAULT 'pending',
  `stock_applied`     TINYINT(1)    NOT NULL DEFAULT 0,
  `verified_by`       VARCHAR(100)  DEFAULT NULL,
  `verified_at`       TIMESTAMP     NULL DEFAULT NULL,
  `recorded_by`       VARCHAR(100)  DEFAULT NULL,
  `notes`             TEXT          DEFAULT NULL,
  `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_no` (`purchase_no`),
  KEY `idx_purchase_supplier` (`supplier_id`),
  KEY `idx_purchase_fuel` (`fuel_inventory_id`),
  KEY `idx_purchase_status` (`status`),
  KEY `idx_purchase_delivery` (`delivery_date`),
  CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_purchase_fuel` FOREIGN KEY (`fuel_inventory_id`)
    REFERENCES `fuel_inventory` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: supplier_payments
-- Payments made to suppliers, optionally allocated to a
-- specific purchase (purchase_id NULL = on account).
-- ------------------------------------------------------------
CREATE TABLE `supplier_payments` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `payment_no`   VARCHAR(30)   DEFAULT NULL,
  `supplier_id`  INT UNSIGNED  NOT NULL,
  `purchase_id`  INT UNSIGNED  DEFAULT NULL,
  `amount`       DECIMAL(10,2) NOT NULL,
  `method`       VARCHAR(20)   NOT NULL DEFAULT 'Cash',
  `reference`    VARCHAR(80)   DEFAULT NULL,
  `payment_date` DATE          DEFAULT NULL,
  `recorded_by`  VARCHAR(100)  DEFAULT NULL,
  `notes`        VARCHAR(255)  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_no` (`payment_no`),
  KEY `idx_payment_supplier` (`supplier_id`),
  KEY `idx_payment_purchase` (`purchase_id`),
  KEY `idx_payment_date` (`payment_date`),
  CONSTRAINT `fk_payment_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_payment_purchase` FOREIGN KEY (`purchase_id`)
    REFERENCES `supplier_purchases` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: market_prices
-- Market fuel price observations, keyed by fuel type + city +
-- date + source. A row is only presented as "Current" while it
-- sits inside the freshness window (`market_stale_days`); older
-- rows are labelled "Stale". `is_demo = 1` marks bundled sample
-- data so it is never mistaken for a live price.
-- ------------------------------------------------------------
CREATE TABLE `market_prices` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fuel_type`       VARCHAR(20)   NOT NULL,
  `city`            VARCHAR(80)   NOT NULL,
  `region`          VARCHAR(80)   DEFAULT NULL,
  `price_per_liter` DECIMAL(10,2) NOT NULL,
  `currency`        VARCHAR(8)    NOT NULL DEFAULT 'USD',
  `price_date`      DATE          NOT NULL,
  `source`          VARCHAR(80)   NOT NULL DEFAULT 'Manual',
  `source_ref`      VARCHAR(255)  DEFAULT NULL,
  `is_demo`         TINYINT(1)    NOT NULL DEFAULT 0,
  `recorded_by`     VARCHAR(100)  DEFAULT NULL,
  `notes`           VARCHAR(255)  DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_market_obs` (`fuel_type`, `city`, `price_date`, `source`),
  KEY `idx_market_fuel` (`fuel_type`),
  KEY `idx_market_city` (`city`),
  KEY `idx_market_date` (`price_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: market_alerts
-- Fired when a new observation moves past the configured %
-- threshold (direction = both | up | down).
-- ------------------------------------------------------------
CREATE TABLE `market_alerts` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fuel_type`     VARCHAR(20)   NOT NULL,
  `city`          VARCHAR(80)   NOT NULL,
  `old_price`     DECIMAL(10,2) NOT NULL,
  `new_price`     DECIMAL(10,2) NOT NULL,
  `change_pct`    DECIMAL(8,2)  NOT NULL,
  `direction`     VARCHAR(10)   NOT NULL DEFAULT 'up',
  `threshold_pct` DECIMAL(6,2)  NOT NULL,
  `message`       VARCHAR(255)  DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert_created` (`created_at`),
  KEY `idx_alert_fuel` (`fuel_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: market_sources
-- Configured price providers. `kind` = api | csv | manual.
-- For an API source: endpoint + json_path (dot notation) +
-- fuel_map (JSON: provider key -> station fuel type) + doc_url.
-- ------------------------------------------------------------
CREATE TABLE `market_sources` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(80)   NOT NULL,
  `kind`         VARCHAR(10)   NOT NULL DEFAULT 'api',
  `endpoint`     VARCHAR(255)  DEFAULT NULL,
  `city`         VARCHAR(80)   DEFAULT NULL,
  `json_path`    VARCHAR(120)  DEFAULT NULL,
  `fuel_map`     TEXT          DEFAULT NULL,
  `currency`     VARCHAR(8)    NOT NULL DEFAULT 'USD',
  `doc_url`      VARCHAR(255)  DEFAULT NULL,
  `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
  `last_sync_at` TIMESTAMP     NULL DEFAULT NULL,
  `last_status`  VARCHAR(255)  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_market_source_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: sales
-- Every fuel sale. Deducts liters from fuel_inventory.
-- ------------------------------------------------------------
CREATE TABLE `sales` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_no`        VARCHAR(30)   DEFAULT NULL,
  `fuel_inventory_id` INT UNSIGNED  NOT NULL,
  `shift_id`          INT UNSIGNED  NULL,
  `liters_sold`       DECIMAL(10,2) NOT NULL,
  `price_per_liter`   DECIMAL(10,2) DEFAULT NULL,
  `total_amount`      DECIMAL(10,2) NOT NULL,
  `payment_method`    VARCHAR(20)   NOT NULL DEFAULT 'Cash',
  `customer_name`     VARCHAR(100)  DEFAULT NULL,
  `recorded_by`       VARCHAR(100)  DEFAULT NULL,
  `sold_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_no` (`invoice_no`),
  KEY `idx_sold_at` (`sold_at`),
  KEY `fk_sales_fuel` (`fuel_inventory_id`),
  KEY `idx_sales_shift` (`shift_id`),
  CONSTRAINT `fk_sales_fuel` FOREIGN KEY (`fuel_inventory_id`)
    REFERENCES `fuel_inventory` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`)
    REFERENCES `shifts` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: employees
-- ------------------------------------------------------------
CREATE TABLE `employees` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `full_name`   VARCHAR(100)  NOT NULL,
  `role`        VARCHAR(50)   NOT NULL DEFAULT 'Attendant',
  `phone`       VARCHAR(20)   DEFAULT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: users
-- role: 'admin' = System Admin, 'operator' = Station Operator
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `full_name`   VARCHAR(100)  NOT NULL,
  `email`       VARCHAR(150)  NOT NULL,
  `password`    VARCHAR(255)  NOT NULL,
  `role`        ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `last_login`  TIMESTAMP     NULL DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: settings
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- SAMPLE DATA
-- =============================================================

-- Default users
--   admin@fuelstation.com    / admin123
--   operator@fuelstation.com / operator123
INSERT INTO `users` (`full_name`, `email`, `password`, `role`) VALUES
  ('System Admin',     'admin@fuelstation.com',    '$2y$10$KnbEgdfkbrB8/grAlog0D.Ox0HagYT9mqE.PmilQMnaczaDWRFlOW', 'admin'),
  ('Station Operator', 'operator@fuelstation.com', '$2y$10$BfX4Wxdxk98g6L4ybwDpRuS94Z2mODXfIyO5dqpqWsJglATPee39C', 'operator');

-- Fuel tanks
INSERT INTO `fuel_inventory`
  (`fuel_type`, `tank_capacity_liters`, `current_stock_liters`, `price_per_liter`, `status`) VALUES
  ('Petrol', 15000.00,  8750.50, 1.85, 'OK'),
  ('Diesel', 20000.00, 14200.00, 1.65, 'OK'),
  ('LPG',    10000.00,  3400.75, 0.95, 'OK');

-- Price change log
INSERT INTO `price_history`
  (`fuel_type`, `old_price`, `new_price`, `difference`, `changed_by`, `source`, `created_at`) VALUES
  ('Petrol', 1.70, 1.75,  0.05, 'System Admin',     'Manual', '2026-07-05 09:00:00'),
  ('Petrol', 1.75, 1.85,  0.10, 'System Admin',     'Manual', '2026-07-12 09:00:00'),
  ('Diesel', 1.60, 1.55, -0.05, 'Station Operator', 'Manual', '2026-07-07 10:30:00'),
  ('Diesel', 1.55, 1.65,  0.10, 'System Admin',     'Manual', '2026-07-14 10:30:00'),
  ('LPG',    0.90, 1.00,  0.10, 'Station Operator', 'Manual', '2026-07-03 11:15:00'),
  ('LPG',    1.00, 0.95, -0.05, 'System Admin',     'Manual', '2026-07-11 11:15:00');

-- Sample sales
INSERT INTO `sales`
  (`invoice_no`, `fuel_inventory_id`, `liters_sold`, `price_per_liter`, `total_amount`, `payment_method`, `customer_name`, `recorded_by`, `sold_at`)
VALUES
  ('INV-2026-00001', 1, 45.50, 1.85,  84.18, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 08:15:00'),
  ('INV-2026-00002', 1, 30.00, 1.85,  55.50, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 09:30:00'),
  ('INV-2026-00003', 2, 60.00, 1.65,  99.00, 'Card', 'Fleet Co', 'Station Operator', '2026-07-17 10:05:00'),
  ('INV-2026-00004', 1, 25.00, 1.85,  46.25, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 11:20:00'),
  ('INV-2026-00005', 3, 15.00, 0.95,  14.25, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 12:45:00'),
  ('INV-2026-00006', 2, 80.00, 1.65, 132.00, 'Credit','Fleet Co', 'Station Operator', '2026-07-17 13:10:00'),
  ('INV-2026-00007', 1, 50.00, 1.85,  92.50, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 14:30:00'),
  ('INV-2026-00008', 3, 20.00, 0.95,  19.00, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 15:00:00'),
  ('INV-2026-00009', 2, 35.00, 1.65,  57.75, 'Card', 'Walk-in', 'Station Operator', '2026-07-17 15:45:00'),
  ('INV-2026-00010', 1, 10.00, 1.85,  18.50, 'Cash', 'Walk-in', 'Station Operator', '2026-07-17 16:20:00');

-- Sample employees
INSERT INTO `employees` (`full_name`, `role`, `phone`) VALUES
  ('Ahmad Khalil', 'Manager',   '+961-71-123456'),
  ('Sara Mansour', 'Attendant', '+961-76-654321'),
  ('Omar Haddad',  'Attendant', '+961-70-789012'),
  ('Layla Fares',  'Cashier',   '+961-71-345678');

-- Sample suppliers
INSERT INTO `suppliers`
  (`id`, `company_name`, `contact_person`, `phone`, `email`, `address`, `notes`) VALUES
  (1, 'Coastal Fuel Traders',   'Nabil Sayegh', '+961 1 555 010', 'sales@coastalfuel.com', 'Industrial Zone, Block C, Beirut', 'Net 30 payment terms. Delivers Mon/Thu.'),
  (2, 'Cedar Oil Distributors', 'Rania Khoury', '+961 3 220 118', 'orders@cedaroil.com',   'Port Road, Warehouse 7, Jounieh',  'Bulk diesel supplier. Requires 24h notice.'),
  (3, 'Metro Gas Supply',       'Karim Nassar', '+961 70 334 455','orders@metrogas.com',   'Sector 5, Tripoli',                'LPG deliveries only.');

-- Sample fuel purchases (stock_applied = 1 means the tank was credited)
INSERT INTO `supplier_purchases`
  (`id`, `purchase_no`, `supplier_id`, `fuel_inventory_id`, `fuel_type`, `liters`, `cost_per_liter`,
   `total_cost`, `invoice_no`, `delivery_date`, `payment_status`, `amount_paid`, `tanker_no`,
   `driver_name`, `driver_phone`, `delivery_ref`, `status`, `stock_applied`, `verified_by`,
   `verified_at`, `recorded_by`, `created_at`) VALUES
  (1, 'PUR-2026-00001', 1, 1, 'Petrol', 5000.00, 1.42,  7100.00, 'INV-SUP-8842', '2026-07-10', 'Paid',    7100.00, 'T-2214', 'Hassan Ali',  '+961 71 220 331', 'DO-2026-4471', 'confirmed', 1, 'System Admin',     '2026-07-10 09:00:00', 'System Admin',     '2026-07-10 08:00:00'),
  (2, 'PUR-2026-00002', 2, 2, 'Diesel', 8000.00, 1.31, 10480.00, 'INV-CED-2201', '2026-07-12', 'Partial',  5000.00, 'T-8890', 'Fadi Karam',  '+961 76 554 221', 'CED-5502',     'confirmed', 1, 'System Admin',     '2026-07-12 08:30:00', 'System Admin',     '2026-07-12 08:00:00'),
  (3, 'PUR-2026-00003', 3, 3, 'LPG',    3000.00, 0.78,  2340.00, 'MGS-77120',    '2026-07-15', 'Unpaid',      0.00, 'T-4451', 'Sami Doumit', '+961 70 998 776', 'MGS-8891',     'pending',   0, NULL,               NULL,                  'Station Operator', '2026-07-15 10:00:00');

-- Sample supplier payments
INSERT INTO `supplier_payments`
  (`id`, `payment_no`, `supplier_id`, `purchase_id`, `amount`, `method`, `reference`, `payment_date`, `recorded_by`, `notes`) VALUES
  (1, 'PAY-2026-00001', 1, 1,    7100.00, 'Bank Transfer', 'Wire 88214', '2026-07-10', 'System Admin',     'Settled invoice INV-SUP-8842 in full.'),
  (2, 'PAY-2026-00002', 2, 2,    5000.00, 'Cheque',        'CHQ-10233',  '2026-07-12', 'System Admin',     'Balance due on delivery CED-5502.'),
  (3, 'PAY-2026-00003', 1, NULL, 1000.00, 'Cash',          'Counter 3',  '2026-07-16', 'Station Operator', 'Advance payment on account.');

-- App settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('station_name',    'BlueWave Fuel Station'),
  ('station_address', '123 Coastal Highway, Beirut'),
  ('station_phone',   '+961 1 234 567'),
  ('receipt_footer',  'Thank you for fueling with us!'),
  ('market_feed_url', 'market_feed.json'),
  ('market_alert_enabled',   '1'),
  ('market_alert_pct',       '3.00'),
  ('market_alert_direction', 'both'),
  ('market_stale_days',      '3'),
  ('market_default_city',    'Beirut'),
  ('schema_version',  '2026.10.18.3');

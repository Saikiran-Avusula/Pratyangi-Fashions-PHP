-- ALL_IN_ORDER.sql: every table of the shop in one file, for a FRESH empty MySQL 8 database (cPanel > phpMyAdmin > pick the database > Import).
-- Generated from the numbered files in this folder, in this order: 001, 002, 003, 003b, 004, 005, 006. Run it ONCE; it is not meant to be repeated or applied on top of an older database.

-- ===== 001_phase1_accounts.sql =====
-- Phase 1: accounts. Same tables, columns, index and unique-key names as the Node API's first migration
-- (apps/api/prisma/migrations/20261017000000_init), minus the tables later phases add
-- (shop_customer_identities for Google, shop_customer_addresses, orders, payments).
-- MySQL 8, InnoDB, utf8mb4. All times are UTC (the PHP connection sets time_zone = '+00:00').
-- NOT YET RUN anywhere. Apply to a fresh database only, after review.

CREATE TABLE IF NOT EXISTS `shop_customers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(80) NOT NULL,
    `email` VARCHAR(254) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `email_verified_at` DATETIME(3) NULL,
    `picture_url` VARCHAR(500) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `uq_shop_customers_email`(`email`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_sessions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NOT NULL,
    `user_agent` VARCHAR(255) NULL,

    UNIQUE INDEX `uq_shop_sessions_token`(`token_hash`),
    INDEX `idx_shop_sessions_customer`(`customer_id`),
    INDEX `idx_shop_sessions_expiry`(`expires_at`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_sessions_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_password_resets` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `code_hash` CHAR(64) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NOT NULL,

    INDEX `idx_shop_resets_customer`(`customer_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_password_resets_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_email_verifications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `uq_shop_email_verification_token`(`token_hash`),
    INDEX `idx_shop_email_verification_customer`(`customer_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_email_verifications_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 002_rate_limits.sql =====
-- Phase 1: request counts for the rate limiter (PHP-only; the Node API keeps them in memory).
-- One row per (bucket + hashed client address, window). The app does
--   INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1
-- and deletes windows older than two hours now and then. window_start is unix seconds (aligned to the window length).
-- NOT YET RUN anywhere.

CREATE TABLE IF NOT EXISTS `shop_rate_limits` (
    `bucket_key` VARCHAR(100) NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (`bucket_key`, `window_start`),
    INDEX `idx_shop_rate_limits_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_bin;

-- ===== 003_mobile_otp.sql =====
-- PHP-only addition (not in the Node API): sign in with a mobile number and a one-time code sent by SMS.
-- See docs/API_CONTRACT.md, section "PHP-only additions".
-- MySQL 8, InnoDB, utf8mb4. All times are UTC.
-- NOT RUN ANYWHERE except the throwaway pratyangi_php_test database on 127.0.0.1:3399. Apply ONCE, after review: the ALTER is not repeatable.
--
-- shop_customers.email becomes nullable: an account created with a mobile number has no email. This is safe for the existing code:
-- the UNIQUE index allows many NULLs, every email lookup is `email = ?` (never matches NULL), and the mail code skips a missing address.
-- The mobile is stored as +91XXXXXXXXXX. `contact_email` is a plain, UNVERIFIED contact field typed during mobile signup:
-- it is never looked up, never unique, never used to sign in, reset a password or merge accounts (those only ever use `email`).

ALTER TABLE `shop_customers`
    MODIFY `email` VARCHAR(254) NULL,
    ADD COLUMN `mobile` VARCHAR(16) NULL AFTER `email`,
    ADD COLUMN `contact_email` VARCHAR(254) NULL AFTER `mobile`,
    ADD UNIQUE INDEX `uq_shop_customers_mobile`(`mobile`);

CREATE TABLE IF NOT EXISTS `shop_otp_codes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `mobile` VARCHAR(16) NOT NULL,
    `code_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `consumed_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `ip_hash` CHAR(64) NULL,

    INDEX `idx_shop_otp_mobile`(`mobile`, `id`),
    INDEX `idx_shop_otp_expiry`(`expires_at`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 003b_verification_codes.sql =====
-- PHP-only addition (not in the Node API): one table for every 6-digit code the shop sends, by channel and purpose.
-- See docs/API_CONTRACT.md, section "PHP-only additions" (11.3).
-- MySQL 8, InnoDB, utf8mb4. All times are UTC.
-- NOT RUN ANYWHERE except the throwaway pratyangi_php_test database on 127.0.0.1:3399. Needs sql/003_mobile_otp.sql first. Safe to run twice.
--
-- shop_verification_codes: channel 'email' | 'mobile'; purpose 'login' | 'email_verify' | 'mobile_verify' | 'reset'; target = the address or
-- +91XXXXXXXXXX the code was sent to. A code only ever opens the (channel, purpose, target) it was made for: the stored hash covers all
-- three, and every lookup filters on all three. customer_id is set for the signed-in purposes and for reset (the account the code was sent for).
-- shop_otp_codes (003) is no longer written to; it stays so the older migration still applies.
--
-- shop_customers.mobile_verified_at: a mobile number is stored on an account only after a code sent to it was entered, so this is set whenever
-- `mobile` is. email_verified_at already exists (001).

CREATE TABLE IF NOT EXISTS `shop_verification_codes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `channel` VARCHAR(8) NOT NULL,
    `purpose` VARCHAR(16) NOT NULL,
    `target` VARCHAR(254) NOT NULL,
    `customer_id` INT NULL,
    `code_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `consumed_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `ip_hash` CHAR(64) NULL,

    INDEX `idx_shop_vcodes_target`(`channel`, `purpose`, `target`, `id`),
    INDEX `idx_shop_vcodes_expiry`(`expires_at`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @pf_has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_customers' AND COLUMN_NAME = 'mobile_verified_at');
SET @pf_sql := IF(@pf_has_col = 0, 'ALTER TABLE `shop_customers` ADD COLUMN `mobile_verified_at` DATETIME(3) NULL AFTER `email_verified_at`', 'DO 0');
PREPARE pf_stmt FROM @pf_sql;
EXECUTE pf_stmt;
DEALLOCATE PREPARE pf_stmt;

-- numbers stored by the 003 sign-in were proved with a code, so they count as verified
UPDATE `shop_customers` SET `mobile_verified_at` = `created_at` WHERE `mobile` IS NOT NULL AND `mobile_verified_at` IS NULL;

-- ===== 004_addresses.sql =====
-- Stage A: Address book
-- MySQL 8, InnoDB, utf8mb4. All times are UTC.

CREATE TABLE IF NOT EXISTS `shop_customer_addresses` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `type` VARCHAR(10) NOT NULL DEFAULT 'Home',
    `label` VARCHAR(30) NOT NULL DEFAULT '',
    `recipient_name` VARCHAR(80) NOT NULL,
    `phone` CHAR(10) NOT NULL,
    `alternate_phone` CHAR(10) NULL,
    `house` VARCHAR(120) NOT NULL,
    `street` VARCHAR(160) NOT NULL,
    `city` VARCHAR(60) NOT NULL,
    `state` VARCHAR(60) NOT NULL,
    `pincode` CHAR(6) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

    INDEX `idx_shop_addresses_customer`(`customer_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_customer_addresses_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 005_orders.sql =====
-- Stage B1: online orders (Razorpay). No cash-on-delivery path. MySQL 8, InnoDB, utf8mb4. All times are UTC, money is whole paise.
-- status: PAYMENT_PENDING -> PAID_PLACING -> PLACED | PAID_NOT_FULFILLED (docs/API_CONTRACT.md section 4).
--   PAID_PLACING = paid, the ERP has not answered (shown as "confirming"; retried). PAID_NOT_FULFILLED = paid, the ERP refused for stock/SKU (refund by hand).
--   PLACING, FAILED and UNCONFIRMED belong to the cash-on-delivery path and are never written while COD is off.

CREATE TABLE IF NOT EXISTS `shop_orders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_no` VARCHAR(20) NOT NULL,
    `customer_id` INT NOT NULL,
    `idempotency_key` VARCHAR(64) NOT NULL,
    `payment_method` VARCHAR(12) NOT NULL DEFAULT 'RAZORPAY',
    `status` VARCHAR(24) NOT NULL,
    `items_total_paise` INT NOT NULL,
    `tax_paise` INT NOT NULL DEFAULT 0,
    `total_paise` INT NOT NULL,
    `ship_name` VARCHAR(80) NOT NULL,
    `ship_phone` CHAR(10) NOT NULL,
    `ship_house` VARCHAR(120) NOT NULL,
    `ship_street` VARCHAR(160) NOT NULL,
    `ship_city` VARCHAR(60) NOT NULL,
    `ship_state` VARCHAR(60) NOT NULL,
    `ship_pincode` CHAR(6) NOT NULL,
    `erp_order_id` INT NULL,
    `erp_status` VARCHAR(40) NULL,
    `failure_code` VARCHAR(40) NULL,
    `failed_skus` TEXT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `payment_provider` VARCHAR(20) NULL,
    `payment_ref` VARCHAR(40) NULL,
    `paid_amount_paise` INT NULL,
    `paid_at` DATETIME(3) NULL,
    `razorpay_order_id` VARCHAR(40) NULL,
    `razorpay_key_tag` VARCHAR(8) NULL,
    `razorpay_order_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `placed_at` DATETIME(3) NULL,
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_shop_orders_order_no`(`order_no`),
    UNIQUE INDEX `uq_shop_orders_customer_key`(`customer_id`, `idempotency_key`),
    UNIQUE INDEX `uq_shop_orders_payment_ref`(`payment_ref`),
    UNIQUE INDEX `uq_shop_orders_razorpay_order`(`razorpay_order_id`),
    INDEX `idx_shop_orders_customer_created`(`customer_id`, `created_at`, `id`),
    INDEX `idx_shop_orders_status`(`status`, `paid_at`),
    CONSTRAINT `shop_orders_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_order_items` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_id` INT NOT NULL,
    `sku` VARCHAR(60) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price_paise` INT NOT NULL,

    PRIMARY KEY (`id`),
    INDEX `idx_shop_order_items_order`(`order_id`),
    CONSTRAINT `shop_order_items_order_id_fkey` FOREIGN KEY (`order_id`) REFERENCES `shop_orders`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Every Razorpay order id ever issued for an order. shop_orders.razorpay_order_id is only the current one; a payment that arrives late on a
-- replaced id still finds its order here.
CREATE TABLE IF NOT EXISTS `shop_razorpay_orders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_id` INT NOT NULL,
    `razorpay_order_id` VARCHAR(40) NOT NULL,
    `amount_paise` INT NOT NULL,
    `key_tag` VARCHAR(8) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_shop_razorpay_orders_rzp`(`razorpay_order_id`),
    INDEX `idx_shop_razorpay_orders_order`(`order_id`),
    CONSTRAINT `shop_razorpay_orders_order_id_fkey` FOREIGN KEY (`order_id`) REFERENCES `shop_orders`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 006_google_identities.sql =====
-- Google sign-in: the sign-in identities linked to an account (same table as the Node API's shop_customer_identities).
-- MySQL 8, InnoDB, utf8mb4. Safe to run twice.

CREATE TABLE IF NOT EXISTS `shop_customer_identities` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `provider` VARCHAR(20) NOT NULL,
    `provider_user_id` VARCHAR(191) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `uq_shop_identities_provider_user`(`provider`, `provider_user_id`),
    INDEX `idx_shop_identities_customer`(`customer_id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_identities_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 007_mail_events.sql =====
-- Emails sent once per event: a row is the claim on one event key (for example welcome:12). MySQL 8, InnoDB, utf8mb4. Safe to run twice.

CREATE TABLE IF NOT EXISTS `shop_mail_events` (
    `event_key` VARCHAR(120) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== 008_consent_numbers.sql =====
-- Marketing consent (DPDP / TRAI) and verified delivery numbers. MySQL 8, InnoDB, utf8mb4. All times are UTC. Safe to run twice.
--
-- shop_consent_log: one row per CHANGE of one channel (sms | whatsapp | email); the newest row of a channel is the current answer. The row keeps when,
-- from which page (signup | address | checkout | account | unsubscribe) and from which IP address the customer said yes or no, as proof of consent.
-- shop_verified_numbers: a 10-digit mobile number the customer proved with a code (verified_at). otp_passed_at is the last code entered for an ORDER
-- (ORDER_OTP=cod|always); the order that uses it clears it.

CREATE TABLE IF NOT EXISTS `shop_consent_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `customer_id` INT NOT NULL,
    `channel` VARCHAR(10) NOT NULL,
    `granted` TINYINT(1) NOT NULL,
    `source` VARCHAR(12) NOT NULL,
    `ip` VARCHAR(45) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `idx_shop_consent_customer`(`customer_id`, `channel`, `id`),
    PRIMARY KEY (`id`),
    CONSTRAINT `shop_consent_log_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_verified_numbers` (
    `customer_id` INT NOT NULL,
    `mobile` CHAR(10) NOT NULL,
    `verified_at` DATETIME(3) NOT NULL,
    `otp_passed_at` DATETIME(3) NULL,

    PRIMARY KEY (`customer_id`, `mobile`),
    CONSTRAINT `shop_verified_numbers_customer_id_fkey` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

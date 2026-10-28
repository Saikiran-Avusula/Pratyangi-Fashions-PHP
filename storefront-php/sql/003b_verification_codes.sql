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

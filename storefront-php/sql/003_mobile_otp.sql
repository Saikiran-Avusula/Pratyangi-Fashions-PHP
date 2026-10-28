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

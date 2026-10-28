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

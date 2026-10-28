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

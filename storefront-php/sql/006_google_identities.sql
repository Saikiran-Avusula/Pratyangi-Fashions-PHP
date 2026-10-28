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
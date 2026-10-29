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

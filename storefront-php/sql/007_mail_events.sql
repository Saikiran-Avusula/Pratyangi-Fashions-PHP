-- Emails sent once per event: a row is the claim on one event key (for example welcome:12). MySQL 8, InnoDB, utf8mb4. Safe to run twice.

CREATE TABLE IF NOT EXISTS `shop_mail_events` (
    `event_key` VARCHAR(120) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

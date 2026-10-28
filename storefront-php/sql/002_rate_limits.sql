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

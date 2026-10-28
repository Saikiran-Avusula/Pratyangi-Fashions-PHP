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

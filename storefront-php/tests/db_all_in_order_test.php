<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php). Imports sql/ALL_IN_ORDER.sql into a brand-new throwaway database (name ends _test) and checks the tables, then drops it.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

$pdo = DbGuard::connect();
$t = new TestCase('db_all_in_order_test');
$name = 'pratyangi_allinorder_test';
$t->test('ALL_IN_ORDER.sql imports into an empty database and creates every table', function () use ($t, $pdo, $name): void {
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $pdo->exec("USE `$name`");
        $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/ALL_IN_ORDER.sql'));
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $t->same(['shop_consent_log', 'shop_customer_addresses', 'shop_customer_identities', 'shop_customers', 'shop_email_verifications', 'shop_mail_events', 'shop_order_items', 'shop_orders', 'shop_otp_codes',
            'shop_password_resets', 'shop_rate_limits', 'shop_razorpay_orders', 'shop_sessions', 'shop_verification_codes', 'shop_verified_numbers'], $tables);
    } finally {
        $pdo->exec("USE `" . (string) getenv('PF_TEST_DB_NAME') . "`");
        $pdo->exec("DROP DATABASE `$name`");
    }
});
exit($t->finish());

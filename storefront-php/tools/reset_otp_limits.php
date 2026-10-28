<?php
declare(strict_types=1);

// TEST DATABASE ONLY: deletes all OTP codes and the OTP rate-limit rows (buckets otp_*). Prints counts only.
// Run from storefront-php: php tools/reset_otp_limits.php
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Config;
use Pratyangi\Db;

$refuse = static function (string $why): never {
    fwrite(STDERR, "REFUSED: {$why}\n");
    exit(2);
};
try {
    $config = Config::load();
} catch (\Throwable $e) {
    $refuse('config did not load');
}
if (!in_array($config->appEnv, ['development', 'test'], true)) {
    $refuse('APP_ENV must be development or test');
}
if (!str_ends_with($config->dbName, '_test')) {
    $refuse('the database name must end in _test');
}
if ($config->dbPort !== 3399) {
    $refuse('the database port must be 3399');
}
$pdo = Db::connectWith('127.0.0.1', 3399, $config->dbName, $config->dbUser, $config->dbPassword());
$codes = $pdo->exec('DELETE FROM shop_verification_codes');
$limits = $pdo->exec("DELETE FROM shop_rate_limits WHERE bucket_key LIKE 'otp\_%'");
echo "shop_verification_codes deleted: {$codes}\nshop_rate_limits otp rows deleted: {$limits}\n";

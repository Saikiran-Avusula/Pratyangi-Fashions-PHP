<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\App;
use Pratyangi\Auth\Mobile;
use Pratyangi\Config;
use Pratyangi\Db;
use Pratyangi\Http\Request;

try {
    $config = Config::load();
} catch (\Throwable $e) {
    fwrite(STDERR, "Config error: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. Non-secret facts
echo "APP_ENV: " . $config->appEnv . "\n";
echo "SMS_MODE: " . $config->smsMode . "\n";
echo "MAIL_MODE: " . $config->mailMode . "\n";

// 2. Log paths and folder writability
$logFiles = [];
$channels = ['app', 'error', 'auth', 'erp', 'orders', 'payment', 'mail', 'sms', 'cron'];
foreach ($channels as $ch) {
    $logFiles[$ch . '.log'] = $config->logDir . '/' . $ch . '.log';
}
$logFiles['configured_mail_log'] = $config->mailLogPath;
$logFiles['configured_sms_log'] = $config->smsLogPath;

echo "Log files & writability:\n";
foreach ($logFiles as $name => $path) {
    $folder = dirname($path);
    $writable = is_dir($folder) && is_writable($folder);
    echo "  - {$name}: {$path} [folder writable: " . ($writable ? 'yes' : 'no') . "]\n";
}

// 3. Database connection on 127.0.0.1:3399
$pdo = null;
try {
    $pdo = Db::connectWith('127.0.0.1', 3399, $config->dbName, $config->dbUser, $config->dbPassword());
    echo "Database on 127.0.0.1:3399 connects: yes\n";
} catch (\Throwable $e) {
    echo "Database on 127.0.0.1:3399 connects: no (" . get_class($e) . ")\n";
}

// 4. OTP_PEPPER
echo "OTP_PEPPER is set: " . ($config->otpPepper() !== null ? "yes" : "no") . "\n";

// 5. Counts of shop_otp_codes rows and rate-limit rows in last hour
if ($pdo !== null) {
    try {
        $otpRows = (int) $pdo->query("SELECT COUNT(*) FROM shop_otp_codes WHERE created_at >= UNIX_TIMESTAMP() - 3600")->fetchColumn();
        echo "shop_otp_codes rows (last hour): {$otpRows}\n";
    } catch (\Throwable $e) {
        echo "shop_otp_codes rows: query failed (" . get_class($e) . ")\n";
    }

    try {
        $rateRows = (int) $pdo->query("SELECT COUNT(*) FROM shop_rate_limits WHERE window_start >= UNIX_TIMESTAMP() - 3600")->fetchColumn();
        echo "shop_rate_limits rows (last hour/active): {$rateRows}\n";
    } catch (\Throwable $e) {
        echo "shop_rate_limits rows: query failed (" . $e->getMessage() . ")\n";
    }
} else {
    echo "shop_otp_codes rows: skipped (no DB connection)\n";
    echo "shop_rate_limits rows: skipped (no DB connection)\n";
}

// 6. Record file sizes before OTP request
$sizes = [];
foreach ($logFiles as $key => $file) {
    $sizes[$file] = file_exists($file) ? (int) filesize($file) : 0;
}

// 7. Call OTP request internally for test number +919999900001
$testNumber = '+919999900001';
$app = App::create($config);
$origin = $config->webOrigins[0] ?? 'http://127.0.0.1:8100';
$req = new Request(
    'POST',
    '/api/v1/auth/otp/request',
    ['Origin' => $origin, 'Content-Type' => 'application/json'],
    json_encode(['mobile' => $testNumber])
);

$res = $app->handle($req);
echo "OTP request status code: " . $res->status() . "\n";

// 8. Determine which log file received a new line and print masked line
clearstatcache();
$updatedFile = null;
$maskedLine = null;
$updatedFiles = [];
foreach ($logFiles as $key => $file) {
    if (isset($updatedFiles[basename($file)])) {
        continue;
    }
    $curSize = file_exists($file) ? (int) filesize($file) : 0;
    $prevSize = $sizes[$file] ?? 0;
    if ($curSize > $prevSize) {
        $content = (string) file_get_contents($file);
        $lines = array_filter(explode("\n", trim($content)));
        $rawLine = end($lines);
        $maskedLine = 'none';
        if ($rawLine !== false) {
            // Mask any full numbers to +91******XXXX
            $maskedLine = (string) preg_replace_callback(
                '/(?:\+?91[\s-]?)?([6-9]\d{9})\b/',
                static fn (array $m): string => '+91******' . substr($m[1], -4),
                $rawLine
            );
        }
        $updatedFiles[basename($file)] = $maskedLine;
    }
}

if ($updatedFiles === []) {
    echo "Updated log file: none\n";
    echo "Masked log line: none\n";
} else {
    foreach ($updatedFiles as $name => $line) {
        echo "Updated log file: {$name}\n";
        echo "Masked log line: {$line}\n";
    }
}

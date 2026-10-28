<?php
declare(strict_types=1);

// No database. Run: php tests/logger_test.php

require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeAuthPdo.php';

use Pratyangi\Config;
use Pratyangi\Http\Request;
use Pratyangi\Support\Logger;

$t = new TestCase('logger_test');

$base = ['DB_NAME' => 'pf_unit_test', 'DB_USER' => 'unit', 'ERP_MODE' => 'off', 'MAIL_MODE' => 'smtp'];

$t->test('Config: LOG_DIR defaults to storage/logs and moves mail.log and sms.log under it', function () use ($t, $base): void {
    $temp = Helpers::tempDir('pf_log_cfg_');
    try {
        $c = new Config(['APP_ENV' => 'test', 'LOG_DIR' => $temp . '/custom_logs'] + $base);
        $t->same($temp . '/custom_logs', $c->logDir);
        $t->same($temp . '/custom_logs/mail.log', $c->mailLogPath);
        $t->same($temp . '/custom_logs/sms.log', $c->smsLogPath);
        $t->ok(is_dir($temp . '/custom_logs'), 'LOG_DIR is created if missing');

        // default LOG_DIR
        $cDef = new Config(['APP_ENV' => 'test'] + $base);
        $t->contains('/storage/logs', str_replace('\\', '/', $cDef->logDir));
        $t->contains('/storage/logs/mail.log', str_replace('\\', '/', $cDef->mailLogPath));
        $t->contains('/storage/logs/sms.log', str_replace('\\', '/', $cDef->smsLogPath));
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('Logger: writes to separate channel files with exact line format', function () use ($t): void {
    $temp = Helpers::tempDir('pf_channels_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'development');
        $logger->setRequestId('req-test-123');

        $channels = ['app', 'error', 'auth', 'erp', 'orders', 'payment', 'mail', 'sms', 'cron'];
        foreach ($channels as $ch) {
            $logger->channel($ch)->info('test message for ' . $ch, ['key' => $ch . '_value']);
            $file = $temp . '/' . $ch . '.log';
            $t->ok(file_exists($file), $ch . '.log exists');
            $content = trim((string) file_get_contents($file));
            // Format: Y-m-d H:i:s IST | level | channel | request id | message | JSON context
            $parts = explode(' | ', $content);
            $t->same(6, count($parts), '6 pipe-separated fields');
            $t->contains(' IST', $parts[0], 'Indian timestamp');
            $t->same(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} IST$/D', $parts[0]));
            $t->same('info', $parts[1]);
            $t->same($ch, $parts[2]);
            $t->same('req-test-123', $parts[3]);
            $t->same('test message for ' . $ch, $parts[4]);
            $t->same('{"key":"' . $ch . '_value"}', $parts[5]);
        }
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('Logger: rotation at 5 MB keeps one old file (name.log.1)', function () use ($t): void {
    $temp = Helpers::tempDir('pf_rot_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'development');
        $file = $temp . '/app.log';
        $rot = $temp . '/app.log.1';

        // create 5MB file
        file_put_contents($file, str_repeat('A', 5 * 1024 * 1024));
        $t->same(5 * 1024 * 1024, filesize($file));

        $logger->info('rotation trigger');
        $t->ok(file_exists($rot), 'app.log.1 created on rotation');
        $t->ok(file_exists($file), 'new app.log created');
        $t->ok(filesize($file) < 1000, 'new app.log is fresh');

        // rotate again
        file_put_contents($file, str_repeat('B', 5 * 1024 * 1024));
        $logger->info('second rotation');
        $t->ok(file_exists($rot), 'app.log.1 still exists');
        $t->same(5 * 1024 * 1024, filesize($rot), 'app.log.1 was replaced with second batch');
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('Logger: no secret or full mobile number reaches the log, newlines sanitized', function () use ($t): void {
    $lines = [];
    $sink = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };

    $logger = new Logger('debug', $sink, null, 'app', 'production');
    $logger->setRequestId('req-sec');

    // 1. Password, token, API key, cookie in context
    $logger->info('user login context', [
        'password' => 'SuperSecret123!',
        'token' => 'bearer-token-secret-999',
        'api_key' => 'erp-api-key-32charslongsecret!!',
        'cookie' => 'pf_session=sessioncookievalue',
        'safe_info' => 'visible',
    ]);
    $entry = $lines[count($lines) - 1];
    $t->ok(!str_contains($entry, 'SuperSecret123!'), 'password redacted');
    $t->ok(!str_contains($entry, 'bearer-token-secret-999'), 'token redacted');
    $t->ok(!str_contains($entry, 'erp-api-key-32charslongsecret!!'), 'api key redacted');
    $t->ok(!str_contains($entry, 'sessioncookievalue'), 'cookie redacted');
    $t->contains('[REDACTED]', $entry);
    $t->contains('visible', $entry);

    // 2. Full mobile number in message and context
    $logger->info('sms notification to +919876543210 and 9123456789', [
        'mobile' => '+919876543210',
        'phone' => '9123456789',
    ]);
    $entry2 = $lines[count($lines) - 1];
    $t->ok(!str_contains($entry2, '9876543210'), 'full mobile 1 not logged');
    $t->ok(!str_contains($entry2, '9123456789'), 'full mobile 2 not logged');
    $t->contains('+91******3210', $entry2, 'masked mobile 1 logged');
    $t->contains('+91******6789', $entry2, 'masked mobile 2 logged');

    // 3. Newlines in message sanitized
    $logger->info("Line 1\r\nLine 2\nLine 3\rLine 4");
    $entry3 = $lines[count($lines) - 1];
    $t->ok(!str_contains($entry3, "\n"), 'no newline in message field');
    $t->ok(!str_contains($entry3, "\r"), 'no carriage return in message field');
    $t->contains('Line 1 Line 2 Line 3 Line 4', $entry3);

    // 4. OTPs and email codes: redacted in production or in non-mail/sms channels
    $logger->info('sent code in app channel', ['otp' => '123456', 'code' => '654321']);
    $entry4 = $lines[count($lines) - 1];
    $t->ok(!str_contains($entry4, '123456') && !str_contains($entry4, '654321'), 'otp redacted in app channel');

    // In production on mail/sms channel: still redacted
    $logger->channel('sms')->info('otp message in production', ['otp' => '888888']);
    $entry5 = $lines[count($lines) - 1];
    $t->ok(!str_contains($entry5, '888888'), 'otp redacted in sms channel in production');

    // In development on mail/sms channel: allowed
    $devLogger = new Logger('debug', $sink, null, 'sms', 'development');
    $devLogger->info('otp message in development', ['otp' => '999999']);
    $entry6 = $lines[count($lines) - 1];
    $t->contains('999999', $entry6, 'otp allowed in sms channel in development');
});

$t->test('X-Request-Id header sent on responses', function () use ($t, $base): void {
    $app = Helpers::app(Helpers::config($base + ['APP_ENV' => 'test']));
    $r = Helpers::request('GET', '/health', ['X-Request-Id' => 'custom-req-id-12345']);
    $res = $app->handle($r);
    $t->same('custom-req-id-12345', $res->header('X-Request-Id'));

    $r2 = Helpers::request('GET', '/health');
    $res2 = $app->handle($r2);
    $t->ok($res2->header('X-Request-Id') !== null, 'generates request id if not provided');
});

$t->test('ERP results logged to erp.log with status code, duration, and failure kind', function () use ($t): void {
    $temp = Helpers::tempDir('pf_erp_log_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'test');
        $logger->setRequestId('req-erp-1');
        $client = new Pratyangi\Erp\ErpClient('http://127.0.0.1:1/v1/', 'a-very-long-dummy-erp-api-key-32chars', null, 'offer-else-retail', 'exclusive', 100, null, $logger);
        try {
            $client->listProducts();
        } catch (\Throwable) {}

        $file = $temp . '/erp.log';
        $t->ok(file_exists($file), 'erp.log exists');
        $content = (string) file_get_contents($file);
        $t->contains(' | erp | req-erp-1 | ', $content);
        $t->contains('"status_code"', $content);
        $t->contains('"duration"', $content);
        $t->ok(str_contains($content, '"failure_kind":"unreachable"') || str_contains($content, '"failure_kind":"timeout"'), 'failure kind logged');
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('5xx and uncaught exceptions logged to error.log with request ID and trace', function () use ($t, $base): void {
    $temp = Helpers::tempDir('pf_err_log_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'test');
        $logger->setRequestId('req-err-99');
        $logger->exception('test unhandled fatal', new \RuntimeException('something broke'));

        $file = $temp . '/error.log';
        $t->ok(file_exists($file), 'error.log exists');
        $content = (string) file_get_contents($file);
        $t->contains(' | error | req-err-99 | ', $content);
        $t->contains('"trace"', $content);
        $t->contains('RuntimeException', $content);
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('Auth outcomes logged to auth.log', function () use ($t): void {
    $temp = Helpers::tempDir('pf_auth_log_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'test');
        $logger->setRequestId('req-auth-42');
        $authLog = $logger->channel('auth');

        $authLog->info('sign-in success', ['method' => 'password', 'customer_id' => 10, 'outcome' => 'success']);
        $authLog->info('otp requested', ['channel' => 'mobile', 'purpose' => 'login', 'outcome' => 'requested']);
        $authLog->info('reset success', ['channel' => 'email', 'customer_id' => 10, 'outcome' => 'success']);
        $authLog->info('mobile link verified', ['channel' => 'mobile', 'customer_id' => 10, 'outcome' => 'success']);

        $file = $temp . '/auth.log';
        $t->ok(file_exists($file), 'auth.log exists');
        $content = (string) file_get_contents($file);
        $t->contains(' | auth | req-auth-42 | sign-in success | ', $content);
        $t->contains(' | auth | req-auth-42 | otp requested | ', $content);
        $t->contains(' | auth | req-auth-42 | reset success | ', $content);
        $t->contains(' | auth | req-auth-42 | mobile link verified | ', $content);
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('OTP request rejection logged to auth.log for cooldown, daily_limit, ip_limit, gateway_failure with masked number', function () use ($t): void {
    $temp = Helpers::tempDir('pf_otp_auth_log_');
    try {
        $logger = new Logger('debug', $temp, null, 'app', 'test');
        $logger->setRequestId('req-otp-rej-1');

        // Test with App dispatch for IP limit & gateway failure
        $config = Helpers::config();
        $clock = new \FixedClock();
        $app = new \Pratyangi\App(
            $config,
            $logger,
            $clock,
            static fn (): \PDO => new FakeAuthPdo(),
            new \Pratyangi\Mail\NullMailer(),
            new \Pratyangi\Erp\MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
            new \Pratyangi\Http\MemoryRateLimitStore(),
            ['otp_ip' => 1],
            null,
            null
        );
        $origin = ['Origin' => Helpers::ORIGIN];

        // 1. Gateway failure (no SMS gateway / no pepper -> 503)
        $r1 = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/request', $origin, ['mobile' => '9999900001']));
        $t->same(503, $r1->status());

        // 2. IP limit (second request exceeds otp_ip limit of 1 -> 429)
        $r2 = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/request', $origin, ['mobile' => '9999900001']));
        $t->same(429, $r2->status());

        $file = $temp . '/auth.log';
        $t->ok(file_exists($file), 'auth.log created');
        $content = (string) file_get_contents($file);

        $t->contains('gateway_failure', $content);
        $t->contains('ip_limit', $content);
        $t->contains('+91******0001', $content);
        $t->ok(!str_contains($content, '9999900001'), 'raw mobile number is never logged');
    } finally {
        Helpers::rmTree($temp);
    }
});

$t->test('Logger timestamp: format "Y-m-d H:i:s IST" in Asia/Kolkata timezone from fixed clock', function () use ($t): void {
    // 2026-10-24 12:07:58 UTC is timestamp 1792843678.0
    // UTC 12:07:58 + 5h30m = IST 17:37:58
    $dtUtc = new \DateTimeImmutable('2026-10-24 12:07:58', new \DateTimeZone('UTC'));
    $unix = (float) $dtUtc->getTimestamp();

    $lines = [];
    $sink = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };

    $clock = new \FixedClock($unix);
    $logger = new Logger('info', $sink, null, 'app', 'test', $clock);
    $logger->setRequestId('req-ist-test');
    $logger->info('testing IST timestamp');

    $t->same(1, count($lines));
    $line = $lines[0];
    $t->ok((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} IST \| /', $line), 'starts with date, time, and " IST | "');
    $t->contains('2026-10-24 17:37:58 IST | info | app | req-ist-test | testing IST timestamp | {}', $line);
});

exit($t->finish());


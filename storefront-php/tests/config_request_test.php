<?php
declare(strict_types=1);

// No database. Run: php tests/config_request_test.php
// WEB_ORIGIN rules in Config, and X-Forwarded-For parsing in Request::clientIp.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Config;
use Pratyangi\ConfigException;

$t = new TestCase('config_request_test');

$base = ['DB_NAME' => 'pf_unit_test', 'DB_USER' => 'unit', 'ERP_MODE' => 'off', 'MAIL_MODE' => 'smtp'];
// what production also needs for mobile sign-in (see tests/otp_config_test.php for the rules themselves)
$prodOtp = ['OTP_PEPPER' => str_repeat('p', 40), 'SMS_MODE' => 'http', 'SMS_API_URL' => 'https://sms.example.com/api', 'SMS_USERNAME' => 'u', 'SMS_APIKEY' => 'k', 'SMS_SENDER_ID' => 'S', 'SMS_OTP_TEMPLATE_ID' => '1'];

$t->test('production without WEB_ORIGIN refuses to start and names the setting', function () use ($t, $base): void {
    foreach ([[], ['WEB_ORIGIN' => ''], ['WEB_ORIGIN' => ' , ']] as $extra) {
        $e = $t->throws(ConfigException::class, fn () => new Config($base + $extra + ['APP_ENV' => 'production']));
        $t->contains('WEB_ORIGIN', $e->getMessage());
    }
    // APP_ENV missing counts as production
    $t->throws(ConfigException::class, fn () => new Config($base));
});

$t->test('production with valid origins starts; the first is the link base', function () use ($t, $base, $prodOtp): void {
    $c = new Config($base + $prodOtp + ['APP_ENV' => 'production', 'WEB_ORIGIN' => 'https://shop.example.com/, https://www.example.com']);
    $t->same(['https://shop.example.com', 'https://www.example.com'], $c->webOrigins);
    $t->same('https://shop.example.com', $c->webUrl);
});

$t->test('invalid origins are refused (message names the setting, never the value)', function () use ($t, $base): void {
    foreach (['shop.example.com', 'ftp://shop.example.com', 'https://shop.example.com/path', 'https://user:secretpw@shop.example.com', 'https://'] as $bad) {
        $e = $t->throws(ConfigException::class, fn () => new Config($base + ['APP_ENV' => 'production', 'WEB_ORIGIN' => $bad]));
        $t->contains('WEB_ORIGIN', $e->getMessage());
        $t->ok(!str_contains($e->getMessage(), 'secretpw') && !str_contains($e->getMessage(), 'shop.example.com'), 'value not printed');
    }
});

$t->test('development and test keep the localhost default', function () use ($t, $base): void {
    foreach (['development', 'test'] as $env) {
        $c = new Config($base + ['APP_ENV' => $env]);
        $t->same(['http://localhost:3000'], $c->webOrigins);
        $t->same('http://localhost:3000', $c->webUrl);
    }
});

$ip = static fn (string $xff, int $hops, string $peer = '10.0.0.1'): string => Helpers::request('GET', '/', ['X-Forwarded-For' => $xff], null, $peer)->clientIp($hops);

$t->test('X-Forwarded-For: IPv4 with a port is cleaned', function () use ($t, $ip): void {
    $t->same('1.2.3.4', $ip('1.2.3.4:5678', 1));
    $t->same('1.2.3.4', $ip('9.9.9.9, 1.2.3.4:5678', 1));
    $t->same('9.9.9.9', $ip('9.9.9.9, 1.2.3.4:5678', 2));
    $t->same('1.2.3.4', $ip('1.2.3.4', 1), 'plain address unchanged');
});

$t->test('X-Forwarded-For: bracketed IPv6 with and without a port', function () use ($t, $ip): void {
    $t->same('::1', $ip('[::1]:5678', 1));
    $t->same('::1', $ip('[::1]', 1));
    $t->same('2001:db8::7', $ip('[2001:db8::7]:443', 1));
    $t->same('2001:db8::7', $ip('2001:db8::7', 1), 'bare IPv6 unchanged');
});

$t->test('X-Forwarded-For: junk still falls back to the socket address', function () use ($t, $ip): void {
    $t->same('10.0.0.1', $ip('1.2.3.4:99999x', 1));
    $t->same('10.0.0.1', $ip('[::1', 1));
    $t->same('10.0.0.1', $ip('999.1.1.1:80', 1));
    $t->same('10.0.0.1', $ip('1.2.3.4:5678', 0), 'no proxy trusted: header ignored');
});

exit($t->finish());

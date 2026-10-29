<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test (see tests/lib/DbGuard.php).
// Stage H1: marketing consent per channel (GET/PUT /api/v1/consent) and the delivery-number code (POST /api/v1/numbers/otp/*, ORDER_OTP).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\App;
use Pratyangi\Auth\NumberVerification;
use Pratyangi\Auth\Otp;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Security\Passwords;
use Pratyangi\Security\Tokens;
use Pratyangi\Sms\LogSmsGateway;
use Pratyangi\Support\Logger;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: database tests run only on port 3399\n");
    exit(2);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);

$t = new TestCase('db_consent_numbers_test');
$origin = ['Origin' => Helpers::ORIGIN];
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$dir = Helpers::tempDir('pf_consent_');
register_shutdown_function(static fn () => Helpers::rmTree($dir));
$smsFile = $dir . '/sms.log';
$logged = [];
$logger = new Logger('debug', static function (string $line) use (&$logged): void {
    $logged[] = $line;
});
$build = static function (bool $sms) use ($pdo, $clock, $logger, $smsFile): App {
    $config = Helpers::config(['OTP_PEPPER' => str_repeat('Q', 48), 'SMS_ENABLED' => $sms ? 'true' : 'false', 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => $smsFile]);
    return new App($config, $logger, $clock, static fn (): \PDO => $pdo, new NullMailer(), new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
        new DbRateLimitStore(static fn (): \PDO => $pdo), ['otp_ip' => 100000, 'otp_verify' => 100000], null, new LogSmsGateway($smsFile, $clock));
};
$app = $build(true);
$insert = $pdo->prepare('INSERT INTO shop_customers (name, email, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, NOW(3), NOW(3), NOW(3))');
$hash = Passwords::hash('TestPass123');
$sessions = new SessionRepo($pdo);
$customer = static function (string $email) use ($insert, $hash, $pdo, $sessions): array {
    $insert->execute(['Asha Rao', $email, $hash]);
    $id = (int) $pdo->lastInsertId();
    $token = Tokens::newToken();
    $sessions->create($id, Tokens::sha256($token), (float) (time() + 86400 * 30), 'TestAgent');
    return [$id, ['Cookie' => 'pf_session=' . $token]];
};
[$idA, $cookieA] = $customer('consent.a@example.com');
[$idB, $cookieB] = $customer('consent.b@example.com');
$call = static function (string $method, string $path, array $headers = [], ?array $body = null, string $ip = '203.0.113.9') use (&$app, $origin): \Pratyangi\Http\Response {
    return $app->handle(Helpers::request($method, '/api/v1' . $path, $origin + $headers, $body, $ip));
};
// only isVerified() and assertForOrder() are used here, and they never touch the code service
$nv = static fn (): NumberVerification => new NumberVerification($pdo, (new ReflectionClass(\Pratyangi\Auth\OtpService::class))->newInstanceWithoutConstructor(), $clock);
$lastCode = static function () use ($smsFile): string {
    preg_match_all('/otp=(\d{6})/', (string) file_get_contents($smsFile), $m);
    return (string) end($m[1]);
};

$t->test('consent: nobody has said yes until they do; the choice is per channel; the page and the IP are kept', function () use ($t, $call, $cookieA, $cookieB, $pdo, $idA): void {
    $t->same(401, $call('GET', '/consent')->status());
    $first = $call('GET', '/consent', $cookieA);
    $t->same(200, $first->status(), $first->body());
    $t->same(['sms' => false, 'whatsapp' => false, 'email' => false], json_decode($first->body(), true)['consent']);
    $r = $call('PUT', '/consent', $cookieA, ['sms' => true, 'email' => true, 'source' => 'signup'], '198.51.100.7');
    $t->same(200, $r->status());
    $t->same(['sms' => true, 'whatsapp' => false, 'email' => true], json_decode($r->body(), true)['consent']);
    $row = $pdo->query("SELECT source, ip, granted FROM shop_consent_log WHERE customer_id = $idA AND channel = 'sms'")->fetch(PDO::FETCH_ASSOC);
    $t->same(['signup', '198.51.100.7', '1'], [$row['source'], $row['ip'], (string) $row['granted']]);
    $t->same(['sms' => false, 'whatsapp' => false, 'email' => false], json_decode($call('GET', '/consent', $cookieB)->body(), true)['consent'], 'another customer is not affected');
});

$t->test('consent: turning a channel off adds a row (history kept); the same answer again adds none; bad input is refused', function () use ($t, $call, $cookieA, $pdo, $idA): void {
    $count = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM shop_consent_log WHERE customer_id = $idA")->fetchColumn();
    $before = $count();
    $call('PUT', '/consent', $cookieA, ['email' => true, 'source' => 'account']);
    $t->same($before, $count(), 'no change, no row');
    $r = $call('PUT', '/consent', $cookieA, ['email' => false, 'source' => 'account']);
    $t->same(['sms' => true, 'whatsapp' => false, 'email' => false], json_decode($r->body(), true)['consent']);
    $t->same($before + 1, $count());
    $t->same(422, $call('PUT', '/consent', $cookieA, ['sms' => 'yes'])->status(), 'only true or false');
    $t->same(422, $call('PUT', '/consent', $cookieA, ['sms' => true, 'source' => 'elsewhere'])->status());
});

$t->test('number code: a code sent to the number verifies it once; it is stored per customer and a repeat order needs no code under new_number', function () use ($t, $call, $cookieA, $cookieB, $lastCode, $nv, $pdo, $idA, $idB, &$logged): void {
    $t->same(401, $call('POST', '/numbers/otp/request', [], ['phone' => '9876543210'])->status());
    $t->same(200, $call('POST', '/numbers/otp/request', $cookieA, ['phone' => '98765 43210'])->status());
    $code = $lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    $t->same(400, $call('POST', '/numbers/otp/verify', $cookieA, ['phone' => '9876543210', 'code' => $wrong])->status());
    $t->same(400, $call('POST', '/numbers/otp/verify', $cookieB, ['phone' => '9876543210', 'code' => $code])->status(), 'a code opens only the customer it was made for');
    $t->same(200, $call('POST', '/numbers/otp/verify', $cookieA, ['phone' => '9876543210', 'code' => $code])->status());
    $row = $pdo->query("SELECT mobile, verified_at FROM shop_verified_numbers WHERE customer_id = $idA")->fetch(PDO::FETCH_ASSOC);
    $t->same('9876543210', $row['mobile']);
    $t->same(400, $call('POST', '/numbers/otp/verify', $cookieA, ['phone' => '9876543210', 'code' => $code])->status(), 'single use');
    $n = $nv();
    $t->same(true, $n->isVerified($idA, '9876543210'));
    $t->same(false, $n->isVerified($idB, '9876543210'), 'verified for one customer only');
    $t->same(false, $n->isVerified($idA, '9123456780'));
    $n->assertForOrder('new_number', true, $idA, '+919876543210', 'cod');
    $t->throws(ApiException::class, static fn () => $n->assertForOrder('new_number', true, $idA, '9123456780', 'cod'));
    foreach ($logged as $line) {
        $t->ok(!str_contains($line, '9876543210') && !str_contains($line, $code), 'no full number or code in the log');
    }
});

$t->test('ORDER_OTP: cod asks before every cash-on-delivery order, always before every order, off never; SMS off never', function () use ($t, $call, $cookieA, $lastCode, $clock, $nv, $pdo, $idA): void {
    $n = $nv();
    $needs = static function (string $mode, bool $sms, string $method) use ($n, $idA): bool {
        try {
            $n->assertForOrder($mode, $sms, $idA, '9876543210', $method);
            return false;
        } catch (ApiException $e) {
            return $e->status === 422 && $e->errorCode === 'otp-required' && $e->field === 'phone';
        }
    };
    $pdo->exec("UPDATE shop_verified_numbers SET otp_passed_at = NULL WHERE customer_id = $idA");
    $t->same([true, false, true, false, false], [$needs('cod', true, 'cod'), $needs('cod', true, 'online'), $needs('always', true, 'online'), $needs('off', true, 'cod'), $needs('cod', false, 'cod')]);
    $t->same(false, $needs('new_number', true, 'cod'), 'the number was verified earlier');
    // a fresh code for this order
    $clock->advance(Otp::RESEND_SECONDS + 1);
    $call('POST', '/numbers/otp/request', $cookieA, ['phone' => '9876543210']);
    $call('POST', '/numbers/otp/verify', $cookieA, ['phone' => '9876543210', 'code' => $lastCode()]);
    $t->same(false, $needs('cod', true, 'cod'), 'passed');
    $n->assertForOrder('cod', true, $idA, '9876543210', 'cod', true);
    $t->same(true, $needs('cod', true, 'cod'), 'the order used it up: the next cash-on-delivery order asks again');
    $clock->advance(Otp::RESEND_SECONDS + 1);
    $call('POST', '/numbers/otp/request', $cookieA, ['phone' => '9876543210']);
    $call('POST', '/numbers/otp/verify', $cookieA, ['phone' => '9876543210', 'code' => $lastCode()]);
    $clock->advance(NumberVerification::PROOF_SECONDS + 5);
    $t->same(true, $needs('cod', true, 'cod'), 'a code is good for 15 minutes');
});

$t->test('tools/export_leads.php: only customers with a current yes, their channels and number status; spreadsheet formulas are neutralised', function () use ($t, $pdo, $idA, $idB): void {
    require_once dirname(__DIR__) . '/tools/export_leads.php';
    $pdo->prepare('INSERT INTO shop_customer_addresses (customer_id, recipient_name, phone, house, street, city, state, pincode, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([$idA, 'Asha', '9876543210', '1', 'Road', 'Hyderabad', 'Telangana', '500034']);
    $pdo->prepare('UPDATE shop_customers SET name = ? WHERE id = ?')->execute(['=HYPERLINK("x")', $idA]);
    $rows = lead_rows($pdo);
    $t->same(['name', 'mobile', 'mobile_status', 'email', 'channels', 'consent_date'], $rows[0]);
    $t->same(2, count($rows), 'header and one customer: B never said yes, A turned email off');
    $t->same(["'=HYPERLINK(\"x\")", '9876543210', 'verified', 'consent.a@example.com', 'sms'], array_slice($rows[1], 0, 5));
    $t->same(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $rows[1][5]));
    $pdo->prepare("INSERT INTO shop_consent_log (customer_id, channel, granted, source) VALUES (?, 'sms', 0, 'account')")->execute([$idA]);
    $t->same(1, count(lead_rows($pdo)), 'turned off: nobody is left');
    $t->same(0, (int) $pdo->query("SELECT COUNT(*) FROM shop_consent_log WHERE customer_id = $idB")->fetchColumn());
});

$t->test('SMS_ENABLED=false: the number routes answer 503 and send nothing', function () use ($t, $build, $cookieA, $origin, $smsFile): void {
    $off = $build(false);
    $before = is_file($smsFile) ? filesize($smsFile) : 0;
    $r = $off->handle(Helpers::request('POST', '/api/v1/numbers/otp/request', $origin + $cookieA, ['phone' => '9876543210']));
    $t->same(503, $r->status());
    $t->same($before, is_file($smsFile) ? filesize($smsFile) : 0);
});

$t->test('account help: a verified number gets the masked email hint; every other number gets the same answer and no SMS; the limit gives retryAfter', function () use ($t, $pdo, $clock, $smsFile, &$app, $build, $origin, &$logged): void {
    $app = $build(true);
    $pdo->exec("UPDATE shop_customers SET mobile = '+919811111111', mobile_verified_at = NOW(3) WHERE email = 'consent.a@example.com'");
    $pdo->exec("INSERT INTO shop_customers (name, email, mobile, mobile_verified_at, password_hash, created_at, updated_at) VALUES ('', NULL, '+919822222222', NOW(3), NULL, NOW(3), NOW(3))");
    $ask = static function (string $mobile) use (&$app, $origin) { // by reference: the last step swaps $app
        return $app->handle(Helpers::request('POST', '/api/v1/auth/account-help/sms', $origin, ['mobile' => $mobile], '203.0.113.30'));
    };
    $size = static fn (): int => is_file($smsFile) ? (int) filesize($smsFile) : 0;
    $known = $ask('9811111111');
    $afterKnown = $size();
    $t->same(200, $known->status(), $known->body());
    $t->contains('email_hint=c*****.a@example.com', (string) file_get_contents($smsFile));
    $unknown = $ask('9833333333');
    $noEmail = $ask('9822222222');
    $t->same([$known->body(), $known->body()], [$unknown->body(), $noEmail->body()], 'one answer for every number');
    $t->same("If this number has an account, we've sent an SMS.", $known->decoded()['message']);
    $t->same($afterKnown, $size(), 'nothing was sent for an unknown number or an account with no email');
    $again = $ask('9811111111');
    $t->same(429, $again->status());
    $t->ok(($again->decoded()['retryAfter'] ?? 0) >= 1 && (int) $again->header('Retry-After') === $again->decoded()['retryAfter'], 'the wait is in the JSON and the header');
    $t->same(422, $ask('abc')->status(), 'a malformed number is refused');
    $app = $build(false);
    $off = $ask('9811111111');
    $t->same(503, $off->status(), 'SMS_ENABLED=false: the route is off ' . $off->body());
    foreach ($logged as $line) {
        $t->ok(!str_contains($line, '9811111111') && !str_contains($line, 'consent.a@example.com'), 'no full number or email in the log');
    }
});

exit($t->finish());

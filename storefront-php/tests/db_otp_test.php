<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test, settings from environment variables only.
// Run: PF_TEST_DB_PORT=3399 PF_TEST_DB_NAME=..._test PF_TEST_DB_USER=... PF_TEST_DB_PASS=... [PF_TEST_APPLY_OTP=1] php tests/db_otp_test.php
// PF_TEST_APPLY_OTP=1 applies sql/003_mobile_otp.sql first (once; skipped when shop_customers already has the mobile column).
// Mobile OTP sign-in end to end through the App: request, cooldown, expiry, attempts, single use, new and existing numbers, the typed
// email, rate limits, a failing gateway, and what the logs hold. Messages from SMS go to a temp file (log mode) read back here.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\App;
use Pratyangi\Auth\Otp;
use Pratyangi\Auth\OtpRepo;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Sms\LogSmsGateway;
use Pratyangi\Sms\SmsException;
use Pratyangi\Sms\SmsGateway;
use Pratyangi\Support\Logger;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: the OTP database tests run only on port 3399\n");
    exit(2);
}
$pdo = DbGuard::connect(); // also refuses: not localhost, port 3306, a name that does not end in _test
DbGuard::clean($pdo);

$t = new TestCase('db_otp_test');
$pepper = str_repeat('Q', 48);
$origin = ['Origin' => Helpers::ORIGIN];
// today at 12:00 UTC: far from a day boundary, so the per-day counts of one test never straddle it
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$dir = Helpers::tempDir('pf_otp_');
register_shutdown_function(static fn () => Helpers::rmTree($dir));
$smsFile = $dir . '/sms.log';
$mailFile = $dir . '/mail.log';
$config = Helpers::config(['OTP_PEPPER' => $pepper, 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => $smsFile]);

/** @var list<string> every line the app logged, at debug level */
$logged = [];
$logger = new Logger('debug', static function (string $line) use (&$logged): void {
    $logged[] = $line;
});
$failing = new class implements SmsGateway {
    public bool $on = false;
    public function __construct(private ?SmsGateway $real = null)
    {
    }
    public function use(SmsGateway $real): void
    {
        $this->real = $real;
    }
    public function sendOtp(string $mobile, string $code): void
    {
        if ($this->on) {
            throw new SmsException('The SMS provider could not be reached.');
        }
        $this->real?->sendOtp($mobile, $code);
    }
};
$failing->use(new LogSmsGateway($smsFile, $clock));

$makeApp = static fn (array $limits = [], ?\Pratyangi\Mail\Mailer $mailer = null): App => new App(
    $config, $logger, $clock, static fn (): \PDO => $pdo, $mailer ?? new NullMailer(),
    new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo),
    $limits + ['otp_ip' => 100000, 'otp_verify' => 100000], null, $failing,
);
$app = $makeApp();

$post = static function (string $path, array $body, array $headers = [], string $ip = '203.0.113.9') use (&$app, $origin): \Pratyangi\Http\Response {
    return $app->handle(Helpers::request('POST', '/api/v1' . $path, $origin + $headers, $body, $ip));
};
// The two purposes: a number with an account goes through "login", a new one through "signup" (the old tests treat the first sign-in as a sign-up).
$hasAccount = static function (string $mobile) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM shop_customers WHERE mobile = ?');
    $stmt->execute(['+91' . substr(preg_replace('/\D/', '', $mobile), -10)]);
    return $stmt->fetchColumn() !== false;
};
$route = static fn (string $mobile, string $step): string => '/auth/otp/' . ($hasAccount($mobile) ? '' : 'signup/') . $step;
$request = static fn (string $mobile, string $ip = '203.0.113.9') => $post($route($mobile, 'request'), ['mobile' => $mobile], [], $ip);
$verify = static fn (string $mobile, string $code, array $extra = [], string $ip = '203.0.113.9') => $post($route($mobile, 'verify'), ['mobile' => $mobile, 'code' => $code] + $extra, [], $ip);
/** The newest code written to the SMS log (log mode). */
$lastCode = static function () use ($smsFile): string {
    preg_match_all('/otp=(\d{6})/', (string) file_get_contents($smsFile), $m);
    return (string) end($m[1]);
};
$smsCount = static fn (): int => is_file($smsFile) ? substr_count((string) file_get_contents($smsFile), "\n") : 0;
/** A code that is not $code. */
$wrongFor = static fn (string $code): string => $code === '000000' ? '111111' : '000000';
$cookieOf = static function (\Pratyangi\Http\Response $r): string {
    foreach ($r->cookies() as $line) {
        if (str_starts_with($line, 'pf_session=')) {
            return explode(';', $line)[0];
        }
    }
    return '';
};
/** Signs a number in (creating the account with $name when new) and returns the response of the verify call. */
$signIn = static function (string $mobile, ?string $name = null) use ($request, $verify, $lastCode, $clock): \Pratyangi\Http\Response {
    $clock->advance(Otp::RESEND_SECONDS + 1);
    $r = $request($mobile);
    if ($r->status() !== 200) {
        throw new RuntimeException('request failed: ' . $r->status() . ' ' . $r->body());
    }
    return $verify($mobile, $lastCode(), $name === null ? [] : ['name' => $name]);
};
$row = static function (string $sql, array $params = []) use ($pdo): array|false {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
};

$t->test('migration 003: email is nullable, mobile is unique, contact_email exists, shop_verification_codes and mobile_verified_at exist (003b)', function () use ($t, $pdo): void {
    $cols = $pdo->query("SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_customers'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $t->same('YES', $cols['email'] ?? null, 'email is nullable');
    $t->same('YES', $cols['mobile'] ?? null);
    $t->same('YES', $cols['contact_email'] ?? null);
    $unique = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_customers' AND INDEX_NAME = 'uq_shop_customers_mobile'")->fetchColumn();
    $t->same(0, (int) $unique, 'unique key on mobile');
    $emailUnique = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_customers' AND INDEX_NAME = 'uq_shop_customers_email'")->fetchColumn();
    $t->same(0, (int) $emailUnique, 'email stays unique');
    $t->same('YES', $cols['mobile_verified_at'] ?? null, 'mobile_verified_at');
    $otp = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_verification_codes' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    $t->same(['id', 'channel', 'purpose', 'target', 'customer_id', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'created_at', 'ip_hash'], $otp);
});

$t->test('request: the code is stored only as its HMAC, expires in 5 minutes, and is written to the SMS log (log mode) with a masked number', function () use ($t, $request, $row, $lastCode, $smsFile, $pepper, $clock, $pdo): void {
    $clock->advance(100);
    $r = $request('98000 00001');
    $t->same(200, $r->status());
    $t->same(['message' => 'If this number can receive SMS, a 6-digit code is on its way.', 'resendAfter' => 45, 'expiresIn' => 300], $r->decoded());
    $code = $lastCode();
    $stored = $row('SELECT * FROM shop_verification_codes WHERE target = ?', ['+919800000001']);
    $t->same(['mobile', 'signup'], [$stored['channel'], $stored['purpose']]);
    $t->same(Otp::hashFor($pepper, 'mobile', 'signup', '+919800000001', $code), $stored['code_hash']);
    $t->ok(!in_array($code, array_map('strval', $stored), true), 'no column holds the code itself');
    $t->same([0, null], [(int) $stored['attempts'], $stored['consumed_at']]);
    $t->same(300.0, \Pratyangi\Support\Clock::fromSql($stored['expires_at']) - \Pratyangi\Support\Clock::fromSql($stored['created_at']));
    $t->same(1, preg_match('/^[0-9a-f]{64}$/D', (string) $stored['ip_hash']));
    $t->ok($stored['ip_hash'] !== hash('sha256', '203.0.113.9') && !str_contains((string) $stored['ip_hash'], '203'), 'the address is not stored plainly');
    $log = (string) file_get_contents($smsFile);
    $t->contains('to=+91******0001 otp=' . $code, $log);
    $t->ok(!str_contains($log, '9800000001'), 'the full number is not in the SMS log');
    $t->same(0, (int) $pdo->query('SELECT COUNT(*) FROM shop_customers')->fetchColumn(), 'asking for a code creates no account');
});

$t->test('login request: an unknown number gets 404 "No account found with this number." and nothing is sent or stored; a known number gets the code', function () use ($t, $signIn, $post, $smsCount, $pdo, $clock): void {
    $signIn('9800000010', 'Known Buyer'); // makes the account (sign-up flow)
    $clock->advance(60);
    $before = $smsCount();
    $r = $post('/auth/otp/request', ['mobile' => '9800000011']);
    $t->same(404, $r->status());
    $t->same('No account found with this number.', json_decode($r->body(), true)['message']);
    $t->same($before, $smsCount(), 'no SMS');
    $stored = $pdo->prepare("SELECT COUNT(*) FROM shop_verification_codes WHERE target = '+919800000011'");
    $stored->execute();
    $t->same(0, (int) $stored->fetchColumn(), 'no code stored');
    $t->same(200, $post('/auth/otp/request', ['mobile' => '9800000010'])->status());
    $t->same($before + 1, $smsCount());
});

$t->test('signup request: a registered number gets 409 "This number is already registered." and nothing is sent; a new number gets the code', function () use ($t, $post, $smsCount, $clock): void {
    $clock->advance(60);
    $before = $smsCount();
    $r = $post('/auth/otp/signup/request', ['mobile' => '9800000010']);
    $t->same(409, $r->status());
    $t->same('This number is already registered.', json_decode($r->body(), true)['message']);
    $t->same($before, $smsCount(), 'no SMS');
    $t->same(200, $post('/auth/otp/signup/request', ['mobile' => '9800000012'])->status());
    $t->same($before + 1, $smsCount());
});

$t->test('purpose binding: a signup code cannot sign in, a login code cannot create an account, the generic refusal for both', function () use ($t, $post, $lastCode, $clock, $pdo): void {
    $clock->advance(60);
    $post('/auth/otp/signup/request', ['mobile' => '9800000013']);
    $code = $lastCode();
    $r = $post('/auth/otp/verify', ['mobile' => '9800000013', 'code' => $code]);
    $t->same(400, $r->status());
    $t->same('Invalid or expired code', json_decode($r->body(), true)['message']);
    $count = $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile = '+919800000013'");
    $t->same(0, (int) $count->fetchColumn(), 'no account from a login verify');
    $t->same(201, $post('/auth/otp/signup/verify', ['mobile' => '9800000013', 'code' => $code])->status(), 'the signup code still works on signup');
    // a login code made for 9800000010 does not sign up that (registered) number
    $clock->advance(60);
    $post('/auth/otp/request', ['mobile' => '9800000010']);
    $r = $post('/auth/otp/signup/verify', ['mobile' => '9800000010', 'code' => $lastCode()]);
    $t->same(400, $r->status());
});

$t->test('concurrent sign-ups: a number that got its account between request and verify gets 409 and no second account', function () use ($t, $post, $lastCode, $clock, $pdo): void {
    $clock->advance(60);
    $post('/auth/otp/signup/request', ['mobile' => '9800000014']);
    $code = $lastCode();
    $pdo->exec("INSERT INTO shop_customers (name, email, mobile, mobile_verified_at, password_hash) VALUES ('', NULL, '+919800000014', NOW(), NULL)");
    $r = $post('/auth/otp/signup/verify', ['mobile' => '9800000014', 'code' => $code]);
    $t->same(409, $r->status());
    $count = $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile = '+919800000014'");
    $t->same(1, (int) $count->fetchColumn());
});

$t->test('cooldown: a second request within 45 seconds is refused (429, Retry-After, no SMS); after 45 seconds it goes through', function () use ($t, $request, $smsCount, $clock): void {
    $clock->advance(100);
    $t->same(200, $request('9800000020')->status());
    $sent = $smsCount();
    $clock->advance(20);
    $r = $request('9800000020');
    $t->same(429, $r->status());
    $wait = (int) $r->header('Retry-After');
    $t->ok($wait >= 24 && $wait <= 25, 'about 25 seconds left, got ' . $wait);
    $t->same('Please wait ' . $wait . ' seconds before asking for another code.', $r->decoded()['message']);
    $t->same($sent, $smsCount(), 'nothing was sent');
    $clock->advance(24);
    $t->same(429, $request('9800000020')->status(), '44 seconds');
    $clock->advance(1);
    $t->same(200, $request('9800000020')->status(), '45 seconds');
    $t->same($sent + 1, $smsCount());
});

$t->test('a new request invalidates the older code of that number', function () use ($t, $request, $verify, $lastCode, $clock, $row, $wrongFor): void {
    $clock->advance(100);
    $request('9800000030');
    $first = $lastCode();
    $clock->advance(46);
    $request('9800000030');
    $second = $lastCode();
    $t->ok($row('SELECT consumed_at FROM shop_verification_codes WHERE target = ? ORDER BY id LIMIT 1', ['+919800000030'])['consumed_at'] !== null, 'the older row is ended');
    if ($first !== $second) {
        $r = $verify('9800000030', $first);
        $t->same([400, ['message' => 'Invalid or expired code']], [$r->status(), $r->decoded()], 'the old code no longer works');
    }
    $r = $verify('9800000030', $second);
    $t->same(201, $r->status(), 'the new code works (a new number: the account is made, no name asked)');
    $t->ok($wrongFor($second) !== $second);
});

$t->test('expiry: valid for 5 minutes, refused after', function () use ($t, $request, $verify, $lastCode, $clock, $signIn): void {
    $signIn('9800000040', 'Expiry Person'); // an account to sign in to
    // inside the window
    $clock->advance(46);
    $request('9800000040');
    $code = $lastCode();
    $clock->advance(299);
    $r = $verify('9800000040', $code);
    $t->same(200, $r->status(), 'at 299 seconds');
    $t->ok(isset($r->decoded()['user']));
    // past the window
    $clock->advance(46);
    $request('9800000040');
    $code = $lastCode();
    $clock->advance(301);
    $r = $verify('9800000040', $code);
    $t->same([400, ['message' => 'Invalid or expired code']], [$r->status(), $r->decoded()], 'at 301 seconds');
    $t->same([], $r->cookies());
});

$t->test('attempts: five wrong codes lock that code, even for the right one; four wrong ones do not', function () use ($t, $request, $verify, $lastCode, $clock, $wrongFor, $signIn, $row): void {
    $signIn('9800000050', 'Attempts Person');
    // four wrong, then the right one works
    $clock->advance(46);
    $request('9800000050');
    $code = $lastCode();
    for ($i = 0; $i < 4; $i++) {
        $t->same(400, $verify('9800000050', $wrongFor($code))->status());
    }
    $t->same(4, (int) $row('SELECT attempts FROM shop_verification_codes WHERE target = ? ORDER BY id DESC LIMIT 1', ['+919800000050'])['attempts']);
    $t->same(200, $verify('9800000050', $code)->status(), 'the fifth try, right code');
    // five wrong, then the right one is refused
    $clock->advance(46);
    $request('9800000050');
    $code = $lastCode();
    for ($i = 0; $i < 5; $i++) {
        $r = $verify('9800000050', $wrongFor($code));
        $t->same([400, ['message' => $i < 4 ? 'Invalid or expired code' : 'Too many wrong codes. Request a new code.']], [$r->status(), $r->decoded()], 'wrong try ' . ($i + 1));
    }
    $r = $verify('9800000050', $code);
    $t->same([400, ['message' => 'Too many wrong codes. Request a new code.'], []], [$r->status(), $r->decoded(), $r->cookies()], 'locked');
    $t->same(5, (int) $row('SELECT attempts FROM shop_verification_codes WHERE target = ? ORDER BY id DESC LIMIT 1', ['+919800000050'])['attempts'], 'no more than five counted');
    // a fresh code works again
    $clock->advance(46);
    $request('9800000050');
    $t->same(200, $verify('9800000050', $lastCode())->status());
});

$t->test('existing account: a correct code signs in with the session cookie (HttpOnly, SameSite=Lax, Path=/), /auth/me works, the code is single use', function () use ($t, $signIn, $request, $verify, $lastCode, $clock, $post, $app, $cookieOf, $row): void {
    $first = $signIn('9800000060', 'Cookie Person');
    $t->same(201, $first->status(), 'first sign-in made the account');
    $id = $first->decoded()['user']['id'];
    $clock->advance(46);
    $request('9800000060');
    $code = $lastCode();
    $r = $verify('9800000060', $code);
    $t->same(200, $r->status());
    $line = $r->cookies()[0] ?? '';
    $t->same(1, preg_match('/^pf_session=[A-Za-z0-9_-]{43}; Path=\/; Expires=[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT; HttpOnly; SameSite=Lax$/D', $line), 'cookie: ' . preg_replace('/=[A-Za-z0-9_-]{43}/', '=<token>', $line));
    $user = $r->decoded()['user'];
    $t->same($id, $user['id'], 'the same account');
    $t->same(['id', 'name', 'email', 'provider', 'emailVerified', 'createdAt', 'mobile', 'mobileVerified'], array_keys($user));
    $t->same(['', '', 'mobile', false, '+919800000060', true], [$user['name'], $user['email'], $user['provider'], $user['emailVerified'], $user['mobile'], $user['mobileVerified']]);
    $me = $app->handle(Helpers::request('GET', '/api/v1/auth/me', ['Cookie' => $cookieOf($r)]));
    $t->same([200, $user], [$me->status(), $me->decoded()['user']]);
    // single use
    $again = $verify('9800000060', $code);
    $t->same([400, ['message' => 'Invalid or expired code'], []], [$again->status(), $again->decoded(), $again->cookies()]);
    $t->same(1, (int) $row('SELECT COUNT(*) AS n FROM shop_customers WHERE mobile = ?', ['+919800000060'])['n']);
    // logout ends the session
    $out = $app->handle(Helpers::request('POST', '/api/v1/auth/logout', ['Origin' => Helpers::ORIGIN, 'Cookie' => $cookieOf($r)], []));
    $t->same(204, $out->status());
    $t->same(401, $app->handle(Helpers::request('GET', '/api/v1/auth/me', ['Cookie' => $cookieOf($r)]))->status());
});

$t->test('new number: a correct code creates the account at once, with no name, no email, no password, and a verified mobile; a name or email in the body is ignored', function () use ($t, $request, $verify, $lastCode, $clock, $row, $cookieOf): void {
    $clock->advance(100);
    $request('9800000070');
    $code = $lastCode();
    $r = $verify('9800000070', $code, ['name' => 'Asha Rao', 'email' => 'asha@example.com']);
    $t->same(201, $r->status());
    $t->ok($cookieOf($r) !== '');
    $c = $row('SELECT * FROM shop_customers WHERE mobile = ?', ['+919800000070']);
    $t->same(['', null, '+919800000070', null, null, null], [$c['name'], $c['email'], $c['mobile'], $c['password_hash'], $c['email_verified_at'], $c['contact_email']]);
    $t->ok($c['mobile_verified_at'] !== null, 'mobile_verified_at is set');
    $user = $r->decoded()['user'];
    $t->same(['mobile', '', false, ''], [$user['provider'], $user['email'], $user['emailVerified'], $user['name']]);
    $t->same(1, (int) $row('SELECT COUNT(*) AS n FROM shop_sessions WHERE customer_id = ?', [$c['id']])['n']);
    $t->same(400, $verify('9800000070', $code)->status(), 'the code is used up');
});

$t->test('two accounts without an email are fine (UNIQUE allows many NULLs); the same number never makes two accounts', function () use ($t, $signIn, $pdo): void {
    $signIn('9800000081', 'First One');
    $signIn('9800000082', 'Second One');
    $t->same(2, (int) $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile IN ('+919800000081','+919800000082') AND email IS NULL")->fetchColumn());
    $t->same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile = '+919800000081'")->fetchColumn());
    $signIn('9800000081'); // signs in again
    $t->same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile = '+919800000081'")->fetchColumn());
});

$t->test('an email sent along with a mobile sign-up is ignored: it never signs in, resets a password, or merges with an email account', function () use ($t, $post, $row, $cookieOf, $clock, $request, $verify, $lastCode): void {
    $own = $post('/auth/signup', ['name' => 'Email Owner', 'email' => 'owner@example.com', 'password' => 'Saree2026']);
    $t->same(201, $own->status());
    $ownerId = $own->decoded()['user']['id'];
    $clock->advance(100);
    $request('9800000090');
    $r = $verify('9800000090', $lastCode(), ['name' => 'Mobile Person', 'email' => 'Owner@Example.com']);
    $t->same(201, $r->status());
    $otpUser = $r->decoded()['user'];
    $t->ok($otpUser['id'] !== $ownerId, 'a separate account, not a merge');
    $c = $row('SELECT email, contact_email, name FROM shop_customers WHERE id = ?', [$otpUser['id']]);
    $t->same([null, null, ''], [$c['email'], $c['contact_email'], $c['name']], 'nothing from the body was stored');
    $o = $row('SELECT mobile FROM shop_customers WHERE id = ?', [$ownerId]);
    $t->same(null, $o['mobile'], 'the email account was not touched');
    $login = $post('/auth/login', ['email' => 'owner@example.com', 'password' => 'Saree2026']);
    $t->same($ownerId, $login->decoded()['user']['id']);
    $login = $post('/auth/login', ['email' => 'owner@example.com', 'password' => 'Wrong2026']);
    $t->same([401, 'Email or password is incorrect'], [$login->status(), $login->decoded()['message']]);
    $login = $post('/auth/login', ['email' => 'never-typed@example.com', 'password' => 'Saree2026']);
    $t->same([401, 'Email or password is incorrect'], [$login->status(), $login->decoded()['message']]);
    $me = $post('/auth/email/resend', [], ['Cookie' => $cookieOf($r)]);
    $t->same(400, $me->status(), 'the mobile-only account has no email to confirm');
});

$t->test('every verify failure is the same refusal: unknown number, wrong code, expired, used, malformed', function () use ($t, $request, $verify, $lastCode, $clock, $post, $signIn, $wrongFor): void {
    $expected = [400, '{"message":"Invalid or expired code"}', []];
    $shape = static fn (\Pratyangi\Http\Response $r): array => [$r->status(), $r->body(), $r->cookies()];
    $signIn('9800000100', 'Refusal Person');
    $clock->advance(46);
    $request('9800000100');
    $code = $lastCode();
    $results = [];
    $results['never asked'] = $shape($verify('9800000101', '123456'));
    $results['wrong'] = $shape($verify('9800000100', $wrongFor($code)));
    $results['malformed code'] = $shape($post('/auth/otp/verify', ['mobile' => '9800000100', 'code' => '12ab56']));
    $results['malformed number'] = $shape($post('/auth/otp/verify', ['mobile' => '12345', 'code' => $code]));
    $results['no body'] = $shape($post('/auth/otp/verify', []));
    $results['array code'] = $shape($post('/auth/otp/verify', ['mobile' => '9800000100', 'code' => [$code]]));
    $t->same(200, $verify('9800000100', $code)->status());
    $results['used'] = $shape($verify('9800000100', $code));
    $clock->advance(46);
    $request('9800000100');
    $code = $lastCode();
    $clock->advance(400);
    $results['expired'] = $shape($verify('9800000100', $code));
    $clock->advance(46);
    $request('9800000100');
    $code = $lastCode();
    // (a locked code gets its own message, covered by the attempts test)
    foreach ($results as $note => $got) {
        $t->same($expected, $got, $note);
    }
});

$t->test('per-number limit: 5 codes a day (stored hashed in shop_rate_limits), the 6th is refused; another number is unaffected', function () use ($t, $request, $clock, $pdo): void {
    $clock->advance(100);
    for ($i = 1; $i <= 5; $i++) {
        $t->same(200, $request('9800000110')->status(), 'request ' . $i);
        $clock->advance(46);
    }
    $r = $request('9800000110');
    $t->same([429, 'Too many codes have been requested for this number today. Please try again tomorrow.'], [$r->status(), $r->decoded()['message']]);
    $t->ok($r->header('Retry-After') !== null);
    $t->same(200, $request('9800000111')->status(), 'another number');
    $keys = $pdo->query("SELECT bucket_key FROM shop_rate_limits WHERE bucket_key LIKE 'otp_mobile:%'")->fetchAll(PDO::FETCH_COLUMN);
    $t->ok(count($keys) >= 2);
    foreach ($keys as $key) {
        $t->same(1, preg_match('/^otp_mobile:[0-9a-f]{64}$/D', $key), 'a hash, not the number');
    }
});

$t->test('per-address limit: 10 requests an hour (shop_rate_limits), the 11th is refused; verify has its own count', function () use ($t, $makeApp, $clock, $pdo): void {
    $limited = $makeApp(['otp_ip' => 10, 'otp_verify' => 3]);
    $ip = '198.51.100.77';
    $go = static fn (string $path, array $body) => $limited->handle(Helpers::request('POST', '/api/v1' . $path, ['Origin' => Helpers::ORIGIN], $body, $ip));
    $clock->advance(100);
    for ($i = 0; $i < 10; $i++) {
        $t->same(200, $go('/auth/otp/signup/request', ['mobile' => '98000002' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->status(), 'request ' . ($i + 1));
    }
    $r = $go('/auth/otp/signup/request', ['mobile' => '9800000299']);
    $t->same([429, 'Too many attempts. Please wait a few minutes and try again.'], [$r->status(), $r->decoded()['message']]);
    $t->same('10;w=3600', $r->header('RateLimit-Policy'));
    for ($i = 0; $i < 3; $i++) {
        $t->same(400, $go('/auth/otp/verify', ['mobile' => '9800000200', 'code' => '123456'])->status());
    }
    $t->same(429, $go('/auth/otp/verify', ['mobile' => '9800000200', 'code' => '123456'])->status(), 'the 4th verify');
    $clock->advance(3601);
    $t->same(200, $go('/auth/otp/signup/request', ['mobile' => '9800000299'])->status(), 'the next hour');
    $t->ok((int) $pdo->query("SELECT COUNT(*) FROM shop_rate_limits WHERE bucket_key LIKE 'otp_ip:%'")->fetchColumn() >= 2);
});

$t->test('a failing gateway: generic 503, the new code is removed, the older code still works, no account is created or changed', function () use ($t, $request, $verify, $lastCode, $clock, $failing, $pdo, $row, $signIn): void {
    $signIn('9800000120', 'Gateway Person');
    $before = $row('SELECT * FROM shop_customers WHERE mobile = ?', ['+919800000120']);
    $customers = (int) $pdo->query('SELECT COUNT(*) FROM shop_customers')->fetchColumn();
    $clock->advance(46);
    $request('9800000120');
    $good = $lastCode();
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM shop_verification_codes WHERE target = '+919800000120'")->fetchColumn();
    $clock->advance(46);
    $failing->on = true;
    $r = $request('9800000120');
    $failing->on = false;
    $t->same([503, ['message' => 'Could not send code, try again.']], [$r->status(), $r->decoded()]);
    $t->same($rows, (int) $pdo->query("SELECT COUNT(*) FROM shop_verification_codes WHERE target = '+919800000120'")->fetchColumn(), 'the undelivered code was removed');
    $t->same($before, $row('SELECT * FROM shop_customers WHERE mobile = ?', ['+919800000120']), 'the account row is unchanged');
    $t->same($customers, (int) $pdo->query('SELECT COUNT(*) FROM shop_customers')->fetchColumn());
    $t->same(200, $verify('9800000120', $good)->status(), 'the earlier code still works');
    // a brand-new number whose SMS fails creates nothing
    $clock->advance(46);
    $failing->on = true;
    $r = $request('9800000121');
    $failing->on = false;
    $t->same(503, $r->status());
    $t->same(0, (int) $pdo->query("SELECT COUNT(*) FROM shop_customers WHERE mobile = '+919800000121'")->fetchColumn());
    $t->same(0, (int) $pdo->query("SELECT COUNT(*) FROM shop_verification_codes WHERE target = '+919800000121'")->fetchColumn());
});

$t->test('/account shows the stored mobile number; /checkout renders for a mobile-only account', function () use ($t, $signIn, $app, $cookieOf): void {
    $r = $signIn('9800000130', 'Page Person');
    $cookie = $cookieOf($r);
    $t->ok($cookie !== '');
    $page = $app->handle(Helpers::request('GET', '/account', ['Cookie' => $cookie]));
    $t->same(200, $page->status());
    $b = $page->body();
    $t->contains('<span>+919800000130</span>', $b);
    $t->contains('<h2>Hello</h2>', $b, 'no name stored: Hello, and a way to add the name');
    $t->contains('data-pf="name-form"', $b);
    $t->contains('data-pf-link="email"', $b, 'a mobile-only account can add an email');
    $t->ok(!str_contains($b, 'data-pf-link="mobile"'), 'it already has a mobile number');
    $t->ok(!str_contains($b, 'Please confirm your email address'), 'no email to confirm');
    $t->contains('Member since ' . gmdate('M Y') . '</span><span>+919800000130</span>', $b, 'no empty email chip between the date and the number');
    $checkout = $app->handle(Helpers::request('GET', '/checkout', ['Cookie' => $cookie]));
    $t->same(200, $checkout->status());
    $t->contains('Checkout', $checkout->body());
});

$t->test('logs: no code and no full number in anything the app logged, including a failed send', function () use ($t, &$logged, $smsFile, $failing, $request, $clock, $pdo): void {
    $clock->advance(100);
    $failing->on = true;
    $request('9800000140');
    $failing->on = false;
    $t->ok($logged !== [], 'the app did log (debug level)');
    $all = implode("\n", $logged);
    preg_match_all('/otp=(\d{6})/', (string) file_get_contents($smsFile), $m);
    foreach (array_unique($m[1]) as $code) {
        // the code as a whole token (not inside a longer number, id or timestamp)
        $t->ok(preg_match('/(?<![0-9A-Za-z-])' . $code . '(?![0-9A-Za-z-])/', $all) !== 1, 'a code appears in the app log');
    }
    $numbers = array_merge($pdo->query("SELECT mobile FROM shop_customers WHERE mobile IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), $pdo->query('SELECT DISTINCT target FROM shop_verification_codes WHERE channel = "mobile"')->fetchAll(PDO::FETCH_COLUMN));
    $t->ok(count($numbers) > 10, 'there are numbers to look for');
    foreach (array_unique($numbers) as $number) {
        $t->ok(!str_contains($all, substr($number, -10)), 'a full mobile number appears in the app log');
    }
    $t->contains('could not send the code', $all);
    $t->contains('+91******0140', $all);
});

$t->test('housekeeping: OtpRepo::purge removes only rows that ended before the cut-off', function () use ($t, $pdo, $clock): void {
    $repo = new OtpRepo($pdo);
    $now = $clock->now();
    $old = $repo->insert('mobile', 'login', '+919800000150', null, str_repeat('a', 64), $now - 200000, $now - 199700, null);
    $new = $repo->insert('mobile', 'login', '+919800000150', null, str_repeat('b', 64), $now, $now + 300, null);
    $repo->purge($now - 86400);
    $left = $pdo->query("SELECT id FROM shop_verification_codes WHERE target = '+919800000150'")->fetchAll(PDO::FETCH_COLUMN);
    $t->same([(string) $new], array_map('strval', $left));
    $t->ok($old !== $new);
});

exit($t->finish());

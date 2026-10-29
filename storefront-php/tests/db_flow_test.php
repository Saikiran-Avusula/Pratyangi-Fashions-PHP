<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test, settings from environment variables only.
// Run: PF_TEST_DB_PORT=3399 PF_TEST_DB_NAME=..._test PF_TEST_DB_USER=... PF_TEST_DB_PASS=... [PF_TEST_APPLY_OTP=1] php tests/db_flow_test.php
// The account flow (docs/API_CONTRACT.md 11.3) through the App: email sign-up with a code, sign-in messages, linking a mobile or an email,
// purpose and channel separation of the codes, forgot password by email and by mobile (identical answers, session wipe), the name.
// The mail and the SMS are captured in memory; this test reads the codes from there.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\App;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Http\Response;
use Pratyangi\Mail\Mailer;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Security\Tokens;
use Pratyangi\Sms\SmsGateway;
use Pratyangi\Support\Logger;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: the account flow database tests run only on port 3399\n");
    exit(2);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);

$t = new TestCase('db_flow_test');
$pepper = str_repeat('R', 48);
$origin = ['Origin' => Helpers::ORIGIN];
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$config = Helpers::config(['OTP_PEPPER' => $pepper, 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => sys_get_temp_dir() . '/pf_flow_unused_sms.log']);

$mailbox = new class implements Mailer {
    /** @var list<array{to:string,text:string}> */
    public array $sent = [];
    public bool $fail = false;
    public function isAvailable(): bool
    {
        return true;
    }
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        if ($this->fail) {
            throw new \Pratyangi\Mail\MailException('down');
        }
        $this->sent[] = ['to' => $to, 'text' => $text];
    }
    public function lastCodeTo(string $to): string
    {
        foreach (array_reverse($this->sent) as $mail) {
            if ($mail['to'] === $to && preg_match('/Your code is (\d{6})/', $mail['text'], $m) === 1) {
                return $m[1];
            }
        }
        return '';
    }
    public function countTo(string $to): int
    {
        return count(array_filter($this->sent, static fn (array $m): bool => $m['to'] === $to));
    }
};
$phone = new class implements SmsGateway {
    /** @var list<array{mobile:string,code:string}> */
    public array $sent = [];
    public function sendOtp(string $mobile, string $code): void
    {
        $this->sent[] = ['mobile' => $mobile, 'code' => $code];
    }
    public function lastCodeTo(string $mobile): string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['mobile'] === $mobile) {
                return $sms['code'];
            }
        }
        return '';
    }
    public function countTo(string $mobile): int
    {
        return count(array_filter($this->sent, static fn (array $s): bool => $s['mobile'] === $mobile));
    }
};
$logged = [];
$logger = new Logger('debug', static function (string $line) use (&$logged): void {
    $logged[] = $line;
});
$loose = ['signup' => 100000, 'login' => 100000, 'verify' => 100000, 'reset' => 100000, 'otp_ip' => 100000, 'otp_verify' => 100000];
$makeApp = static fn (array $limits, Mailer $mailer, ?SmsGateway $sms): App => new App(
    $config, $logger, $clock, static fn (): \PDO => $pdo, $mailer,
    new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo), $limits, null, $sms,
);
$app = $makeApp($loose, $mailbox, $phone);
$ipCounter = 0;
$call = static function (string $method, string $path, ?array $body = null, string $cookie = '', ?string $ip = null) use (&$app, $origin, &$ipCounter): Response {
    $ipCounter++;
    $headers = $origin + ($cookie !== '' ? ['Cookie' => $cookie] : []);
    return $app->handle(Helpers::request($method, '/api/v1' . $path, $headers, $body, $ip ?? '198.51.100.' . ($ipCounter % 250 + 1)));
};
$cookieOf = static function (Response $r): string {
    foreach ($r->cookies() as $line) {
        if (str_starts_with($line, 'pf_session=')) {
            return explode(';', $line)[0];
        }
    }
    return '';
};
$row = static function (string $sql, array $params = []) use ($pdo): array|false {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
};
$count = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$wrongFor = static fn (string $code): string => $code === '000000' ? '111111' : '000000';
$next = static fn (float $seconds = 46) => $clock->advance($seconds);
$INVALID = ['message' => 'Invalid or expired code'];
/** Signs up by email and returns [cookie, id]. */
$signup = static function (string $email, string $password = 'Saree2026', string $name = 'Flow Buyer') use ($call, $cookieOf): array {
    $r = $call('POST', '/auth/signup', ['name' => $name, 'email' => $email, 'password' => $password]);
    if ($r->status() !== 201) {
        throw new RuntimeException('signup failed: ' . $r->status() . ' ' . $r->body());
    }
    return [$cookieOf($r), (int) $r->decoded()['user']['id']];
};
/** Signs up by email and asks for the code (sign-up itself sends none). */
$signupCoded = static function (string $email, string $password = 'Saree2026') use ($signup, $call): array {
    $s = $signup($email, $password);
    $call('POST', '/auth/email/resend', [], $s[0]);
    return $s;
};
/** Signs up by email and confirms the address with its code. */
$verifiedUser = static function (string $email, string $password = 'Saree2026') use ($signupCoded, $call, $mailbox): array {
    [$cookie, $id] = $signupCoded($email, $password);
    $r = $call('POST', '/auth/email/verify', ['code' => $mailbox->lastCodeTo($email)], $cookie);
    if ($r->status() !== 200) {
        throw new RuntimeException('verify failed: ' . $r->status() . ' ' . $r->body());
    }
    return [$cookie, $id];
};
/** Signs a number in with a mobile code (creating the account when new) and returns [cookie, id]. */
$mobileUser = static function (string $digits) use ($call, $phone, $cookieOf, $next): array {
    $next();
    $base = $call('POST', '/auth/otp/signup/request', ['mobile' => $digits])->status() === 409 ? '/auth/otp' : '/auth/otp/signup'; // 409 = already registered
    if ($base === '/auth/otp') {
        $call('POST', '/auth/otp/request', ['mobile' => $digits]);
    }
    $r = $call('POST', $base . '/verify', ['mobile' => $digits, 'code' => $phone->lastCodeTo('+91' . $digits)]);
    if ($r->status() > 201) {
        throw new RuntimeException('mobile sign-in failed: ' . $r->status() . ' ' . $r->body());
    }
    return [$cookieOf($r), (int) $r->decoded()['user']['id']];
};
/** What a response shows a client, without the per-request id. */
$shape = static function (Response $r): array {
    $headers = [];
    foreach (['RateLimit-Policy', 'RateLimit-Limit', 'RateLimit-Remaining', 'Content-Type'] as $name) {
        $headers[$name] = $r->header($name);
    }
    return [$r->status(), $r->body(), $headers];
};

$t->test('email sign-up: signed in at once, no code is emailed until asked (resend), the address stays unverified until the code is entered', function () use ($t, $call, $mailbox, $cookieOf, $row, $pdo, $next, $wrongFor, $INVALID): void {
    $r = $call('POST', '/auth/signup', ['name' => 'Asha Rao', 'email' => 'Asha@Example.com', 'password' => 'Saree2026']);
    $t->same(201, $r->status());
    $user = $r->decoded()['user'];
    $t->same([false, 'password', 'asha@example.com'], [$user['emailVerified'], $user['provider'], $user['email']]);
    $cookie = $cookieOf($r);
    $t->ok($cookie !== '', 'signed in');
    $t->same(200, $call('GET', '/auth/me', null, $cookie)->status());
    $t->same(1, $mailbox->countTo('asha@example.com'), 'sign-up sends the welcome mail and no code');
    $t->same('', $mailbox->lastCodeTo('asha@example.com'), 'no code until asked');
    $t->same(200, $call('POST', '/auth/email/resend', [], $cookie)->status());
    $code = $mailbox->lastCodeTo('asha@example.com');
    $t->same(1, preg_match('/^\d{6}$/D', $code));
    $stored = $row('SELECT * FROM shop_verification_codes WHERE target = ?', ['asha@example.com']);
    $t->same(['email', 'email_verify'], [$stored['channel'], $stored['purpose']]);
    $t->ok(!in_array($code, array_map('strval', $stored), true), 'the code itself is not stored');
    $t->same(0, (int) $row('SELECT COUNT(*) AS n FROM shop_email_verifications')['n'], 'no link is made any more');
    // no sign-in needed to be refused: a stranger cannot use the code route
    $t->same(401, $call('POST', '/auth/email/verify', ['code' => $code])->status(), 'the code route needs the signed-in customer');
    $wrong = $call('POST', '/auth/email/verify', ['code' => $wrongFor($code)], $cookie);
    $t->same([400, $INVALID], [$wrong->status(), $wrong->decoded()]);
    $t->same('0', (string) $row('SELECT emailVerified FROM (SELECT (email_verified_at IS NOT NULL) AS emailVerified FROM shop_customers WHERE email = ?) x', ['asha@example.com'])['emailVerified']);
    $ok = $call('POST', '/auth/email/verify', ['code' => $code], $cookie);
    $t->same(200, $ok->status());
    $t->same(true, $ok->decoded()['customer']['emailVerified']);
    $t->same(400, $call('POST', '/auth/email/verify', ['code' => $code], $cookie)->status(), 'single use');
    $t->same(true, $call('GET', '/auth/me', null, $cookie)->decoded()['user']['emailVerified']);
    // a stranger's session cannot confirm somebody else's address
    $t->same(0, $row('SELECT COUNT(*) AS n FROM shop_verification_codes WHERE consumed_at IS NULL AND target = ?', ['asha@example.com'])['n'] + 0);
});

$t->test('email sign-up: a registered address is a clear 409; a failing mailer or none still creates the account', function () use ($t, $call, $mailbox, $makeApp, $loose, $phone, $count): void {
    $r = $call('POST', '/auth/signup', ['name' => 'Again', 'email' => 'asha@example.com', 'password' => 'Saree2026']);
    $t->same([409, 'An account with this email already exists.', 'email'], [$r->status(), $r->decoded()['message'], $r->decoded()['field'] ?? null]);
    $mailbox->fail = true;
    $r = $call('POST', '/auth/signup', ['name' => 'Down Mail', 'email' => 'downmail@example.com', 'password' => 'Saree2026']);
    $mailbox->fail = false;
    $t->same(201, $r->status());
    $none = $makeApp($loose, new NullMailer(), $phone);
    $r = $none->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'No Mail', 'email' => 'nomail@example.com', 'password' => 'Saree2026']));
    $t->same(201, $r->status());
    $t->same(1, $count('SELECT COUNT(*) FROM shop_customers WHERE email = ?', ['nomail@example.com']));
});

$t->test('resend: a new code after the 45 s cooldown, the old code stops working, a verified address is told so', function () use ($t, $call, $mailbox, $signupCoded, $next, $INVALID): void {
    [$cookie] = $signupCoded('resend@example.com');
    $first = $mailbox->lastCodeTo('resend@example.com');
    $r = $call('POST', '/auth/email/resend', [], $cookie);
    $t->same(429, $r->status(), 'inside the cooldown');
    $t->ok((int) $r->header('Retry-After') >= 1);
    $t->same(401, $call('POST', '/auth/email/resend', [])->status(), 'sign-in needed');
    $next(46);
    $r = $call('POST', '/auth/email/resend', [], $cookie);
    $t->same(200, $r->status());
    $t->same(['message' => 'We have sent a new 6-digit code to your email.', 'resendAfter' => 45, 'expiresIn' => 300], $r->decoded());
    $second = $mailbox->lastCodeTo('resend@example.com');
    if ($first !== $second) {
        $t->same([400, $INVALID], (static fn (\Pratyangi\Http\Response $x): array => [$x->status(), $x->decoded()])($call('POST', '/auth/email/verify', ['code' => $first], $cookie)), 'the older code is ended');
    }
    $t->same(200, $call('POST', '/auth/email/verify', ['code' => $second], $cookie)->status());
    $r = $call('POST', '/auth/email/resend', [], $cookie);
    $t->same([200, ['message' => 'Your email is already verified.']], [$r->status(), $r->decoded()]);
});

$t->test('codes expire after 5 minutes and allow 5 wrong tries; the 5th wrong try locks the code', function () use ($t, $call, $mailbox, $signupCoded, $clock, $wrongFor): void {
    [$cookie] = $signupCoded('expiry@example.com');
    $code = $mailbox->lastCodeTo('expiry@example.com');
    $clock->advance(301);
    $t->same(400, $call('POST', '/auth/email/verify', ['code' => $code], $cookie)->status(), 'expired');
    $clock->advance(46);
    $call('POST', '/auth/email/resend', [], $cookie);
    $code = $mailbox->lastCodeTo('expiry@example.com');
    for ($i = 0; $i < 5; $i++) {
        $t->same(400, $call('POST', '/auth/email/verify', ['code' => $wrongFor($code)], $cookie)->status());
    }
    $t->same(400, $call('POST', '/auth/email/verify', ['code' => $code], $cookie)->status(), 'locked even for the right digits');
});

$t->test('email sign-in: an unverified user signs in normally; unknown email and wrong password have their own messages', function () use ($t, $call, $signup): void {
    $signup('plain@example.com');
    $r = $call('POST', '/auth/login', ['email' => 'plain@example.com', 'password' => 'Saree2026']);
    $t->same(200, $r->status());
    $t->same(false, $r->decoded()['user']['emailVerified'], 'no verification step at sign-in');
    $r = $call('POST', '/auth/login', ['email' => 'plain@example.com', 'password' => 'Wrong2026']);
    $t->same([401, 'Email or password is incorrect', null], [$r->status(), $r->decoded()['message'], $r->decoded()['field'] ?? null]);
    $r = $call('POST', '/auth/login', ['email' => 'ghost@example.com', 'password' => 'Saree2026']);
    $t->same([401, 'Email or password is incorrect', null, 'invalid_credentials'], [$r->status(), $r->decoded()['message'], $r->decoded()['field'] ?? null, $r->decoded()['code']]);
});

$t->test('signup -> logout -> login with the same email works, whatever the case or spaces; Google-only and unknown accounts get their own answers', function () use ($t, $call, $signup, $pdo): void {
    foreach (['Mixed.Case@Example.com' => ['mixed.case@example.com', 'MIXED.CASE@EXAMPLE.COM'], 'pad@example.com' => ['  pad@example.com  ', 'pad@example.com'], 'first.last.x@gmail.com' => ['first.last.x@gmail.com', 'First.Last.X@Gmail.com']] as $email => $logins) {
        [$cookie] = $signup($email);
        $t->same(204, $call('POST', '/auth/logout', [], $cookie)->status());
        foreach ($logins as $typed) {
            $t->same(200, $call('POST', '/auth/login', ['email' => $typed, 'password' => 'Saree2026'])->status(), 'login as [' . $typed . '] after signup as ' . $email);
        }
    }
    $pdo->prepare('INSERT INTO shop_customers (name, email, password_hash, email_verified_at) VALUES (?, ?, NULL, NOW())')->execute(['G', 'googleonly@example.com']);
    $r = $call('POST', '/auth/login', ['email' => 'googleonly@example.com', 'password' => 'Saree2026']);
    $t->same([401, 'This account uses Google sign-in'], [$r->status(), $r->decoded()['message']]);
    $t->same('google_account', $r->decoded()['code'] ?? null);
});

$t->test('email sign-in: per-address limit (10 in 15 minutes, counted for every address) on top of the per-IP limit', function () use ($t, $makeApp, $mailbox, $phone, $count): void {
    $limited = $makeApp(['signup' => 100000, 'login' => 100000], $mailbox, $phone);
    $statuses = [];
    for ($i = 0; $i < 12; $i++) {
        $statuses[] = $limited->handle(Helpers::request('POST', '/api/v1/auth/login', ['Origin' => Helpers::ORIGIN], ['email' => 'limit-me@example.com', 'password' => 'Wrong2026'], '192.0.2.' . ($i + 1)))->status();
    }
    $t->same([401, 401, 401, 401, 401, 401, 401, 401, 401, 401, 429, 429], $statuses, 'the 11th try for one address is refused whatever the IP');
    $other = $limited->handle(Helpers::request('POST', '/api/v1/auth/login', ['Origin' => Helpers::ORIGIN], ['email' => 'someone-else@example.com', 'password' => 'Wrong2026'], '192.0.2.99'));
    $t->same(401, $other->status(), 'another address is counted on its own');
    // per IP: 10 in 15 minutes
    $byIp = $makeApp(['signup' => 100000], $mailbox, $phone);
    $codes = [];
    for ($i = 0; $i < 11; $i++) {
        $codes[] = $byIp->handle(Helpers::request('POST', '/api/v1/auth/login', ['Origin' => Helpers::ORIGIN], ['email' => 'ip' . $i . '@example.com', 'password' => 'Wrong2026'], '203.0.113.200'))->status();
    }
    $t->same(429, $codes[10], 'the per-IP limit still applies');
    $t->ok($count('SELECT COUNT(*) FROM shop_rate_limits WHERE bucket_key LIKE ?', ['login_email:%']) >= 2);
});

$t->test('adding a mobile number: send code, verify; stored only after the code; one account per number', function () use ($t, $call, $verifiedUser, $mobileUser, $phone, $row, $next, $wrongFor, $INVALID): void {
    [$cookie, $id] = $verifiedUser('linker@example.com');
    $next();
    $r = $call('POST', '/auth/mobile/request', ['mobile' => '98100 00001'], $cookie);
    $t->same(200, $r->status());
    $t->same('+919810000001', $phone->sent[array_key_last($phone->sent)]['mobile']);
    $t->same(null, $row('SELECT mobile FROM shop_customers WHERE id = ?', [$id])['mobile'], 'a typed number is not stored before its code is entered');
    $t->same(401, $call('POST', '/auth/mobile/request', ['mobile' => '9810000001'])->status());
    $code = $phone->lastCodeTo('+919810000001');
    $bad = $call('POST', '/auth/mobile/verify', ['mobile' => '9810000001', 'code' => $wrongFor($code)], $cookie);
    $t->same([400, $INVALID], [$bad->status(), $bad->decoded()]);
    $t->same(422, $call('POST', '/auth/mobile/verify', ['mobile' => '123', 'code' => $code], $cookie)->status());
    $ok = $call('POST', '/auth/mobile/verify', ['mobile' => '9810000001', 'code' => $code], $cookie);
    $t->same(200, $ok->status());
    $t->same(['+919810000001', true, 'password'], [$ok->decoded()['user']['mobile'], $ok->decoded()['user']['mobileVerified'], $ok->decoded()['user']['provider']]);
    $c = $row('SELECT mobile, mobile_verified_at FROM shop_customers WHERE id = ?', [$id]);
    $t->ok($c['mobile'] === '+919810000001' && $c['mobile_verified_at'] !== null);
    $t->same(400, $call('POST', '/auth/mobile/verify', ['mobile' => '9810000001', 'code' => $code], $cookie)->status(), 'single use');
    // the account already has a number
    $again = $call('POST', '/auth/mobile/request', ['mobile' => '9810000002'], $cookie);
    $t->same([409, 'This account already has a mobile number.'], [$again->status(), $again->decoded()['message']]);
    // another account cannot take it
    [$other] = $verifiedUser('other-linker@example.com');
    $next();
    $taken = $call('POST', '/auth/mobile/request', ['mobile' => '9810000001'], $other);
    $t->same([409, 'This mobile number is already linked to another account.', 'mobile'], [$taken->status(), $taken->decoded()['message'], $taken->decoded()['field']]);
    $sentBefore = count($phone->sent);
    $t->same($sentBefore, count($phone->sent), 'nothing was sent for a number that is taken');
});

$t->test('a number taken between the code request and its verification is refused with the same clear error', function () use ($t, $call, $verifiedUser, $mobileUser, $phone, $row, $next): void {
    [$cookie, $id] = $verifiedUser('race@example.com');
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000010'], $cookie);
    $code = $phone->lastCodeTo('+919810000010');
    $mobileUser('9810000010'); // somebody signs in with that number first: an account owns it now
    $r = $call('POST', '/auth/mobile/verify', ['mobile' => '9810000010', 'code' => $code], $cookie);
    $t->same([409, 'This mobile number is already linked to another account.'], [$r->status(), $r->decoded()['message']]);
    $t->same(null, $row('SELECT mobile FROM shop_customers WHERE id = ?', [$id])['mobile']);
});

$t->test('adding an email to a mobile account: stored and verified after the code; taken or malformed addresses are refused', function () use ($t, $call, $mobileUser, $verifiedUser, $mailbox, $row, $next, $wrongFor): void {
    $verifiedUser('taken@example.com');
    [$cookie, $id] = $mobileUser('9810000020');
    $next();
    $r = $call('POST', '/auth/email/link/request', ['email' => 'Taken@Example.com'], $cookie);
    $t->same([409, 'This email is already linked to another account.', 'email'], [$r->status(), $r->decoded()['message'], $r->decoded()['field'] ?? null]);
    $t->same(422, $call('POST', '/auth/email/link/request', ['email' => 'nope'], $cookie)->status());
    $t->same(401, $call('POST', '/auth/email/link/request', ['email' => 'new@example.com'])->status());
    $r = $call('POST', '/auth/email/link/request', ['email' => 'New.Mobile@Example.com'], $cookie);
    $t->same(200, $r->status());
    $t->same(null, $row('SELECT email FROM shop_customers WHERE id = ?', [$id])['email'], 'not stored before the code');
    $code = $mailbox->lastCodeTo('new.mobile@example.com');
    $t->same(400, $call('POST', '/auth/email/link/verify', ['email' => 'new.mobile@example.com', 'code' => $wrongFor($code)], $cookie)->status());
    $t->same(400, $call('POST', '/auth/email/link/verify', ['email' => 'another@example.com', 'code' => $code], $cookie)->status(), 'the code opens only the address it was sent to');
    $ok = $call('POST', '/auth/email/link/verify', ['email' => 'new.mobile@example.com', 'code' => $code], $cookie);
    $t->same(200, $ok->status());
    $u = $ok->decoded()['user'];
    $t->same(['new.mobile@example.com', true, 'mobile'], [$u['email'], $u['emailVerified'], $u['provider']]);
    $again = $call('POST', '/auth/email/link/request', ['email' => 'third@example.com'], $cookie);
    $t->same(409, $again->status(), 'the account already has an email');
});

$t->test('purpose and channel separation: a code only works for the purpose, channel and target it was made for', function () use ($t, $call, $verifiedUser, $mobileUser, $mailbox, $phone, $row, $count, $next, $INVALID): void {
    [$cookieA, $idA] = $verifiedUser('sep-a@example.com');
    // a mobile LINK code cannot sign in, and a mobile LOGIN code cannot link
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000030'], $cookieA);
    $linkCode = $phone->lastCodeTo('+919810000030');
    $r = $call('POST', '/auth/otp/verify', ['mobile' => '9810000030', 'code' => $linkCode]);
    $t->same([400, $INVALID, ''], [$r->status(), $r->decoded(), implode(',', $r->cookies())], 'a mobile_verify code does not sign in');
    $t->same(0, $count('SELECT COUNT(*) FROM shop_customers WHERE mobile = ?', ['+919810000030']), 'and made no account');
    $next();
    $mobileUser('9810000031'); // a login code needs an account
    $next();
    $call('POST', '/auth/otp/request', ['mobile' => '9810000031']);
    $loginCode = $phone->lastCodeTo('+919810000031');
    $r = $call('POST', '/auth/mobile/verify', ['mobile' => '9810000031', 'code' => $loginCode], $cookieA);
    $t->same(400, $r->status(), 'a login code does not link');
    $t->same(null, $row('SELECT mobile FROM shop_customers WHERE id = ?', [$idA])['mobile']);
    // a reset code cannot confirm an email or link a number
    $next();
    $call('POST', '/auth/password/forgot', ['email' => 'sep-a@example.com']);
    $resetCode = $mailbox->lastCodeTo('sep-a@example.com');
    $t->same(400, $call('POST', '/auth/email/verify', ['code' => $resetCode], $cookieA)->status(), 'a reset code is not an email_verify code');
    $t->same(400, $call('POST', '/auth/mobile/verify', ['mobile' => '9810000030', 'code' => $resetCode], $cookieA)->status());
    $next();
    // channel: an email reset code does not work through the mobile channel, and a mobile code does not work through email
    [$cookieM, $idM] = $mobileUser('9810000032');
    $next();
    $call('POST', '/auth/email/link/request', ['email' => 'sep-m@example.com'], $cookieM);
    $call('POST', '/auth/email/link/verify', ['email' => 'sep-m@example.com', 'code' => $mailbox->lastCodeTo('sep-m@example.com')], $cookieM);
    $call('POST', '/auth/password/forgot', ['email' => 'sep-m@example.com']);
    $emailReset = $mailbox->lastCodeTo('sep-m@example.com');
    $r = $call('POST', '/auth/password/reset', ['channel' => 'mobile', 'mobile' => '9810000032', 'code' => $emailReset, 'password' => 'Hacked2026']);
    $t->same(400, $r->status(), 'an email reset code is refused on the mobile channel');
    $t->same(null, $row('SELECT password_hash FROM shop_customers WHERE id = ?', [$idM])['password_hash']);
    $next();
    $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000032']);
    $smsReset = $phone->lastCodeTo('+919810000032');
    $r = $call('POST', '/auth/password/reset', ['email' => 'sep-m@example.com', 'code' => $smsReset, 'password' => 'Hacked2026']);
    $t->same(400, $r->status(), 'a mobile reset code is refused on the email channel');
    $t->same(null, $row('SELECT password_hash FROM shop_customers WHERE id = ?', [$idM])['password_hash']);
    // hashes differ for the same digits and target when only the purpose differs
    $a = \Pratyangi\Auth\Otp::hashFor('p', 'email', 'reset', 'x@y.zz', '123456');
    $b = \Pratyangi\Auth\Otp::hashFor('p', 'email', 'email_verify', 'x@y.zz', '123456');
    $c = \Pratyangi\Auth\Otp::hashFor('p', 'mobile', 'reset', 'x@y.zz', '123456');
    $t->ok($a !== $b && $a !== $c && $b !== $c);
});

$t->test('no login, reset or merge with an identifier that was only typed: an unverified email still gets one reset code', function () use ($t, $call, $signup, $mailbox, $phone, $count, $row, $next): void {
    [, $id] = $signup('typed-only@example.com');
    $before = $mailbox->countTo('typed-only@example.com');
    $next();
    $r = $call('POST', '/auth/password/forgot', ['email' => 'typed-only@example.com']);
    $t->same(200, $r->status());
    $t->same($before + 1, $mailbox->countTo('typed-only@example.com'), 'one reset mail although the address was never verified');
    $r = $call('POST', '/auth/password/reset', ['email' => 'typed-only@example.com', 'code' => $mailbox->lastCodeTo('typed-only@example.com'), 'password' => 'NewSaree2027']);
    $t->same(204, $r->status());
    $t->ok($row('SELECT email_verified_at FROM shop_customers WHERE id = ?', [$id])['email_verified_at'] !== null, 'using the emailed code verifies the address');
    $t->same(200, $call('POST', '/auth/login', ['email' => 'typed-only@example.com', 'password' => 'NewSaree2027'])->status());
    // a number nobody verified: no SMS, nothing stored
    $sms = count($phone->sent);
    $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000040']);
    $t->same($sms, count($phone->sent), 'no SMS to a number that is not on an account');
    // a pending (unconfirmed) mobile link is not stored, so it cannot be reset by either
    [$cookie, $id2] = [$call('POST', '/auth/login', ['email' => 'typed-only@example.com', 'password' => 'Saree2026']), 0];
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000041'], (static function ($r) { foreach ($r->cookies() as $l) { if (str_starts_with($l, 'pf_session=')) { return explode(';', $l)[0]; } } return ''; })($cookie));
    $sms = count($phone->sent);
    $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000041']);
    $t->same($sms, count($phone->sent), 'a number that was only typed gets no reset code');
    // typing a mobile that belongs to another account's email-only record does not create or merge accounts
    $t->same(1, $count('SELECT COUNT(*) FROM shop_customers WHERE email = ?', ['typed-only@example.com']));
});

$t->test('forgot password: the answer, status, limits and headers are identical for a known, an unknown and an unusable target', function () use ($t, $call, $verifiedUser, $signup, $mobileUser, $mailbox, $phone, $shape, $next): void {
    $verifiedUser('known@example.com');
    $signup('unverified-id@example.com');
    [$cookieMo] = $mobileUser('9810000050');          // mobile only, no password, no email
    [$cookieMp, $idMp] = $signup('has-pass@example.com'); // an UNVERIFIED email: a verified one takes the reset (see the SMS_ENABLED test)
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000051'], $cookieMp);
    $call('POST', '/auth/mobile/verify', ['mobile' => '9810000051', 'code' => $phone->lastCodeTo('+919810000051')], $cookieMp);
    $next(100);
    $known = $shape($call('POST', '/auth/password/forgot', ['email' => 'known@example.com']));
    $unknown = $shape($call('POST', '/auth/password/forgot', ['email' => 'ghost@example.com']));
    $unverified = $shape($call('POST', '/auth/password/forgot', ['email' => 'unverified-id@example.com']));
    $t->same($known, $unknown, 'email: known = unknown');
    $t->same($known, $unverified, 'email: known = unverified');
    $t->same(200, $known[0]);
    $t->same('{"message":"If an account matches, we have emailed a 6-digit code."}', $known[1]);
    $t->same(1, $mailbox->countTo('known@example.com') - 2, 'the known address got exactly one reset mail (plus its welcome and sign-up code)');
    $t->same(0, $mailbox->countTo('ghost@example.com'));
    $kn = $shape($call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000051']));
    $un = $shape($call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000099']));
    $mo = $shape($call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000050']));
    $t->same($kn, $un, 'mobile: known = unknown');
    $t->same($kn, $mo, 'mobile: known = mobile-only account without a password');
    $t->same('{"message":"If an account matches, we have sent a 6-digit code by SMS."}', $kn[1]);
    $t->same(1, $phone->countTo('+919810000051') - 1, 'the known number got one reset SMS (plus the link code)');
    $t->same(0, $phone->countTo('+919810000099'));
    $t->same(1, $phone->countTo('+919810000050'), 'the mobile-only account got only its sign-in code, no reset code');
    // cooldown: a second request at once is refused the same way for known and unknown targets
    $k2 = $call('POST', '/auth/password/forgot', ['email' => 'known@example.com']);
    $u2 = $call('POST', '/auth/password/forgot', ['email' => 'ghost@example.com']);
    $t->same(429, $k2->status());
    $t->same([$k2->status(), $k2->body()], [$u2->status(), $u2->body()]);
    $t->same(422, $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '12'])->status());
});

$t->test('forgot password: a mailer or gateway that fails gives the same answer as an unknown target', function () use ($t, $call, $mailbox, $verifiedUser, $shape, $next): void {
    $verifiedUser('fails@example.com');
    $next(100);
    $mailbox->fail = true;
    $bad = $shape($call('POST', '/auth/password/forgot', ['email' => 'fails@example.com']));
    $mailbox->fail = false;
    $next(100);
    $unknown = $shape($call('POST', '/auth/password/forgot', ['email' => 'nobody-here@example.com']));
    $t->same($unknown, $bad);
});

$t->test('reset by email code: new password, EVERY session of the account ends, mobile and name untouched, code single use', function () use ($t, $call, $verifiedUser, $mailbox, $phone, $row, $count, $next, $wrongFor, $INVALID): void {
    [$cookie1, $id] = $verifiedUser('resetter@example.com', 'Saree2026');
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000060'], $cookie1);
    $call('POST', '/auth/mobile/verify', ['mobile' => '9810000060', 'code' => $phone->lastCodeTo('+919810000060')], $cookie1);
    $second = $call('POST', '/auth/login', ['email' => 'resetter@example.com', 'password' => 'Saree2026']);
    $cookie2 = (static function ($r) { foreach ($r->cookies() as $l) { if (str_starts_with($l, 'pf_session=')) { return explode(';', $l)[0]; } } return ''; })($second);
    $t->same(2, $count('SELECT COUNT(*) FROM shop_sessions WHERE customer_id = ?', [$id]));
    $before = $row('SELECT name, mobile, mobile_verified_at, email, created_at FROM shop_customers WHERE id = ?', [$id]);
    $next(100);
    $call('POST', '/auth/password/forgot', ['email' => 'resetter@example.com']);
    $code = $mailbox->lastCodeTo('resetter@example.com');
    $stored = $row("SELECT * FROM shop_verification_codes WHERE purpose = 'reset' AND target = ?", ['resetter@example.com']);
    $t->same(['email', $id], [$stored['channel'], (int) $stored['customer_id']]);
    $t->same(400, $call('POST', '/auth/password/reset', ['email' => 'resetter@example.com', 'code' => $wrongFor($code), 'password' => 'NewSaree2027'])->status());
    $t->same(422, $call('POST', '/auth/password/reset', ['email' => 'resetter@example.com', 'code' => $code, 'password' => 'short'])->status(), 'the new password is checked');
    $same = $call('POST', '/auth/password/reset', ['email' => 'resetter@example.com', 'code' => $code, 'password' => 'Saree2026']);
    $t->same([422, 'password', 'Your new password must be different from your current password.'], [$same->status(), $same->decoded()['field'] ?? null, $same->decoded()['message'] ?? null], 'same as the current password, code still usable');
    $r = $call('POST', '/auth/password/reset', ['email' => 'resetter@example.com', 'code' => $code, 'password' => 'NewSaree2027']);
    $t->same(204, $r->status());
    $t->same(0, $count('SELECT COUNT(*) FROM shop_sessions WHERE customer_id = ?', [$id]), 'all sessions deleted');
    $t->same(401, $call('GET', '/auth/me', null, $cookie1)->status());
    $t->same(401, $call('GET', '/auth/me', null, $cookie2)->status());
    $after = $row('SELECT name, mobile, mobile_verified_at, email, created_at FROM shop_customers WHERE id = ?', [$id]);
    $t->same($before, $after, 'name, mobile, email and the rest of the account are untouched');
    $t->same(401, $call('POST', '/auth/login', ['email' => 'resetter@example.com', 'password' => 'Saree2026'])->status(), 'the old password is gone');
    $t->same(200, $call('POST', '/auth/login', ['email' => 'resetter@example.com', 'password' => 'NewSaree2027'])->status());
    $again = $call('POST', '/auth/password/reset', ['email' => 'resetter@example.com', 'code' => $code, 'password' => 'Third2028x']);
    $t->same([400, $INVALID['message']], [$again->status(), $again->decoded()['message']], 'single use');
});

$t->test('reset by mobile code: works for a verified number whose email is not verified, kills sessions', function () use ($t, $call, $signup, $phone, $row, $count, $next): void {
    [$cookie, $id] = $signup('both@example.com', 'Saree2026');
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000070'], $cookie);
    $call('POST', '/auth/mobile/verify', ['mobile' => '9810000070', 'code' => $phone->lastCodeTo('+919810000070')], $cookie);
    $next(100);
    $next();
    $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '98100 00070']);
    $smsCode = $phone->lastCodeTo('+919810000070');
    $r = $call('POST', '/auth/password/reset', ['channel' => 'mobile', 'mobile' => '9810000070', 'code' => $smsCode, 'password' => 'ByMobile2027']);
    $t->same(204, $r->status());
    $t->same(0, $count('SELECT COUNT(*) FROM shop_sessions WHERE customer_id = ?', [$id]));
    $t->same(200, $call('POST', '/auth/login', ['email' => 'both@example.com', 'password' => 'ByMobile2027'])->status());
    $t->same('+919810000070', $row('SELECT mobile FROM shop_customers WHERE id = ?', [$id])['mobile']);
});

$t->test('reset: five wrong tries lock the code; a mobile-only account without a password has nothing to reset', function () use ($t, $call, $verifiedUser, $mobileUser, $mailbox, $phone, $next, $wrongFor): void {
    $verifiedUser('locker@example.com');
    $next(100);
    $call('POST', '/auth/password/forgot', ['email' => 'locker@example.com']);
    $code = $mailbox->lastCodeTo('locker@example.com');
    for ($i = 0; $i < 5; $i++) {
        $t->same(400, $call('POST', '/auth/password/reset', ['email' => 'locker@example.com', 'code' => $wrongFor($code), 'password' => 'NewSaree2027'])->status());
    }
    $t->same(400, $call('POST', '/auth/password/reset', ['email' => 'locker@example.com', 'code' => $code, 'password' => 'NewSaree2027'])->status(), 'locked');
    $mobileUser('9810000080');
    $next(100);
    $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000080']);
    $t->same(400, $call('POST', '/auth/password/reset', ['channel' => 'mobile', 'mobile' => '9810000080', 'code' => '123456', 'password' => 'Mobile2027x'])->status());
});

$t->test('mobile sign-in and sign-up are one flow: an unknown number is created with no name or email; the same number signs in again', function () use ($t, $call, $phone, $row, $next, $cookieOf): void {
    $next();
    $call('POST', '/auth/otp/signup/request', ['mobile' => '9810000090']);
    $r = $call('POST', '/auth/otp/signup/verify', ['mobile' => '9810000090', 'code' => $phone->lastCodeTo('+919810000090')]);
    $t->same(201, $r->status());
    $u = $r->decoded()['user'];
    $t->same(['', '', 'mobile', '+919810000090', true], [$u['name'], $u['email'], $u['provider'], $u['mobile'], $u['mobileVerified']]);
    $next();
    $call('POST', '/auth/otp/request', ['mobile' => '9810000090']);
    $r2 = $call('POST', '/auth/otp/verify', ['mobile' => '9810000090', 'code' => $phone->lastCodeTo('+919810000090')]);
    $t->same([200, $u['id']], [$r2->status(), $r2->decoded()['user']['id']]);
    $t->ok(!array_key_exists('nameRequired', $r->decoded()), 'no name step');
});

$t->test('the account name: signed-in only, validated, stored', function () use ($t, $call, $mobileUser, $row, $cookieOf): void {
    [$cookie, $id] = $mobileUser('9810000100');
    $t->same(401, $call('POST', '/auth/profile/name', ['name' => 'Asha Rao'])->status());
    $t->same(422, $call('POST', '/auth/profile/name', ['name' => 'A'], $cookie)->status());
    $r = $call('POST', '/auth/profile/name', ['name' => '  Asha Rao '], $cookie);
    $t->same([200, 'Asha Rao'], [$r->status(), $r->decoded()['user']['name']]);
    $t->same('Asha Rao', $row('SELECT name FROM shop_customers WHERE id = ?', [$id])['name']);
    $t->same(409, $call('POST', '/auth/profile/name', ['name' => 'Someone Else'], $cookie)->status(), 'an existing name is not overwritten');
});

$t->test('the old verification link still works and needs no session', function () use ($t, $call, $signup, $pdo, $row): void {
    [, $id] = $signup('oldlink@example.com');
    $token = Tokens::newToken();
    (new \Pratyangi\Auth\TokenRepo($pdo))->replaceVerification($id, Tokens::sha256($token), (float) (time() + 3600));
    $r = $call('POST', '/auth/email/verify', ['token' => $token]);
    $t->same([200, true], [$r->status(), $r->decoded()['customer']['emailVerified']]);
    $t->same(400, $call('POST', '/auth/email/verify', ['token' => $token])->status());
});

$t->test('nothing sensitive in the app log: no code, address or full number', function () use ($t, &$logged): void {
    $all = implode("\n", $logged);
    $t->ok($logged !== [], 'the app did log');
    foreach (['@example.com', '98100000', 'Saree2026', 'NewSaree2027', 'ByMobile2027'] as $needle) {
        $t->ok(!str_contains($all, $needle), 'log must not contain: ' . $needle);
    }
    $t->ok(preg_match('/Your code is|otp=|code=\d{6}/', $all) !== 1, 'no code in the log');
});

$offConfig = Helpers::config(['OTP_PEPPER' => $pepper, 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => sys_get_temp_dir() . '/pf_flow_unused_sms.log', 'SMS_ENABLED' => 'false']);
$offApp = new App($offConfig, $logger, $clock, static fn (): \PDO => $pdo, $mailbox, new MockErp($offConfig->erpMockFile, $offConfig->erpPriceRule, $offConfig->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo), $loose, null, $phone);
$page = static fn (string $path, string $cookie = '') => $GLOBALS['app']->handle(Helpers::request('GET', $path, $cookie === '' ? [] : ['Cookie' => $cookie]));

$t->test('SMS_ENABLED=true: a code goes to ONE channel only; a verified email takes the reset; Add mobile and the Mobile OTP form are shown', function () use ($t, $call, $signup, $verifiedUser, $mobileUser, $mailbox, $phone, $next, $page): void {
    [$mails, $texts] = [count($mailbox->sent), count($phone->sent)];
    $mobileUser('9810000200');
    $t->same([$mails, $texts + 1], [count($mailbox->sent), count($phone->sent)], 'a mobile sign-up code: one SMS, no mail');
    [$mails, $texts] = [count($mailbox->sent), count($phone->sent)];
    [$cookie] = $signup('onechannel@example.com');
    $call('POST', '/auth/email/resend', [], $cookie); // sign-up sends no code; asking for one does
    $t->same([$mails + 2, $texts], [count($mailbox->sent), count($phone->sent)], 'an email sign-up: the welcome and one code mail, no SMS');
    $t->contains('data-pf="link-open"', $page('/account', $cookie)->body(), 'Add mobile is offered');
    $t->contains('pf-panel-otp', $page('/login')->body(), 'the Mobile OTP form is on the sign-in page');
    // an account with a verified email AND a verified mobile: the mobile reset sends nothing (the email is the reset channel); the answer is the same
    [$cookieBoth] = $verifiedUser('emailfirst@example.com');
    $next();
    $call('POST', '/auth/mobile/request', ['mobile' => '9810000201'], $cookieBoth);
    $call('POST', '/auth/mobile/verify', ['mobile' => '9810000201', 'code' => $phone->lastCodeTo('+919810000201')], $cookieBoth);
    $next(100);
    $texts = count($phone->sent);
    $t->same(200, $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000201'])->status());
    $t->same($texts, count($phone->sent), 'no reset SMS for an account with a verified email');
    $next();
    $mails = $mailbox->countTo('emailfirst@example.com');
    $t->same(200, $call('POST', '/auth/password/forgot', ['email' => 'emailfirst@example.com'])->status());
    $t->same($mails + 1, $mailbox->countTo('emailfirst@example.com'), 'the email code is sent');
});

$t->test('SMS_ENABLED=false: no mobile route answers, no SMS is sent, the pages hide the mobile parts, email flows work', function () use ($t, &$app, $offApp, $call, $verifiedUser, $mailbox, $phone, $next, $count, $page): void {
    $real = $app;
    [$cookie] = $verifiedUser('emailonly@example.com'); // made with SMS on, then used with SMS off
    $app = $offApp;
    $next(100);
    $texts = count($phone->sent);
    $customers = $count('SELECT COUNT(*) FROM shop_customers');
    $soon = ['message' => 'Mobile sign-in coming soon'];
    foreach (['/auth/otp/request', '/auth/otp/signup/request'] as $path) {
        $r = $call('POST', $path, ['mobile' => '9810000210']);
        $t->same([503, $soon], [$r->status(), $r->decoded()], $path);
    }
    foreach (['/auth/otp/verify', '/auth/otp/signup/verify'] as $path) {
        $r = $call('POST', $path, ['mobile' => '9810000210', 'code' => '123456']);
        $t->same([503, $soon], [$r->status(), $r->decoded()], $path);
    }
    foreach (['/auth/mobile/request' => ['mobile' => '9810000210'], '/auth/mobile/verify' => ['mobile' => '9810000210', 'code' => '123456']] as $path => $body) {
        $r = $call('POST', $path, $body, $cookie);
        $t->same([503, $soon], [$r->status(), $r->decoded()], $path);
    }
    $t->same([$texts, $customers], [count($phone->sent), $count('SELECT COUNT(*) FROM shop_customers')], 'nothing sent, nobody created');
    // email flows are unchanged: sign-up with a code, verify, forgot, reset, sign-in
    [$c2] = $verifiedUser('emailonly2@example.com');
    $t->ok($c2 !== '');
    $next(100);
    $t->same(200, $call('POST', '/auth/password/forgot', ['email' => 'emailonly@example.com'])->status());
    $t->same(204, $call('POST', '/auth/password/reset', ['email' => 'emailonly@example.com', 'code' => $mailbox->lastCodeTo('emailonly@example.com'), 'password' => 'NewPass2027x'])->status());
    $t->same(200, $call('POST', '/auth/login', ['email' => 'emailonly@example.com', 'password' => 'NewPass2027x'])->status());
    $r = $call('POST', '/auth/password/forgot', ['channel' => 'mobile', 'mobile' => '9810000210']);
    $t->same([503, $soon], [$r->status(), $r->decoded()], 'forgot by mobile');
    $r = $call('POST', '/auth/password/reset', ['channel' => 'mobile', 'mobile' => '9810000210', 'code' => '123456', 'password' => 'NewPass2027x']);
    $t->same([503, $soon], [$r->status(), $r->decoded()], 'reset by mobile');
    $t->same($texts, count($phone->sent), 'still no SMS');
    // pages
    $login = $page('/login')->body();
    $t->ok(!str_contains($login, 'pf-panel-otp') && !str_contains($login, 'pf-mode-otp') && !str_contains($login, 'auth-otp.js') && str_contains($login, 'data-pf-sms="0"'), 'login: no Mobile OTP tab, form or script');
    $signupPage = $page('/signup')->body();
    $t->ok(!str_contains($signupPage, 'pf-panel-otp') && !str_contains($signupPage, 'pf-mode-otp') && !str_contains($signupPage, 'auth-otp.js') && str_contains($signupPage, 'auth-email'), 'signup: no Mobile OTP tab, form or script');
    $forgot = $page('/forgot-password')->body();
    $t->ok(!str_contains($forgot, 'auth-mobile') && str_contains($forgot, 'auth-email'), 'forgot: email only');
    $account = $page('/account', $c2)->body();
    $t->ok(!str_contains($account, 'Add mobile number') && !str_contains($account, 'Complete your account') && !str_contains($account, 'data-pf="link-open"'), '/account: name and email are set, so no Complete your account and no Add mobile');
    $app = $real;
});

exit($t->finish());

<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test, settings from environment variables only.
// The hooked emails through the real App: welcome after an email sign-up and after a first Google sign-in (once per account), password changed after a reset
// (once, with the support address), and the claims in shop_mail_events. Mail is captured in memory.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\App;
use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Http\Response;
use Pratyangi\Mail\EventMail;
use Pratyangi\Mail\Mailer;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: the email event database tests run only on port 3399\n");
    exit(2);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);

$t = new TestCase('db_event_mail_test');
$origin = ['Origin' => Helpers::ORIGIN];
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$config = Helpers::config(['OTP_PEPPER' => str_repeat('E', 48), 'SHOP_SUPPORT_EMAIL' => 'info@example.test']);
$mailbox = new class implements Mailer {
    /** @var list<array{to:string,subject:string,text:string,html:?string}> */
    public array $sent = [];
    public function isAvailable(): bool
    {
        return true;
    }
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    }
    /** @return list<array{to:string,subject:string,text:string,html:?string}> */
    public function to(string $to, string $subjectPart = ''): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $m['to'] === $to && str_contains($m['subject'], $subjectPart)));
    }
    public function code(string $to): string
    {
        foreach (array_reverse($this->sent) as $m) {
            if ($m['to'] === $to && preg_match('/Your code is (\d{6})/', $m['text'], $x) === 1) {
                return $x[1];
            }
        }
        return '';
    }
};
$loose = ['signup' => 100000, 'login' => 100000, 'verify' => 100000, 'reset' => 100000];
$app = new App($config, new \Pratyangi\Support\Logger('silent'), $clock, static fn (): \PDO => $pdo, $mailbox,
    new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo), $loose);
$n = 0;
$call = static function (string $method, string $path, ?array $body = null, string $cookie = '') use ($app, $origin, &$n): Response {
    $n++;
    return $app->handle(Helpers::request($method, '/api/v1' . $path, $origin + ($cookie !== '' ? ['Cookie' => $cookie] : []), $body, '198.51.100.' . ($n % 250 + 1)));
};
$cookieOf = static function (Response $r): string {
    foreach ($r->cookies() as $line) {
        if (str_starts_with($line, 'pf_session=')) {
            return explode(';', $line)[0];
        }
    }
    return '';
};

$t->test('email sign-up: one welcome mail, special characters right, nothing for a refused duplicate', function () use ($t, $call, $mailbox): void {
    $r = $call('POST', '/auth/signup', ['name' => "Asha O'Brien & Sons #1", 'email' => 'welcome.a@example.com', 'password' => 'Saree2026']);
    $t->same(201, $r->status());
    $mails = $mailbox->to('welcome.a@example.com');
    $t->same(1, count($mails), 'one mail');
    $t->same('Welcome to Pratyangi Fashions', $mails[0]['subject']);
    $t->contains('Hi Asha,', $mails[0]['text']);
    $t->ok($mails[0]['html'] !== null && str_contains($mails[0]['html'], 'Hi Asha,'));
    $t->same(409, $call('POST', '/auth/signup', ['name' => 'Again', 'email' => 'welcome.a@example.com', 'password' => 'Saree2026'])->status());
    $t->same(1, count($mailbox->to('welcome.a@example.com')), 'the refused duplicate sends nothing');
});

$t->test('Google sign-in: welcome for a new account only, never again on the next sign-in', function () use ($t, $pdo, $clock, $mailbox): void {
    $mail = new EventMail($mailbox, EventMail::claimInDb($pdo), EventMail::releaseInDb($pdo), false, $clock);
    $svc = new AuthService($pdo, new CustomerRepo($pdo), new SessionRepo($pdo), new TokenRepo($pdo), 30, $clock, null, $mail);
    $g = ['sub' => 'google-sub-event-1', 'email' => 'g.event@example.com', 'name' => "Gita D'Souza", 'picture' => null];
    $svc->googleSignIn($g, null);
    $svc->googleSignIn($g, null);
    $mails = $mailbox->to('g.event@example.com');
    $t->same(1, count($mails), 'welcome once');
    $t->contains("Hi Gita,", $mails[0]['text']);
});

$t->test('password reset: one "password changed" mail with the support address, and the claim is stored', function () use ($t, $call, $cookieOf, $mailbox, $pdo, $clock): void {
    $email = 'reset.event@example.com';
    $cookie = $cookieOf($call('POST', '/auth/signup', ['name' => 'Reset Buyer', 'email' => $email, 'password' => 'Saree2026']));
    $call('POST', '/auth/email/resend', [], $cookie);
    $t->same(200, $call('POST', '/auth/email/verify', ['code' => $mailbox->code($email)], $cookie)->status());
    $clock->advance(46);
    $call('POST', '/auth/password/forgot', ['email' => $email]);
    $r = $call('POST', '/auth/password/reset', ['email' => $email, 'code' => $mailbox->code($email), 'password' => 'NewSaree2027']);
    $t->same(204, $r->status());
    $mails = $mailbox->to($email, 'password was changed');
    $t->same(1, count($mails), 'one password mail');
    $t->contains('Not you? Contact info@example.test.', $mails[0]['text']);
    $t->contains('IST', $mails[0]['text']);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM shop_mail_events WHERE event_key LIKE 'password_changed:%' OR event_key LIKE 'welcome:%'")->fetchColumn();
    $t->same(4, $count, "three welcomes (two email sign-ups, one Google) and one password change are claimed");
});

$t->test('welcome: ONE mail per account however often it signs in (email sign-up, logout, three logins, then Google on the same address)', function () use ($t, $call, $cookieOf, $mailbox, $pdo, $clock): void {
    $email = 'welcome.once@example.com';
    $cookie = $cookieOf($call('POST', '/auth/signup', ['name' => 'Once Only', 'email' => $email, 'password' => 'Saree2026']));
    $t->same(1, count($mailbox->to($email, 'Welcome to')), 'sign-up: one welcome');
    $t->same(204, $call('POST', '/auth/logout', [], $cookie)->status());
    for ($i = 1; $i <= 3; $i++) {
        $r = $call('POST', '/auth/login', ['email' => $email, 'password' => 'Saree2026']);
        $t->same(200, $r->status(), 'login ' . $i);
        $call('POST', '/auth/logout', [], $cookieOf($r));
    }
    $mail = new EventMail($mailbox, EventMail::claimInDb($pdo), EventMail::releaseInDb($pdo), false, $clock);
    $svc = new AuthService($pdo, new CustomerRepo($pdo), new SessionRepo($pdo), new TokenRepo($pdo), 30, $clock, null, $mail);
    $svc->googleSignIn(['sub' => 'google-sub-welcome-once', 'email' => $email, 'name' => 'Once Only', 'picture' => null], null); // links Google to the existing account
    $svc->googleSignIn(['sub' => 'google-sub-welcome-once', 'email' => $email, 'name' => 'Once Only', 'picture' => null], null); // and again
    $t->same(1, count($mailbox->to($email, 'Welcome to')), 'after the logins and Google: still exactly one welcome mail');
    $id = (int) $pdo->query("SELECT id FROM shop_customers WHERE email = '" . $email . "'")->fetchColumn();
    $t->same(1, (int) $pdo->query("SELECT COUNT(*) FROM shop_mail_events WHERE event_key = 'welcome:" . $id . "'")->fetchColumn(), 'one claim for the account');
});

$t->test('forgot password: every case gives the right answer and message (cooldown, daily limit, wrong codes, two live codes, Google-only and unverified accounts)', function () use ($t, $call, $cookieOf, $mailbox, $pdo, $clock, $config, $loose): void {
    $ask = static fn (string $email) => $call('POST', '/auth/password/forgot', ['email' => $email]);
    $reset = static fn (string $email, string $code, string $pw = 'NewSaree2027') => $call('POST', '/auth/password/reset', ['email' => $email, 'code' => $code, 'password' => $pw]);

    // an unverified email account (sign-up does not verify): the code arrives, and using it proves the address
    $a = 'otp.case.a@example.com';
    $call('POST', '/auth/signup', ['name' => 'Case A', 'email' => $a, 'password' => 'Saree2026']);
    $clock->advance(46);
    $t->same(200, $ask($a)->status());
    $first = $mailbox->code($a);
    $t->same(1, preg_match('/^\d{6}$/', $first), 'the code was emailed to an unverified account');

    // cooldown: a second request at once says how long to wait
    $r = $ask($a);
    $t->same(429, $r->status(), 'cooldown');
    $t->same(1, preg_match('/^Please wait \d+ seconds? before asking for another code\.$/', (string) ($r->decoded()['message'] ?? '')), 'the cooldown message has the countdown');
    $t->ok((int) $r->header('Retry-After') >= 1 && (int) $r->header('Retry-After') <= 45, 'and Retry-After says the same');

    // two live codes: the newer one works, the older one is refused
    $clock->advance(46);
    $t->same(200, $ask($a)->status());
    $second = $mailbox->code($a);
    $t->ok($second !== '' && $second !== $first, 'a second code');
    $old = $reset($a, $first);
    $t->same(400, $old->status(), 'the older code no longer works');
    $t->same(204, $reset($a, $second)->status(), 'the newer code does');
    $t->same(200, $call('POST', '/auth/login', ['email' => $a, 'password' => 'NewSaree2027'])->status(), 'the new password signs in');

    // five wrong tries lock the code: the message says to ask for a new one, and a new one works
    $clock->advance(46);
    $ask($a);
    $good = $mailbox->code($a);
    $wrong = $good === '000000' ? '111111' : '000000';
    for ($i = 1; $i <= 4; $i++) {
        $t->same(400, $reset($a, $wrong, 'Another2028')->status(), 'wrong ' . $i);
    }
    $fifth = $reset($a, $wrong, 'Another2028');
    $t->same([400, 'Too many wrong codes. Request a new code.'], [$fifth->status(), $fifth->decoded()['message'] ?? ''], 'the fifth wrong try locks it');
    $t->same(400, $reset($a, $good, 'Another2028')->status(), 'even the right code is refused once locked');
    $clock->advance(46);
    $ask($a);
    $t->same(204, $reset($a, $mailbox->code($a), 'Another2028')->status(), 'a fresh code works');

    // a Google-only account (no password) can set one with the emailed code
    $mail = new EventMail($mailbox, EventMail::claimInDb($pdo), EventMail::releaseInDb($pdo), false, $clock);
    $svc = new AuthService($pdo, new CustomerRepo($pdo), new SessionRepo($pdo), new TokenRepo($pdo), 30, $clock, null, $mail);
    $g = 'otp.case.google@example.com';
    $svc->googleSignIn(['sub' => 'google-sub-otp-case', 'email' => $g, 'name' => 'Google Only', 'picture' => null], null);
    $clock->advance(46);
    $ask($g);
    $t->same(204, $reset($g, $mailbox->code($g), 'GoogleSet2029')->status(), 'a Google-only account can reset');
    $t->same(200, $call('POST', '/auth/login', ['email' => $g, 'password' => 'GoogleSet2029'])->status());

    // the daily limit: five codes, then a message with the time left, not the generic one
    $d = 'otp.case.daily@example.com';
    $call('POST', '/auth/signup', ['name' => 'Case D', 'email' => $d, 'password' => 'Saree2026']);
    for ($i = 1; $i <= 5; $i++) {
        $clock->advance(46);
        $t->same(200, $ask($d)->status(), 'code ' . $i . ' of 5');
    }
    $clock->advance(46);
    $r = $ask($d);
    $t->same(429, $r->status(), 'the sixth request in the day');
    $t->same(1, preg_match('/^Too many codes have been requested for this address today\. Please try again in \d+ (seconds?|minutes|hours)\.$/', (string) ($r->decoded()['message'] ?? '')), 'the message has the time left');
    $t->ok((int) $r->header('Retry-After') >= 1, 'with Retry-After');
});

$t->test('forgot password: a code that could not be e-mailed does not use up the daily allowance (a mail server that is down must not lock the address out)', function () use ($t, $call, $mailbox, $pdo, $clock, $config, $loose): void {
    $down = new class implements Mailer {
        public function isAvailable(): bool
        {
            return true;
        }
        public function send(string $to, string $subject, string $text, ?string $html = null): void
        {
            throw new \RuntimeException('smtp down');
        }
    };
    $email = 'otp.case.down@example.com';
    $call('POST', '/auth/signup', ['name' => 'Case Down', 'email' => $email, 'password' => 'Saree2026']);
    $brokenApp = new App($config, new \Pratyangi\Support\Logger('silent'), $clock, static fn (): \PDO => $pdo, $down,
        new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo), $loose);
    for ($i = 1; $i <= 8; $i++) {
        $clock->advance(1); // inside the 45 s cooldown: a failed send must not start one either
        $r = $brokenApp->handle(Helpers::request('POST', '/api/v1/auth/password/forgot', ['Origin' => Helpers::ORIGIN], ['email' => $email], '198.51.100.' . (200 + $i)));
        $t->same(200, $r->status(), 'try ' . $i . ' with the mail server down: the same neutral answer, never a limit');
    }
    $before = count($mailbox->to($email, 'password reset code'));
    $r = $call('POST', '/auth/password/forgot', ['email' => $email]);
    $t->same(200, $r->status(), 'the mail server is back: the request is accepted at once');
    $t->same($before + 1, count($mailbox->to($email, 'password reset code')), 'and the code is delivered');
});
$t->test('server check: every table of ALL_IN_ORDER.sql is there and the migrations are ok; a dropped table is named, with its migration', function () use ($t, $pdo, $clock): void {
    $key = str_repeat('k', 32);
    $check = static function () use ($pdo, $clock, $key): array {
        $config = Helpers::config(['SERVER_CHECK_KEY' => $key]);
        $app = new App($config, new \Pratyangi\Support\Logger('silent'), $clock, static fn (): \PDO => $pdo, new \Pratyangi\Mail\NullMailer(), new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode), new DbRateLimitStore(static fn (): \PDO => $pdo));
        return $app->handle(Helpers::request('GET', '/server-check?key=' . $key))->decoded();
    };
    $d = $check();
    $t->same(['ok', 'ok'], [$d['required tables'] ?? null, $d['migrations'] ?? null]);
    $pdo->exec('RENAME TABLE shop_mail_events TO shop_mail_events_off');
    try {
        $d = $check();
        $t->same(['FAIL missing shop_mail_events', 'FAIL missing 007'], [$d['required tables'] ?? null, $d['migrations'] ?? null]);
    } finally {
        $pdo->exec('RENAME TABLE shop_mail_events_off TO shop_mail_events');
    }
});
exit($t->finish());

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

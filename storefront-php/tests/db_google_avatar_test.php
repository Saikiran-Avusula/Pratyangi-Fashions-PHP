<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway, port 3399, *_test). Stage G1: the Google picture is kept only from *.googleusercontent.com, refreshed on each Google
// sign-in, drawn in the header and on /account; a Google-only account can set a password with an email code and then sign in with it.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\App;
use Pratyangi\Auth\PublicCustomer;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\Mailer;

$t = new TestCase('db_google_avatar_test');
$t->test('safePicture: https on *.googleusercontent.com only', function () use ($t): void {
    $ok = 'https://lh3.googleusercontent.com/a/abc=s96-c';
    $t->same($ok, PublicCustomer::safePicture($ok));
    foreach (['http://lh3.googleusercontent.com/a', 'https://evil.example/x.googleusercontent.com', 'https://googleusercontent.com.evil.example/a', 'https://user@lh3.googleusercontent.com/a', 'https://lh3.googleusercontent.com/a"onerror=1', 'javascript:alert(1)', '', null, 42] as $bad) {
        $t->same(null, PublicCustomer::safePicture($bad), 'ignored: ' . var_export($bad, true));
    }
});

$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$clock = new FixedClock((float) time());
$mailer = new class implements Mailer {
    /** @var list<string> */
    public array $codes = [];
    public function isAvailable(): bool
    {
        return true;
    }
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        if (preg_match('/\b(\d{6})\b/', $text, $m) === 1) {
            $this->codes[] = $m[1];
        }
    }
};
$app = Helpers::app(Helpers::config(['OTP_PEPPER' => str_repeat('S', 48), 'SMS_ENABLED' => 'false']), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, $mailer, static fn (): \PDO => $pdo);
$call = static fn (string $m, string $path, ?array $body = null, string $cookie = ''): \Pratyangi\Http\Response => $app->handle(Helpers::request($m, $path, ['Origin' => Helpers::ORIGIN] + ($cookie !== '' ? ['Cookie' => $cookie] : []), $body, '198.51.100.7'));

// the Google token check needs a real signed token, so the already-verified claims go straight to AuthService
$t->test('picture refresh, header and account avatar, then set a password on a Google-only account', function () use ($t, $app, $call, $mailer, $clock): void {
    $service = (new ReflectionMethod(App::class, 'auth'))->invoke($app);
    $g = ['sub' => 'g-avatar-1', 'email' => 'avatar.user@example.com', 'name' => 'Avni Rao', 'picture' => 'https://lh3.googleusercontent.com/a/one'];
    $first = $service->googleSignIn($g, null);
    $t->same('https://lh3.googleusercontent.com/a/one', $first['customer']['picture']);
    $second = $service->googleSignIn(['picture' => 'https://lh3.googleusercontent.com/a/two'] + $g, null);
    $t->same('https://lh3.googleusercontent.com/a/two', $second['customer']['picture'], 'refreshed on the next sign-in');
    $page = $call('GET', '/account', null, 'pf_session=' . $second['token'])->body();
    $t->ok(substr_count($page, 'src="https://lh3.googleusercontent.com/a/two"') >= 2, 'the header and the /account card draw the picture');
    $t->contains('referrerpolicy="no-referrer"', $page);
    $t->contains('alt="Avni"', $page);
    $t->contains('loading="lazy"', $page);
    $r = $call('POST', '/api/v1/auth/login', ['email' => 'avatar.user@example.com', 'password' => 'Whatever2026']);
    $t->same([401, 'google_account'], [$r->status(), $r->decoded()['code'] ?? null]);
    $clock->advance(60);
    $t->same(200, $call('POST', '/api/v1/auth/password/forgot', ['email' => 'avatar.user@example.com'])->status());
    $code = end($mailer->codes) ?: '';
    $t->ok($code !== '', 'a reset code was emailed');
    $r = $call('POST', '/api/v1/auth/password/reset', ['email' => 'avatar.user@example.com', 'code' => $code, 'password' => 'NewSaree2027']);
    $t->same(204, $r->status(), 'the reset sets the first password');
    $t->same(200, $call('POST', '/api/v1/auth/login', ['email' => 'avatar.user@example.com', 'password' => 'NewSaree2027'])->status(), 'email + password now works');
});
exit($t->finish());

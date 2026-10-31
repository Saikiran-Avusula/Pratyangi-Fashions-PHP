<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway *_test database, port != 3306, env settings only; see tests/lib/DbGuard.php). NOT run by the developer.
// Drives the whole App in-process (Request in, Response out) with the real database, the mock ERP and a log mailer.
// Run: PF_TEST_DB_PORT=... PF_TEST_DB_NAME=..._test PF_TEST_DB_USER=... PF_TEST_DB_PASS=... php tests/http_smoke_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Http\Response;
use Pratyangi\Mail\LogMailer;

$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('http_smoke_test');

$mailFile = Tmp::base() . '/pf_smoke_mail_' . bin2hex(random_bytes(4)) . '.log';
register_shutdown_function(static function () use ($mailFile): void {
    if (is_file($mailFile)) {
        unlink($mailFile);
    }
});
$config = Helpers::config(['MAIL_LOG_PATH' => $mailFile, 'OTP_PEPPER' => str_repeat('S', 48), 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => sys_get_temp_dir() . '/pf_smoke_sms_unused.log']);
$clock = new FixedClock((float) time());
$app = Helpers::app($config, $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($mailFile, $clock), static fn (): \PDO => $pdo);
$O = Helpers::ORIGIN;
$email = 'smoke' . bin2hex(random_bytes(3)) . '@example.com';
$cookie = '';

/** @param array<string,string> $extra */
$call = static function (string $method, string $path, ?array $body = null, array $extra = []) use ($app, $O, &$cookie): Response {
    $headers = ['Origin' => $O] + $extra;
    if ($cookie !== '') {
        $headers['Cookie'] = 'pf_session=' . $cookie;
    }
    return $app->handle(Helpers::request($method, $path, $headers, $body));
};
$cookieOf = static function (Response $r): string {
    return preg_match('/^pf_session=([^;]*);/', $r->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';
};
$mail = static fn (): string => is_file($mailFile) ? (string) file_get_contents($mailFile) : '';

$t->test('signup: 201, user only, cookie flags', function () use ($t, $call, $cookieOf, &$cookie, $email): void {
    $r = $call('POST', '/api/v1/auth/signup', ['name' => 'Smoke Buyer', 'email' => $email, 'password' => 'Saree2026']);
    $t->same(201, $r->status());
    $d = $r->decoded();
    $t->same(['user'], array_keys($d));
    $t->same($email, $d['user']['email']);
    $line = $r->cookies()[0];
    $t->contains('HttpOnly', $line);
    $t->contains('SameSite=Lax', $line);
    $t->contains('Path=/', $line);
    $t->same(false, str_contains($line, 'Secure'), 'not production');
    $t->same(false, str_contains($line, 'Max-Age') || str_contains($line, 'Domain'));
    $cookie = $cookieOf($r);
    $t->same(43, strlen($cookie));
    $dup = $call('POST', '/api/v1/auth/signup', ['name' => 'Smoke Buyer', 'email' => $email, 'password' => 'Saree2026']);
    $t->same([409, 'email'], [$dup->status(), $dup->decoded()['field']]);
});

$t->test('me with the cookie, 401 without', function () use ($t, $call, &$cookie, $email): void {
    $r = $call('GET', '/api/v1/auth/me');
    $t->same([200, $email], [$r->status(), $r->decoded()['user']['email']]);
    $saved = $cookie;
    $cookie = '';
    $t->same([401, ['message' => 'Please sign in to continue.']], [$call('GET', '/api/v1/auth/me')->status(), $call('GET', '/api/v1/auth/me')->decoded()]);
    $cookie = 'garbage-token-garbage-token-garbage-token-12';
    $t->same(401, $call('GET', '/api/v1/auth/me')->status(), 'unknown token = signed out, never a 500');
    $cookie = $saved;
});

$t->test('verify email with the emailed code (key is "customer")', function () use ($t, $call, $mail, $email): void {
    $t->same(200, $call('POST', '/api/v1/auth/email/resend', [])->status(), 'sign-up sends no code; resend does');
    preg_match_all('/Your code is (\d{6})/', $mail(), $m);
    $r = $call('POST', '/api/v1/auth/email/verify', ['code' => end($m[1])]);
    $t->same(200, $r->status());
    $t->same(['customer'], array_keys($r->decoded()));
    $t->same(true, $r->decoded()['customer']['emailVerified']);
    $again = $call('POST', '/api/v1/auth/email/resend', []);
    $t->same(['message' => 'Your email is already verified.'], $again->decoded());
});

$t->test('logout: 204, cookie cleared, old cookie no longer works; login again', function () use ($t, $call, $cookieOf, &$cookie, $email): void {
    $old = $cookie;
    $r = $call('POST', '/api/v1/auth/logout');
    $t->same([204, ''], [$r->status(), $r->body()]);
    $t->contains('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $r->cookies()[0]);
    $t->same(401, $call('GET', '/api/v1/auth/me')->status());
    $cookie = '';
    $t->same(204, $call('POST', '/api/v1/auth/logout')->status(), 'logout with no cookie is fine');
    $bad = $call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'Wrong2026']);
    $t->same([401, 'Email or password is incorrect'], [$bad->status(), $bad->decoded()['message']]);
    $ok = $call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'Saree2026']);
    $t->same([200, ['user']], [$ok->status(), array_keys($ok->decoded())]);
    $cookie = $cookieOf($ok);
    $t->ok($cookie !== $old);
});

$t->test('forgot + reset over HTTP: 200 for any email, 204 on success, sessions end', function () use ($t, $call, $mail, &$cookie, $email): void {
    $unknown = $call('POST', '/api/v1/auth/password/forgot', ['email' => 'ghost@example.com']);
    $known = $call('POST', '/api/v1/auth/password/forgot', ['email' => $email]);
    $t->same([200, 200], [$unknown->status(), $known->status()]);
    $t->same($unknown->body(), $known->body());
    preg_match_all('/Your code is (\d{6})/', $mail(), $m);
    $r = $call('POST', '/api/v1/auth/password/reset', ['email' => $email, 'code' => end($m[1]), 'password' => 'NewSaree2027']);
    $t->same([204, ''], [$r->status(), $r->body()]);
    $t->same(401, $call('GET', '/api/v1/auth/me')->status(), 'old session ended');
    $cookie = '';
    $t->same(200, $call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'NewSaree2027'])->status());
});

$t->test('login is limited to 10 per 15 minutes per address (real table)', function () use ($t, $call): void {
    $last = 0;
    for ($i = 0; $i < 12; $i++) {
        $last = $call('POST', '/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'Nope2026'])->status();
    }
    $t->same(429, $last);
});

$t->test('catalogue over HTTP with the mock ERP', function () use ($t, $call, $config): void {
    $r = $call('GET', '/api/v1/catalog/products?limit=2');
    $t->same(200, $r->status());
    $t->same('public, max-age=60', $r->header('Cache-Control'));
    $d = $r->decoded();
    $t->same(['products', 'total', 'nextCursor'], array_keys($d));
    $t->same(12, count($d['products'][0]));
    $t->same([5, '2'], [$d['total'], $d['nextCursor']]);
    $t->same(422, $call('GET', '/api/v1/catalog/products?limit=101')->status());
    Helpers::rmTree($config->catalogCacheDir);
});

exit($t->finish());

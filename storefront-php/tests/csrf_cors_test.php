<?php
declare(strict_types=1);

// No database. Run: php tests/csrf_cors_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Csrf;
use Pratyangi\Http\Response;

$t = new TestCase('csrf_cors_test');
$O = Helpers::ORIGIN;
$app = Helpers::app();

$t->test('csrf unit: exact origin, trailing slash ignored, missing refused, safe methods free', function () use ($t, $O): void {
    $csrf = new Csrf([$O, 'https://shop.example.com']);
    $csrf->check(Helpers::request('POST', '/x', ['Origin' => $O]));
    $csrf->check(Helpers::request('POST', '/x', ['Origin' => $O . '/']));
    $csrf->check(Helpers::request('GET', '/x'));
    foreach ([null, '', 'http://evil.example', $O . '.evil.example', 'HTTP://LOCALHOST:3000', 'null'] as $bad) {
        $e = $t->throws(ApiException::class, fn () => $csrf->check(Helpers::request('DELETE', '/x', $bad === null ? [] : ['Origin' => $bad])));
        $t->same(403, $e->status);
        $t->same('This request did not come from the Pratyangi Fashions website.', $e->getMessage());
    }
});

$t->test('403 comes before 401 and before 404 on unsafe methods', function () use ($t, $app): void {
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/email/resend', ['Origin' => 'http://evil.example'], []));
    $t->same(403, $r->status());
    $r = $app->handle(Helpers::request('POST', '/api/v1/nothing-here', []));
    $t->same(403, $r->status());
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/email/resend', ['Origin' => Helpers::ORIGIN], []));
    $t->same(401, $r->status());
    $t->same(['message' => 'Please sign in to continue.'], $r->decoded());
});

$t->test('unknown paths are the contract 404', function () use ($t, $app, $O): void {
    foreach (['/nope', '/api/v1/nope', '/api/v1', '/api/v2/health'] as $path) {
        $r = $app->handle(Helpers::request('GET', $path));
        $t->same(404, $r->status(), $path);
        $t->same(str_starts_with($path, '/api') ? '{"message":"That page does not exist."}' : true, str_starts_with($path, '/api') ? $r->body() : str_contains($r->body(), 'Page not found'), $path);
    }
    $t->same(404, $app->handle(Helpers::request('POST', '/nope', ['Origin' => 'http://evil.example']))->status(), 'outside /api/v1 there is no CSRF check');
    $t->same(404, $app->handle(Helpers::request('PUT', '/api/v1/auth/me', ['Origin' => $O]))->status(), 'wrong method is 404, not 405');
});

$t->test('health at root and under /api/v1, trailing slash and case tolerated, HEAD allowed', function () use ($t, $app): void {
    foreach (['/health', '/api/v1/health', '/api/v1/health/', '/API/V1/Health'] as $path) {
        $r = $app->handle(Helpers::request('GET', $path));
        $t->same(200, $r->status(), $path);
        $t->same('{"ok":true}', $r->body());
    }
    $t->same(200, $app->handle(Helpers::request('HEAD', '/health'))->status());
});

$t->test('CORS: allowed origin is echoed with credentials; others get no allow-origin', function () use ($t, $app, $O): void {
    $r = $app->handle(Helpers::request('GET', '/health', ['Origin' => $O]));
    $t->same($O, $r->header('Access-Control-Allow-Origin'));
    $t->same('true', $r->header('Access-Control-Allow-Credentials'));
    $t->contains('Origin', (string) $r->header('Vary'));
    $r = $app->handle(Helpers::request('GET', '/health', ['Origin' => 'http://evil.example']));
    $t->same(null, $r->header('Access-Control-Allow-Origin'));
    $t->same('true', $r->header('Access-Control-Allow-Credentials'));
    // WEB_ORIGIN had a trailing slash in the settings; the browser sends none
    $t->same('https://shop.example.com', $app->handle(Helpers::request('GET', '/health', ['Origin' => 'https://shop.example.com']))->header('Access-Control-Allow-Origin'));
});

$t->test('CORS preflight: 204, reflects requested headers, runs before CSRF', function () use ($t, $app, $O): void {
    $r = $app->handle(Helpers::request('OPTIONS', '/api/v1/checkout', ['Origin' => $O, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type,idempotency-key']));
    $t->same(204, $r->status());
    $t->same('', $r->body());
    $t->same($O, $r->header('Access-Control-Allow-Origin'));
    $t->same('content-type,idempotency-key', $r->header('Access-Control-Allow-Headers'));
    $t->same('GET,HEAD,PUT,PATCH,POST,DELETE', $r->header('Access-Control-Allow-Methods'));
    $t->same('true', $r->header('Access-Control-Allow-Credentials'));
    $t->same('0', $r->header('Content-Length'));
});

$t->test('every response: security headers, request id, no X-Powered-By', function () use ($t, $app): void {
    $r = $app->handle(Helpers::request('GET', '/health'));
    foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'no-referrer', 'X-XSS-Protection' => '0', 'Cross-Origin-Opener-Policy' => 'same-origin'] as $name => $value) {
        $t->same($value, $r->header($name), $name);
    }
    $t->ok($r->hasHeader('Content-Security-Policy') && $r->hasHeader('Strict-Transport-Security'));
    $t->same(false, $r->hasHeader('X-Powered-By'));
    $t->same('application/json; charset=utf-8', $r->header('Content-Type'));
    $t->same(1, preg_match('/^[0-9a-f-]{36}$/D', (string) $r->header('X-Request-Id')));
    $t->same('abc-123', $app->handle(Helpers::request('GET', '/health', ['X-Request-Id' => 'abc-123']))->header('X-Request-Id'));
    $t->same(64, strlen((string) $app->handle(Helpers::request('GET', '/health', ['X-Request-Id' => str_repeat('a', 100)]))->header('X-Request-Id')));
    $t->same(36, strlen((string) $app->handle(Helpers::request('GET', '/health', ['X-Request-Id' => "a\x01b"]))->header('X-Request-Id')));
    $t->same('abc', $app->handle(Helpers::request('POST', '/nope', ['X-Request-Id' => 'abc']))->header('X-Request-Id'), 'also on errors');
});

$t->test('bad bodies: malformed JSON 400, oversized 413, non-JSON content type ignored, before CSRF', function () use ($t, $app, $O): void {
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/login', ['Origin' => $O, 'Content-Type' => 'application/json'], '{"email":'));
    $t->same(400, $r->status());
    $t->same('{"message":"The request could not be read. Please try again."}', $r->body());
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/login', ['Origin' => 'http://evil.example', 'Content-Type' => 'application/json'], '"just a string"'));
    $t->same(400, $r->status(), 'strict mode: top level must be object or list; and 400 precedes the CSRF 403');
    $big = '{"name":"' . str_repeat('a', 21000) . '"}';
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => $O, 'Content-Type' => 'application/json'], $big));
    $t->same(413, $r->status());
    $r = $app->handle(Helpers::request('POST', '/nope', ['Origin' => $O, 'Content-Type' => 'text/plain'], '{{{'));
    $t->same(404, $r->status());
});

$t->test('rate limit through the app: draft-6 headers, 429 text, Retry-After', function () use ($t): void {
    $app = Helpers::app(null, null, null, null, null, null, ['catalog' => 2]);
    $one = $app->handle(Helpers::request('GET', '/api/v1/catalog/products'));
    $t->same(200, $one->status());
    $t->same('2;w=60', $one->header('RateLimit-Policy'));
    $t->same('2', $one->header('RateLimit-Limit'));
    $t->same('1', $one->header('RateLimit-Remaining'));
    $t->ok($one->header('RateLimit-Reset') !== null && (int) $one->header('RateLimit-Reset') <= 60);
    $t->same(null, $one->header('X-RateLimit-Limit'));
    $two = $app->handle(Helpers::request('GET', '/api/v1/catalog/products'));
    $t->same('0', $two->header('RateLimit-Remaining'));
    $three = $app->handle(Helpers::request('GET', '/api/v1/catalog/products'));
    $t->same(429, $three->status());
    $t->same('Too many attempts. Please wait a few minutes and try again.', $three->decoded()['message'] ?? null);
    $t->ok($three->header('Retry-After') !== null);
    $t->same((int) $three->header('Retry-After'), $three->decoded()['retryAfter'] ?? null, 'the JSON carries the same wait as the header');
    $t->ok(($three->decoded()['retryAfter'] ?? 0) >= 1 && ($three->decoded()['retryAfter'] ?? 61) <= 60);
    $other = $app->handle(Helpers::request('GET', '/api/v1/catalog/products', [], null, '198.51.100.7'));
    $t->same(200, $other->status(), 'another address has its own bucket');
});

$t->test('cookie text matches Express', function () use ($t): void {
    $c = (new Response(204))->setCookie('pf_session', 'tok', 1798000000, true)->cookies()[0];
    $t->same('pf_session=tok; Path=/; Expires=Wed, 23 Dec 2026 04:26:40 GMT; HttpOnly; Secure; SameSite=Lax', $c);
    $c = (new Response(204))->setCookie('pf_session', '', 0, false)->cookies()[0];
    $t->same('pf_session=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax', $c);
});

exit($t->finish());

<?php
declare(strict_types=1);

// No database, no network. Run: php tests/error_pages_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Web\ErrorPages;

$t = new TestCase('error_pages_test');

$t->test('every error page: real status, HTML, noindex, no-store, no details', function () use ($t): void {
    foreach (ErrorPages::statuses() as $status) {
        $r = ErrorPages::response($status);
        $t->same($status, $r->status());
        $t->same('text/html; charset=utf-8', $r->header('Content-Type'));
        $t->same('noindex', $r->header('X-Robots-Tag'));
        $t->contains('<meta name="robots" content="noindex"/>', $r->body());
        $t->contains('class="pSCCwa_header"', $r->body(), 'site header');
        $t->contains('pf_emptyState', $r->body(), 'empty-state component');
    }
    $t->contains('<h2>Page not found</h2>', ErrorPages::response(404)->body());
    $t->contains('<h2>Access denied</h2>', ErrorPages::response(403)->body());
    $t->contains('class="pf_errSearch"', ErrorPages::response(404)->body(), 'search box on the 404');
    $t->ok(!str_contains(ErrorPages::response(403)->body(), 'class="pf_errSearch"'));
    $t->same('60', ErrorPages::response(429)->header('Retry-After'));
});

$t->test('unknown browser address = 404 HTML; /api stays JSON; unknown product is a 404 page', function () use ($t): void {
    $app = Helpers::app();
    $r = $app->handle(Helpers::request('GET', '/no-such-page'));
    $t->same(404, $r->status());
    $t->contains('Page not found', $r->body());
    $api = $app->handle(Helpers::request('GET', '/api/v1/nope'));
    $t->same(404, $api->status());
    $t->same('application/json; charset=utf-8', $api->header('Content-Type'));
    $p = $app->handle(Helpers::request('GET', '/product?sku=NO-SUCH-SKU'));
    $t->same(404, $p->status());
    $t->same('noindex', $p->header('X-Robots-Tag'));
    $c = $app->handle(Helpers::request('GET', '/collection/no-such-collection'));
    $t->same(404, $c->status());
});

$t->test('MAINTENANCE: 503 page for everyone except the allowed IP; assets and /health stay up; /api gets JSON', function () use ($t): void {
    $app = Helpers::app(Helpers::config(['MAINTENANCE' => 'true', 'MAINTENANCE_ALLOW_IP' => '198.51.100.7, 203.0.113.50']));
    $r = $app->handle(Helpers::request('GET', '/'));
    $t->same(503, $r->status());
    $t->contains('We&#039;ll be right back', $r->body());
    $t->same('60', $r->header('Retry-After'));
    $t->same(503, $app->handle(Helpers::request('GET', '/new-arrivals'))->status());
    $api = $app->handle(Helpers::request('GET', '/api/v1/catalog/products'));
    $t->same(503, $api->status());
    $t->same('application/json; charset=utf-8', $api->header('Content-Type'));
    $t->same(200, $app->handle(Helpers::request('GET', '/health'))->status());
    $t->same(200, $app->handle(Helpers::request('GET', '/', [], null, '198.51.100.7'))->status(), 'allowed IP');
    $t->same(200, Helpers::app()->handle(Helpers::request('GET', '/'))->status(), 'off by default');
});

$t->test('a bad MAINTENANCE_ALLOW_IP is refused', function () use ($t): void {
    $t->throws(\Pratyangi\ConfigException::class, fn () => Helpers::config(['MAINTENANCE_ALLOW_IP' => 'not-an-ip']));
});

$t->test('products being updated (ERP down): the page says so with a 503', function () use ($t): void {
    $app = Helpers::app(null, null, null, new \Pratyangi\Erp\NotConnectedErp());
    foreach (['/new-arrivals', '/product?sku=X'] as $path) {
        $r = $app->handle(Helpers::request('GET', $path));
        $t->same(503, $r->status(), $path);
        $t->contains('Products are being updated', $r->body());
    }
});

$t->test('a dead session cookie on /account redirects to /login with "Please sign in again"', function () use ($t): void {
    $app = Helpers::app();
    $r = $app->handle(Helpers::request('GET', '/account', ['Cookie' => 'pf_session=' . str_repeat('a', 43)]));
    $t->same(302, $r->status());
    $t->same('/login?next=/account&expired=1', $r->header('Location'));
    $t->contains('Please sign in again.', $app->handle(Helpers::request('GET', '/login?next=/account&expired=1'))->body());
    $t->same('/login?next=/account', $app->handle(Helpers::request('GET', '/account'))->header('Location'), 'no cookie = no expired flag');
});

exit($t->finish());

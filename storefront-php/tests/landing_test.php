<?php
declare(strict_types=1);

// No database, no network. Run: php tests/landing_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Erp\NotConnectedErp;
use Pratyangi\Views\View;

$t = new TestCase('landing_test');
$root = dirname(__DIR__);

/** A mock ERP file with the given products (name, price, stock). */
$mockFile = static function (array $rows): string {
    $products = [];
    $stock = [];
    foreach ($rows as $i => [$name, $price, $pieces]) {
        $sku = 'LND-' . $i;
        $products[] = ['sku' => $sku, 'name' => $name, 'category' => 'Sarees', 'fabric' => null, 'pattern_name' => null, 'design_model' => null,
            'color' => null, 'size' => null, 'mrp' => $price, 'retail_price' => $price, 'offer_price' => null, 'tax_rate' => '5.00', 'image_path' => null];
        $stock[$sku] = $pieces;
    }
    $file = Helpers::tempDir('pf_landing_') . '/erp.json';
    file_put_contents($file, json_encode(['products' => $products, 'stock' => $stock], JSON_THROW_ON_ERROR));
    return $file;
};

$t->test('home renders with the shipped mock ERP: names, escaped rupee prices, header and footer', function () use ($t): void {
    $r = Helpers::app()->handle(Helpers::request('GET', '/'));
    $t->same(200, $r->status());
    $t->same('text/html; charset=utf-8', $r->header('Content-Type'));
    $b = $r->body();
    foreach (['Chiffon Dupatta', 'Cotton Handloom Saree', 'Kanjivaram Silk Saree', 'Printed Cotton Kurti'] as $name) {
        $t->contains($name, $b, $name);
    }
    $t->contains('Rs. 11,499.00', $b); // Kanjivaram: offer price
    $t->contains('<del>Rs. 14,999.00</del>', $b);
    $t->contains('Rs. 1,199.50', $b);
    $t->contains('class="pSCCwa_header"', $b);
    $t->contains('class="f_viWW_footer"', $b);
    $t->contains('/assets/css/3wh3hbs4o_ca8.css', $b);
    $t->contains('/assets/site.css', $b);
    // two script tags since step 2B: site.js and bag.js (the cards' "Add to cart"), both same-origin and deferred
    $t->same(2, substr_count($b, '<script'), 'exactly two script tags');
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/bag.js"></script>', $b, 'the page scripts: same-origin, deferred, no inline code');
    $t->same(0, preg_match('/\son[a-z]+="/i', $b), 'no inline event handler attributes');
    $t->contains('<span class="pSCCwa_count" data-pf="bag-count">0</span></a>', $b, 'the bag icon carries the count (site.js fills it from the saved bag)');
    $t->ok(!str_contains($b, '/_next/'), 'no Next.js urls left');
    $t->same(0, preg_match('#(src|href)="(https?:)?//#', $b), 'no external resources');
    $t->same(false, $r->hasHeader('X-Powered-By'));
});

$t->test('HEAD works and security headers are present on HTML', function () use ($t): void {
    $r = Helpers::app()->handle(Helpers::request('HEAD', '/'));
    $t->same(200, $r->status());
    $t->same('nosniff', $r->header('X-Content-Type-Options'));
    $t->contains("default-src 'self'", (string) $r->header('Content-Security-Policy'));
    $t->ok(!str_contains((string) $r->header('Content-Security-Policy'), 'upgrade-insecure-requests'), 'not upgraded on plain-http test/dev runs');
    $prod = Helpers::app(Helpers::config(['APP_ENV' => 'production', 'ERP_MODE' => 'off', 'MAIL_MODE' => 'smtp', 'OTP_PEPPER' => str_repeat('p', 40), 'SMS_MODE' => 'http', 'SMS_API_URL' => 'https://sms.example.com/api', 'SMS_USERNAME' => 'u', 'SMS_APIKEY' => 'k', 'SMS_SENDER_ID' => 'S', 'SMS_OTP_TEMPLATE_ID' => '1']))->handle(Helpers::request('GET', '/'));
    $t->contains('upgrade-insecure-requests', (string) $prod->header('Content-Security-Policy'));
});

$t->test('sold-out label, low stock, and in-stock products first', function () use ($t, $mockFile): void {
    $file = $mockFile([['Gone Saree', '999.00', 0], ['Last Saree', '987654.50', 2]]);
    $b = Helpers::app(Helpers::config(['ERP_MOCK_FILE' => $file]))->handle(Helpers::request('GET', '/'))->body();
    $t->contains('<span class="JEjkwq_soldOutBadge">Out of stock</span>', $b);
    $t->contains('aria-label="Gone Saree is out of stock"', $b);
    $t->contains(' disabled=""', $b);
    $t->contains('Only 2 left', $b);
    $t->contains('Rs. 9,87,654.50', $b, 'Indian digit grouping');
    $t->ok(strpos($b, 'Last Saree') < strpos($b, 'Gone Saree'), 'in-stock card comes first');
    $t->same(1, substr_count($b, 'JEjkwq_soldOutBadge">'));
});

$t->test('a hostile product name is escaped everywhere', function () use ($t, $mockFile): void {
    $evil = '<script>alert("x")</script> O\'Neil & "Sons"';
    $b = Helpers::app(Helpers::config(['ERP_MOCK_FILE' => $mockFile([[$evil, '500.00', 5]])]))->handle(Helpers::request('GET', '/'))->body();
    $t->same(2, substr_count($b, '<script'), 'no raw script tag from the product name (only the two page scripts)');
    $t->contains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; O&#039;Neil &amp; &quot;Sons&quot;', $b);
    $t->same('&lt;a b=&quot;c&quot; &#039;d&#039;&gt;', View::e('<a b="c" \'d\'>'));
});

$t->test('ERP down: friendly message, no product cards, still 200', function () use ($t): void {
    $r = Helpers::app(null, null, null, new NotConnectedErp())->handle(Helpers::request('GET', '/'));
    $t->same(200, $r->status());
    $t->contains('We could not load products right now', $r->body());
    $t->ok(!str_contains($r->body(), 'JEjkwq_card'), 'no cards');
    $t->ok(!str_contains($r->body(), 'YnyroG_productGrid'), 'no grid');
});

$t->test('/soon and static assets', function () use ($t, $root): void {
    $app = Helpers::app();
    $r = $app->handle(Helpers::request('GET', '/soon'));
    $t->same(200, $r->status());
    $t->same('text/html; charset=utf-8', $r->header('Content-Type'));
    $t->contains('This page comes in the next build phase', $r->body());
    $t->same(200, $app->handle(Helpers::request('GET', '/soon/'))->status());

    $r = $app->handle(Helpers::request('GET', '/assets/site.css'));
    $t->same(200, $r->status());
    $t->same('text/css; charset=utf-8', $r->header('Content-Type'));
    $t->same(file_get_contents($root . '/public/assets/site.css'), $r->body());
    $t->same(200, $app->handle(Helpers::request('GET', '/assets/css/3wh3hbs4o_ca8.css'))->status());
    $r = $app->handle(Helpers::request('GET', '/assets/images/reference/product-1.webp'));
    $t->same(200, $r->status());
    $t->same('image/webp', $r->header('Content-Type'));
    $t->same(200, $app->handle(Helpers::request('GET', '/assets/icon.svg'))->status());
    $t->same(200, $app->handle(Helpers::request('HEAD', '/assets/site.css'))->status());

    // site.js is the only JavaScript file served
    $r = $app->handle(Helpers::request('GET', '/assets/site.js'));
    $t->same(200, $r->status());
    $t->same('text/javascript; charset=utf-8', $r->header('Content-Type'));
    $t->same(file_get_contents($root . '/public/assets/site.js'), $r->body());
    $t->contains("script-src 'self'", (string) $r->header('Content-Security-Policy'));
    $t->same(404, $app->handle(Helpers::request('GET', '/assets/other.js'))->status(), 'an unknown .js is 404');

    // every image the page and the copied CSS point to exists
    $html = $app->handle(Helpers::request('GET', '/'))->body();
    $css = (string) file_get_contents($root . '/public/assets/css/3wh3hbs4o_ca8.css');
    preg_match_all('#/assets/images/[A-Za-z0-9_./-]+#', $html . $css, $m);
    foreach (array_unique($m[0]) as $url) {
        $t->ok(is_file($root . '/public' . $url), 'missing ' . $url);
    }
});

$t->test('unknown paths 404, traversal and odd paths blocked', function () use ($t): void {
    $app = Helpers::app();
    foreach (['/nope', '/assets/nope.css', '/assets/', '/assets', '/assets/../app/App.php', '/assets/../../config.example.php',
        '/assets/%2e%2e/app/App.php', '/assets/%2E%2E%2Fapp%2FApp.php', '/assets/css/..%5C..%5Capp%5CApp.php', '/assets/site.css%00.php',
        '/assets/css', '/assets/css/', '/assets/../public/index.php'] as $path) {
        $r = $app->handle(Helpers::request('GET', $path));
        $t->same(404, $r->status(), $path);
        $t->same('{"message":"That page does not exist."}', $r->body(), $path);
    }
    $t->same(404, $app->handle(Helpers::request('POST', '/'))->status(), 'POST / is not a page');
    $t->same(404, $app->handle(Helpers::request('POST', '/soon'))->status());
});

$t->test('API routes are unaffected', function () use ($t): void {
    $app = Helpers::app();
    $t->same('{"ok":true}', $app->handle(Helpers::request('GET', '/api/v1/health'))->body());
    $t->same(200, $app->handle(Helpers::request('GET', '/api/v1/catalog/products'))->status());
    $t->same(403, $app->handle(Helpers::request('POST', '/api/v1/auth/logout', ['Origin' => 'http://evil.example'], []))->status());
});

exit($t->finish());

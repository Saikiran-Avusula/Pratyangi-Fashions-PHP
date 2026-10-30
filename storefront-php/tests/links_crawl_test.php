<?php
declare(strict_types=1);

// No database, no network. Run: php tests/links_crawl_test.php [folder the built zip was unpacked into]
// Crawls every page the app can render (signed out, mock ERP) and every email template, follows each internal href / src / script / stylesheet
// and FAILS on any 404 (or a missing file under public/). With a zip path it also checks that every asset those pages use is IN the zip and is
// served by StaticFiles when the zip is unpacked (the build check).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Mail\EventTemplates;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Views\ShopInfo;
use Pratyangi\Web\StaticFiles;

$t = new TestCase('links_crawl_test');
$root = dirname(__DIR__);
$config = Helpers::config(['WEB_ORIGIN' => 'https://shop.example.com']);
$app = Helpers::app($config);
$site = 'https://shop.example.com';
$sku = (string) (json_decode((string) file_get_contents($root . '/tests/fixtures/mock_erp.json'), true)['products'][0]['sku'] ?? 'MOCK-SAR-001');

$pages = ['/', '/new-arrivals', '/collection/sarees', '/search?q=saree', '/product?sku=' . rawurlencode($sku), '/bag', '/checkout', '/wishlist', '/login', '/signup', '/forgot-password',
    '/verify-email', '/account-help', '/privacy', '/terms', '/refund-policy', '/shipping-policy', '/contact', '/cookie-policy', '/soon', '/no-such-page', '/auth/anything', '/robots.txt'];

/** @return list<string> internal targets (path + query) found in html */
$targets = static function (string $html) use ($site): array {
    preg_match_all('/\s(?:href|src)="([^"#]+)/i', $html, $m);
    $out = [];
    foreach ($m[1] as $u) {
        $u = html_entity_decode($u, ENT_QUOTES);
        if (str_starts_with($u, $site)) {
            $u = substr($u, strlen($site)) ?: '/';
        }
        if ($u[0] === '/' && !str_starts_with($u, '//')) {
            $out[$u] = true;
        }
    }
    return array_keys($out);
};

$order = ['orderNo' => 'PF-10021', 'name' => 'Asha', 'items' => [['Silk Saree', 2, 199900]], 'itemsTotalPaise' => 399800, 'taxPaise' => 19038, 'totalPaise' => 399800,
    'address' => '5 Lane, Hyderabad', 'amountPaise' => 399800, 'method' => 'UPI', 'invoiceCode' => 'k3JdQ9xT2mWp7Z', 'courier' => 'Delhivery', 'awb' => '123',
    'trackingUrl' => 'https://track.example.test/123', 'refundPaise' => 399800, 'note' => 'x'];
ShopInfo::init($config);
$mails = [MailTemplates::code('verify', '123456', 10, 'Asha'), EventTemplates::welcome('Asha'), EventTemplates::passwordChanged('Asha', 'now'), EventTemplates::emailChanged('Asha', 'old', 'a@b.c', 'd@e.f'),
    EventTemplates::orderConfirmed($order), EventTemplates::paymentReceipt($order), EventTemplates::paymentFailed($order), EventTemplates::shipped($order), EventTemplates::outForDelivery($order),
    EventTemplates::delivered($order), EventTemplates::cancelled($order), EventTemplates::refundProcessed($order), EventTemplates::returnUpdate($order)];

$seen = [];
$assets = [];
$check = static function (string $from, string $path) use ($app, $root, &$seen, &$assets, $t): void {
    if (isset($seen[$path])) {
        return;
    }
    $seen[$path] = true;
    $file = parse_url($path, PHP_URL_PATH);
    if (str_starts_with($file, '/assets/') || is_file($root . '/public' . $file)) {
        $assets[$file] = true;
        $t->ok(is_file($root . '/public' . $file), $from . ' -> ' . $file . ' exists under public/');
        return;
    }
    $status = $app->handle(Helpers::request('GET', $path))->status();
    $t->ok($status !== 404 && $status < 500, $from . ' -> ' . $path . ' answered ' . $status);
};

foreach ($pages as $page) {
    $r = $app->handle(Helpers::request('GET', $page));
    if ($page !== '/no-such-page' && $page !== '/robots.txt') {
        $t->ok($r->status() < 400 || $r->status() === 302, $page . ' itself answered ' . $r->status());
    }
    foreach ($targets($r->body()) as $u) {
        $check($page, $u);
    }
}
foreach ($mails as $i => $mail) {
    foreach ($targets((string) $mail['html']) as $u) {
        $check('email #' . $i, $u);
    }
}
$t->ok(count($seen) > 40, 'the crawl reached more than 40 distinct targets (' . count($seen) . ')');

if (isset($argv[1])) {
    $zipPath = rtrim($argv[1], '/' . chr(92));
    $dir = $zipPath; // the unpacked zip (tools/build_deploy.ps1 unpacks it; or: unzip x.zip -d folder)
    $t->ok(is_dir($dir . '/public'), 'unpacked zip has public/');
    $files = new StaticFiles($dir . '/public/assets');
    foreach (array_keys($assets) as $file) {
        $t->ok(is_file($dir . '/public' . $file), 'in the zip: ' . $file);
        if (str_starts_with($file, '/assets/')) {
            $t->ok($files->serve($file) !== null, 'served from the zip: ' . $file);
        }
    }
    foreach (array_keys($assets) as $file) { // pictures and fonts a stylesheet pulls in
        if (!str_ends_with($file, ".css")) { continue; }
        preg_match_all("~url\(\s*['\"]?(/assets/[^)'\"?#]+)~", (string) file_get_contents($dir . "/public" . $file), $m);
        foreach (array_unique($m[1]) as $u) { $t->ok(is_file($dir . "/public" . $u), "css url in the zip: " . $u); }
    }
}
exit($t->finish());

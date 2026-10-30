<?php
declare(strict_types=1);

// No database, no network. Run: php tests/server_check_test.php
// /server-check (tools/server_check.php): refuses without the key, shows no secret; STAGING=true adds X-Robots-Tag; the staging settings template loads.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Config;

$t = new TestCase('server_check_test');
$key = 'k3y-for-the-check-0123456789';
$secrets = ['DB_PASS' => 'DbPassSecret123', 'SMTP_PASS' => 'SmtpPassSecret123', 'SMTP_USER' => 'smtp-user-secret', 'SMTP_HOST' => 'smtp.secret.example', 'ERP_CATALOG_KEY' => 'ErpCatalogSecret1-0123456789-0123456789', 'ERP_ORDER_KEY' => 'ErpOrderSecret1-0123456789-0123456789ab'];
$get = static fn (array $settings, string $uri) => Helpers::app(Helpers::config($settings))->handle(Helpers::request('GET', $uri));

$t->test('refuses (404) when the key is empty, missing or wrong', function () use ($t, $get, $key): void {
    $t->same(404, $get([], '/server-check?key=anything')->status(), 'no key in the settings');
    $t->same(404, $get(['SERVER_CHECK_KEY' => $key], '/server-check')->status(), 'no key given');
    $t->same(404, $get(['SERVER_CHECK_KEY' => $key], '/server-check?key=wrong')->status(), 'wrong key');
    $t->same(404, $get(['SERVER_CHECK_KEY' => ''], '/server-check?key=')->status(), 'empty equals empty must not pass');
});

$t->test('with the key: PHP, extensions, database, folders, mail, ERP; a failing database says FAIL; no secret value appears', function () use ($t, $get, $key, $secrets): void {
    $r = $get(['SERVER_CHECK_KEY' => $key, 'MAIL_MODE' => 'smtp', 'ERP_MODE' => 'http', 'ERP_BASE_URL' => 'http://127.0.0.1:9', 'APP_ENV' => 'test'] + $secrets, '/server-check?key=' . $key);
    $t->same(200, $r->status());
    $t->same('no-store', $r->header('Cache-Control'));
    $d = $r->decoded();
    foreach (['php', 'ext pdo_mysql', 'database', 'writable storage', 'writable cache', 'mail', 'erp', 'erp reachable', 'staging'] as $row) {
        $t->ok(isset($d[$row]), 'row ' . $row);
    }
    $t->contains('FAIL', $d['database'], 'the test app has no database');
    $t->contains('settings present', $d['mail']);
    $t->contains('FAIL', $d['erp reachable'], 'nothing listens on port 9');
    foreach ($secrets as $name => $value) {
        $t->ok(!str_contains($r->body(), $value), $name . ' is not printed');
    }
});

$t->test('robots.txt: STAGING=true disallows everything; otherwise allow + Sitemap; sitemap.xml lists the fixed pages on WEB_ORIGIN', function () use ($t, $get): void {
    $r = $get(['STAGING' => 'true', 'WEB_ORIGIN' => 'https://test.pratyangi.com'], '/robots.txt');
    $t->same([200, "User-agent: *\nDisallow: /\n"], [$r->status(), $r->body()]);
    $t->contains('text/plain', (string) $r->header('Content-Type'));
    $r = $get(['STAGING' => 'false', 'WEB_ORIGIN' => 'https://www.pratyangi.com'], '/robots.txt');
    $t->same("User-agent: *\nAllow: /\nSitemap: https://www.pratyangi.com/sitemap.xml\n", $r->body());
    $r = $get(['STAGING' => 'false', 'WEB_ORIGIN' => 'https://www.pratyangi.com'], '/sitemap.xml');
    $t->same(200, $r->status());
    $t->contains('application/xml', (string) $r->header('Content-Type'));
    foreach (['<loc>https://www.pratyangi.com/</loc>', '/new-arrivals</loc>', '/contact</loc>', '/privacy</loc>', '/terms</loc>', '/refund-policy</loc>', '/shipping-policy</loc>'] as $needle) {
        $t->contains($needle, $r->body());
    }
    $t->same(true, simplexml_load_string($r->body()) !== false, 'well-formed XML');
});

$t->test('/server-check shows the version from public/version.txt, or says it is not a built version', function () use ($t): void {
    $file = dirname(__DIR__) . '/public/version.txt';
    $t->same(false, is_file($file), 'version.txt is made by the build, never kept in the source tree');
    $r = (require dirname(__DIR__) . '/tools/server_check.php')(Helpers::config(['SERVER_CHECK_KEY' => 'k' . str_repeat('x', 20)]), Helpers::request('GET', '/server-check?key=k' . str_repeat('x', 20)), static fn () => throw new \RuntimeException('no db'));
    $t->same('not a built version', $r->decoded()['version']);
    file_put_contents($file, "v3\n2026-10-30\nsummary\n");
    try {
        $r = (require dirname(__DIR__) . '/tools/server_check.php')(Helpers::config(['SERVER_CHECK_KEY' => 'k' . str_repeat('x', 20)]), Helpers::request('GET', '/server-check?key=k' . str_repeat('x', 20)), static fn () => throw new \RuntimeException('no db'));
        $t->same('v3', $r->decoded()['version']);
    } finally {
        unlink($file);
    }
});

$t->test('STAGING=true: every answer carries X-Robots-Tag noindex; otherwise none', function () use ($t, $get): void {
    $t->contains('noindex', (string) $get(['STAGING' => 'true'], '/privacy')->header('X-Robots-Tag'));
    $t->contains('noindex', (string) $get(['STAGING' => 'true'], '/api/v1/health')->header('X-Robots-Tag'));
    $t->same(null, $get([], '/privacy')->header('X-Robots-Tag'));
    $t->throws(\Pratyangi\ConfigException::class, static fn () => Helpers::config(['STAGING' => 'yes']));
});

$t->test('config.staging.example.php: loads without a problem and has the staging, payment and SMS switches the plan needs', function () use ($t): void {
    $s = require dirname(__DIR__) . '/config.staging.example.php';
    $c = new Config($s);
    $t->same(true, $c->staging);
    $t->same(false, $c->paymentsEnabled);
    $t->same(false, $c->checkoutEnabled);
    $t->same(false, $c->smsEnabled);
    $t->same('https://test.pratyangi.com', $s['WEB_ORIGIN']);
    $t->same('Vistaraverse E-Cart Private Limited', $c->shopLegalName);
    $t->same('919989233328', $c->shopWhatsapp);
    $t->ok(!array_key_exists('SHOP_REGISTERED_OFFICE', $s), 'no registered office');
    foreach (require dirname(__DIR__) . '/config.example.php' as $name => $_) {
        $t->ok(array_key_exists($name, $s), 'the staging file lists ' . $name);
    }
});
exit($t->finish());

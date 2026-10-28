<?php
declare(strict_types=1);

// No database, no network. Run: php tests/landing_account_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Catalog\CatalogCache;
use Pratyangi\Catalog\CatalogService;
use Pratyangi\Erp\MockErp;
use Pratyangi\Support\Logger;
use Pratyangi\Web\HeaderAccount;
use Pratyangi\Web\LandingController;

$t = new TestCase('landing_account_test');

/** A controller with a fake session lookup; $calls counts how often the lookup ran. */
$controller = static function (?Closure $lookup, ?Logger $logger = null): LandingController {
    $config = Helpers::config();
    $logger ??= new Logger('silent');
    $service = new CatalogService(
        new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
        new CatalogCache($config->catalogCacheDir, $config->catalogCacheSeconds, new FixedClock(), $logger),
        $logger,
    );
    return new LandingController($service, $lookup, $logger);
};

$t->test('signed in: avatar initial and first name, escaped', function () use ($t, $controller): void {
    $b = $controller(static fn (string $token): ?array => ['name' => 'asha Rao', 'email' => 'asha@example.com'])->home('tok')->body();
    $t->contains('<span class="pSCCwa_avatar" aria-hidden="true">A</span>', $b);
    $t->contains('<span class="pSCCwa_accountHello">Hello,</span><span class="pSCCwa_accountName">asha</span>', $b);
    $t->contains('aria-label="My account, signed in as asha Rao"', $b);
    $t->contains('title="asha@example.com"', $b);
    $t->ok(!str_contains($b, 'aria-label="Sign in"'), 'no sign-in link when signed in');
    $t->contains('<span class="pSCCwa_count" data-pf="bag-count">0</span>', $b, 'the bag count starts at 0 (site.js fills it)');
});

$t->test('signed out: sign-in link in the Node style', function () use ($t, $controller): void {
    $b = $controller(static fn (string $token): ?array => null)->home(null)->body();
    $t->contains('<a class="pSCCwa_iconButton" aria-label="Sign in" href="/login">', $b); // Node: <Link href="/login">
    $t->contains('lucide-user-round', $b);
    $t->ok(!str_contains($b, 'pSCCwa_accountLink'), 'no account link');
});

$t->test('a hostile name is escaped everywhere', function () use ($t, $controller): void {
    $b = $controller(static fn (string $token): ?array => ['name' => '<script>"x"&', 'email' => 'e"<@x.com'])->home('tok')->body();
    $t->same(2, substr_count($b, '<script'), 'only the two page script tags (site.js, bag.js)');
    $t->contains('&lt;script&gt;&quot;x&quot;&amp;', $b);
    $t->contains('<span class="pSCCwa_avatar" aria-hidden="true">&lt;</span>', $b);
    $t->contains('title="e&quot;&lt;@x.com"', $b);
});

$t->test('name rules: email-like name, blank name, accents, emoji', function () use ($t): void {
    $a = HeaderAccount::fromRow(['name' => 'sam.k@example.com', 'email' => 'x']);
    $t->same(['sam.k', 'S', 'sam.k'], [$a->first, $a->initial, $a->full]);
    $a = HeaderAccount::fromRow(['name' => '   ', 'email' => 'x']);
    $t->same(['', '', ''], [$a->first, $a->initial, $a->full]);
    $a = HeaderAccount::fromRow(['name' => "  \u{00e9}lodie  Dupont ", 'email' => 'x']);
    $t->same(["\u{00e9}lodie", "\u{00c9}"], [$a->first, $a->initial]);
    $a = HeaderAccount::fromRow(['name' => "\u{1F600} Joy", 'email' => 'x']);
    $t->same("\u{1F600}", $a->initial);
    $b = (new LandingController(
        (function (): CatalogService {
            $c = Helpers::config();
            $l = new Logger('silent');
            return new CatalogService(new MockErp($c->erpMockFile, $c->erpPriceRule, $c->erpTaxMode), new CatalogCache($c->catalogCacheDir, $c->catalogCacheSeconds, new FixedClock(), $l), $l);
        })(),
        static fn (string $token): ?array => ['name' => '', 'email' => 'q@x.com'],
    ))->home('tok')->body();
    $t->contains('lucide-user-round', $b, 'no name: a person icon in the avatar');
    $t->contains('<span class="pSCCwa_accountHello">Hello</span>', $b, 'no name: just Hello');
    $t->ok(!str_contains($b, 'pSCCwa_accountName'), 'no name: no name element');
    $t->contains('aria-label="My account"', $b);
});

$t->test('invalid cookie (lookup finds nothing) renders signed out', function () use ($t, $controller): void {
    $r = $controller(static fn (string $token): ?array => null)->home('not-a-real-token');
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
});

$t->test('a failing lookup renders signed out, 200, and logs only the class name', function () use ($t, $controller): void {
    $lines = [];
    $logger = new Logger('debug', static function (string $line) use (&$lines): void { $lines[] = $line; });
    $r = $controller(static function (string $token): ?array {
        throw new PDOException('SQLSTATE[HY000] [2002] secret-host cookie=' . $token);
    }, $logger)->home('SECRET-COOKIE-VALUE');
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
    $log = implode("\n", $lines);
    $t->contains('PDOException', $log);
    $t->ok(!str_contains($log, 'SECRET-COOKIE-VALUE') && !str_contains($log, 'secret-host'), 'no cookie or driver text in the log');
});

$t->test('no cookie: the lookup (and so the database) is never touched', function () use ($t, $controller): void {
    $calls = 0;
    $c = $controller(static function (string $token) use (&$calls): ?array { $calls++; return null; });
    $c->home(null);
    $c->home('');
    $t->same(0, $calls);
    $r = $c->home(null);
    $t->ok(!$r->hasHeader('Cache-Control'), 'no private cache header without a cookie');

    // through the whole App: the default test pdoFactory throws if anything reaches the database
    $r = Helpers::app()->handle(Helpers::request('GET', '/'));
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
});

$t->test('through the App: a cookie with an unreachable database is signed out and the page still works', function () use ($t): void {
    $r = Helpers::app()->handle(Helpers::request('GET', '/', ['Cookie' => 'pf_session=abc123']));
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
    $t->contains('private, no-store', (string) $r->header('Cache-Control'));
    $t->contains('Cookie', (string) $r->header('Vary'));
});

exit($t->finish());

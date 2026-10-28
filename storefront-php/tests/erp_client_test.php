<?php
declare(strict_types=1);

// No database, no real ERP: talks to a local fake ERP (php -S on 127.0.0.1) that this script starts and stops.
// Run: php tests/erp_client_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeErp.php';

use Pratyangi\Erp\ErpClient;
use Pratyangi\Erp\ErpException;

$t = new TestCase('erp_client_test');
$fake = new FakeErp();
$fake->start();
register_shutdown_function(static fn () => $fake->stop());

$client = static fn (array $o = []): ErpClient => new ErpClient(
    $o['url'] ?? $fake->baseUrl(),
    $o['key'] ?? FakeErp::KEY,
    $o['warehouse'] ?? null,
    $o['rule'] ?? 'offer-else-retail',
    $o['tax'] ?? 'exclusive',
    $o['timeout'] ?? 3000,
);

$t->test('follows next_cursor across pages, skips bad and repeated rows, counts them', function () use ($t, $fake, $client): void {
    $got = $client()->listProducts();
    $t->same(['FAKE-1', 'FAKE-2', 'FAKE-4'], array_map(fn ($p) => $p->sku, $got['products']));
    $t->same(2, $got['skipped'], 'one unreadable price, one repeated sku');
    $t->same([129950, 99900], [$got['products'][0]->sellingPricePaise, $got['products'][1]->sellingPricePaise], 'offer else retail');
    $t->same(500, $got['products'][0]->taxRateBp);
    $reqs = $fake->requests();
    $t->same(['limit=200&after_id=0', 'limit=200&after_id=3'], array_column($reqs, 'query'));
    $t->same([true, true], array_column($reqs, 'authOk'), 'bearer key sent');
    $t->same('application/json', $reqs[0]['accept']);
});

$t->test('stock: batches, warehouse sent, volunteered SKUs ignored', function () use ($t, $fake, $client): void {
    $skus = [];
    for ($i = 1; $i <= 250; $i++) {
        $skus[] = 'S' . $i;
    }
    $before = count($fake->requests());
    $got = $client(['warehouse' => 'Main'])->getAvailability($skus);
    $reqs = array_slice($fake->requests(), $before);
    $t->same(3, count($reqs), '250 SKUs = batches of 100, 100, 50');
    $first = json_decode($reqs[0]['body'], true);
    $t->same([100, 'Main'], [count($first['skus']), $first['warehouse']]);
    $t->same(250, count($got));
    $t->same(false, isset($got['NOT-ASKED']));
    $t->same(['S1' => 0, 'S2' => 1, 'S3' => 2, 'S4' => 0], array_slice($got, 0, 4));
    $before = count($fake->requests());
    $client()->getAvailability(['A']);
    $body = json_decode($fake->requests()[$before]['body'], true);
    $t->same(false, array_key_exists('warehouse', $body), 'no warehouse key when none is set');
});

$t->test('refused key is an auth failure and the key never appears in the message', function () use ($t, $client): void {
    $e = $t->throws(ErpException::class, fn () => $client(['key' => 'wrong-key-wrong-key-wrong-key-wrong'])->listProducts());
    $t->same('auth', $e->kind);
    $t->same(false, str_contains($e->getMessage(), 'wrong-key'));
});

$t->test('server failure is "unavailable"', function () use ($t, $fake, $client): void {
    $fake->flag('fail');
    $e = $t->throws(ErpException::class, fn () => $client()->listProducts());
    $t->same('unavailable', $e->kind);
    $fake->flag('fail', false);
});

$t->test('a cursor that does not advance is refused', function () use ($t, $fake, $client): void {
    $fake->flag('cursor');
    $e = $t->throws(ErpException::class, fn () => $client()->listProducts());
    $t->same('bad-response', $e->kind);
    $t->contains('did not advance', $e->getMessage());
    $fake->flag('cursor', false);
});

$t->test('redirects are refused, not followed', function () use ($t, $fake, $client): void {
    $fake->flag('redirect');
    $e = $t->throws(ErpException::class, fn () => $client()->listProducts());
    $t->same('unreachable', $e->kind);
    $fake->flag('redirect', false);
});

$t->test('answers over the size cap are refused', function () use ($t, $fake, $client): void {
    $fake->flag('big');
    $e = $t->throws(ErpException::class, fn () => $client(['timeout' => 8000])->listProducts());
    $t->same('bad-response', $e->kind);
    $t->contains('too large', $e->getMessage());
    $fake->flag('big', false);
});

$t->test('nothing listening is "unreachable"; an expired deadline is "timeout"', function () use ($t, $client): void {
    $e = $t->throws(ErpException::class, fn () => $client(['url' => 'http://127.0.0.1:9/'])->listProducts());
    $t->ok(in_array($e->kind, ['unreachable', 'timeout'], true));
    $e = $t->throws(ErpException::class, fn () => $client()->listProducts(microtime(true) - 1));
    $t->same('timeout', $e->kind);
});

$t->test('constructor refuses a non-http address', function () use ($t): void {
    $t->throws(InvalidArgumentException::class, fn () => new ErpClient('ftp://example.com/', 'k'));
});

exit($t->finish());

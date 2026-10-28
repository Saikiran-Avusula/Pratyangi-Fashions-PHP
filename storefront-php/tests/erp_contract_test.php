<?php
declare(strict_types=1);

// No database. Run: php tests/erp_contract_test.php
// What the ERP team told us (docs/API_CONTRACT.md 3.1): two keys that each open only their own endpoints, 200 products per page and 100 SKUs per stock call,
// an offer of null or 0 is no offer, tax on top, picture address = ERP base + "/" + image_path, both ids on every order, 422 is final.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeErp.php';

use Pratyangi\Catalog\ProductImage;
use Pratyangi\ConfigException;
use Pratyangi\Erp\ErpClient;
use Pratyangi\Erp\ErpException;
use Pratyangi\Erp\ErpNotConfiguredException;
use Pratyangi\Erp\ErpOrderRejectedException;

$t = new TestCase('erp_contract_test');
$fake = new FakeErp();
$fake->start();
register_shutdown_function(static fn () => $fake->stop());
$client = static fn (?string $catalogKey, ?string $orderKey): ErpClient => new ErpClient($fake->baseUrl(), (string) $catalogKey, null, 'offer-else-retail', 'exclusive', 3000, null, null, $orderKey);
$order = ['orderNo' => 'PF-TESTTESTTEST', 'customerName' => 'Priya Rao', 'customerMobile' => '9876543210', 'shippingAddress' => '12-3-45, Road, Hyderabad, Telangana - 500034',
    'items' => [['sku' => 'FAKE-1', 'quantity' => 1]], 'orderAmountPaise' => 136448, 'paymentStatus' => 'PAID'];

$t->test('two keys: the catalogue key opens the catalogue, the order key opens only api/order_create.php, neither opens the other', function () use ($t, $client, $order, $fake): void {
    $t->same(3, count($client(FakeErp::KEY, FakeErp::ORDER_KEY)->listProducts()['products']));
    $t->same('auth', $t->throws(ErpException::class, fn () => $client(FakeErp::ORDER_KEY, FakeErp::ORDER_KEY)->listProducts())->kind, 'the order key does not open the catalogue');
    $t->same('auth', $t->throws(ErpException::class, fn () => $client(FakeErp::KEY, FakeErp::KEY)->createOrder($order))->kind, 'the catalogue key does not open orders (401)');
    $made = $client(FakeErp::KEY, FakeErp::ORDER_KEY)->createOrder($order);
    $t->same(['PF-TESTTESTTEST', false], [$made['erpOrderNo'], $made['duplicate']]);
    $t->throws(ErpNotConfiguredException::class, fn () => $client(FakeErp::KEY, null)->createOrder($order), 'no order key: nothing is sent, and there is no fall back to the catalogue key');
    $paths = array_column(array_filter($fake->requests(), static fn (array $r): bool => $r['path'] === '/api/order_create.php'), 'orderAuthOk');
    $t->same([false, true], $paths, 'two order requests reached the ERP (one with the wrong key, one right); the keyless call sent none');
});

$t->test('the order carries BOTH ids and a payment status; a repeat is duplicate:true = the same order, success', function () use ($t, $client, $order, $fake): void {
    $sent = null;
    foreach ($fake->requests() as $r) {
        if ($r['path'] === '/api/order_create.php' && $r['orderAuthOk']) {
            $sent = json_decode($r['body'], true);
        }
    }
    $t->same(['PF-TESTTESTTEST', 'PF-TESTTESTTEST', 'PAID', '1364.48', 'WEBSITE'], [$sent['order_no'], $sent['external_order_id'], $sent['payment_status'], $sent['order_amount'], $sent['source']]);
    $again = $client(FakeErp::KEY, FakeErp::ORDER_KEY)->createOrder($order);
    $t->same(true, $again['duplicate'], 'the same order number comes back as the existing order');
    $t->same(100, $again['erpOrderId'], 'the same ERP order id');
    $cod = $client(FakeErp::KEY, FakeErp::ORDER_KEY)->createOrder(['orderNo' => 'PF-TESTCODCODCO', 'paymentStatus' => 'COD'] + $order);
    $t->same(false, $cod['duplicate'], 'COD is accepted by the ERP too (the shop has no COD route)');
});

$t->test('answers: 422 is final whatever the wording, 401 and 503 are configuration errors (never a refusal), 500 is an unknown outcome', function () use ($t, $client, $order, $fake): void {
    $c = $client(FakeErp::KEY, FakeErp::ORDER_KEY);
    $kind = static fn (string $flag, string $no): string => (function () use ($c, $order, $fake, $flag, $no): string {
        $fake->flag($flag);
        try {
            $c->createOrder(['orderNo' => $no] + $order);
            return 'created';
        } catch (ErpOrderRejectedException $e) {
            return 'refused:' . $e->kind . ($e->isFinal() ? ':final' : ':not-final');
        } catch (ErpException $e) {
            return 'unknown:' . $e->kind;
        } finally {
            $fake->flag($flag, false);
        }
    })();
    $t->same('refused:out-of-stock:final', $kind('order_oos', 'PF-AAAAAAAAAAAA'));
    $t->same('refused:unknown-sku:final', $kind('order_unknown', 'PF-BBBBBBBBBBBB'));
    $t->same('refused:unprocessable:final', $kind('order_422', 'PF-CCCCCCCCCCCC'), 'a 422 with wording we do not know is still final');
    $t->same('unknown:disabled', $kind('order_503', 'PF-DDDDDDDDDDDD'), '503: the order API is switched off = a configuration problem');
    $t->same('unknown:unavailable', $kind('order_down', 'PF-EEEEEEEEEEEE'), '500: unknown outcome, retried later with the same order number');
});

$t->test('catalogue: 200 products a page with after_id, 100 SKUs per stock call, offer 0 or null is no offer, tax on top', function () use ($t, $client, $fake): void {
    $before = count($fake->requests());
    $got = $client(FakeErp::KEY, null)->listProducts();
    $queries = array_column(array_slice($fake->requests(), $before), 'query');
    $t->same(['limit=200&after_id=0', 'limit=200&after_id=3'], $queries);
    $t->same(99900, $got['products'][1]->sellingPricePaise, 'offer_price null: the retail price');
    $skus = array_map(static fn (int $i): string => 'S-' . $i, range(1, 250));
    $before = count($fake->requests());
    $client(FakeErp::KEY, null)->getAvailability($skus);
    $sizes = array_map(static fn (array $r): int => count(json_decode($r['body'], true)['skus']), array_slice($fake->requests(), $before));
    $t->same([100, 100, 50], $sizes);
    $t->same(500, $got['products'][0]->taxRateBp, 'the ERP price excludes tax; tax_rate is added on top');
    // an offer of 0.00 is no offer (the mock fixture has one)
    $mock = new Pratyangi\Erp\MockErp(dirname(__DIR__) . '/tests/fixtures/mock_erp.json');
    $cotton = array_values(array_filter($mock->listProducts()['products'], static fn ($p): bool => $p->sku === 'MOCK-SAR-002'))[0];
    $t->same(249900, $cotton->sellingPricePaise, 'offer_price "0.00": the retail price');
});

$t->test('settings: ERP_CATALOG_KEY (ERP_API_KEY is the same key), ERP_ORDER_KEY (ERP_ORDER_API_KEY is the same key), no fallback, needed for payments', function () use ($t): void {
    $base = ['ERP_MODE' => 'http', 'ERP_BASE_URL' => 'http://127.0.0.1:9'];
    $cat = str_repeat('c', 40);
    $ord = str_repeat('o', 40);
    $c = Helpers::config($base + ['ERP_CATALOG_KEY' => $cat, 'ERP_ORDER_KEY' => $ord]);
    $t->same([$cat, $ord], [$c->erpApiKey(), $c->erpOrderApiKey()]);
    $c = Helpers::config($base + ['ERP_API_KEY' => $cat, 'ERP_ORDER_API_KEY' => $ord]);
    $t->same([$cat, $ord], [$c->erpApiKey(), $c->erpOrderApiKey()], 'the old names still work');
    $c = Helpers::config($base + ['ERP_CATALOG_KEY' => $cat, 'ERP_API_KEY' => str_repeat('x', 40)]);
    $t->same($cat, $c->erpApiKey(), 'ERP_CATALOG_KEY wins');
    $t->same(null, Helpers::config($base + ['ERP_CATALOG_KEY' => $cat])->erpOrderApiKey(), 'no order key = none, never the catalogue key');
    $e = $t->throws(ConfigException::class, fn () => Helpers::config($base + ['ERP_CATALOG_KEY' => $cat, 'PAYMENTS_ENABLED' => 'true', 'RAZORPAY_KEY_ID' => 'rzp_test_AbCdEf123456', 'RAZORPAY_KEY_SECRET' => 'x']));
    $t->contains('ERP_ORDER_KEY: required when PAYMENTS_ENABLED=true', $e->getMessage());
    $t->contains('ERP_CATALOG_KEY: required', $t->throws(ConfigException::class, fn () => Helpers::config($base))->getMessage());
});

$t->test('pictures: ERP base + "/" + image_path on the cards, the product page and the bag page; the placeholder without a picture or without an ERP address; the ERP address is allowed in img-src', function () use ($t): void {
    $file = Helpers::tempDir('pf_img_') . '/erp.json';
    $row = ['category' => 'Sarees', 'fabric' => null, 'pattern_name' => null, 'design_model' => null, 'color' => null, 'size' => null, 'mrp' => '2000.00', 'retail_price' => '2000.00', 'offer_price' => null, 'tax_rate' => '5.00'];
    file_put_contents($file, json_encode(['products' => [['sku' => 'IMG-1', 'name' => 'With picture', 'image_path' => 'uploads/products/a.jpg'] + $row, ['sku' => 'IMG-2', 'name' => 'No picture', 'image_path' => null] + $row, ['sku' => 'IMG-3', 'name' => 'Bad path', 'image_path' => 'http://evil.example/x.jpg'] + $row], 'stock' => ['IMG-1' => 5, 'IMG-2' => 5, 'IMG-3' => 5]]));
    $config = Helpers::config(['ERP_MODE' => 'http', 'ERP_BASE_URL' => 'https://erp.example.test/', 'ERP_CATALOG_KEY' => str_repeat('c', 40), 'ERP_MOCK_FILE' => $file, 'CATALOG_CACHE_SECONDS' => '0']);
    $app = Helpers::app($config, null, null, new Pratyangi\Erp\MockErp($file));
    $page = $app->handle(Helpers::request('GET', '/new-arrivals'));
    $b = $page->body();
    $t->contains('src="https://erp.example.test/uploads/products/a.jpg"', $b, 'the card of the product with a picture');
    $t->same(2, substr_count($b, 'src="/assets/images/reference/product-1.webp"'), 'the others show the placeholder (none, and a bad path)');
    $t->ok(!str_contains($b, 'evil.example'), 'a picture address outside uploads/products is never used');
    $p = $app->handle(Helpers::request('GET', '/product?sku=IMG-1'))->body();
    $t->contains('src="https://erp.example.test/uploads/products/a.jpg"', $p, 'the product page');
    $csp = (string) $page->header('Content-Security-Policy');
    $t->contains("img-src 'self' data: https://erp.example.test;", $csp, 'only the ERP address is added to img-src');
    $t->contains('data-pf-image-base="https://erp.example.test"', $app->handle(Helpers::request('GET', '/bag'))->body(), 'the bag page tells the script the base');
    // without an ERP address (mock or off) nothing changes
    $mock = Helpers::app(Helpers::config(['ERP_MOCK_FILE' => $file, 'CATALOG_CACHE_SECONDS' => '0']));
    $mb = $mock->handle(Helpers::request('GET', '/new-arrivals'));
    $t->same(3, substr_count($mb->body(), 'src="/assets/images/reference/product-1.webp"'), 'mock ERP: placeholders');
    $t->ok(str_contains((string) $mb->header('Content-Security-Policy'), "img-src 'self' data:;"), 'mock ERP: the policy is unchanged');
    Helpers::app(Helpers::config()); // leaves ProductImage as it was
});

exit($t->finish());

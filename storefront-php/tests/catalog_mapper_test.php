<?php
declare(strict_types=1);

// No database. Run: php tests/catalog_mapper_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Catalog\CatalogCache;
use Pratyangi\Catalog\CatalogMapper;
use Pratyangi\Catalog\CatalogService;
use Pratyangi\Erp\ErpException;
use Pratyangi\Erp\ErpPort;
use Pratyangi\Erp\ErpProduct;
use Pratyangi\Erp\ErpRowMapper;
use Pratyangi\Erp\MockErp;
use Pratyangi\Erp\NotConnectedErp;
use Pratyangi\Http\ApiException;

$t = new TestCase('catalog_mapper_test');
$p = static fn (array $o = []): ErpProduct => new ErpProduct($o['sku'] ?? 'S1', $o['name'] ?? 'Saree', $o['category'] ?? 'Sarees', null, null, null, $o['mrp'] ?? 1499900, $o['price'] ?? 1149950, $o['tax'] ?? 500, $o['image'] ?? null);

$t->test('exactly 12 keys in contract order, rupees as JavaScript prints them', function () use ($t, $p): void {
    $dto = CatalogMapper::toDto($p(), 4);
    $t->same(['sku', 'name', 'category', 'fabric', 'color', 'size', 'price', 'mrp', 'taxPercent', 'imagePath', 'inStock', 'availableQuantity'], array_keys($dto));
    $t->same('{"sku":"S1","name":"Saree","category":"Sarees","fabric":null,"color":null,"size":null,"price":11499.5,"mrp":14999,"taxPercent":5,"imagePath":null,"inStock":true,"availableQuantity":4}', json_encode($dto));
});

$t->test('stock: capped at 10, inStock from the real count, never negative', function () use ($t, $p): void {
    $t->same(10, CatalogMapper::toDto($p(), 25)['availableQuantity']);
    $t->same(10, CatalogMapper::toDto($p(), 10)['availableQuantity']);
    $t->same(9, CatalogMapper::toDto($p(), 9)['availableQuantity']);
    $zero = CatalogMapper::toDto($p(), 0);
    $t->same([false, 0], [$zero['inStock'], $zero['availableQuantity']]);
    $t->same(0, CatalogMapper::toDto($p(), -3)['availableQuantity']);
});

$t->test('mrp is never below price', function () use ($t, $p): void {
    $t->same(11499.5, CatalogMapper::toDto($p(['mrp' => 0]), 1)['mrp']);
    $t->same(11499.5, CatalogMapper::toDto($p(['mrp' => 1000]), 1)['mrp']);
    $t->same(11499.5, CatalogMapper::toDto($p(['mrp' => 1149950]), 1)['mrp']);
    $t->same(11499.5, CatalogMapper::toDto($p(['mrp' => 100000001]), 1)['mrp']);
});

$t->test('unpriced and over-priced items are hidden', function () use ($t, $p): void {
    $t->same(false, CatalogMapper::isSellable($p(['price' => 0])));
    $t->same(true, CatalogMapper::isSellable($p(['price' => 100000000])));
    $t->same(false, CatalogMapper::isSellable($p(['price' => 100000001])));
});

$t->test('imagePath only under uploads/products/ and only picture files', function () use ($t): void {
    foreach (['uploads/products/a.jpg', 'uploads/products/sub/a-b_c.PNG', 'uploads/products/x.webp'] as $path) {
        $t->same($path, CatalogMapper::verifiedImagePath($path), $path);
    }
    foreach ([null, '', '../../etc/passwd', 'uploads/products/../x.jpg', 'uploads/other/a.jpg', 'http://x/uploads/products/a.jpg', '/uploads/products/a.jpg', 'uploads/products/a.php', 'uploads/products/.hidden.jpg', "uploads/products/a.jpg\n"] as $bad) {
        $t->same(null, CatalogMapper::verifiedImagePath($bad), (string) $bad);
    }
});

$t->test('list is sorted by name then sku', function () use ($t, $p): void {
    $list = CatalogMapper::buildList([$p(['sku' => 'B', 'name' => 'beta']), $p(['sku' => 'A2', 'name' => 'Alpha']), $p(['sku' => 'A1', 'name' => 'Alpha']), $p(['sku' => 'Z', 'name' => 'alpha'])], []);
    $t->same(['Z', 'A1', 'A2', 'B'], array_column($list, 'sku'), 'letters tie: lower case first, then sku');
});

$t->test('row mapping: offer-else-retail, retail-only, tax none, unreadable skipped', function () use ($t): void {
    $row = ['sku' => ' X1 ', 'name' => 'N', 'category' => ' ', 'fabric' => null, 'pattern_name' => null, 'design_model' => null, 'color' => 'Red', 'size' => null, 'mrp' => '100.00', 'retail_price' => '80.00', 'offer_price' => '60.50', 'tax_rate' => '5.00', 'image_path' => null];
    $a = ErpRowMapper::map($row, 'offer-else-retail', 'exclusive');
    $t->same([6050, 10000, 500, 'X1', null], [$a->sellingPricePaise, $a->mrpPaise, $a->taxRateBp, $a->sku, $a->category]);
    $t->same(8000, ErpRowMapper::map($row, 'retail-only', 'exclusive')->sellingPricePaise);
    $t->same(0, ErpRowMapper::map($row, 'offer-else-retail', 'none')->taxRateBp);
    $t->same(8000, ErpRowMapper::map(['offer_price' => '0.00'] + $row, 'offer-else-retail', 'exclusive')->sellingPricePaise, 'a zero offer falls back to retail');
    $t->same(null, ErpRowMapper::map(['tax_rate' => 'x'] + $row, 'offer-else-retail', 'exclusive'), 'unreadable tax skips the row when tax is on');
    $t->same(0, ErpRowMapper::map(['tax_rate' => 'x'] + $row, 'offer-else-retail', 'none')->taxRateBp);
    $t->same(null, ErpRowMapper::map(['retail_price' => '12,99', 'offer_price' => null] + $row, 'offer-else-retail', 'exclusive'));
    unset($row['color']);
    $t->same(null, ErpRowMapper::map($row, 'offer-else-retail', 'exclusive'), 'a missing key skips the row');
});

$mock = static fn (): MockErp => new MockErp(dirname(__DIR__) . '/tests/fixtures/mock_erp.json');
$q = ['category' => null, 'q' => null, 'limit' => 24, 'cursor' => null];

$t->test('mock fixture through the service: filters, paging, totals', function () use ($t, $mock, $q): void {
    $dir = Helpers::tempDir();
    $service = new CatalogService($mock(), new CatalogCache($dir, 60, new FixedClock()));
    $all = $service->list($q);
    $t->same(5, $all['page']['total'], 'zero-priced and unreadable rows are hidden');
    $t->same(null, $all['page']['nextCursor']);
    $by = array_column($all['page']['products'], null, 'sku');
    $t->same(null, $by['MOCK-DUP-001']['imagePath']);
    $t->same(10, $by['MOCK-SAR-002']['availableQuantity']);
    $t->same(11499, $by['MOCK-SAR-001']['price']);
    $t->same(1199.5, $by['MOCK-KUR-001']['price']);
    $t->same(false, $by['MOCK-KUR-002']['inStock']);
    $page = $service->list(['limit' => 2, 'cursor' => '2'] + $q);
    $t->same([2, 5, '4'], [count($page['page']['products']), $page['page']['total'], $page['page']['nextCursor']]);
    $t->same(2, $service->list(['category' => 'KURTIS'] + $q)['page']['total'], 'category is case-insensitive exact');
    $t->same(1, $service->list(['q' => 'maroon'] + $q)['page']['total'], 'q searches colour');
    $t->same(0, $service->list(['q' => 'mock-'] + $q)['page']['total'], 'q does not search the sku (a typed word must start a word of name, fabric, category or colour)');
    $t->same([], $service->list(['cursor' => '999'] + $q)['page']['products']);
    Helpers::rmTree($dir);
});

$t->test('cache: fresh copy, max-age counts down, stale on ERP failure, cool-down, 503 text', function () use ($t, $mock, $q): void {
    $dir = Helpers::tempDir();
    $clock = new FixedClock();
    $state = new stdClass();
    $state->calls = 0;
    $state->failing = false;
    $erp = new class ($mock(), $state) implements ErpPort {
        public function __construct(private ErpPort $inner, private stdClass $state)
        {
        }

        public function listProducts(?float $deadline = null): array
        {
            $this->state->calls++;
            if ($this->state->failing) {
                throw new ErpException('down', 'unreachable');
            }
            return $this->inner->listProducts($deadline);
        }

        public function getAvailability(array $skus, ?float $deadline = null): array
        {
            return $this->inner->getAvailability($skus, $deadline);
        }

        public function createOrder(array $order): array
        {
            return $this->inner->createOrder($order);
        }
    };
    $service = new CatalogService($erp, new CatalogCache($dir, 60, $clock));
    $t->same(60, $service->list($q)['maxAge']);
    $clock->advance(20.5);
    $r = $service->list($q);
    $t->same([1, 39], [$state->calls, $r['maxAge']], 'served from the file, 39 whole seconds left');
    $state->failing = true;
    $clock->advance(40.0); // 60.5 s old: expired
    $r = $service->list($q);
    $t->same([2, 0, 5], [$state->calls, $r['maxAge'], $r['page']['total']], 'stale copy served with no-store');
    $service->list($q);
    $t->same(2, $state->calls, 'cool-down: no new ERP call within 5 s');
    $clock->advance(6.0);
    $service->list($q);
    $t->same(3, $state->calls);
    $clock->advance(700.0); // older than 60 + 600 s
    $e = $t->throws(ApiException::class, fn () => $service->list($q));
    $t->same([503, 'The catalogue is temporarily unavailable. Please try again in a moment.'], [$e->status, $e->getMessage()]);
    Helpers::rmTree($dir);
});

$t->test('ERP off is the "not available yet" 503; ttl 0 never caches', function () use ($t, $mock, $q): void {
    $dir = Helpers::tempDir();
    $off = new CatalogService(new NotConnectedErp(), new CatalogCache($dir, 60, new FixedClock()));
    $e = $t->throws(ApiException::class, fn () => $off->list($q));
    $t->same([503, 'The catalogue is not available yet. Please try again later.'], [$e->status, $e->getMessage()]);
    $zero = new CatalogService($mock(), new CatalogCache($dir, 0, new FixedClock()));
    $t->same(0, $zero->list($q)['maxAge']);
    $t->same(false, is_file($dir . '/catalog.json'));
    Helpers::rmTree($dir);
});

$t->test('unusable cache folder fails open', function () use ($t, $mock, $q): void {
    $file = (string) tempnam(Tmp::base(), 'pfx');
    $service = new CatalogService($mock(), new CatalogCache($file . '/sub', 60, new FixedClock()));
    $t->same(5, $service->list($q)['page']['total']);
    unlink($file);
});

exit($t->finish());

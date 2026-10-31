<?php
declare(strict_types=1);

// No database. UI10: the DEMO size source (Variants) and the product page's Open Graph / Twitter tags.
require __DIR__ . '/lib/TestCase.php';
require dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Catalog\Variants;
use Pratyangi\Http\Request;
use Pratyangi\Web\CatalogPages;

$t = new TestCase('ui10_test');
$kurti = ['sku' => 'K-1', 'name' => 'Cotton Kurti', 'category' => 'Kurtis', 'size' => 'M', 'inStock' => true, 'price' => 1888.95, 'color' => 'Indigo', 'imagePath' => null];

$t->test('sizes: S-XXL with one sold out; none for free size, sarees, kids ages, sold-out products', function () use ($t, $kurti): void {
    $s = Variants::sizes($kurti);
    $t->same(Variants::SIZES, array_column($s, 'size'));
    $t->same(1, count(array_filter($s, static fn (array $v): bool => !$v['inStock'])), 'one size sold out');
    $t->same($s, Variants::sizes($kurti), 'stable for the same SKU');
    $t->ok(preg_match('/^S:[01],M:[01],L:[01],XL:[01],XXL:[01]$/', Variants::attr($kurti)) === 1, Variants::attr($kurti));
    foreach ([['size' => 'Free'], ['size' => 'Free Size'], ['category' => 'Sarees', 'size' => 'M'], ['size' => '4-5Y'], ['size' => ''], ['inStock' => false]] as $change) {
        $t->same(null, Variants::sizes(array_merge($kurti, $change)), json_encode($change));
        $t->same('', Variants::attr(array_merge($kurti, $change)));
    }
});

$t->test('share meta: og + twitter, absolute URLs, INR price, escaped', function () use ($t, $kurti): void {
    $req = new Request('GET', '/product?sku=K-1', ['Host' => 'test.pratyangi.com']);
    $html = CatalogPages::shareMeta($req, array_merge($kurti, ['name' => 'Kurti "Rani" <b>']));
    foreach (['<meta property="og:type" content="product"/>', '<meta property="og:url" content="https://test.pratyangi.com/product?sku=K-1"/>',
        '<meta property="og:image" content="https://test.pratyangi.com/', '<meta property="product:price:amount" content="1888.95"/>',
        '<meta property="product:price:currency" content="INR"/>', '<meta name="twitter:card" content="summary_large_image"/>',
        'og:title" content="Kurti &quot;Rani&quot; &lt;b&gt; | Pratyangi Fashions"', 'og:description" content="₹1,888.95 · '] as $needle) {
        $t->contains($needle, $html);
    }
    $t->ok(!str_contains($html, '<b>'), 'name escaped');
    $local = CatalogPages::shareMeta(new Request('GET', '/product', ['Host' => '127.0.0.1:8100']), $kurti);
    $t->contains('content="http://127.0.0.1:8100/product?sku=K-1"', $local, 'local dev is http');
});
exit($t->finish());

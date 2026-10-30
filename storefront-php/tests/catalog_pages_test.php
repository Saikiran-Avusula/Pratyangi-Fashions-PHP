<?php
declare(strict_types=1);

// No database, no network. Run: php tests/catalog_pages_test.php
// The catalogue pages (/new-arrivals and /product?sku=...), the product card links, the header and footer links, escaping, StaticFiles.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Erp\NotConnectedErp;
use Pratyangi\Web\CatalogLinks;
use Pratyangi\Web\StaticFiles;

$t = new TestCase('catalog_pages_test');
$root = dirname(__DIR__);

/**
 * A mock ERP file. Each row: [sku, name, price, pieces, category, fabric, color, size].
 * @param list<array{0:string,1:string,2:string,3:int,4:?string,5:?string,6:?string,7:?string}> $rows
 */
$mockFile = static function (array $rows): string {
    $products = [];
    $stock = [];
    foreach ($rows as [$sku, $name, $price, $pieces, $category, $fabric, $color, $size]) {
        $products[] = ['sku' => $sku, 'name' => $name, 'category' => $category, 'fabric' => $fabric, 'pattern_name' => null, 'design_model' => null,
            'color' => $color, 'size' => $size, 'mrp' => $price, 'retail_price' => $price, 'offer_price' => null, 'tax_rate' => '5.00', 'image_path' => null];
        $stock[$sku] = $pieces;
    }
    $file = Helpers::tempDir('pf_pages_') . '/erp.json';
    file_put_contents($file, json_encode(['products' => $products, 'stock' => $stock], JSON_THROW_ON_ERROR));
    return $file;
};
$appWith = static fn (string $file) => Helpers::app(Helpers::config(['ERP_MOCK_FILE' => $file]));
$get = static fn ($app, string $uri, array $headers = []) => $app->handle(Helpers::request('GET', $uri, $headers));
$evilText = '"><script>alert(1)</script> \'q\' & "d"';

$t->test('/new-arrivals with the shipped mock ERP: 200, Node markup, filters, cards, three deferred scripts, nothing inline', function () use ($t, $get): void {
    $app = Helpers::app();
    $r = $get($app, '/new-arrivals');
    $t->same(200, $r->status());
    $t->same('text/html; charset=utf-8', $r->header('Content-Type'));
    $b = $r->body();
    $t->contains('<title>New Arrivals | Pratyangi Fashions</title>', $b);
    foreach (['<main class="_4HCqHa_page"', 'class="pSCCwa_header"', 'class="f_viWW_footer"', '<nav class="_4HCqHa_breadcrumb" aria-label="Breadcrumb">', '<aside class="_4HCqHa_sidebar" aria-label="Catalog filters">',
        '<h1>New Arrivals</h1>', 'Discover the latest additions to our exclusive collection', 'Fresh Styles. Timeless Grace.', '<div class="_4HCqHa_grid"', '<nav class="_4HCqHa_pagination"',
        'All New Arrivals (5)', 'Sarees (2)', 'Kurtis (2)', 'Other (1)', 'Cotton (2)', 'In Stock (4)', 'Reset price range', 'Up to ₹10,000', '₹10,000 – ₹25,000', 'Over ₹1,00,000',
        '<option value="featured" selected="">Featured</option>', '<option value="price-asc">Price: Low to High</option>', '<option value="price-desc">Price: High to Low</option>',
        '5 Products', 'Sort by:', 'aria-pressed="false" data-value="Maroon"'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->same(5, substr_count($b, '<article class="JEjkwq_card"'), 'all five sellable products are on the page');
    $t->same(0, substr_count($b, ' hidden="" data-pf="clear"'), 'no filter ticked: the clear button is hidden');
    $t->contains('/assets/css/0xslsry9_slir.css', $b);
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/bag.js"></script><script defer src="/assets/listing.js"></script>', $b);
    $t->same(4, substr_count($b, '<script'));
    $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), 'no inline script');
    $t->same(0, preg_match('/\son[a-z]+="/i', $b), 'no inline event handler');
    $t->same(0, preg_match('#(src|href)="(https?:)?//#', $b), 'no external resource');
    $t->ok(!str_contains($b, '/_next/'));
    $t->ok(!str_contains($b, 'uploads/products'), 'only the placeholder picture');
    $t->contains('data-min="0" data-max="15000"', $b, 'slider spans the cheapest to the dearest, rounded outwards');
    $t->contains('data-price="11499"', $b);
    $t->contains('data-price="1199.5"', $b);
    $t->same('nosniff', $r->header('X-Content-Type-Options'));
    // the same page for HEAD
    $t->same(200, $app->handle(Helpers::request('HEAD', '/new-arrivals'))->status());
    $t->same(200, $app->handle(Helpers::request('GET', '/new-arrivals/'))->status(), 'trailing slash');
});

$t->test('?fabric= ticks that fabric, shows only its cards and ignores names the catalogue does not have; ?occasion= is ignored', function () use ($t, $get, $evilText): void {
    $app = Helpers::app();
    $b = $get($app, '/new-arrivals?fabric=Cotton')->body();
    $t->contains('<input type="checkbox" autocomplete="off" value="Cotton" checked="" data-pf="fabric"/>', $b);
    $t->same(2, preg_match_all('/<article class="JEjkwq_card"(?![^>]*hidden="")/', $b), 'two Cotton cards visible');
    $t->same(3, preg_match_all('/<article class="JEjkwq_card"[^>]*hidden=""/', $b), 'the others are hidden');
    $t->contains('2 Products', $b);
    $t->ok(!str_contains($b, 'data-pf="clear" hidden'), 'clear button visible');
    $t->ok(!str_contains($b, '"No products match these filters.</p><button type="button" data-pf="clear">') || str_contains($b, '<div class="_4HCqHa_empty" data-pf="empty" hidden="">'), 'empty box hidden');
    // two fabrics
    $b2 = $get($app, '/new-arrivals?fabric=Cotton&fabric=Rayon')->body();
    $t->same(2, substr_count($b2, ' checked=""'));
    $t->contains('3 Products', $b2);
    // unknown / hostile fabric: nothing ticked, everything listed, status 200
    foreach (['/new-arrivals?fabric=Nope', '/new-arrivals?fabric=' . rawurlencode($evilText), '/new-arrivals?occasion=Wedding', '/new-arrivals?occasion=Festive&q=' . rawurlencode($evilText), '/new-arrivals?fabric[]=x'] as $uri) {
        $r = $get($app, $uri);
        $t->same(200, $r->status(), $uri);
        $t->same(0, substr_count($r->body(), ' checked=""'), $uri);
        $t->same(0, preg_match('/<article class="JEjkwq_card"[^>]*hidden=""/', $r->body()), $uri . ': no card hidden');
        $t->contains('5 Products', $r->body(), $uri);
    }
});

$t->test('pagination: nine cards a page, the rest hidden, page buttons; "Show More" for more than five fabrics', function () use ($t, $get, $mockFile, $appWith): void {
    $rows = [];
    $fabrics = ['Aa', 'Bb', 'Cc', 'Dd', 'Ee', 'Ff', 'Gg'];
    for ($i = 1; $i <= 21; $i++) {
        $rows[] = ['PG-' . $i, 'Saree ' . $i, (string) (100 * $i), 3, 'Sarees', $fabrics[$i % 7], 'Red', null];
    }
    $app = $appWith($mockFile($rows));
    $b = $get($app, '/new-arrivals')->body();
    $t->same(21, substr_count($b, '<article class="JEjkwq_card"'));
    $t->same(9, preg_match_all('/<article class="JEjkwq_card"(?![^>]*hidden="")/', $b), 'nine visible');
    $t->same(12, preg_match_all('/<article class="JEjkwq_card"[^>]*hidden=""/', $b), 'twelve hidden');
    $t->contains('21 Products', $b);
    $t->ok(!str_contains($b, 'data-pf="pagination" hidden'), 'pagination shown');
    $t->contains('<button type="button" aria-current="page" data-page="1">1</button>', $b);
    $t->contains('<button type="button" data-page="3">3</button>', $b);
    $t->ok(!str_contains($b, 'data-page="4"'), 'three pages');
    $t->contains('+ Show More', $b);
    $t->same(2, substr_count($b, 'data-pf="fabric-choice" hidden=""') + substr_count($b, ' hidden="" data-pf="fabric-choice"'), 'fabrics six and seven start hidden');
    // a fabric beyond the first five arrives ticked and the list is open
    $b2 = $get($app, '/new-arrivals?fabric=Gg')->body();
    $t->contains('− Show Less', $b2);
    $t->same(0, substr_count($b2, 'data-pf="fabric-choice" hidden=""') + substr_count($b2, ' hidden="" data-pf="fabric-choice"'));
    $t->contains('3 Products', $b2);
    $t->ok(str_contains($b, 'Aa (3)'), 'counts per fabric');
});

$t->test('/product?sku=: 200 with the Node layout, price, MRP, tax line, stock line, button, scripts', function () use ($t, $get): void {
    $app = Helpers::app();
    $r = $get($app, '/product?sku=MOCK-SAR-001');
    $t->same(200, $r->status());
    $b = $r->body();
    foreach (['<title>Product | Pratyangi Fashions</title>', '<main class="udK_nG_page">', '<div class="udK_nG_wrap">', '<a href="/new-arrivals">← Back to New Arrivals</a>',
        '<article class="udK_nG_product" aria-labelledby="product-title"', '<h1 id="product-title">Kanjivaram Silk Saree</h1>', '<p class="udK_nG_subtitle">Maroon</p>',
        '<strong>Rs. 11,499.00</strong><del>Rs. 14,999.00</del>', '<p class="udK_nG_stock">Plus 5% tax, added at checkout.</p>', '<p class="udK_nG_stock">In stock</p>',
        '<button type="button" class="udK_nG_add" data-pf="add-product" data-sku="MOCK-SAR-001" data-bag-href="/bag">Add to bag</button>',
        'src="/assets/images/reference/product-1.webp"', 'object-position:center top', 'alt="Kanjivaram Silk Saree"', 'class="pSCCwa_header"', 'class="f_viWW_footer"'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->ok(!str_contains($b, 'udK_nG_options'), 'a product with no sibling has no options box');
    $t->ok(!str_contains($b, 'udK_nG_soldOut'));
    $t->ok(!str_contains($b, 'uploads/products'), 'the ERP picture path is not used');
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/bag.js"></script><script defer src="/assets/product.js"></script>', $b);
    $t->same(4, substr_count($b, '<script'));
    $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b));
    $t->contains('/assets/css/3wh3hbs4o_ca8.css', $b);
    $t->ok(!str_contains($b, '0xslsry9'), 'the product page does not load the listing sheet');
    // a price with no MRP discount shows no <del>; no tax line when the tax is 0 is covered by the unit below
    $b2 = $get($app, '/product?sku=MOCK-SAR-002')->body();
    $t->ok(!str_contains($b2, '<del>'));
    $t->contains('Rs. 2,499.00', $b2);
    $t->contains('<p class="udK_nG_subtitle">Indigo</p>', $b2);
    $b3 = $get($app, '/product?sku=MOCK-KUR-001')->body();
    $t->contains('<p class="udK_nG_subtitle">Teal · M</p>', $b3);
    $t->contains('Plus 5% tax', $b3);
    $b4 = $get($app, '/product?sku=MOCK-DUP-001')->body();
    $t->contains('Plus 12% tax, added at checkout.', $b4);
    $t->same(200, $app->handle(Helpers::request('HEAD', '/product?sku=MOCK-SAR-001'))->status());
});

$t->test('sold out and low stock', function () use ($t, $get, $mockFile, $appWith): void {
    $app = Helpers::app();
    $b = $get($app, '/product?sku=MOCK-KUR-002')->body();
    $t->contains('<p class="udK_nG_stock udK_nG_soldOut">Out of stock</p>', $b);
    $t->contains('data-bag-href="/bag" disabled="">Sold out</button>', $b);
    $t->contains('<p class="udK_nG_note" role="status">This option is sold out.</p>', $b);
    $t->ok(!str_contains($b, '>Add to bag<'));
    $low = $appWith($mockFile([['LOW-1', 'Last Pieces', '999.00', 2, 'Sarees', null, null, null], ['LOW-2', 'Plenty', '999.00', 4, 'Sarees', null, null, null]]));
    $b = $get($low, '/product?sku=LOW-1')->body();
    $t->contains('<p class="udK_nG_stock">Only 2 left</p>', $b);
    $t->contains('>Add to bag</button>', $b);
    $t->contains('<p class="udK_nG_stock">In stock</p>', $get($low, '/product?sku=LOW-2')->body(), 'four left is still "In stock"');
    // listing cards: badge, disabled button, low-stock line
    $l = $get($low, '/new-arrivals')->body();
    $t->contains('Only 2 left', $l);
    $sold = $get($app, '/new-arrivals')->body();
    $t->same(1, substr_count($sold, 'JEjkwq_soldOutBadge">'));
    $t->contains('aria-label="Embroidered Kurti (sold out) is out of stock"', $sold);
    $t->contains('data-instock="0"', $sold);
});

$t->test('other options: same name and category, current chip, sold-out chip, sorted by label', function () use ($t, $get, $mockFile, $appWith): void {
    $app = $appWith($mockFile([
        ['TW-1', 'Twin Saree', '1000.00', 5, 'Sarees', 'Silk', 'Red', 'M'],
        ['TW-2', 'Twin Saree', '1000.00', 0, 'Sarees', 'Silk', 'Blue', 'M'],
        ['TW-3', 'Twin Saree', '1000.00', 3, 'Sarees', 'Silk', 'Green', null],
        ['TW-4', 'Twin Saree', '1000.00', 3, 'Kurtis', 'Silk', 'Pink', null],
    ]));
    $b = $get($app, '/product?sku=TW-1')->body();
    $t->contains('<h2 id="options-title">Other options</h2>', $b);
    $t->contains('<span class="udK_nG_chip udK_nG_chipCurrent" aria-current="true">Red · M</span>', $b);
    $t->contains('<a href="/product?sku=TW-2" class="udK_nG_chip udK_nG_chipSoldOut" aria-label="Blue · M (sold out)">Blue · M</a>', $b);
    $t->contains('<a href="/product?sku=TW-3" class="udK_nG_chip" aria-label="Green">Green</a>', $b);
    $t->ok(!str_contains($b, 'sku=TW-4'), 'another category is not an option');
    $t->ok(strpos($b, 'Blue · M</a>') < strpos($b, 'Green</a>'), 'sorted by label');
    $t->ok(!str_contains($b, 'href="/product?sku=TW-1"'), 'no link to itself');
});

$t->test('not found: unknown sku, missing sku, empty sku (404, the Node message, nothing echoed)', function () use ($t, $get, $evilText): void {
    $app = Helpers::app();
    foreach (['/product?sku=NO-SUCH', '/product', '/product?sku=', '/product?other=1', '/product?sku=mock-sar-001', '/product?sku=' . rawurlencode($evilText)] as $uri) {
        $r = $get($app, $uri);
        $t->same(404, $r->status(), $uri);
        $b = $r->body();
        $t->contains('<div class="GSLLNW_note"><p>We could not find this product. It may no longer be sold.</p></div>', $b, $uri);
        $t->contains('<a href="/new-arrivals">← Back to New Arrivals</a>', $b, $uri);
        $t->contains('<title>Product | Pratyangi Fashions</title>', $b, $uri);
        $t->ok(!str_contains($b, 'udK_nG_product'), $uri);
        $t->ok(!str_contains($b, 'alert(1)'), $uri . ': the address is never printed');
        $t->same(2, substr_count($b, '<script'), $uri . ': only the page script');
    }
    // the first sku wins, like URLSearchParams.get
    $t->same(200, $get($app, '/product?sku=MOCK-SAR-001&sku=NO-SUCH')->status());
    $t->same(404, $get($app, '/product?sku=NO-SUCH&sku=MOCK-SAR-001')->status());
    // routes are GET/HEAD only
    $t->same(404, $app->handle(Helpers::request('POST', '/product?sku=MOCK-SAR-001'))->status());
    $t->same(404, $app->handle(Helpers::request('POST', '/new-arrivals'))->status());
});

$t->test('hostile product data is escaped: names, sku, category, fabric, colour (listing, card, product page, options)', function () use ($t, $get, $mockFile, $appWith, $evilText): void {
    $evilSku = 'X"><img src=x onerror=alert(1)>';
    $app = $appWith($mockFile([
        [$evilSku, $evilText, '500.00', 2, $evilText, $evilText, $evilText, $evilText],
        ['SIB-2', $evilText, '500.00', 5, $evilText, $evilText, 'Sib', 'S'],
    ]));
    foreach (['/new-arrivals', '/product?sku=' . rawurlencode($evilSku), '/product?sku=SIB-2', '/'] as $uri) {
        $r = $get($app, $uri);
        $t->same(200, $r->status(), $uri);
        $b = $r->body();
        $t->ok(!str_contains($b, '"><script>'), $uri . ': no raw script from data');
        $t->ok(!str_contains($b, '<img src=x'), $uri . ': no raw img from the sku');
        $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), $uri . ': no inline script');
        $t->same(0, preg_match('/<img[^>]*onerror/i', $b), $uri . ': no event handler attribute');
        $t->contains('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; &#039;q&#039; &amp; &quot;d&quot;', $b, $uri);
    }
    // the card link carries the sku URL-encoded
    $t->contains('href="/product?sku=X%22%3E%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E"', $get($app, '/new-arrivals')->body());
    // hostile query parameters are not echoed anywhere
    foreach (['sku', 'fabric', 'occasion', 'search', 'q', 'next', 'token'] as $param) {
        foreach (['/new-arrivals', '/product'] as $path) {
            $b = $get($app, $path . '?' . $param . '=' . rawurlencode($evilText))->body();
            $t->ok(!str_contains($b, 'alert(1)<') && !str_contains($b, '"><script>alert'), $path . ' ' . $param);
            $t->ok(!str_contains($b, "alert(1)</script>"), $path . ' ' . $param . ' (script text)');
        }
    }
});

$t->test('catalogue unavailable: the friendly state, retry button, no products, no cache; empty catalogue message; never the reason', function () use ($t, $get, $mockFile, $appWith): void {
    $off = Helpers::app(null, null, null, new NotConnectedErp());
    $r = $get($off, '/new-arrivals');
    $t->same(503, $r->status());
    $b = $r->body();
    $t->contains('<div class="GSLLNW_note" role="alert"><p>Products are being updated. Please try again in a moment.</p><button type="button" data-pf="retry">Try again</button></div>', $b);
    $t->contains('<h1>New Arrivals</h1>', $b);
    $t->ok(!str_contains($b, 'JEjkwq_card') && !str_contains($b, '_4HCqHa_sidebar'), 'no products and no filters');
    $t->same('no-store', $r->header('Cache-Control'));
    $t->ok(!str_contains($b, 'catalogue is not available yet'), 'the internal reason is not shown');
    $p = $get($off, '/product?sku=MOCK-SAR-001');
    $t->same(503, $p->status(), 'an unreadable catalogue is not a "not found"');
    $t->contains('Products are being updated. Please try again in a moment.', $p->body());
    $t->contains('data-pf="retry"', $p->body());
    $t->ok(!str_contains($p->body(), 'We could not find this product'));
    $t->same('no-store', $p->header('Cache-Control'));
    // a broken mock file: also unavailable, no exception page
    $broken = Helpers::tempDir('pf_pages_') . '/erp.json';
    file_put_contents($broken, '{not json');
    $r = $get($appWith($broken), '/new-arrivals');
    $t->same(503, $r->status());
    $t->contains('Products are being updated.', $r->body());
    // empty catalogue
    $none = $appWith($mockFile([]));
    $r = $get($none, '/new-arrivals');
    $t->same(200, $r->status());
    $t->contains('<div class="GSLLNW_note"><p>No products are available right now. Please check back soon.</p></div>', $r->body());
    $t->same(404, $get($none, '/product?sku=MOCK-SAR-001')->status());
});

$t->test('product cards link to /product?sku= (home and listing), no card points at /soon; add-to-cart buttons carry the sku', function () use ($t, $get): void {
    $app = Helpers::app();
    foreach (['/', '/new-arrivals'] as $uri) {
        $b = $get($app, $uri)->body();
        $t->contains('<a class="JEjkwq_photoLink" href="/product?sku=MOCK-SAR-001">', $b, $uri);
        $t->contains('<h3><a href="/product?sku=MOCK-SAR-001">Kanjivaram Silk Saree</a></h3>', $b, $uri);
        $t->contains('data-pf="add-to-bag" data-sku="MOCK-SAR-001"', $b, $uri);
        $t->same(0, preg_match('/JEjkwq_photoLink" href="\/soon"/', $b), $uri);
        $t->same(0, preg_match('/<h3><a href="\/soon"/', $b), $uri);
    }
    $home = $get($app, '/')->body();
    $t->contains('<script defer src="/assets/bag.js"></script>', $home);
    // every card link opens a product page
    preg_match_all('#<a class="JEjkwq_photoLink" href="(/product\?sku=[^"]+)"#', $get($app, '/new-arrivals')->body(), $m);
    $t->same(5, count($m[1]));
    foreach ($m[1] as $href) {
        $t->same(200, $get($app, html_entity_decode($href))->status(), $href);
    }
});

$t->test('productHref and catalogLink: encodeURIComponent and URLSearchParams spelling', function () use ($t, $get, $mockFile, $appWith): void {
    $t->same('/product?sku=MOCK-SAR-001', CatalogLinks::productHref('MOCK-SAR-001'));
    $t->same("/product?sku=a%20b!*'()~-_.%2F%26%3F%23%2B%25", CatalogLinks::productHref("a b!*'()~-_./&?#+%"));
    $t->same('/product?sku=%E0%A4%B8', CatalogLinks::productHref('स'));
    $t->same('/new-arrivals', CatalogLinks::catalogLink());
    $t->same('/new-arrivals?fabric=Uppada+Pattu+Saree', CatalogLinks::catalogLink('Uppada Pattu Saree'));
    $t->same('/new-arrivals?occasion=Wedding', CatalogLinks::catalogLink(null, 'Wedding'));
    $t->same('/new-arrivals?fabric=a+b&occasion=c', CatalogLinks::catalogLink('a b', 'c'));
    // a sku with spaces and symbols round-trips through the address
    $app = $appWith($mockFile([['A B/C&D', 'Odd Sku', '700.00', 5, 'Sarees', 'Silk', null, null]]));
    $b = $get($app, '/new-arrivals')->body();
    $t->contains('href="/product?sku=A%20B%2FC%26D"', $b);
    $r = $get($app, '/product?sku=A%20B%2FC%26D');
    $t->same(200, $r->status());
    $t->contains('<h1 id="product-title">Odd Sku</h1>', $r->body());
    $t->contains('data-sku="A B/C&amp;D"', $r->body());
});

$t->test('header and footer links all resolve: built routes (the bag icon goes to /bag), anchors', function () use ($t, $get): void {
    $app = Helpers::app();
    $built = ['/', '/soon', '/login', '/signup', '/forgot-password', '/account-help', '/verify-email', '/account', '/account/orders', '/account/addresses', '/account/help', '/wishlist', '/search', '/new-arrivals', '/product', '/bag', '/checkout', '/privacy', '/cookie-policy', '/terms', '/refund-policy', '/shipping-policy', '/contact'];
    $checked = [];
    foreach (['/', '/new-arrivals', '/product?sku=MOCK-SAR-001', '/product?sku=NOPE', '/login'] as $uri) {
        $b = $get($app, $uri)->body();
        // the header (menu, search results, icons) and the footer
        $parts = [];
        foreach (['<header class="pSCCwa_header"', '<div class="pSCCwa_scrim"', '<footer class="f_viWW_footer">'] as $start) {
            $at = strpos($b, $start);
            $t->ok($at !== false, $uri . ' has ' . $start);
            $parts[] = substr($b, (int) $at, 60000);
        }
        $soon = 0;
        foreach ($parts as $part) {
            preg_match_all('#<a\b[^>]*\shref="([^"]*)"#', $part, $m);
            foreach ($m[1] as $raw) {
                $href = html_entity_decode($raw, ENT_QUOTES);
                if ($href === '/soon') {
                    $soon++;
                    continue;
                }
                if ($href[0] === '#' || $href === '/#arrivals') {
                    continue;
                }
                $t->same(0, preg_match('#^(https?:)?//#', $href), $uri . ': external link ' . $href);
                $path = (string) parse_url($href, PHP_URL_PATH);
                $t->ok(in_array($path, $built, true) || str_starts_with($path, "/collection/"), $uri . ': unknown route in header/footer ' . $href);
                if (!isset($checked[$href])) {
                    $checked[$href] = $get($app, $href)->status();
                }
            }
        }
        $t->same(0, $soon, $uri . ': no header/footer link points at /soon since the bag page exists');
    }
    $t->ok(count($checked) >= 10, 'checked ' . count($checked) . ' distinct links');
    foreach ($checked as $href => $status) {
        $t->same(200, $status, $href);
    }
    // the exact menu links of the web
    $b = $get($app, '/')->body();
    foreach (['Half Sarees' => '/new-arrivals', 'Uppada' => '/new-arrivals?fabric=Uppada+Pattu+Saree', 'Banarasi' => '/new-arrivals?fabric=Banarasi+Saree',
        'Kanchipuram Pattu' => '/new-arrivals?fabric=Kanchi+Pattu+Saree', 'Dharmavaram' => '/new-arrivals?fabric=Dharmavaram+Saree', 'Soft Silk' => '/new-arrivals?fabric=Bangalore+Silk+Saree',
        'Bridal Sarees' => '/new-arrivals?occasion=Wedding', 'Gadwal' => '/new-arrivals', 'Men’s Ethnic Wear' => '/new-arrivals', 'Jewellery' => '/new-arrivals', 'Accessories' => '/new-arrivals'] as $label => $href) {
        $t->contains('<li><a href="' . $href . '">' . $label . '</a></li>', $b, $label);
    }
    $t->same(0, substr_count(substr($b, (int) strpos($b, 'id="shop-menu"'), (int) strpos($b, 'data-pf="search-scrim"') - (int) strpos($b, 'id="shop-menu"')), 'href="/soon"'), 'no /soon in the Shop menu');
    // search results
    foreach (['<a href="/new-arrivals" data-pf-text="new arrivals">', '<a href="/new-arrivals?fabric=Pure+Silk+Saree" data-pf-text="pure silk saree|pure silk|silk">',
        '<a href="/new-arrivals?occasion=Festive" data-pf-text="festive collections|festival">', '<a href="/#arrivals" data-pf-text="best sellers">'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    // the footer links only to pages that exist (checked above), and names no page that does not
    $footer = substr($b, (int) strpos($b, '<footer class="f_viWW_footer">'));
    foreach (['href="/privacy"', 'href="/terms"', 'href="/refund-policy"', 'href="/shipping-policy"', 'href="/contact"'] as $link) {
        $t->contains($link, $footer, $link);
    }
    foreach (['Track Order', 'Store Locator', 'Our Story', 'Careers', 'Size Guide', 'FAQs', 'App Store'] as $gone) {
        $t->ok(!str_contains($b, $gone), $gone . ' is not on the page');
    }
});

$t->test('a signed-in visitor: the pages stay private and vary by cookie; a failing lookup still renders', function () use ($t, $get): void {
    $app = Helpers::app(); // the fake pdo factory throws: the lookup fails, the page must still render
    foreach (['/new-arrivals', '/product?sku=MOCK-SAR-001'] as $uri) {
        $r = $get($app, $uri, ['Cookie' => 'pf_session=abc']);
        $t->same(200, $r->status(), $uri);
        $t->same('private, no-store', $r->header('Cache-Control'), $uri);
        $t->contains('Cookie', (string) $r->header('Vary'), $uri);
        $t->contains('class="pSCCwa_iconButton" aria-label="Sign in"', $r->body(), $uri);
        $t->ok(!str_contains($r->body(), 'abc'), $uri . ': the cookie value is never printed');
    }
    $plain = $get($app, '/new-arrivals');
    $t->ok(!$plain->hasHeader('Vary') || !str_contains((string) $plain->header('Vary'), 'Cookie'));
});

$t->test('every image and style the new pages point to exists', function () use ($t, $get, $root): void {
    $app = Helpers::app();
    $html = $get($app, '/new-arrivals')->body() . $get($app, '/product?sku=MOCK-SAR-001')->body();
    $css = (string) file_get_contents($root . '/public/assets/css/0xslsry9_slir.css');
    $t->ok($css !== '' && str_contains($css, 'ZxDzjq_range'));
    preg_match_all('#/assets/[A-Za-z0-9_./-]+\.(?:svg|webp|jpg|png|css|js)#', $html . $css, $m);
    foreach (array_unique($m[0]) as $url) {
        $t->ok(is_file($root . '/public' . $url), 'missing ' . $url);
        $t->same(200, $get($app, $url)->status(), $url);
    }
    foreach (['filter-arrow', 'sort-arrow', 'chevron-right', 'lotus-emblem'] as $icon) {
        $t->same(200, $get($app, '/assets/images/figma/' . $icon . '.svg')->status(), $icon);
    }
});

$t->test('StaticFiles: exactly the named scripts are JavaScript', function () use ($t, $root): void {
    $app = Helpers::app();
    foreach (['site.js', 'auth.js', 'bag.js', 'listing.js', 'product.js', 'bag-page.js', 'account.js', 'auth-otp.js', 'address-book.js', 'wishlist.js', 'cookies.js', 'offers.js', 'demo.js'] as $name) {
        $r = $app->handle(Helpers::request('GET', '/assets/' . $name));
        $t->same(200, $r->status(), $name);
        $t->same('text/javascript; charset=utf-8', $r->header('Content-Type'), $name);
        $t->same(file_get_contents($root . '/public/assets/' . $name), $r->body(), $name);
    }
    foreach (['/assets/other.js', '/assets/css/bag.js', '/assets/css/listing.js', '/assets/images/product.js', '/assets/bag.js.map', '/assets/listing.mjs', '/assets/../bag.js'] as $path) {
        $t->same(404, $app->handle(Helpers::request('GET', $path))->status(), $path);
    }
    // every .js file in public/assets is either on the allow-list or refused
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/assets', FilesystemIterator::SKIP_DOTS));
    $allowed = ['site.js', 'auth.js', 'bag.js', 'listing.js', 'product.js', 'bag-page.js', 'account.js', 'auth-otp.js', 'address-book.js', 'wishlist.js', 'cookies.js', 'offers.js', 'demo.js'];
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) === 'js') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/public/assets/')));
            $status = $app->handle(Helpers::request('GET', '/assets/' . $relative))->status();
            $t->same(in_array($relative, $allowed, true) ? 200 : 404, $status, $relative);
        }
    }
    $static = new StaticFiles($root . '/public/assets');
    $t->same(null, $static->serve('/assets/listing.js.txt'));
});

$t->test('/search?q=: matches from the catalogue, "No products found", a hint under 2 characters; the header carries the live-search hooks', function () use ($t, $get): void {
    $app = Helpers::app();
    $r = $get($app, '/search?q=saree');
    $t->same(200, $r->status());
    $t->contains('data-sku="MOCK-SAR-001"', $r->body());
    $t->contains('data-pf="search-products"', $r->body());
    $t->contains('No products found', $get($app, '/search?q=zzzznothing')->body());
    $t->contains('Type at least 2 characters', $get($app, '/search?q=a')->body());
    $t->contains('Type at least 2 characters', $get($app, '/search')->body());
});


$t->test('wishlist and quick view: header heart with count, /wishlist page, cards carry the eye and heart hooks and the quick-view values', function () use ($t, $get): void {
    $app = Helpers::app();
    $home = $get($app, '/new-arrivals')->body();
    $t->contains('data-pf="wish-link"', $home);
    $t->contains('data-pf="quick-view"', $home);
    $t->contains('data-pf="wish" data-sku="MOCK-SAR-001"', $home);
    $t->contains('data-qv-sku="MOCK-SAR-001"', $home);
    $t->contains('data-qv-stock="', $home);
    $t->contains('data-pf="wish" data-sku="MOCK-SAR-001" aria-pressed="false">Add to wishlist</button>', $get($app, '/product?sku=MOCK-SAR-001')->body());
    $w = $get($app, '/wishlist');
    $t->same(200, $w->status());
    $t->contains('data-pf="wishlist"', $w->body());
    $t->contains('<script defer src="/assets/wishlist.js"></script>', $w->body());
});

$t->test('footer collections: own listing when the fabric has products, else the search for the name, else no link', function () use ($t, $get): void {
    Helpers::app(); // gives CatalogLinks the (mock) catalogue
    $t->same('/new-arrivals?fabric=Cotton', CatalogLinks::collectionLink('Cotton Sarees', 'Cotton'), 'the fabric has products');
    $t->same('/search?q=Silk', CatalogLinks::collectionLink('Silk', 'Pattu Saree'), 'no product of that fabric, but the name finds some');
    $t->same(null, CatalogLinks::collectionLink('Bridal Sarees', 'Pattu Saree'), 'the search finds nothing: hidden');
    $t->same(null, CatalogLinks::collectionLink('Bridal Sarees', null), 'an occasion has no products');
    $t->same('/new-arrivals', CatalogLinks::collectionLink('New Arrivals', ''));
    $body = $get(Helpers::app(), '/new-arrivals')->body();
    $footer = substr($body, (int) strpos($body, '<footer'));
    $t->ok(!str_contains($footer, '>Bridal Sarees</a>') && !str_contains($footer, '>Banarasi</a>'), 'the mock catalogue has no Banarasi or bridal products, so those footer links are gone');
    $t->contains('>New Arrivals</a>', $footer, 'New Arrivals stays');
});

$t->test('/collection/<slug>: only that category, listed in the footer and the Shop menu; unknown slug is 404', function () use ($t, $get): void {
    $app = Helpers::app();
    $r = $get($app, '/collection/kurtis');
    $t->same(200, $r->status());
    $t->contains('<title>Kurtis | Pratyangi Fashions</title>', $r->body());
    $t->contains('<h1>Kurtis</h1>', $r->body());
    $t->ok(!str_contains($r->body(), 'MOCK-SAR-001'), 'a saree is not in the Kurtis collection');
    $t->contains('href="/collection/sarees"', $r->body());
    $t->contains('href="/collection/kurtis"', $r->body());
    $t->same(404, $get($app, '/collection/wedding')->status());
});
exit($t->finish());

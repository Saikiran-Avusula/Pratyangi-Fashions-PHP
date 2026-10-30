<?php
declare(strict_types=1);

// No database, no network. Run: php tests/shop_pages_test.php
// The bag, checkout and account pages: signed-in and signed-out output, redirects, the empty bag, escaping of hostile values, scripts, the header count.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Catalog\CatalogCache;
use Pratyangi\Catalog\CatalogService;
use Pratyangi\Erp\MockErp;
use Pratyangi\Erp\NotConnectedErp;
use Pratyangi\Support\Logger;
use Pratyangi\Web\ShopPages;
use Pratyangi\Web\StaticFiles;

$t = new TestCase('shop_pages_test');
$root = dirname(__DIR__);
$evil = '"><script>alert(1)</script> \'q\' & "d"';

/** A mock ERP file: rows of [sku, name, price, pieces]. */
$mockFile = static function (array $rows): string {
    $products = [];
    $stock = [];
    foreach ($rows as [$sku, $name, $price, $pieces]) {
        $products[] = ['sku' => $sku, 'name' => $name, 'category' => 'Sarees', 'fabric' => null, 'pattern_name' => null, 'design_model' => null,
            'color' => null, 'size' => null, 'mrp' => $price, 'retail_price' => $price, 'offer_price' => null, 'tax_rate' => '5.00', 'image_path' => null];
        $stock[$sku] = $pieces;
    }
    $file = Helpers::tempDir('pf_shop_') . '/erp.json';
    file_put_contents($file, json_encode(['products' => $products, 'stock' => $stock], JSON_THROW_ON_ERROR));
    return $file;
};

/** ShopPages with a fake session lookup (null = nobody is signed in) over the given mock file. */
$pages = static function (?array $customer, ?string $file = null, bool $sms = true): ShopPages {
    $config = Helpers::config($file === null ? [] : ['ERP_MOCK_FILE' => $file]);
    $logger = new Logger('silent');
    $service = new CatalogService(
        new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
        new CatalogCache($config->catalogCacheDir, $config->catalogCacheSeconds, new FixedClock(), $logger),
        $logger,
    );
    return new ShopPages($service, static fn (string $token): ?array => $customer, $logger, null, $sms);
};
$cookie = ['Cookie' => 'pf_session=abc123'];
$get = static fn (string $uri, array $headers = []) => Helpers::request('GET', $uri, $headers);
$asha = ['name' => 'asha Rao', 'email' => 'asha@example.com', 'created_at' => '2026-10-03 08:00:00', 'email_verified_at' => '2026-10-03 08:05:00'];

$noInline = static function (TestCase $t, string $b, int $scripts, string $note): void {
    $t->same($scripts, substr_count($b, '<script'), $note . ': script tag count');
    $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), $note . ': no inline script');
    $t->same(0, preg_match('/\son[a-z]+="/i', $b), $note . ': no inline event handler');
    $t->same(0, preg_match('#(src|href)="(https?:)?//#', $b), $note . ': no external resource');
};

$t->test('/bag signed out: empty-bag frame in the Node markup, sign-in checkout link, three deferred scripts, nothing inline', function () use ($t, $pages, $get, $noInline): void {
    $r = $pages(null)->bag($get('/bag'));
    $t->same(200, $r->status());
    $t->same('text/html; charset=utf-8', $r->header('Content-Type'));
    $t->same(false, $r->hasHeader('Cache-Control'), 'no session cookie: cacheable like the other pages');
    $b = $r->body();
    foreach (['<title>Your Bag | Pratyangi Fashions</title>', '<main class="BpPmcW_page" data-pf-bag-page="bag" data-pf-image-base="" data-checkout-href="/login?next=/checkout">',
        '<section class="BpPmcW_bag" aria-labelledby="bag-title">', '<h1 id="bag-title">Your Bag <span data-pf="title-count">(0 items)</span></h1>',
        '<a class="BpPmcW_continue" href="/new-arrivals">', 'data-pf="bag-lead"></p>', '<div class="BpPmcW_items" data-pf="bag-items"></div>',
        '<h2>Order Summary</h2>', '<dd data-pf="subtotal">Rs. 0.00</dd>', '<dd>Confirmed by the shop</dd>', '<strong data-pf="total">Rs. 0.00</strong>',
        'Delivery charges are not included.', 'class="BpPmcW_checkout" data-pf="checkout-slot" disabled=""', 'Online payment is not available yet', 'Coupons are not available yet.',
        '<h2 id="recommended-title">You May Also Like</h2>', 'Complete your look with these beautiful sarees', '<section class="OL4btW_section"', 'class="f_viWW_footer"', 'class="pSCCwa_header"',
        '<span class="pSCCwa_count" data-pf="bag-count">0</span>', 'aria-label="Open your bag, 0 items" href="/bag"', 'aria-label="Sign in" href="/login"'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/bag.js"></script><script defer src="/assets/bag-page.js"></script>', $b);
    $noInline($t, $b, 4, '/bag');
    $t->same(4, substr_count($b, '<article class="JEjkwq_card"'), 'four recommended cards');
    $t->contains('<div class="BpPmcW_recommendedGrid">', $b);
    $t->contains('/assets/css/3wh3hbs4o_ca8.css', $b);
});

$t->test('/bag signed in: checkout link goes straight to /checkout, header shows the person, per-visitor cache headers', function () use ($t, $pages, $get, $cookie, $asha): void {
    $r = $pages($asha)->bag($get('/bag', $cookie));
    $t->same(200, $r->status());
    $b = $r->body();
    $t->contains('data-checkout-href="/checkout"', $b);
    $t->contains('<span class="pSCCwa_accountName">asha</span>', $b);
    $t->ok(!str_contains($b, 'aria-label="Sign in"'));
    $t->same('private, no-store', $r->header('Cache-Control'));
    $t->contains('Cookie', (string) $r->header('Vary'));
});

$t->test('/bag: a hostile product name in "You May Also Like" is escaped, only in-stock products, at most four', function () use ($t, $pages, $get, $mockFile, $evil, $noInline): void {
    $file = $mockFile([['E-1', $evil, '1000', 5], ['S-1', 'Sold Out Saree', '2000', 0], ['A-1', 'A', '1', 3], ['A-2', 'B', '1', 3], ['A-3', 'C', '1', 3], ['A-4', 'D', '1', 3]]);
    $b = $pages(null, $file)->bag($get('/bag'))->body();
    $t->ok(!str_contains($b, '<script>alert(1)'), 'no raw script from the product name');
    $t->contains('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; &#039;q&#039; &amp; &quot;d&quot;', $b);
    $t->ok(!str_contains($b, 'Sold Out Saree'), 'a sold-out product is not recommended');
    $t->same(4, substr_count($b, '<article class="JEjkwq_card"'));
    $noInline($t, $b, 4, 'hostile bag');
});

$t->test('/bag with the catalogue unreadable: still 200 with the friendly retry box and no reason shown, never cached', function () use ($t, $get): void {
    $app = Helpers::app(null, null, null, new NotConnectedErp());
    $r = $app->handle($get('/bag'));
    $t->same(200, $r->status());
    $b = $r->body();
    $t->contains('Products are being updated. Please try again in a moment.', $b);
    $t->contains('data-pf="retry"', $b);
    $t->contains('data-pf="bag-lead"></p>', $b);
    $t->same('no-store', $r->header('Cache-Control'));
});

$t->test('/checkout signed out: 302 to /login?next=/checkout, no body, nothing cached', function () use ($t, $pages, $get, $cookie): void {
    foreach ([[], $cookie] as $headers) {
        $r = $pages(null)->checkout($get('/checkout', $headers));
        $t->same(302, $r->status());
        $t->same($headers === [] ? '/login?next=/checkout' : '/login?next=/checkout&expired=1', $r->header('Location'));
        $t->same('', $r->body());
        $t->same('no-store', $r->header('Cache-Control'));
    }
});

$t->test('/checkout signed in: steps, summary frame, delivery step enabled with addresses and dialog, Place order disabled', function () use ($t, $pages, $get, $cookie, $asha, $noInline): void {
    $r = $pages($asha)->checkout($get('/checkout', $cookie));
    $t->same(200, $r->status());
    $b = $r->body();
    foreach (['<title>Checkout | Pratyangi Fashions</title>', '<main class="FVHMJa_page" data-pf-bag-page="checkout" data-pf-image-base="">', '<section class="FVHMJa_workspace" aria-labelledby="checkout-title">',
        '<h1 id="checkout-title">Checkout</h1>', 'Complete your order in a few simple steps', '<ol class="FVHMJa_steps" aria-label="Checkout progress">',
        '<li class="FVHMJa_stepActive" aria-current="step"><span class="FVHMJa_stepNumber">1</span><span class="FVHMJa_stepLabel">Delivery</span></li>',
        '<li><span class="FVHMJa_stepNumber">2</span><span class="FVHMJa_stepLabel">Payment</span></li>',
        '<li><span class="FVHMJa_stepNumber">3</span><span class="FVHMJa_stepLabel">Review &amp; Place Order</span></li>',
        '<h2 id="address-title">Delivery Address</h2>', 'class="FVHMJa_addAddress" aria-haspopup="dialog" data-pf-open-address',
        '<div class="FVHMJa_addressList" role="radiogroup" aria-label="Saved addresses" data-pf-address-list>',
        '<h2 id="delivery-title">Delivery</h2>', 'Delivery charges are confirmed by the shop and are not included in the total.',
        'class="mdI9xa_scrim" data-pf-address-modal hidden', 'id="address-dialog-title" data-pf-modal-title>Add New Address</h2>',
        '<aside class="FVHMJa_card FVHMJa_summary" style="display:none" aria-labelledby="summary-title">', '<ul class="FVHMJa_lines" data-pf="bag-items"></ul>', 'Coupons are not available yet.',
        '<h3>Price Details</h3>', 'Shipping Charges', 'Confirmed by the shop', 'Secure online payment with Razorpay', 'Coming soon', 'data-pf-payment="cod"', '<span class="pSCCwa_accountName">asha</span>'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->same(1, preg_match('/<button type="button" class="FVHMJa_pay" disabled="">.*Proceed to Pay/s', $b), 'the pay button is disabled');
    $t->ok(!str_contains($b, 'Place Order (Cash on Delivery)'), 'no working Place order button');
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/bag.js"></script><script defer src="/assets/offers.js"></script><script defer src="/assets/address-book.js"></script><script defer src="/assets/bag-page.js"></script>', $b);
    $noInline($t, $b, 6, '/checkout');
    $t->same('private, no-store', $r->header('Cache-Control'));
    // no order API exists yet
    $t->same(404, Helpers::app()->handle(Helpers::request('POST', '/api/v1/checkout', ['Origin' => Helpers::ORIGIN], []))->status());
});


$t->test('/checkout with no saved address: empty list the script opens the add-address form on', function () use ($t, $pages, $get, $cookie, $asha): void {
    $b = $pages($asha)->checkout($get('/checkout', $cookie))->body();
    $t->ok(!str_contains($b, 'data-pf-address-id'), 'no saved address is listed');
    $t->contains("if (checkoutPage && list && !list.querySelector('[data-pf-address-id]')) openModal(null);", (string) file_get_contents(dirname(__DIR__) . '/public/assets/address-book.js'), 'the dialog opens by itself on /checkout only');
});
$t->test('/checkout: a hostile account name is escaped in the header', function () use ($t, $pages, $get, $cookie, $evil): void {
    $b = $pages(['name' => $evil, 'email' => 'e"<@x.com', 'created_at' => null])->checkout($get('/checkout', $cookie))->body();
    $t->ok(!str_contains($b, '<script>alert(1)'));
    $t->contains('title="e&quot;&lt;@x.com"', $b);
});

$t->test('/account signed out: 302 to /login?next=/account', function () use ($t, $pages, $get): void {
    $r = $pages(null)->account($get('/account'));
    $t->same(302, $r->status());
    $t->same('/login?next=/account', $r->header('Location'));
    $t->same('', $r->body());
});

$t->test('/account signed in: name, email, member since, Logout button, links to /soon, saved addresses panel', function () use ($t, $pages, $get, $cookie, $asha, $noInline): void {
    $r = $pages($asha)->account($get('/account', $cookie));
    $t->same(200, $r->status());
    $b = $r->body();
    foreach (['<title>My Account | Pratyangi Fashions</title>', '<div class="pnvDaq_page">', '<main class="pnvDaq_main pf_menuOn">', '<h1>My Account</h1>', 'Manage your orders, addresses and more',
        '<a class="pnvDaq_navItem pnvDaq_navActive" aria-current="page" href="/account">', '<span>Overview</span>', '<span>My Orders</span>', '<span>Help &amp; Support</span>',
        '<button type="button" class="pnvDaq_logout" data-pf="logout">', 'Logout</button>', '<h2>Hello, asha</h2>', 'Welcome to Pratyangi Fashions',
        '<span>Member since Oct 2026</span>', '<span>asha@example.com</span>', '<strong>0</strong>Total orders', '<strong>0</strong>Saved Addresses',
        'id="addresses" data-pf="addresses-panel"', '<h2>Saved Addresses</h2>', '<h2>Recent Orders</h2>',
        '<p>No orders yet.</p>', 'href="/new-arrivals">Start shopping', '<h2>No saved addresses yet.</h2>', 'data-pf-open-address>+ Add address</button>', 'data-pf-address-modal'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->same(7, substr_count($b, 'class="pnvDaq_navItem'), 'seven sidebar rows (Figma; My Reviews is hidden until it exists)');
    $t->same(7, substr_count($b, 'class="pf_acctMenuRow"'), 'seven phone menu rows (Figma D1 without Membership and My Reviews)');
    $t->same(0, substr_count($b, 'href="/soon"'), 'every sidebar row has its own page');
    $t->contains('href="/account/orders"', $b, 'My Orders has its own page');
    foreach (['Manage at Checkout', 'Go to Checkout'] as $gone) {
        $t->ok(!str_contains($b, $gone), 'no "' . $gone . '": it has no page');
    }
    $t->contains('<div class="pf_topbar"><div class="pf_ann" data-pf="announce"><p>Authentic Handlooms</p><p>Handwoven by master artisans</p><p>Help on WhatsApp</p></div><p class="pf_topbarLinks"><a href="/contact">Contact</a></p></div>', $b, 'the one top bar, with a link only to a page that exists');
    $t->same(1, substr_count($b, 'pf_topbar"'), 'one top bar');
    $t->same(2, substr_count($b, 'P71-SG_statOrders'), 'both stat cards share one style');
    $t->same(1, substr_count($b, 'data-pf-open-address'), 'one Add address button (empty state)');
    $t->contains('href="/account/addresses"', $b, 'Addresses row has its own page');
    $t->ok(!str_contains($b, 'Please confirm your email address'), 'verified: no notice');
    $t->same(1, preg_match('#<div class="P71-SG_profileMeta"><span>Member since Oct 2026</span><span>asha@example.com</span></div>#', $b), 'no mobile line');
    $t->contains('<script defer src="/assets/site.js"></script><script defer src="/assets/offers.js"></script><script defer src="/assets/address-book.js"></script><script defer src="/assets/account.js"></script><script defer src="/assets/cookies.js"></script>', $b);
    $noInline($t, $b, 5, '/account');
    $t->same('private, no-store', $r->header('Cache-Control'));
});

$t->test('/account: mobile shown when stored, verify notice when the email is not verified, hostile values escaped', function () use ($t, $pages, $get, $cookie, $evil, $noInline): void {
    $row = ['name' => $evil, 'email' => 'e"<@x.com', 'mobile' => '<b>98765</b>', 'created_at' => 'garbage', 'email_verified_at' => null];
    $quiet = $pages($row)->account($get('/account', $cookie))->body();
    $t->ok(!str_contains($quiet, 'Verify your email') && !str_contains($quiet, 'data-pf="email-send"'), 'no banner on an ordinary visit');
    $t->contains('<a class="pf_verifyHint" href="/account?verify=1">Verify email</a>', $quiet, 'one quiet line instead');
    $b = $pages($row)->account($get('/account?verify=1', $cookie))->body();
    $t->ok(!str_contains($b, '<script>alert(1)') && !str_contains($b, '<b>98765'), 'nothing raw');
    $t->contains('<span>&lt;b&gt;98765&lt;/b&gt;</span>', $b);
    $t->contains('Confirm e&quot;&lt;@x.com with a 6-digit code.', $b, 'the banner when asked for (?verify=1)');
    $t->contains('data-pf="email-send">Send code</button>', $b);
    $t->ok(!str_contains($b, 'pf_verifyHint'), 'and then no extra link');
    $verified = $pages(array_merge($row, ['email_verified_at' => '2026-10-03 08:05:00']))->account($get('/account?verify=1', $cookie))->body();
    $t->ok(!str_contains($verified, 'Verify your email'), 'a verified email never gets the banner');
    $t->ok(!str_contains($b, 'Member since'), 'an unreadable date shows no "Member since"');
    $noInline($t, $b, 5, 'hostile account');
});

$t->test('/account "Complete your account": hidden while SMS_ENABLED=false and name + email are set; shown when SMS is on (mobile missing) or a detail is missing', function () use ($t, $pages, $get, $cookie, $asha): void {
    $off = $pages($asha, null, false)->account($get('/account', $cookie))->body();
    $t->ok(!str_contains($off, 'Complete your account'), 'SMS off, name + email set: no panel');
    $t->ok(!str_contains($off, 'Add mobile number'), 'and no Add mobile');
    $on = $pages($asha, null, true)->account($get('/account', $cookie))->body();
    $t->contains('Complete your account', $on);
    $t->contains('data-pf="link-open">Add mobile number</button>', $on);
    $noName = $pages(['email' => 'x@example.com', 'name' => '', 'created_at' => '2026-10-03 08:00:00', 'email_verified_at' => '2026-10-03 08:05:00'], null, false)->account($get('/account', $cookie))->body();
    $t->contains('Complete your account', $noName, 'a missing name still shows it with SMS off');
    $t->ok(!str_contains($noName, 'Coming soon') && !str_contains($noName, 'Add mobile number'), 'no "Coming soon" button: the mobile number is asked for in the address form while SMS is off');
});

$t->test('through the App: /bag is 200, /checkout and /account redirect when signed out or when the session lookup fails, /account/orders redirects when signed out', function () use ($t, $get, $cookie): void {
    $app = Helpers::app();
    $t->same(200, $app->handle($get('/bag'))->status());
    $t->same(200, $app->handle($get('/bag/'))->status());
    foreach (['/checkout' => '/login?next=/checkout', '/account' => '/login?next=/account'] as $path => $to) {
        $r = $app->handle($get($path));
        $t->same(302, $r->status(), $path);
        $t->same($to, $r->header('Location'), $path);
        $r = $app->handle($get($path, $cookie)); // the database is unreachable in this test: the lookup fails, so the visitor counts as signed out
        $t->same(302, $r->status(), $path . ' with a cookie');
    }
    $r = $app->handle($get('/account/orders'));
    $t->same([302, '/login?next=/account/orders'], [$r->status(), $r->header('Location')]);
});

$t->test('/account/orders: a clean empty state with Start shopping; the overview count comes from the order count, not a literal', function () use ($t, $pages, $get, $cookie, $asha): void {
    $b = $pages($asha)->account($get('/account/orders', $cookie), 'orders')->body();
    $t->contains('No orders yet', $b);
    $t->contains('class="pf_emptyBtn" href="/new-arrivals">Start shopping</a>', $b);
    $t->contains('<strong>0</strong>Total orders', $pages($asha)->account($get('/account', $cookie))->body());
});

$t->test('account sections: each has its own page, the same sidebar, and only the right panels', function () use ($t, $pages, $get, $cookie, $asha): void {
    $page = static fn (string $s): string => $pages($asha)->account($get('/account/' . $s, $cookie), $s)->body();
    $t->contains('id="addresses"', $page('addresses'));
    $t->ok(!str_contains($page('addresses'), 'Recent Orders'), 'addresses has no overview panels');
    $t->contains('Common questions', $page('help'));
    $n = $page('notifications');
    $t->contains('Offers and new arrivals', $n);
    foreach (['sms', 'whatsapp', 'email'] as $channel) {
        $t->contains('<input type="checkbox" disabled data-pf-consent="' . $channel . '"/>', $n, $channel . ': a box that is never ticked by the server (the page loads the saved choice)');
    }
    foreach (['payments' => 'Payment Methods', 'reviews' => 'My Reviews'] as $s => $title) {
        $t->contains('<h2>' . $title . '</h2>', $page($s));
    }
    foreach (['overview', 'orders', 'addresses', 'notifications', 'payments', 'reviews', 'help'] as $s) {
        $t->ok(stripos($page($s), 'coming soon') === false && stripos($page($s), 'will come later') === false, $s . ': no "coming soon" text');
        $t->ok(!str_contains($page($s), 'pf_emptyState"'), $s . ': no tall empty box (compact in every panel)');
    }
    $t->contains('aria-current="page" href="/account/help"', $page('help'));
    $t->same([302, '/login?next=/account/help'], (static fn ($r): array => [$r->status(), $r->header('Location')])($pages(null)->account($get('/account/help'), 'help')));
});

$t->test('the header bag link goes to /bag on every page, and the bag-page and account scripts are served as JavaScript', function () use ($t, $get, $root): void {
    $app = Helpers::app();
    foreach (['/', '/new-arrivals', '/login', '/soon'] as $path) {
        $b = $app->handle($get($path))->body();
        if ($path === '/soon') {
            continue; // the coming page has no header
        }
        $t->contains('aria-label="Open your bag, 0 items" href="/bag" data-pf="bag-link"', $b, $path);
        $t->contains('<span class="pSCCwa_count" data-pf="bag-count">0</span>', $b, $path);
    }
    $static = new StaticFiles($root . '/public/assets');
    foreach (['/assets/bag-page.js', '/assets/account.js', '/assets/wishlist.js', '/assets/images/figma/trash.svg', '/assets/images/figma/checkout-arrow.svg'] as $path) {
        $r = $static->serve($path);
        $t->ok($r !== null && $r->status() === 200, $path);
    }
    $t->same('text/javascript; charset=utf-8', $static->serve('/assets/bag-page.js')?->header('Content-Type'));
    $t->same(null, $static->serve('/assets/bag-page.js.map'));
    foreach (glob($root . '/public/assets/*.js') as $file) {
        $t->ok($static->serve('/assets/' . basename($file)) !== null, basename($file) . ' is served (a script missing from the list stays "Loading…" forever)');
    }
    $t->same(['no-cache', 'public, max-age=3600'], [$static->serve('/assets/wishlist.js')?->header('Cache-Control'), $static->serve('/assets/images/figma/trash.svg')?->header('Cache-Control')], 'a changed script is never served from an old copy');
});

exit($t->finish());

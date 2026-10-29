<?php
declare(strict_types=1);

// No database. Run: php tests/c1_content_test.php
// C1: nothing fake or unfinished on the home page; the floating WhatsApp button; the account links in the menu; the neutral policy line; /contact without DRAFT.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

$t = new TestCase('c1_content_test');
$full = [
    'SHOP_LEGAL_NAME' => 'Sample Legal Name Pvt Ltd', 'SHOP_SE_REG' => 'SEA/RAN/ALO/BN/1425883/2026', 'SHOP_WHATSAPP' => '919989233328',
    'SHOP_SUPPORT_EMAIL' => 'help@example.test', 'SHOP_SOCIAL_INSTAGRAM' => 'https://instagram.example.test/shop',
];
$get = static fn (string $path, array $settings) => Helpers::app(Helpers::config($settings))->handle(Helpers::request('GET', $path))->body();

$t->test('home: no offers, delivery promise, reviews, newsletter or "coming soon"; the social block only when a link is set', function () use ($t, $get, $full): void {
    $home = $get('/', $full);
    foreach (['20%', 'FIRST20', 'Claim Offer', 'Coming Soon', 'coming soon', 'ewsletter', 'Subscribe', '3–5 days', 'Express shipping', 'reviewCard', 'pending configuration', 'demo'] as $gone) {
        $t->ok(!str_contains($home, $gone), 'home: no ' . $gone);
    }
    $t->contains('id="join"', $home, 'a social link is set');
    $t->contains('href="https://instagram.example.test/shop"', $home);
    $bare = $get('/', array_diff_key($full, ['SHOP_SOCIAL_INSTAGRAM' => 1]));
    $t->ok(!str_contains($bare, 'id="join"') && !str_contains($bare, 'JOIN OUR WORLD'), 'no social link: no social block');
});

$t->test('the floating WhatsApp button: a wa.me link with the greeting, labelled, hidden when SHOP_WHATSAPP is empty', function () use ($t, $get, $full): void {
    $home = $get('/', $full);
    $t->contains('class="pf_wa" href="https://wa.me/919989233328?text=Hi%20Pratyangi%20Fashions"', $home);
    $t->contains('aria-label="Chat with us on WhatsApp"', $home);
    $t->ok(!str_contains($get('/', ['SHOP_WHATSAPP' => ''] + $full), 'class="pf_wa"'), 'empty: no button');
    $t->contains('class="pf_wa"', $get('/contact', $full), 'also on other pages');
});

$t->test('the shop menu: "Kanchipuram Pattu" and the account links for a signed-out visitor', function () use ($t, $get, $full): void {
    $page = $get('/login', $full);
    foreach (['Kanchipuram Pattu', '<a href="/login">Sign in</a>', '<a href="/signup">Create account</a>'] as $needle) {
        $t->contains($needle, $page, $needle);
    }
    $t->ok(!str_contains($page, 'Kanchipuram Patti') && !str_contains($page, 'data-pf="logout"'), 'no "Patti", no Sign out when signed out');
});

$t->test('policy pages keep DRAFT with one neutral line instead of "[to be decided]"; /contact has no DRAFT and shows the registration number', function () use ($t, $get, $full): void {
    foreach (['/privacy', '/terms', '/refund-policy', '/shipping-policy'] as $path) {
        $b = $get($path, $full);
        $t->contains('DRAFT - to be reviewed', $b, $path);
        $t->ok(!str_contains($b, 'to be decided'), $path . ': no placeholder text');
    }
    $t->contains('Details will be confirmed at order time. Contact us at help@example.test.', $get('/refund-policy', $full));
    $t->ok(!str_contains($get('/refund-policy', ['SHOP_SUPPORT_EMAIL' => ''] + $full), 'Contact us at .'), 'no email, no dangling sentence');
    $c = $get('/contact', $full);
    $t->ok(!str_contains($c, 'DRAFT'), '/contact: no DRAFT banner');
    $t->contains('<dt>Shops &amp; Establishments Reg. No.</dt><dd>SEA/RAN/ALO/BN/1425883/2026</dd>', $c);
    $t->ok(!str_contains($get('/privacy', $full), 'Establishments'), 'the registration number is on /contact only');
    $t->ok(!str_contains($get('/contact', ['SHOP_SE_REG' => ''] + $full), 'Establishments'), 'empty: hidden');
});

exit($t->finish());

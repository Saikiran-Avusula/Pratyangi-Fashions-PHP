<?php
declare(strict_types=1);

// No database. Run: php tests/policy_pages_test.php
// The shop's company details (SHOP_* settings) in the footer, on the policy and contact pages and in the emails; empty = hidden; escaped; nothing hard-coded.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\ConfigException;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Views\ShopInfo;

$t = new TestCase('policy_pages_test');
$full = [
    'SHOP_LEGAL_NAME' => 'Sample Legal Name Pvt Ltd', 'SHOP_ADDRESS' => "1-2-3 Sample Street\nHyderabad 500001", 'SHOP_GSTIN' => '36ABCDE1234F1Z5',
    'SHOP_CIN' => 'U46901AP2026PTC126193', 'SHOP_SE_REG' => 'SEA/RAN/ALO/BN/1425883/2026', 'SHOP_WHATSAPP' => '919989233328', 'SHOP_GRIEVANCE_EMAIL' => 'grievance@example.test',
    'SHOP_PHONE' => '+91 98765 43210', 'SHOP_SUPPORT_EMAIL' => 'help@example.test', 'SHOP_HOURS' => 'Mon-Sat 10:00-18:00',
    'SHOP_SOCIAL_INSTAGRAM' => 'https://instagram.example.test/shop', 'SHOP_SOCIAL_WHATSAPP' => 'https://wa.example.test/91',
];
$get = static fn (string $path, array $settings) => Helpers::app(Helpers::config($settings))->handle(Helpers::request('GET', $path));
$paths = ['/privacy', '/terms', '/refund-policy', '/shipping-policy', '/contact'];

$t->test('the five pages exist, say DRAFT, use the layout, header and footer, and print the shop details from the settings', function () use ($t, $get, $full, $paths): void {
    foreach ($paths as $path) {
        $r = $get($path, $full);
        $t->same([200, 'text/html; charset=utf-8'], [$r->status(), $r->header('Content-Type')], $path);
        $b = $r->body();
        foreach (['<header class="pSCCwa_header"', '<footer class="f_viWW_footer">', '<div class="pf_topbar">', 'Sample Legal Name Pvt Ltd'] as $needle) {
            $t->contains($needle, $b, $path . ': ' . $needle);
        }
        $t->same($path !== '/contact', str_contains($b, 'DRAFT - to be reviewed'), $path . ': DRAFT banner on the policy pages, not on /contact (real data)');
        $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), $path . ': no inline script');
        $t->same(1, preg_match('#<h1>(Privacy Policy|Terms &amp; Conditions|Refund Policy|Shipping Policy|Contact us)</h1>#', $b), $path . ': the heading is the page title');
    }
    $b = $get('/contact', $full)->body();
    foreach (["1-2-3 Sample Street<br>
Hyderabad 500001", '36ABCDE1234F1Z5', 'href="tel:+919876543210"', 'href="mailto:help@example.test"', 'Mon-Sat 10:00-18:00'] as $needle) {
        $t->contains($needle, $b, '/contact: ' . $needle);
    }
    $t->contains('write to help@example.test', $get('/privacy', $full)->body(), 'the support email is in the policy text');
    $t->same(404, $get('/privacy-policy', $full)->status(), 'only the five addresses');
});

$t->test('the footer on every kind of page: legal name, address, GSTIN, phone, email, hours, policy links, the copyright with the legal name', function () use ($t, $get, $full): void {
    foreach (['/', '/login', '/privacy', '/new-arrivals'] as $path) {
        $b = $get($path, $full)->body();
        $footer = substr($b, (int) strpos($b, '<footer class="f_viWW_footer">'));
        foreach (['Sample Legal Name Pvt Ltd', 'GSTIN: 36ABCDE1234F1Z5', 'href="tel:+919876543210"', 'href="mailto:help@example.test"', 'Mon-Sat 10:00-18:00', "1-2-3 Sample Street<br>
Hyderabad 500001",
            'href="/privacy"', 'href="/terms"', 'href="/refund-policy"', 'href="/shipping-policy"', 'href="/contact"', '© 2026 Sample Legal Name Pvt Ltd. All rights reserved.'] as $needle) {
            $t->contains($needle, $footer, $path . ': ' . $needle);
        }
        $t->same(4, substr_count($footer, 'target="_blank"'), $path . ': the two social links that are set, the WhatsApp link and the floating WhatsApp button');
        $t->contains('href="https://instagram.example.test/shop"', $footer);
        $t->ok(!str_contains($footer, 'facebook.svg') && !str_contains($footer, 'youtube.svg'), $path . ': no icon for an unset network');
        $t->same(1, substr_count($b, 'class="pf_topbar"'), $path . ': one top bar');
        foreach (['Track Order', 'Store Locator'] as $gone) {
            $t->ok(!str_contains($b, $gone), $path . ': no ' . $gone);
        }
    }
});

$t->test('empty settings hide their lines; the copyright falls back to the brand name', function () use ($t, $get): void {
    $b = $get('/contact', [])->body();
    $footer = substr($b, (int) strpos($b, '<footer class="f_viWW_footer">'));
    foreach (['GSTIN', 'tel:', 'mailto:', 'target="_blank"', 'Mon-Sat'] as $gone) {
        $t->ok(!str_contains($footer, $gone), 'no ' . $gone);
    }
    $t->contains('© 2026 Pratyangi Fashions. All rights reserved.', $footer);
    $t->ok(!str_contains($b, '<dt>Address</dt>') && !str_contains($b, '<dt>Phone</dt>') && !str_contains($b, '<dt>Email</dt>'), 'the contact list shows only what is set');
});

$t->test('the logo picture can be embedded by other sites (an email shown in a webmail page), pages cannot', function () use ($t): void {
    $app = Helpers::app();
    $t->same('cross-origin', $app->handle(Helpers::request('GET', '/assets/images/reference/brand-emblem.webp'))->header('Cross-Origin-Resource-Policy'));
    $t->same('same-origin', $app->handle(Helpers::request('GET', '/contact'))->header('Cross-Origin-Resource-Policy'));
});

$t->test('SHOP_FREE_SHIPPING_ABOVE: the top bar line with Indian digit grouping; empty hides it; a bad value stops start-up', function () use ($t, $get): void {
    foreach (['1999' => '₹1,999', '999' => '₹999', '125000' => '₹1,25,000', '12345678' => '₹1,23,45,678'] as $value => $shown) {
        $b = $get('/contact', ['SHOP_FREE_SHIPPING_ABOVE' => (string) $value])->body();
        $t->contains('<div class="pf_topbar"><p>Free Shipping on Orders Above ' . $shown . ' &nbsp;|&nbsp; Authentic Handlooms</p>', $b, (string) $value);
    }
    $b = $get('/contact', [])->body();
    $t->ok(!str_contains($b, 'Free Shipping'), 'empty: no free-shipping line anywhere');
    $t->contains('<div class="pf_topbar"><p>Authentic Handlooms</p>', $b);
    foreach (['abc', '0', '-5', '19.99', '1,999'] as $bad) {
        $t->throws(ConfigException::class, static fn () => Helpers::config(['SHOP_FREE_SHIPPING_ABOVE' => $bad]), $bad);
    }
});

$t->test('logos: the website takes the first of svg, webp, png; emails always take brand/logo.png and fall back to the emblem', function () use ($t, $full): void {
    $dir = Helpers::tempDir('pf_brand_') . '/'; // never the real brand folder
    $made = [];
    register_shutdown_function(static fn () => Helpers::rmTree(rtrim($dir, '/')));
    $config = Helpers::config($full + ['WEB_ORIGIN' => 'https://shop.example.test']);
    ShopInfo::init($config, $dir);
    $emblem = 'https://shop.example.test/assets/images/reference/brand-emblem.webp';
    $t->same(['/assets/images/reference/brand-emblem.webp', $emblem], [ShopInfo::logoPath(), ShopInfo::mailLogoUrl()], 'no file: the emblem');
    file_put_contents($made[] = $dir . 'logo.webp', 'x');
    $t->same(['/assets/images/brand/logo.webp', $emblem], [ShopInfo::logoPath(), ShopInfo::mailLogoUrl()], 'webp only: the site uses it, the email still has no png');
    file_put_contents($made[] = $dir . 'logo.png', 'x');
    $t->same(['/assets/images/brand/logo.webp', 'https://shop.example.test/assets/images/brand/logo.png'], [ShopInfo::logoPath(), ShopInfo::mailLogoUrl()], 'webp + png: the site keeps the first found, the email takes the png');
    file_put_contents($made[] = $dir . 'logo.svg', 'x');
    $t->same('/assets/images/brand/logo.svg', ShopInfo::logoPath(), 'svg comes first');
    $t->contains('<img src="https://shop.example.test/assets/images/brand/logo.png"', MailTemplates::code('reset', '123456', 5)['html']);
    ShopInfo::init($config);
});

$t->test('SHOP_* text is escaped once: an entity typed into the settings is kept as its character, and an apostrophe and & print once-escaped on the page and in the email', function () use ($t, $get, $full): void {
    foreach (["Sahiti's Ramkuteer & Co", "Sahiti&apos;s Ramkuteer &amp; Co"] as $typed) {
        $settings = ['SHOP_ADDRESS' => $typed, 'SHOP_LEGAL_NAME' => $typed, 'SHOP_HOURS' => $typed] + $full;
        $b = $get('/contact', $settings)->body();
        $t->contains('Sahiti&#039;s Ramkuteer &amp; Co', $b, $typed);
        $t->ok(!str_contains($b, '&amp;apos;') && !str_contains($b, '&amp;amp;'), 'never escaped twice: ' . $typed);
        $t->same("Sahiti's Ramkuteer & Co", Helpers::config($settings)->shopAddress, 'stored as plain text');
        ShopInfo::init(Helpers::config($settings));
        $m = MailTemplates::code('reset', '123456', 5);
        $t->contains('Sahiti&#039;s Ramkuteer &amp; Co', $m['html'], 'email html: ' . $typed);
        $t->contains("Sahiti's Ramkuteer & Co", $m['text'], 'email text is plain: ' . $typed);
    }
    ShopInfo::init(Helpers::config($full));
});

$t->test('one email layout: greeting, title, optional button, only the social icons that are set, alt text on every image, a plain-text twin', function () use ($t, $full): void {
    ShopInfo::init(Helpers::config($full + ['WEB_ORIGIN' => 'https://shop.example.test']), Helpers::tempDir('pf_brand_') . '/');
    $m = MailTemplates::layout('Your order is on its way', ['It left our shop today.'], ['label' => 'Track order', 'url' => 'https://shop.example.test/account/orders'], 'Asha Rao');
    foreach (['>Your order is on its way</h1>', 'Hi Asha,', 'It left our shop today.', 'href="https://shop.example.test/account/orders"', '>Track order</a>', 'max-width:600px', 'background:#800020', 'brand/mail/instagram.png', 'brand/mail/whatsapp.png', 'mailto:help@example.test', 'You received this because you have an account at shop.example.test.'] as $needle) {
        $t->contains($needle, $m['html'], $needle);
    }
    foreach (['facebook', 'youtube'] as $unset) {
        $t->ok(!str_contains($m['html'], 'brand/mail/' . $unset . '.png'), $unset . ' is not set, so no icon');
    }
    preg_match_all('/<img\b[^>]*>/', $m['html'], $imgs);
    $t->ok(count($imgs[0]) >= 4, 'logo twice and two icons');
    foreach ($imgs[0] as $tag) {
        $t->same(1, preg_match('/\salt="[^"]+"/', $tag), 'alt text: ' . $tag);
    }
    $t->contains("Hi Asha,\n\nIt left our shop today.\n\nTrack order: https://shop.example.test/account/orders", $m['text']);
    $t->contains('Hi there,', MailTemplates::layout('T', ['x'])['html']);
    ShopInfo::init(Helpers::config($full));
});
$t->test('the email header is the one navbar-logo picture, linked to the shop, shown at half its 2x size; without the file the emblem and the name stay', function () use ($t, $full): void {
    $dir = Helpers::tempDir('pf_brand_') . '/';
    register_shutdown_function(static fn () => Helpers::rmTree(rtrim($dir, '/')));
    ShopInfo::init(Helpers::config($full + ['WEB_ORIGIN' => 'https://shop.example.test']), $dir);
    $t->same(null, ShopInfo::emailLogo());
    $t->contains('>PRATYANGI FASHIONS</a>', MailTemplates::layout('T', ['x'])['html'], 'fallback: the name as text');
    // a 40 x 20 picture (a 2x render of a 20 x 10 one)
    file_put_contents($dir . 'email-logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAACgAAAAUCAYAAAD6L+6mAAAAEklEQVR4nGP4TyJgGNUwPDUAAIPEf5Dhma5vAAAAAElFTkSuQmCC'));
    $logo = ShopInfo::emailLogo();
    $t->same(['https://shop.example.test/assets/images/brand/email-logo.png', 20, 10], [$logo['url'] ?? null, $logo['width'] ?? null, $logo['height'] ?? null]);
    $html = MailTemplates::layout('T', ['x'])['html'];
    $t->contains('<a href="https://shop.example.test"><img src="https://shop.example.test/assets/images/brand/email-logo.png" alt="Pratyangi Fashions - Tradition Meets Elegance" width="20" height="10"', $html);
    $t->ok(!str_contains($html, '>PRATYANGI FASHIONS</a>'), 'one picture, no separate name');
    ShopInfo::init(Helpers::config($full));
});
$t->test('hostile values are escaped', function () use ($t, $get): void {
    $b = $get('/contact', ['SHOP_LEGAL_NAME' => '<script>alert(1)</script>', 'SHOP_ADDRESS' => '"><img src=x onerror=alert(1)>'])->body();
    $t->ok(!str_contains($b, '<script>alert(1)') && !str_contains($b, '<img src=x') , 'nothing raw');
    $t->contains('&lt;script&gt;alert(1)&lt;/script&gt;', $b);
});

$t->test('bad shop settings stop start-up with the setting name only', function () use ($t): void {
    foreach ([['SHOP_GSTIN' => 'short'], ['SHOP_SUPPORT_EMAIL' => 'not an email'], ['SHOP_SOCIAL_FACEBOOK' => 'http://insecure.example.test'], ['SHOP_SOCIAL_YOUTUBE' => 'javascript:alert(1)'], ['SHOP_ADDRESS' => str_repeat('x', 301)]] as $bad) {
        $e = $t->throws(ConfigException::class, static fn () => Helpers::config($bad));
        $t->contains(array_key_first($bad), $e->getMessage());
        $t->ok(!str_contains($e->getMessage(), 'insecure.example') && !str_contains($e->getMessage(), 'javascript:alert'), 'the value is not echoed');
    }
});

$t->test('the emails: logo on top, brand colours, the code, and the legal name, address and support email at the bottom (HTML escaped); plain text has the same footer', function () use ($t, $full): void {
    ShopInfo::init(Helpers::config($full + ['WEB_ORIGIN' => 'https://shop.example.test']), Helpers::tempDir('pf_brand_') . '/'); // an empty brand folder: the emblem
    foreach (['email_verify', 'reset'] as $purpose) {
        $m = MailTemplates::code($purpose, '482913', 10);
        foreach (['<img src="https://shop.example.test/assets/images/reference/brand-emblem.webp"', '#800020', '482913', 'Sample Legal Name Pvt Ltd', 'CIN: U46901AP2026PTC126193', 'mailto:help@example.test', 'This code works for 10 minutes.'] as $needle) {
            $t->contains($needle, $m['html'], $purpose . ': ' . $needle);
        }
        $t->contains('Your code is 482913.', $m['text']);
        $t->contains("--
Pratyangi Fashions (a unit of Sample Legal Name Pvt Ltd)
CIN: U46901AP2026PTC126193
Support: help@example.test", $m['text']);
        $t->ok(!str_contains($m['text'], 'http'), 'no link in the text');
    }
    ShopInfo::init(Helpers::config(['SHOP_LEGAL_NAME' => '<b>x</b>', 'SHOP_ADDRESS' => '<i>y</i>']));
    $t->ok(!str_contains(MailTemplates::code('reset', '123456', 5)['html'], '<b>x</b>'), 'hostile company text is escaped in the HTML mail');
});

$t->test('CIN, WhatsApp and the Grievance Officer: footer, /contact and policy text; hidden when empty; escaped once; checked at start-up', function () use ($t, $get, $full): void {
    $tricky = "Asha O'Brien, #5 & Co";
    $b = $get('/contact', ['SHOP_GRIEVANCE_NAME' => $tricky, 'SHOP_GRIEVANCE_PHONE' => '+91 90000 11111'] + $full)->body();
    foreach (['<dt>CIN</dt><dd>U46901AP2026PTC126193</dd>', 'href="https://wa.me/919989233328"', 'Chat on WhatsApp', '<h2>Grievance Officer</h2>', 'Asha O&#039;Brien, #5 &amp; Co',
        'href="mailto:grievance@example.test"', '+91 90000 11111', 'We acknowledge complaints within 48 hours and resolve them within 30 days.', '<dt>Shops &amp; Establishments Reg. No.</dt><dd>SEA/RAN/ALO/BN/1425883/2026</dd>',
        'Pratyangi Fashions (a unit of Sample Legal Name Pvt Ltd)', 'CIN: U46901AP2026PTC126193'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
    $t->ok(!str_contains($b, "O'Brien") && !str_contains($b, '&amp;amp;'), 'escaped once');
    $t->ok(!stripos($b, 'registered office'), 'no registered office anywhere');
    $terms = $get('/terms', $full)->body();
    $t->contains('Pratyangi Fashions (a unit of Sample Legal Name Pvt Ltd) is the seller of every order.', $terms);
    // empty = hidden (the grievance name and phone are optional on their own)
    $plain = $get('/contact', ['SHOP_GRIEVANCE_EMAIL' => ''] + $full)->body();
    $t->ok(!str_contains($plain, 'Grievance Officer'), 'no email, no Grievance block');
    $bare = $get('/contact', [])->body();
    foreach (['wa.me', 'CIN', 'Grievance Officer', 'a unit of', 'Establishments'] as $gone) {
        $t->ok(!str_contains($bare, $gone), 'hidden when empty: ' . $gone);
    }
    $some = $get('/contact', ['SHOP_GRIEVANCE_EMAIL' => 'g@example.test'] + $full)->body();
    $t->ok(!str_contains(substr($some, (int) strpos($some, 'Grievance Officer')), '<dt>Name</dt><dd></dd>'), 'no empty name row');
    foreach ([['SHOP_CIN' => 'bad'], ['SHOP_WHATSAPP' => '+91 99892 33328'], ['SHOP_GRIEVANCE_EMAIL' => 'nope'], ['SHOP_GRIEVANCE_NAME' => str_repeat('a', 81)], ['SERVER_CHECK_KEY' => 'short']] as $bad) {
        $t->throws(ConfigException::class, static fn () => Helpers::config($bad), key($bad));
    }
});

exit($t->finish());

<?php
declare(strict_types=1);

// No database. Run: php tests/h1_pages_test.php
// Stage H1: the cookie notice on every page, /cookie-policy, the unticked "offers and new arrivals" boxes, the delivery-number note, and the header rules in the stylesheet.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Views\View;

$t = new TestCase('h1_pages_test');
$root = dirname(__DIR__);
$app = Helpers::app();
$get = static fn (string $path): string => $app->handle(Helpers::request('GET', $path))->body();

$t->test('every page has the cookie notice (hidden until the script decides), the script, and the footer link to reopen it', function () use ($t, $get): void {
    foreach (['/', '/new-arrivals', '/login', '/signup', '/bag', '/privacy', '/contact', '/cookie-policy'] as $path) {
        $b = $get($path);
        $t->contains('data-pf="cookie-bar" role="region" aria-label="Cookie notice" hidden>', $b, $path);
        $t->contains('We use essential cookies to run this site.', $b, $path);
        $t->contains('data-pf="cookie-accept">Accept</button>', $b, $path);
        $t->contains('data-pf="cookie-manage"', $b, $path);
        $t->contains('<script defer src="/assets/cookies.js"></script>', $b, $path);
        $t->same(1, substr_count($b, 'data-pf="cookie-settings">Cookie settings</button>'), $path . ': footer link');
    }
    $b = $get('/');
    $t->contains('<strong>Necessary</strong>', $b);
    $t->contains('<input type="checkbox" checked disabled/>', $b, 'Necessary is on and cannot be turned off');
    $t->contains('<input type="checkbox" data-pf="cookie-analytics"/>', $b, 'Analytics starts off');
    $t->contains('<input type="checkbox" data-pf="cookie-marketing"/>', $b, 'Marketing starts off');
    $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), 'no inline script');
});

$t->test('/cookie-policy: a draft banner and the real cookies and storage', function () use ($t, $get): void {
    $b = $get('/cookie-policy');
    foreach (['DRAFT - to be reviewed', 'Cookie Policy', 'pf_session', 'pf_consent', 'Security checks (no cookie)', 'local storage', 'Google sign-in', 'Analytics and marketing'] as $needle) {
        $t->contains($needle, $b);
    }
    $t->contains('href="/cookie-policy">Cookie Policy</a>', $b, 'the footer lists it');
});

$t->test('the offers boxes are never ticked, on sign-up (email and mobile) and in the address dialog; the mobile number is required with +91 shown', function () use ($t, $root): void {
    $signup = View::render('auth/signup', ['fromCheckout' => false, 'query' => '', 'smsEnabled' => true, 'googleClientId' => null]);
    foreach (['auth', 'otp'] as $id) {
        $t->contains('<input type="checkbox" id="' . $id . '-offers"/>', $signup, $id);
    }
    $t->contains('Send me offers and new arrivals by SMS, WhatsApp and email.', $signup);
    $t->ok(!str_contains($signup, 'offers" checked') && !preg_match('/offers"[^>]*checked/', $signup), 'never pre-ticked');
    $off = View::render('auth/signup', ['fromCheckout' => false, 'query' => '', 'smsEnabled' => false, 'googleClientId' => null]);
    $t->contains('id="auth-offers"', $off, 'also with SMS off');
    foreach ([false, true] as $sms) {
        $dialog = View::render('partials/address_dialog', ['smsEnabled' => $sms]);
        $t->contains('<input type="checkbox" name="offers" value="1" data-pf-field="offers"/>', $dialog);
        $t->same(0, preg_match('/data-pf-field="offers"[^>]*checked|checked[^>]*data-pf-field="offers"/', $dialog), 'never pre-ticked');
        $t->contains('<label class="mdI9xa_required" for="addr-phone">Phone Number</label><div class="mdI9xa_phone"><span class="mdI9xa_country">+91</span><input type="text" id="addr-phone" name="phone" required', $dialog);
        $t->contains($sms ? 'We&#039;ll send a code to confirm this number when you place an order.' : 'We&#039;ll call this number for delivery.', $dialog);
        $t->ok(!str_contains($dialog, $sms ? 'call this number' : 'send a code'), 'only the note that applies');
    }
});

$t->test('the cookie script gates optional scripts: nothing non-essential is loaded by a tag, only through loadScript after consent', function () use ($t, $root): void {
    $js = (string) file_get_contents($root . '/public/assets/cookies.js');
    $t->contains("NAME = 'pf_consent'", $js);
    $t->contains("'=v1.a'", $js);
    $t->contains('Max-Age=', $js);
    $t->contains('60 * 60 * 24 * 365', $js, '12 months');
    $t->contains('SameSite=Lax', $js);
    $t->contains("category !== 'analytics' && category !== 'marketing'", $js);
    foreach (['/', '/new-arrivals', '/login', '/signup', '/bag', '/cookie-policy'] as $path) {
        $app = Helpers::app();
        preg_match_all('/<script[^>]*\ssrc="([^"]+)"/', $app->handle(Helpers::request('GET', $path))->body(), $m);
        foreach ($m[1] as $src) {
            $t->ok(str_starts_with($src, '/assets/'), $path . ': every script is our own file (' . $src . ')');
        }
    }
});

$t->test('stylesheet: the header keeps 24px between the logo and the icons, the phone header centres the logo, the buttons are 44px, the bar never hides the buy buttons', function () use ($t, $root): void {
    $css = (string) file_get_contents($root . '/public/assets/site.css');
    $t->contains('column-gap: 24px', $css);
    $t->contains('grid-template-columns: minmax(max-content, 1fr) auto minmax(max-content, 1fr)', $css);
    $t->contains('.pSCCwa_brand { justify-self: center; }', $css);
    $t->contains('.pf_cookieBtn { appearance: none; min-height: 44px; min-width: 44px;', $css);
    $t->contains('--pf-cookie-h', $css);
    $t->same(3, substr_count($css, 'var(--pf-cookie-h, 0px)') >= 3 ? 3 : 0, 'the WhatsApp button and both sticky buy buttons are lifted above the bar');
    $t->contains('background: #25d366', $css, 'the WhatsApp button is green as in the web app');
});

exit($t->finish());

<?php
declare(strict_types=1);

// No database, no network. Run: php tests/demo_ui_test.php
// DEMO_UI: off by default (no preview page, no preview row, no pill), refused in production, and with it on the preview pages show only DemoData.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Config;
use Pratyangi\ConfigException;
use Pratyangi\Demo\DemoData;

$t = new TestCase('demo_ui_test');
$paths = ['/checkout/address', '/checkout/payment', '/checkout/review', '/checkout/demo-confirmation', '/account/payments', '/account/membership', '/account/reviews', '/account/reviews/edit?id=1'];
$cust = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'created_at' => '2026-10-03 08:00:00', 'email_verified_at' => '2026-10-03 08:05:00'];
$cookie = ['Cookie' => 'pf_session=abc'];

/** An App with a fake signed-in customer (no database). */
$app = static function (bool $demo) use ($cust) {
    $app = Helpers::app(Helpers::config(['DEMO_UI' => $demo ? 'true' : 'false']));
    foreach (['landing', 'authPages', 'catalogPages', 'shopPages', 'policyPages', 'demoPages'] as $name) {
        $page = (new ReflectionProperty($app, $name))->getValue($app);
        if ($page !== null) {
            (new ReflectionProperty($page, 'sessionLookup'))->setValue($page, static fn (string $token): ?array => $cust);
        }
    }
    return $app;
};

$t->test('DEMO_UI is off by default and refused in production', function () use ($t): void {
    $t->same(false, Helpers::config()->demoUi);
    $t->same(true, Helpers::config(['DEMO_UI' => 'true'])->demoUi);
    try {
        new Config(['APP_ENV' => 'production', 'DEMO_UI' => 'true']);
        throw new RuntimeException('production + DEMO_UI=true must not start');
    } catch (ConfigException $e) {
        $t->contains('DEMO_UI', $e->getMessage());
    }
});

$t->test('DEMO_UI=false: the preview pages are ordinary 404s and the account menu has no preview rows', function () use ($t, $app, $paths, $cookie): void {
    $a = $app(false);
    foreach ($paths as $p) {
        $r = $a->handle(Helpers::request('GET', $p, $cookie));
        if (in_array($p, ['/account/payments', '/account/reviews'], true)) { // the real (empty) account pages exist; they must show no preview content
            $t->ok(!str_contains($r->body(), 'pf_d') && !str_contains($r->body(), 'Preview'), $p . ': the real page, no preview content');
            continue;
        }
        $t->same(404, $r->status(), $p);
    }
    $menu = $a->handle(Helpers::request('GET', '/account', $cookie))->body();
    foreach (['Membership', 'My Reviews', 'pf_dPill', 'Preview'] as $needle) {
        $t->ok(!str_contains($menu, $needle), 'no "' . $needle . '" on /account');
    }
    $t->ok(!str_contains($a->handle(Helpers::request('GET', '/account/payments', $cookie))->body(), 'credit or debit card'), 'no card dialog');
});

$t->test('DEMO_UI=true: every preview page answers 200 with the Preview pill, no inline script or handler, signed out goes to /login', function () use ($t, $app, $paths, $cookie): void {
    $a = $app(true);
    foreach ($paths as $p) {
        $r = $a->handle(Helpers::request('GET', $p, $cookie));
        $t->same(200, $r->status(), $p);
        $b = $r->body();
        $t->contains('Preview', $b, $p);
        $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), $p . ': no inline script');
        $t->same(0, preg_match('/\son[a-z]+="/i', $b), $p . ': no inline event handler');
        $t->same('no-store', $r->header('Cache-Control'), $p);
        $t->same(302, $a->handle(Helpers::request('GET', $p))->status(), $p . ' signed out');
    }
    $menu = $a->handle(Helpers::request('GET', '/account', $cookie))->body();
    $t->contains('<strong>Membership</strong>', $menu);
    $t->contains('<strong>My Reviews</strong>', $menu);
});

$t->test('price rule: Subtotal, Discount, GST (rate and amount), Shipping, Total; never "Inclusive of all taxes"', function () use ($t, $app, $cookie): void {
    $a = $app(true);
    $sum = DemoData::summary(DemoData::lines());
    $t->same($sum['subtotalPaise'] - $sum['discountPaise'] + $sum['gstPaise'] + $sum['shippingPaise'], $sum['totalPaise']);
    foreach (['/checkout/address', '/checkout/payment', '/checkout/review', '/checkout/demo-confirmation'] as $p) {
        $b = $a->handle(Helpers::request('GET', $p, $cookie))->body();
        $t->ok(stripos($b, 'inclusive of all taxes') === false, $p . ': no "Inclusive of all taxes"');
    }
    $b = $a->handle(Helpers::request('GET', '/checkout/review', $cookie))->body();
    foreach (['Subtotal (3 items)', 'Discount (WELCOME10)', 'GST (5%)', DemoData::inr($sum['gstPaise']), 'Shipping Charges', 'Total Amount', DemoData::inr($sum['totalPaise']), 'Place Order'] as $needle) {
        $t->contains($needle, $b, $needle);
    }
});

$t->test('payment options: UPI and card can be chosen, COD is "Coming soon" and disabled; the review page follows the choice', function () use ($t, $app, $cookie): void {
    $a = $app(true);
    $b = $a->handle(Helpers::request('GET', '/checkout/payment', $cookie))->body();
    $t->contains('Coming soon', $b);
    $t->same(1, preg_match('/name="pay" value="cod" disabled/', $b));
    $t->contains('aria-disabled="true" data-pf="demo-continue"', $b);
    $t->contains('Credit / Debit Card', $a->handle(Helpers::request('GET', '/checkout/review?pay=card', $cookie))->body());
    $t->contains('<strong>UPI</strong>', $a->handle(Helpers::request('GET', '/checkout/review?pay=cod', $cookie))->body(), 'a disabled choice falls back to UPI');
});

$t->test('saved payments are masked and the card dialog is UI only', function () use ($t, $app, $cookie): void {
    $b = $app(true)->handle(Helpers::request('GET', '/account/payments', $cookie))->body();
    $t->contains('Visa •••• 4242', $b);
    $t->same(0, preg_match('/\b\d{13,19}\b/', strip_tags($b)), 'no full card number anywhere');
    $start = (int) strpos($b, 'data-demo-modal');
    $dialog = substr($b, $start, (int) strpos($b, '</form>', $start) - $start);
    $t->contains('Cards are added securely in the Razorpay payment window.', $dialog);
    $t->same(0, preg_match('/<(input|select)(?![^>]*\sdisabled)/', $dialog), 'every field in the dialog is disabled');
    $t->same(0, preg_match('/<(input|select)(?![^>]*autocomplete="off")/', $dialog), 'autocomplete="off" on every field');
    $t->ok(!str_contains($dialog, '<form action') && !str_contains($dialog, 'method='), 'the form posts nowhere');
});

$t->test('reviews, edit review and membership use the mock data and save nothing', function () use ($t, $app, $cookie): void {
    $a = $app(true);
    $r = $a->handle(Helpers::request('GET', '/account/reviews', $cookie))->body();
    $t->contains('Reviewed (4)', $r);
    $t->contains('Pending Reviews (1)', $r);
    $e = $a->handle(Helpers::request('GET', '/account/reviews/edit?id=1', $cookie))->body();
    $t->contains('Update Review', $e);
    $t->contains('data-demo-toast="Preview only: nothing was saved."', $e);
    $form = substr($e, (int) strpos($e, 'pf_dEditForm'), (int) strpos($e, '</form>', (int) strpos($e, 'pf_dEditForm')) - (int) strpos($e, 'pf_dEditForm'));
    $t->ok($form !== '' && !str_contains($form, 'action=') && !str_contains($form, 'method='), 'the review form posts nowhere');
    $m = $a->handle(Helpers::request('GET', '/account/membership', $cookie))->body();
    foreach (['Silver', 'Platinum', 'Gold', 'Compare Membership Benefits'] as $needle) {
        $t->contains($needle, $m, $needle);
    }
});

$t->test('demo.js is served and the pages are not cached', function () use ($t, $app): void {
    $r = $app(true)->handle(Helpers::request('GET', '/assets/demo.js'));
    $t->same(200, $r->status());
    $t->contains('text/javascript', (string) $r->header('Content-Type'));
});

exit($t->finish());

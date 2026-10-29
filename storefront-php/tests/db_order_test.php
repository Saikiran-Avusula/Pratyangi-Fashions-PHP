<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test, settings from environment variables only.
// Stage B1 (docs/API_CONTRACT.md 2.6): the online order and payment routes through the App, with the ERP on a local fake HTTP server
// (tests/fake_erp/router.php) and an in-memory fake Razorpay. No real ERP, Razorpay, SMS or mail is contacted.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/FakeErp.php';
require __DIR__ . '/lib/FakeRazorpay.php';

use Pratyangi\App;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\ConfigException;
use Pratyangi\Erp\ErpClient;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Http\Response;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Security\Tokens;
use Pratyangi\Support\Logger;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: the order database tests run only on port 3399\n");
    exit(2);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);

$erp = new FakeErp();
$erp->start();
register_shutdown_function(static fn () => $erp->stop());

$t = new TestCase('db_order_test');
$secret = 'fake-razorpay-secret-for-tests-only';
$keyId = 'rzp_test_FakeKeyId123';
$origin = ['Origin' => Helpers::ORIGIN];
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$logged = [];
$logger = new Logger('debug', static function (string $line) use (&$logged): void {
    $logged[] = $line;
});
$rzp = new FakeRazorpay();
$settings = [
    'ERP_MODE' => 'http', 'ERP_BASE_URL' => $erp->baseUrl(), 'ERP_CATALOG_KEY' => FakeErp::KEY, 'ERP_ORDER_KEY' => FakeErp::ORDER_KEY, 'ERP_TIMEOUT_MS' => '500',
    'PAYMENTS_ENABLED' => 'true', 'CHECKOUT_ENABLED' => 'true', 'RAZORPAY_KEY_ID' => $keyId, 'RAZORPAY_KEY_SECRET' => $secret,
];
$makeApp = static function (array $override, ?FakeRazorpay $razorpay, ?string $orderKey = FakeErp::ORDER_KEY) use ($pdo, $clock, $logger, $erp): App {
    $config = Helpers::config($override);
    return new App(
        $config, $logger, $clock, static fn (): \PDO => $pdo, new NullMailer(),
        new ErpClient($erp->baseUrl(), FakeErp::KEY, null, 'offer-else-retail', 'exclusive', 500, null, $logger, $orderKey),
        new DbRateLimitStore(static fn (): \PDO => $pdo), ['payment_order' => 100000, 'payment_verify' => 100000], null, null, $razorpay,
    );
};
$app = $makeApp($settings, $rzp);

// customers: A has a verified mobile, B has a mobile that was never verified
$insert = $pdo->prepare('INSERT INTO shop_customers (name, email, mobile, mobile_verified_at, email_verified_at, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(3), NULL, NOW(3), NOW(3))');
$insert->execute(['Buyer A', 'buyer.a@example.com', '+919800000001', '2026-01-01 00:00:00.000']);
$custA = (int) $pdo->lastInsertId();
$insert->execute(['Buyer B', 'buyer.b@example.com', '+919800000002', null]);
$custB = (int) $pdo->lastInsertId();
$insert->execute(['Buyer C', 'buyer.c@example.com', '+919800000003', '2026-01-01 00:00:00.000']);
$custC = (int) $pdo->lastInsertId();
$insert->execute(['Buyer D', 'buyer.d@example.com', '+919800000004', '2026-01-01 00:00:00.000']);
$custD = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE shop_customers SET email_verified_at = NULL WHERE id = ?')->execute([$custD]);
$sessions = new SessionRepo($pdo);
$cookieFor = static function (int $id) use ($sessions): array {
    $token = Tokens::newToken();
    $sessions->create($id, Tokens::sha256($token), (float) (time() + 86400 * 30), 'TestAgent');
    return ['Cookie' => 'pf_session=' . $token];
};
$cookieA = $cookieFor($custA);
$cookieB = $cookieFor($custB);
$cookieC = $cookieFor($custC);
$cookieD = $cookieFor($custD);
$addAddress = $pdo->prepare("INSERT INTO shop_customer_addresses (customer_id, type, label, recipient_name, phone, house, street, city, state, pincode, is_default) VALUES (?, 'Home', '', ?, '9876543210', '12-3-45', 'Road No. 5', 'Hyderabad', 'Telangana', '500034', 1)");
$addAddress->execute([$custA, 'Priya Rao']);
$addrA = (int) $pdo->lastInsertId();
$addAddress->execute([$custB, 'Buyer B']);
$addrB = (int) $pdo->lastInsertId();
$addAddress->execute([$custC, 'Buyer C']);
$addrC = (int) $pdo->lastInsertId();
$addAddress->execute([$custD, 'Buyer D']);
$addrD = (int) $pdo->lastInsertId();

$n = 0;
$newKey = static function () use (&$n): string {
    return 'test-key-' . str_pad((string) ++$n, 12, '0', STR_PAD_LEFT);
};
$ip = 0;
$post = static function (string $path, array $body, array $headers = [], ?App $with = null) use (&$app, $origin, &$ip): Response {
    $ip++;
    return ($with ?? $app)->handle(Helpers::request('POST', '/api/v1' . $path, $origin + $headers, $body, '198.51.100.' . ($ip % 250 + 1)));
};
$bag = [['sku' => 'FAKE-1', 'quantity' => 2], ['sku' => 'FAKE-2', 'quantity' => 1]];
$start = static fn (array $cookie, string $key, int $address, ?array $lines = null, ?App $with = null): Response => $post('/payments/razorpay/order', ['addressId' => (string) $address, 'lines' => $lines ?? $GLOBALS['bag']], $cookie + ['Idempotency-Key' => $key], $with);
$verify = static fn (array $cookie, string $orderId, string $paymentId, ?string $signature = null): Response => $post('/payments/razorpay/verify', [
    'razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $signature ?? FakeRazorpay::signature($orderId, $paymentId, $GLOBALS['secret']),
], $cookie);
$count = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$row = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: [];
};
$erpOrderCalls = static fn (): int => count(array_filter($erp->requests(), static fn (array $r): bool => $r['path'] === '/api/order_create.php'));
$erp->flag('stock_ok');
/** Pays an order the way the customer would and returns [razorpayOrderId, paymentId]. */
$started = static function (array $cookie, int $address, ?array $lines = null) use ($start, $newKey): array {
    $r = $start($cookie, $newKey(), $address, $lines);
    if ($r->status() !== 201) {
        throw new RuntimeException('start failed: ' . $r->status() . ' ' . $r->body());
    }
    return [$r->decoded()['order']['orderNo'], $r->decoded()['razorpay']['orderId']];
};

$t->test('GET /payments/config: on with the mode from the key id; off when payments are off; no key id in the answer', function () use ($t, $app, $makeApp): void {
    $r = $app->handle(Helpers::request('GET', '/api/v1/payments/config'));
    $t->same([200, ['enabled' => true, 'mode' => 'test'], 'no-store'], [$r->status(), $r->decoded(), $r->header('Cache-Control')]);
    $off = $makeApp(['ERP_MODE' => 'off'], null)->handle(Helpers::request('GET', '/api/v1/payments/config'));
    $t->same(['enabled' => false, 'mode' => null], $off->decoded());
});

$t->test('signed out is 401, a bad Origin is 403, and the answer is never cached', function () use ($t, $app, $newKey): void {
    $r = $app->handle(Helpers::request('POST', '/api/v1/payments/razorpay/order', ['Origin' => Helpers::ORIGIN, 'Idempotency-Key' => $newKey()], ['addressId' => '1', 'lines' => []]));
    $t->same([401, 'no-store'], [$r->status(), $r->header('Cache-Control')]);
    $r = $app->handle(Helpers::request('POST', '/api/v1/payments/razorpay/verify', ['Origin' => 'https://evil.example'], []));
    $t->same(403, $r->status());
});

$t->test('payments off: 503 payments-disabled, nothing saved; CHECKOUT_ENABLED off: 503 checkout-disabled, nothing validated, saved or sent', function () use ($t, $makeApp, $start, $settings, $cookieA, $addrA, $newKey, $count, $rzp, $erpOrderCalls): void {
    $before = $count('SELECT COUNT(*) FROM shop_orders');
    $off = $makeApp(['PAYMENTS_ENABLED' => 'false'] + $settings, null);
    $r = $start($cookieA, $newKey(), $addrA, null, $off);
    $t->same([503, 'payments-disabled'], [$r->status(), $r->decoded()['code'] ?? null]);
    $closed = $makeApp(['CHECKOUT_ENABLED' => 'false'] + $settings, $rzp);
    $r = $start($cookieA, 'bad key with spaces', 0, [], $closed); // not even looked at
    $t->same([503, 'checkout-disabled', 'Online ordering is not open yet. Please check back soon.'], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['message']]);
    $t->same($before, $count('SELECT COUNT(*) FROM shop_orders'));
    $t->same([0, 0], [$rzp->createCalls, $erpOrderCalls()]);
    $default = Helpers::config(['PAYMENTS_ENABLED' => 'true', 'RAZORPAY_KEY_ID' => 'rzp_test_FakeKeyId123', 'RAZORPAY_KEY_SECRET' => 'x']);
    $t->same(false, $default->checkoutEnabled, 'CHECKOUT_ENABLED defaults to false');
});

$t->test('a customer without a verified mobile cannot start an order (403 mobile-required); nothing is saved', function () use ($t, $start, $cookieB, $addrB, $newKey, $count, $rzp): void {
    $r = $start($cookieB, $newKey(), $addrB);
    $t->same([403, 'mobile-required'], [$r->status(), $r->decoded()['code'] ?? null]);
    $t->same(0, $count('SELECT COUNT(*) FROM shop_orders WHERE customer_id <> 0 AND ship_name = ?', ['Buyer B']));
    $t->same(0, $rzp->createCalls);
});

$t->test('bad key and bad body are 422; another customer\'s address is refused and nothing is saved', function () use ($t, $post, $start, $cookieA, $addrA, $addrC, $newKey, $count, $bag): void {
    $r = $post('/payments/razorpay/order', ['addressId' => (string) $addrA, 'lines' => $bag], $cookieA + ['Idempotency-Key' => 'short']);
    $t->same([422, 'This checkout attempt could not be identified. Please reload the page and try again.'], [$r->status(), $r->decoded()['message']]);
    $r = $post('/payments/razorpay/order', ['addressId' => 'abc', 'lines' => []], $cookieA + ['Idempotency-Key' => $newKey()]);
    $t->same(422, $r->status());
    $t->same(['addressId', 'lines'], array_keys($r->decoded()['errors']));
    $r = $post('/payments/razorpay/order', ['addressId' => (string) $addrA, 'lines' => [['sku' => 'FAKE-1', 'quantity' => 11]]], $cookieA + ['Idempotency-Key' => $newKey()]);
    $t->same(422, $r->status());
    $before = $count('SELECT COUNT(*) FROM shop_orders');
    $r = $start($cookieA, $newKey(), $addrC); // C's address
    $t->same([422, 'invalid', 'Choose a delivery address from your saved addresses.'], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['message']]);
    $t->same($before, $count('SELECT COUNT(*) FROM shop_orders'));
});

$t->test('unknown SKU 409 with skus; the early stock check refuses with out-of-stock', function () use ($t, $start, $cookieA, $addrA, $newKey, $erp): void {
    $r = $start($cookieA, $newKey(), $addrA, [['sku' => 'NOPE-1', 'quantity' => 1]]);
    $t->same([409, 'unknown-sku', ['NOPE-1']], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['skus'] ?? null]);
    $erp->flag('stock_ok', false);
    $r = $start($cookieA, $newKey(), $addrA, [['sku' => 'FAKE-1', 'quantity' => 1]]);
    $t->same([409, 'out-of-stock', ['FAKE-1']], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['skus'] ?? null]);
    $erp->flag('stock_ok');
});

$happyKey = 'happy-key-000000000001';
$happyOrder = '';
$happyRzp = '';
$t->test('create: 201, priced by the ERP with tax on top (price tampering ignored), one Razorpay order, nothing sent to the ERP', function () use ($t, $app, $post, $cookieA, $addrA, $happyKey, $rzp, $erpOrderCalls, $row, $count, $keyId, &$happyOrder, &$happyRzp): void {
    $r = $post('/payments/razorpay/order', ['addressId' => $addrA, 'lines' => [['sku' => 'FAKE-1', 'quantity' => 2], ['sku' => 'FAKE-2', 'quantity' => 1]], 'price' => 1, 'total' => 1], $cookieA + ['Idempotency-Key' => $happyKey]);
    $t->same(201, $r->status());
    $b = $r->decoded();
    $t->same(['order', 'razorpay'], array_keys($b));
    $o = $b['order'];
    $t->same(['orderNo', 'status', 'paymentMethod', 'paid', 'paidAt', 'itemsTotal', 'tax', 'total', 'items', 'shipTo', 'createdAt', 'placedAt'], array_keys($o));
    $t->same(['awaiting-payment', 'RAZORPAY', false, null, null], [$o['status'], $o['paymentMethod'], $o['paid'], $o['paidAt'], $o['placedAt']]);
    // 2 x 1299.50 + 1 x 999.00 = 3598.00; tax 5% per line: 129.95 + 49.95 = 179.90 (rounded half up per line); total 3777.90
    $t->same([3598, 179.9, 3777.9], [$o['itemsTotal'], $o['tax'], $o['total']]);
    $t->same([['sku' => 'FAKE-1', 'name' => 'First Saree', 'quantity' => 2, 'unitPrice' => 1299.5, 'lineTotal' => 2599], ['sku' => 'FAKE-2', 'name' => 'Second Saree', 'quantity' => 1, 'unitPrice' => 999, 'lineTotal' => 999]], $o['items']);
    $t->same(['name' => 'Priya Rao', 'phone' => '9876543210', 'address' => '12-3-45, Road No. 5, Hyderabad, Telangana - 500034'], $o['shipTo']);
    $t->same(1, preg_match('/^PF-[2-9A-HJKMNP-Z]{12}$/D', $o['orderNo']));
    $t->same(['keyId', 'orderId', 'amountPaise', 'currency'], array_keys($b['razorpay']));
    $t->same([$keyId, 377790, 'INR'], [$b['razorpay']['keyId'], $b['razorpay']['amountPaise'], $b['razorpay']['currency']]);
    $t->same(['no-store', 1, 0], [$r->header('Cache-Control'), $rzp->createCalls, $erpOrderCalls()]);
    $t->ok(!str_contains($r->body(), 'idempotency') && !str_contains($r->body(), 'customer'), 'no key or customer id in the body');
    $stored = $row('SELECT * FROM shop_orders WHERE order_no = ?', [$o['orderNo']]);
    $t->same(['PAYMENT_PENDING', 377790, $b['razorpay']['orderId']], [$stored['status'], (int) $stored['total_paise'], $stored['razorpay_order_id']]);
    $t->same(1, $count('SELECT COUNT(*) FROM shop_razorpay_orders WHERE order_id = ?', [$stored['id']]));
    $happyOrder = $o['orderNo'];
    $happyRzp = $b['razorpay']['orderId'];
});

$t->test('the same Idempotency-Key: 200, the same order and the same Razorpay order, no second order; a different bag or payment is key-reused', function () use ($t, $post, $start, $cookieA, $cookieC, $addrA, $addrC, $happyKey, $happyOrder, $happyRzp, $rzp, $count): void {
    $calls = $rzp->createCalls;
    $r = $post('/payments/razorpay/order', ['addressId' => $addrA, 'lines' => [['sku' => 'FAKE-2', 'quantity' => 1], ['sku' => 'FAKE-1', 'quantity' => 2]]], $cookieA + ['Idempotency-Key' => $happyKey]);
    $t->same([200, $happyOrder, $happyRzp], [$r->status(), $r->decoded()['order']['orderNo'], $r->decoded()['razorpay']['orderId']]);
    $t->same($calls, $rzp->createCalls, 'no second Razorpay order');
    $t->same(1, $count('SELECT COUNT(*) FROM shop_orders WHERE idempotency_key = ?', [$happyKey]));
    $r = $start($cookieA, $happyKey, $addrA, [['sku' => 'FAKE-1', 'quantity' => 3]]);
    $t->same([409, 'key-reused'], [$r->status(), $r->decoded()['code'] ?? null]);
    // keys belong to a customer: the same text from another customer is a new order
    $r = $start($cookieC, $happyKey, $addrC);
    $t->same(201, $r->status());
});

$t->test('verify: a wrong signature is 400 payment-signature and Razorpay is not asked; another customer gets 404', function () use ($t, $verify, $cookieA, $cookieC, $happyRzp, $rzp, $count, $happyOrder): void {
    $paymentId = $rzp->pay($happyRzp);
    $calls = $rzp->fetchPaymentCalls;
    $r = $verify($cookieA, $happyRzp, $paymentId, str_repeat('a', 64));
    $t->same([400, 'payment-signature', 'We could not verify this payment. Please try again.'], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['message']]);
    $t->same($calls, $rzp->fetchPaymentCalls);
    $t->same(404, $verify($cookieC, $happyRzp, $paymentId)->status(), 'someone else\'s order');
    $t->same(1, $count("SELECT COUNT(*) FROM shop_orders WHERE order_no = ? AND status = 'PAYMENT_PENDING'", [$happyOrder]));
    $extra = $GLOBALS['post']('/payments/razorpay/verify', ['razorpay_order_id' => $happyRzp, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => str_repeat('a', 64), 'extra' => 1], $cookieA);
    $t->same(422, $extra->status(), 'a strict body');
});

$t->test('verify: a payment for another amount is 409 payment-mismatch (with the order number) and the order stays unpaid; an authorized payment is 202 pending', function () use ($t, $verify, $cookieA, $happyRzp, $rzp, $count, $happyOrder, $erpOrderCalls): void {
    $wrong = $rzp->pay($happyRzp, 'authorized', 100);
    $r = $verify($cookieA, $happyRzp, $wrong);
    $t->same([409, 'payment-mismatch'], [$r->status(), $r->decoded()['code'] ?? null]);
    $t->contains($happyOrder, $r->decoded()['message']);
    $auth = $rzp->pay($happyRzp, 'authorized');
    $r = $verify($cookieA, $happyRzp, $auth);
    $t->same([202, 'pending', 'awaiting-payment'], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status']]);
    $t->same([1, 0], [$count("SELECT COUNT(*) FROM shop_orders WHERE order_no = ? AND status = 'PAYMENT_PENDING'", [$happyOrder]), $erpOrderCalls()]);
});

$t->test('verify happy path: captured payment, ERP order made once with payment_status PAID, order PLACED; repeating verify does not ask the ERP again', function () use ($t, $verify, $cookieA, $happyRzp, $happyOrder, $rzp, $erp, $erpOrderCalls, $row): void {
    $paymentId = $rzp->pay($happyRzp);
    $r = $verify($cookieA, $happyRzp, $paymentId);
    $t->same(200, $r->status());
    $b = $r->decoded();
    $t->same(['order', 'outcome'], array_keys($b));
    $t->same(['placed', 'placed', 'RAZORPAY', true], [$b['outcome'], $b['order']['status'], $b['order']['paymentMethod'], $b['order']['paid']]);
    $t->ok($b['order']['paidAt'] !== null && $b['order']['placedAt'] !== null);
    $t->same(1, $erpOrderCalls());
    $sent = null;
    foreach ($erp->requests() as $req) {
        if ($req['path'] === '/api/order_create.php') {
            $sent = json_decode($req['body'], true);
        }
    }
    $t->same(['WEBSITE', $happyOrder, $happyOrder, 'Priya Rao', '9876543210', '12-3-45, Road No. 5, Hyderabad, Telangana - 500034', '3777.90', 'PAID'],
        [$sent['source'], $sent['order_no'], $sent['external_order_id'], $sent['customer_name'], $sent['customer_mobile'], $sent['shipping_address'], $sent['order_amount'], $sent['payment_status']]);
    $t->same([['sku' => 'FAKE-1', 'quantity' => 2], ['sku' => 'FAKE-2', 'quantity' => 1]], $sent['items']);
    $stored = $row('SELECT * FROM shop_orders WHERE order_no = ?', [$happyOrder]);
    $t->same(['PLACED', $paymentId, 377790, 1], [$stored['status'], $stored['payment_ref'], (int) $stored['paid_amount_paise'], (int) $stored['attempts']]);
    $again = $verify($cookieA, $happyRzp, $paymentId);
    $t->same([200, 'placed'], [$again->status(), $again->decoded()['outcome']]);
    $t->same(1, $erpOrderCalls(), 'the ERP was not asked again');
});

$t->test('a replayed key after payment returns the placed order with razorpay null', function () use ($t, $post, $cookieA, $addrA, $happyKey, $happyOrder): void {
    $r = $post('/payments/razorpay/order', ['addressId' => $addrA, 'lines' => [['sku' => 'FAKE-1', 'quantity' => 2], ['sku' => 'FAKE-2', 'quantity' => 1]]], $cookieA + ['Idempotency-Key' => $happyKey]);
    $t->same([200, $happyOrder, null, 'placed'], [$r->status(), $r->decoded()['order']['orderNo'], $r->decoded()['razorpay'], $r->decoded()['order']['status']]);
});

$t->test('an old Razorpay order is replaced after 30 minutes; every id stays in the history and a late payment on the OLD id still finds the order', function () use ($t, $start, $verify, $cookieA, $addrA, $newKey, $clock, $rzp, $count, $row, $erpOrderCalls): void {
    $key = $newKey();
    $first = $start($cookieA, $key, $addrA);
    $oldId = $first->decoded()['razorpay']['orderId'];
    $clock->advance(31 * 60);
    $second = $start($cookieA, $key, $addrA);
    $newId = $second->decoded()['razorpay']['orderId'];
    $t->same([200, true], [$second->status(), $newId !== $oldId]);
    $t->same($first->decoded()['order']['orderNo'], $second->decoded()['order']['orderNo']);
    $orderNo = $first->decoded()['order']['orderNo'];
    $t->same(2, $count('SELECT COUNT(*) FROM shop_razorpay_orders r JOIN shop_orders o ON o.id = r.order_id WHERE o.order_no = ?', [$orderNo]));
    $t->same($newId, $row('SELECT razorpay_order_id FROM shop_orders WHERE order_no = ?', [$orderNo])['razorpay_order_id']);
    $calls = $erpOrderCalls();
    $late = $rzp->pay($oldId); // the customer paid on the page opened before the swap
    $r = $verify($cookieA, $oldId, $late);
    $t->same([200, 'placed', $orderNo], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['orderNo']]);
    $t->same($calls + 1, $erpOrderCalls());
});

$t->test('a Razorpay order that is already paid is never replaced: 409 payment-received', function () use ($t, $start, $cookieA, $addrA, $newKey, $rzp, $clock): void {
    $key = $newKey();
    $r = $start($cookieA, $key, $addrA);
    $rzp->pay($r->decoded()['razorpay']['orderId']); // paid at Razorpay, verify not called yet
    $r = $start($cookieA, $key, $addrA);
    $t->same([409, 'payment-received'], [$r->status(), $r->decoded()['code'] ?? null]);
});

$t->test('Razorpay down when starting: 503 provider-unavailable, retrySafe, and the order is saved without a Razorpay order; a retry with the same key makes it', function () use ($t, $start, $cookieA, $addrA, $newKey, $rzp, $count): void {
    $key = $newKey();
    $rzp->failCreate = true;
    $r = $start($cookieA, $key, $addrA);
    $t->same([503, 'provider-unavailable', true], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['retrySafe'] ?? null]);
    $t->same(1, $count('SELECT COUNT(*) FROM shop_orders WHERE idempotency_key = ? AND razorpay_order_id IS NULL', [$key]));
    $rzp->failCreate = false;
    $r = $start($cookieA, $key, $addrA);
    $t->same(200, $r->status());
    $t->same(1, $count('SELECT COUNT(*) FROM shop_orders WHERE idempotency_key = ?', [$key]));
});

$t->test('Razorpay down when verifying: 503 provider-unavailable, retrySafe, nothing paid', function () use ($t, $started, $verify, $cookieA, $addrA, $rzp, $count): void {
    [$orderNo, $rid] = $started($cookieA, $addrA);
    $pid = $rzp->pay($rid);
    $rzp->failFetchPayment = true;
    $r = $verify($cookieA, $rid, $pid);
    $t->same([503, 'provider-unavailable', true], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['retrySafe'] ?? null]);
    $rzp->failFetchPayment = false;
    $t->same(1, $count("SELECT COUNT(*) FROM shop_orders WHERE order_no = ? AND status = 'PAYMENT_PENDING'", [$orderNo]));
    $t->same('placed', $verify($cookieA, $rid, $pid)->decoded()['outcome'], 'the same three values work when Razorpay is back');
});

$t->test('sold out after payment: the ERP refuses for stock, the order is paid-unfulfilled (refund by hand, logged), never failed, and no ERP words reach the customer', function () use ($t, $started, $verify, $cookieA, $addrA, $rzp, $erp, $row, &$logged): void {
    [$orderNo, $rid] = $started($cookieA, $addrA);
    $erp->flag('order_oos');
    $r = $verify($cookieA, $rid, $rzp->pay($rid));
    $erp->flag('order_oos', false);
    $t->same([200, 'not-fulfilled', 'paid-unfulfilled', true], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status'], $r->decoded()['order']['paid']]);
    $t->ok(!str_contains($r->body(), 'insufficient') && !str_contains($r->body(), 'failure'), 'no ERP words or failure code in the body');
    $stored = $row('SELECT status, failure_code, failed_skus FROM shop_orders WHERE order_no = ?', [$orderNo]);
    $t->same(['PAID_NOT_FULFILLED', 'out-of-stock', '["FAKE-1"]'], [$stored['status'], $stored['failure_code'], $stored['failed_skus']]);
    $t->ok(count(array_filter($logged, static fn (string $l): bool => str_contains($l, 'payment.refund_needed') && str_contains($l, $orderNo))) === 1, 'payment.refund_needed logged once');
    $erp->flag('order_unknown');
    [$orderNo2, $rid2] = $started($cookieA, $addrA);
    $r = $verify($cookieA, $rid2, $rzp->pay($rid2));
    $erp->flag('order_unknown', false);
    $t->same('not-fulfilled', $r->decoded()['outcome'], 'unknown SKU is final too');
});

$t->test('ERP timeout after payment: paid-confirming (never failed, never sold out); verifying again later finds the ERP order and places it, once', function () use ($t, $started, $verify, $cookieA, $addrA, $rzp, $erp, $row, $erpOrderCalls): void {
    [$orderNo, $rid] = $started($cookieA, $addrA);
    $pid = $rzp->pay($rid);
    $erp->flag('order_slow');
    $r = $verify($cookieA, $rid, $pid);
    $erp->flag('order_slow', false);
    $t->same([200, 'confirming', 'paid-confirming', true], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status'], $r->decoded()['order']['paid']]);
    $t->ok(!stripos($r->body(), 'sold') && !stripos($r->body(), 'available'), 'not worded as sold out');
    $t->same('PAID_PLACING', $row('SELECT status FROM shop_orders WHERE order_no = ?', [$orderNo])['status']);
    sleep(2); // the fake ERP is still finishing the slow request
    $r = $verify($cookieA, $rid, $pid);
    $t->same([200, 'placed'], [$r->status(), $r->decoded()['outcome']]);
    $sent = array_filter($GLOBALS['erp']->requests(), static fn (array $q): bool => $q['path'] === '/api/order_create.php' && str_contains($q['body'], $orderNo));
    $t->same(2, count($sent), 'the same order number was sent twice; the ERP answered the second with the existing order');
});

$t->test('ERP down or misconfigured after payment: stays paid-confirming and is logged; only stock and SKU refusals are final', function () use ($t, $started, $verify, $cookieA, $addrA, $rzp, $erp, $row, &$logged): void {
    foreach (['order_down' => 'down', 'order_config' => 'config'] as $flag => $label) {
        [$orderNo, $rid] = $started($cookieA, $addrA);
        $erp->flag($flag);
        $r = $verify($cookieA, $rid, $rzp->pay($rid));
        $erp->flag($flag, false);
        $t->same([200, 'confirming', 'paid-confirming'], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status']], $label);
        $t->same('PAID_PLACING', $row('SELECT status FROM shop_orders WHERE order_no = ?', [$orderNo])['status'], $label);
    }
    $t->ok(count(array_filter($logged, static fn (string $l): bool => str_contains($l, 'payment.erp_refused_paid_order') && str_contains($l, '"class":"config"'))) >= 1, 'config refusal logged as a config problem');
});

$t->test('ERP key and switch problems after payment: the order stays paid and "confirming", is logged as a configuration error, nothing is created at the ERP and the customer never hears "sold out"; a 422 of any wording is final', function () use ($t, &$app, $makeApp, $settings, $rzp, $started, $verify, $cookieA, $addrA, $erp, $row, &$logged, $erpOrderCalls): void {
    $real = $app;
    $cases = [
        'the catalogue key used for orders (401)' => [FakeErp::KEY, null, 'auth'],
        'no order key at all' => [null, null, 'not-configured'],
        'the ERP switched its order API off (503)' => [FakeErp::ORDER_KEY, 'order_503', 'disabled'],
    ];
    foreach ($cases as $label => [$key, $flag, $kind]) {
        $app = $makeApp($settings, $rzp, $key);
        [$orderNo, $rid] = $started($cookieA, $addrA);
        $calls = $erpOrderCalls();
        if ($flag !== null) {
            $erp->flag($flag);
        }
        $r = $verify($cookieA, $rid, $rzp->pay($rid));
        if ($flag !== null) {
            $erp->flag($flag, false);
        }
        $t->same([200, 'confirming', 'paid-confirming', true], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status'], $r->decoded()['order']['paid']], $label);
        $t->ok(!stripos($r->body(), 'sold') && !stripos($r->body(), 'available'), $label . ': not worded as sold out');
        $t->same('PAID_PLACING', $row('SELECT status FROM shop_orders WHERE order_no = ?', [$orderNo])['status'], $label);
        $t->ok(count(array_filter($logged, static fn (string $l): bool => str_contains($l, 'payment.erp_config_error') && str_contains($l, $orderNo) && str_contains($l, '"kind":"' . $kind . '"'))) === 1, $label . ': logged as a configuration error');
        if ($key === null) {
            $t->same($calls, $erpOrderCalls(), 'no key: nothing was sent');
        }
        // the key is fixed (or the switch is back on): the next verify finishes the order
        $app = $makeApp($settings, $rzp);
        $again = $verify($cookieA, $rid, array_key_last($rzp->payments));
        $t->same([200, 'placed'], [$again->status(), $again->decoded()['outcome']], $label . ': placed once the key works');
    }
    $app = $makeApp($settings, $rzp);
    [$orderNo, $rid] = $started($cookieA, $addrA);
    $erp->flag('order_422');
    $r = $verify($cookieA, $rid, $rzp->pay($rid));
    $erp->flag('order_422', false);
    $t->same([200, 'not-fulfilled', 'paid-unfulfilled'], [$r->status(), $r->decoded()['outcome'], $r->decoded()['order']['status']], '422 whatever the wording: final');
    $t->same('unprocessable', $row('SELECT failure_code FROM shop_orders WHERE order_no = ?', [$orderNo])['failure_code']);
    $app = $real;
});

$t->test('a live key is refused outside production, and in production without RAZORPAY_ALLOW_LIVE; test keys are fine', function () use ($t): void {
    $prod = ['APP_ENV' => 'production', 'DB_NAME' => 'x', 'DB_USER' => 'x', 'RAZORPAY_KEY_ID' => 'rzp_live_AbCdEf123456', 'RAZORPAY_KEY_SECRET' => 'x'];
    $problem = static function (array $values): string {
        try {
            new \Pratyangi\Config($values);
        } catch (ConfigException $e) {
            return $e->getMessage();
        }
        return '';
    };
    $t->contains('a live key (rzp_live_) is refused', $problem($prod), 'production without RAZORPAY_ALLOW_LIVE');
    $t->ok(!str_contains($problem($prod + ['RAZORPAY_ALLOW_LIVE' => 'true']), 'a live key (rzp_live_) is refused'), 'production with RAZORPAY_ALLOW_LIVE=true accepts the key (other settings may still be missing)');
    $live = ['RAZORPAY_KEY_ID' => 'rzp_live_AbCdEf123456', 'RAZORPAY_KEY_SECRET' => 'x', 'PAYMENTS_ENABLED' => 'true'];
    $e = $t->throws(ConfigException::class, static fn () => Helpers::config($live));
    $t->contains('RAZORPAY_KEY_ID', $e->getMessage());
    $t->contains('rzp_live_', $e->getMessage());
    $t->ok(!str_contains($e->getMessage(), 'AbCdEf123456'), 'the key is not echoed');
    $t->throws(ConfigException::class, static fn () => Helpers::config($live + ['RAZORPAY_ALLOW_LIVE' => 'true']), 'ALLOW_LIVE alone does not help outside production');
    $t->throws(ConfigException::class, static fn () => Helpers::config(['RAZORPAY_KEY_ID' => 'nonsense']));
    $t->throws(ConfigException::class, static fn () => Helpers::config(['PAYMENTS_ENABLED' => 'true']), 'payments on without keys');
    $t->same('test', Helpers::config(['RAZORPAY_KEY_ID' => 'rzp_test_AbCdEf123456', 'RAZORPAY_KEY_SECRET' => 'x'])->razorpayMode());
});

$t->test('first-order contact rule: SMS_ENABLED=true needs a verified mobile, SMS_ENABLED=false needs a verified email', function () use ($t, $app, $makeApp, $settings, $start, $newKey, $cookieB, $addrB, $cookieD, $addrD, $rzp): void {
    $off = $makeApp(['SMS_ENABLED' => 'false'] + $settings, $rzp);
    // B: verified email, mobile never verified. D: verified mobile, email never verified.
    $r = $start($cookieB, $newKey(), $addrB, null, $off);
    $t->same(201, $r->status(), 'off: B (verified email) may order');
    $r = $start($cookieD, $newKey(), $addrD, null, $off);
    $t->same([403, 'email-required', 'Please verify your email address before placing an order.'], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['message']], 'off: D (no verified email) may not');
    $r = $start($cookieD, $newKey(), $addrD, null, $app);
    $t->same(201, $r->status(), 'on: D (verified mobile) may order');
    $r = $start($cookieB, $newKey(), $addrB, null, $app);
    $t->same([403, 'mobile-required'], [$r->status(), $r->decoded()['code'] ?? null], 'on: B (no verified mobile) may not');
});

$t->test('ORDER_OTP on POST /payments/razorpay/order: cod and off never ask; always and new_number ask until the number has its proof', function () use ($t, $settings, $pdo, $erp, $keyId, $logger, $clock, $start, $newKey, $cookieA, $addrA, $custA, $post): void {
    $gateway = new class implements Pratyangi\Sms\SmsGateway {
        public function sendOtp(string $mobile, string $code): void
        {
        }
    };
    $appFor = static fn (string $mode): App => new App(
        Helpers::config($settings + ['ORDER_OTP' => $mode, 'SMS_ENABLED' => 'true', 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => sys_get_temp_dir() . '/pf_order_otp_unused.log', 'OTP_PEPPER' => str_repeat('P', 48)]),
        $logger, $clock, static fn (): \PDO => $pdo, new NullMailer(),
        new ErpClient($erp->baseUrl(), FakeErp::KEY, null, 'offer-else-retail', 'exclusive', 500, null, $logger, FakeErp::ORDER_KEY),
        new DbRateLimitStore(static fn (): \PDO => $pdo), ['payment_order' => 100000, 'payment_verify' => 100000], null, $gateway, new FakeRazorpay(),
    );
    $pdo->prepare('DELETE FROM shop_verified_numbers WHERE customer_id = ?')->execute([$custA]);
    foreach (['off', 'cod'] as $mode) {
        $r = $start($cookieA, $newKey(), $addrA, null, $appFor($mode));
        $t->same(201, $r->status(), $mode . ' does not ask for a code on an online payment');
    }
    foreach (['always', 'new_number'] as $mode) {
        $r = $start($cookieA, $newKey(), $addrA, null, $appFor($mode));
        $t->same([422, 'otp-required', 'phone'], [$r->status(), $r->decoded()['code'] ?? null, $r->decoded()['field'] ?? null], $mode . ' asks for a code');
    }
    // the delivery number (9876543210) is proven: both modes let the order through
    $pdo->prepare('INSERT INTO shop_verified_numbers (customer_id, mobile, verified_at, otp_passed_at) VALUES (?, ?, ?, ?)')->execute([$custA, '9876543210', gmdate('Y-m-d H:i:s', (int) $clock->now()), gmdate('Y-m-d H:i:s', (int) $clock->now())]);
    foreach (['always', 'new_number'] as $mode) {
        $r = $start($cookieA, $newKey(), $addrA, null, $appFor($mode));
        $t->same(201, $r->status(), $mode . ' lets a proven number through');
    }
});

exit($t->finish());

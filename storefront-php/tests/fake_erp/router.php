<?php
declare(strict_types=1);

// Router script for `php -S 127.0.0.1:<port> tests/fake_erp/router.php`. A fake ERP for tests ONLY (no real data, no real key).
// State lives in the folder named by the environment variable PF_FAKE_ERP_DIR:
//   fail.flag      -> every API call answers 500
//   cursor.flag    -> the first page answers a cursor that does not advance
//   redirect.flag  -> products answer a 302
//   big.flag       -> products answer a body larger than the client's cap
//   requests.log   -> one JSON line per request: method, path, query, bearer ok?, body
const FAKE_KEY = 'fake-erp-key-0123456789abcdef0123456789';
const FAKE_ORDER_KEY = 'fake-erp-order-key-0123456789abcdef01'; // opens api/order_create.php only; the catalogue key does not, and this one does not open the catalogue

$dir = getenv('PF_FAKE_ERP_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = file_get_contents('php://input') ?: '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$authOk = $auth === 'Bearer ' . FAKE_KEY;
$orderAuthOk = $auth === 'Bearer ' . FAKE_ORDER_KEY;
file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'authOk' => $authOk,
    'orderAuthOk' => $orderAuthOk,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
    'body' => $body,
]) . "\n", FILE_APPEND);

$reply = static function (int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};
$row = static fn (string $sku, string $name, array $over = []): array => $over + [
    'sku' => $sku, 'name' => $name, 'category' => 'Sarees', 'fabric' => 'Silk', 'pattern_name' => null, 'design_model' => null,
    'color' => 'Red', 'size' => null, 'mrp' => '2000.00', 'retail_price' => '1500.00', 'offer_price' => '1299.50', 'tax_rate' => '5.00', 'image_path' => null,
];

if ($path === '/api/catalog_products.php' || $path === '/api/catalog_stock.php') {
    if (is_file($dir . '/fail.flag')) {
        $reply(500, ['ok' => false, 'error' => 'boom']);
        return;
    }
    if (!$authOk) {
        $reply(401, ['ok' => false, 'error' => 'bad key']);
        return;
    }
}

if ($path === '/api/catalog_products.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (is_file($dir . '/redirect.flag')) {
        header('Location: http://127.0.0.1:1/elsewhere', true, 302);
        return;
    }
    if (is_file($dir . '/big.flag')) {
        header('Content-Type: application/json');
        echo '{"ok":true,"products":[],"pad":"' . str_repeat('x', 8100000) . '","next_cursor":null}';
        return;
    }
    $after = (int) ($_GET['after_id'] ?? 0);
    if ($after === 0) {
        $reply(200, ['ok' => true, 'products' => [
            $row('FAKE-1', 'First Saree'),
            $row('FAKE-2', 'Second Saree', ['offer_price' => null, 'retail_price' => '999.00', 'wholesale_price' => '1.00']),
            $row('FAKE-BAD', 'Bad price', ['retail_price' => 'ten', 'offer_price' => null]),
        ], 'next_cursor' => is_file($dir . '/cursor.flag') ? '0' : '3']);
        return;
    }
    $reply(200, ['ok' => true, 'products' => [
        $row('FAKE-4', 'Fourth Saree'),
        $row('FAKE-1', 'Duplicate of first'),
    ], 'next_cursor' => null]);
    return;
}

if ($path === '/api/catalog_stock.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $data = json_decode($body, true);
    $skus = is_array($data['skus'] ?? null) ? $data['skus'] : [];
    $stock = [];
    foreach ($skus as $i => $sku) {
        $stock[] = ['sku' => $sku, 'available_pieces' => is_file($dir . '/stock_ok.flag') ? 50 : $i % 3];
    }
    $stock[] = ['sku' => 'NOT-ASKED', 'available_pieces' => 99];
    $reply(200, ['ok' => true, 'stock' => $stock]);
    return;
}

if ($path === '/api/order_create.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // order_503.flag -> 503 (the ERP says its order API is switched off), order_422.flag -> 422 with wording we do not know (still final), order_oos.flag -> 422 stock refusal, order_unknown.flag -> 422 unknown sku, order_config.flag -> 422 fulfilment setup,
    // order_down.flag -> 500, order_slow.flag -> the order IS saved but the answer comes after 2 s (the client times out first: answer lost),
    // stock_ok.flag -> the stock call answers 50 for every SKU (so the early stock check passes)
    if (!$orderAuthOk) {
        $reply(401, ['ok' => false, 'error' => 'bad key']);
        return;
    }
    if (is_file($dir . '/order_503.flag')) {
        $reply(503, ['ok' => false, 'error' => 'order API is switched off']);
        return;
    }
    if (is_file($dir . '/order_down.flag')) {
        $reply(500, ['ok' => false, 'error' => 'boom']);
        return;
    }
    $data = json_decode($body, true) ?: [];
    $store = $dir . '/orders.json';
    $known = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
    $no = (string) ($data['order_no'] ?? '');
    // the ERP team: ALWAYS both ids, and payment_status PAID or COD
    if ($no === '' || (string) ($data['external_order_id'] ?? '') === '' || !in_array($data['payment_status'] ?? null, ['PAID', 'COD'], true)) {
        $reply(400, ['ok' => false, 'error' => 'order_no, external_order_id and a payment_status of PAID or COD are required']);
        return;
    }
    if (is_file($dir . '/order_422.flag')) {
        $reply(422, ['ok' => false, 'error' => 'cannot process this order']);
        return;
    }
    if (is_file($dir . '/order_oos.flag')) {
        $reply(422, ['ok' => false, 'error' => 'insufficient sellable stock for FAKE-1 · need 1']);
        return;
    }
    if (is_file($dir . '/order_unknown.flag')) {
        $reply(422, ['ok' => false, 'error' => 'unknown sku: FAKE-1']);
        return;
    }
    if (is_file($dir . '/order_config.flag')) {
        $reply(422, ['ok' => false, 'error' => 'multiple fulfilment warehouses']);
        return;
    }
    if (isset($known[$no])) {
        $reply(200, ['ok' => true, 'duplicate' => true, 'order' => ['id' => $known[$no], 'order_no' => $no, 'status' => 'ALLOCATED']]);
        return;
    }
    $known[$no] = count($known) + 100;
    file_put_contents($store, json_encode($known));
    if (is_file($dir . '/order_slow.flag')) {
        sleep(2);
    }
    $reply(201, ['ok' => true, 'order_id' => $known[$no], 'order_no' => $no, 'status' => 'ALLOCATED']);
    return;
}

$reply(404, ['ok' => false, 'error' => 'no such endpoint']);

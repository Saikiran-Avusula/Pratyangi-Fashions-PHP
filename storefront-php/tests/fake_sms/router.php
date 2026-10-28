<?php
declare(strict_types=1);

// Router script for `php -S 127.0.0.1:<port> tests/fake_sms/router.php`. A fake SMS provider for tests ONLY (no real key, no real number).
// State lives in the folder named by the environment variable PF_FAKE_SMS_DIR:
//   fail.flag      -> every call answers 500
//   error.flag     -> every call answers 200 with {"Error":"..."} (the provider refuses the message)
//   empty.flag     -> every call answers 200 with an empty body
//   redirect.flag  -> every call answers a 302
//   requests.log   -> one JSON line per request: method, path, the query as a list of name => value
const FAKE_KEY = 'fake-sms-key-0123456789abcdef0123456789';

$dir = getenv('PF_FAKE_SMS_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_GET,
    'raw' => $_SERVER['QUERY_STRING'] ?? '',
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($path !== '/v3/api.php') {
    http_response_code(404);
    echo '{"Error":"no such endpoint"}';
    return;
}
if (is_file($dir . '/fail.flag')) {
    http_response_code(500);
    echo '{"Error":"boom"}';
    return;
}
if (is_file($dir . '/redirect.flag')) {
    header('Location: http://127.0.0.1:1/elsewhere', true, 302);
    return;
}
if (is_file($dir . '/empty.flag')) {
    return;
}
if (is_file($dir . '/error.flag') || ($_GET['apikey'] ?? '') !== FAKE_KEY) {
    echo "{'Error':'Invalid API key'}";
    return;
}
echo json_encode(['Reports' => [['status' => 'queued', 'campid' => 'TEST1']]]);

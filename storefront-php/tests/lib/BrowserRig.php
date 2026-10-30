<?php
declare(strict_types=1);

// Shared by tests/browser_console_test.php and tests/browser_layout_test.php (both need MySQL, see DbGuard, and Google Chrome).
// serve() runs the real App on the PHP built-in server (test database, mock ERP, log mailer); dump() loads a URL in headless Chrome.
final class BrowserRig
{
    public static function chrome(): ?string
    {
        foreach (['C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Writes the router into $tmp. Env it reads: PF_CC_SMS (true|false), PF_CC_COOKIE (a session token), PF_CC_ALL (1 = send the session on every page, else only /account). */
    public static function router(string $tmp): string
    {
        $root = dirname(__DIR__, 2);
        file_put_contents($tmp . '/router.php', '<?php
require ' . var_export($root . '/tests/lib/TestCase.php', true) . '; require ' . var_export($root . '/tests/lib/Helpers.php', true) . '; require ' . var_export($root . '/tests/lib/DbGuard.php', true) . ';
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/__w") { header("Content-Type: text/html"); readfile(' . var_export($tmp . '/wrap.html', true) . '); return; }
if ($path === "/__p") { file_put_contents(' . var_export($tmp . '/progress.txt', true) . ', str_replace(["\r", "\n"], " ", (string) file_get_contents("php://input")) . "\n", FILE_APPEND); header("Content-Type: text/plain"); echo "ok"; return; } // progress events of tests/browser_layout_test.php
$file = ' . var_export($root . '/public', true) . ' . $path;
if ($path !== "/" && is_file($file)) {
    $types = ["css" => "text/css", "js" => "text/javascript", "svg" => "image/svg+xml", "png" => "image/png", "jpg" => "image/jpeg", "webp" => "image/webp", "woff2" => "font/woff2"];
    header("Content-Type: " . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? "application/octet-stream"));
    readfile($file);
    return;
}
ob_start(); // DbGuard prints a banner; it must not land in the page
$pdo = DbGuard::connect();
ob_end_clean();
$config = Helpers::config(["DEMO_UI" => getenv("PF_TEST_DEMO_UI") ?: "false", "SMS_ENABLED" => getenv("PF_CC_SMS") ?: "true", "SMS_MODE" => "log", "SMS_LOG_PATH" => ' . var_export($tmp . '/sms.log', true) . ', "MAIL_LOG_PATH" => ' . var_export($tmp . '/mail.log', true) . ', "OTP_PEPPER" => str_repeat("S", 48),
    // the real company details, so the footer and /contact are drawn in full (an apostrophe, comma, # and & in the Grievance Officer prove the escaping)
    "SHOP_LEGAL_NAME" => "Vistaraverse E-Cart Private Limited", "SHOP_ADDRESS" => "#102, Sahiti Ramkuteer, Jai Hind Gandhi Road, VIP Hills, Silicon Valley, Madhapur, Hyderabad, Telangana 500081", "SHOP_SE_REG" => "SEA/RAN/ALO/BN/1425883/2026", "SHOP_CIN" => "U46901AP2026PTC126193",
    "SHOP_GSTIN" => "36AAMCV3017H1Z9", "SHOP_PHONE" => "+91 99892 33328", "SHOP_WHATSAPP" => "919989233328", "SHOP_SUPPORT_EMAIL" => "info@pratyangi.com",
    "SHOP_GRIEVANCE_NAME" => "Asha O\x27Brien, #5 & Co", "SHOP_GRIEVANCE_EMAIL" => "grievance@pratyangi.com", "SHOP_GRIEVANCE_PHONE" => "+91 90000 11111", "SHOP_HOURS" => "9:00 AM - 5:00 PM, Monday to Saturday"]);
$app = Helpers::app($config, null, new Pratyangi\Http\DbRateLimitStore(static fn (): PDO => $pdo), null, new Pratyangi\Mail\LogMailer(' . var_export($tmp . '/mail.log', true) . ', new FixedClock((float) time())), static fn (): PDO => $pdo);
if (preg_match("#^/__e/(\d+)$#", $path, $m)) { $r = Pratyangi\Web\ErrorPages::response((int) $m[1]); http_response_code($r->status()); header("Content-Type: " . $r->header("Content-Type")); echo $r->body(); return; }
$headers = [];
if (getenv("PF_CC_COOKIE") && ($path === "/account" || getenv("PF_CC_ALL") === "1")) { $headers["Cookie"] = "pf_session=" . getenv("PF_CC_COOKIE"); }
if (getenv("PF_CC_FWD") === "1") { foreach (["Origin" => "HTTP_ORIGIN", "Cookie" => "HTTP_COOKIE"] as $h => $k) { if (isset($_SERVER[$k])) { $headers[$h] = $_SERVER[$k]; } } }
$body = getenv("PF_CC_FWD") === "1" && $_SERVER["REQUEST_METHOD"] === "POST" ? json_decode((string) file_get_contents("php://input"), true) : null;
$r = $app->handle(Helpers::request($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"], $headers, is_array($body) ? $body : null));
foreach ($r->cookies() as $line) { header("Set-Cookie: " . $line, false); }
http_response_code($r->status());
header("Content-Type: " . $r->header("Content-Type"));
echo $r->body();
');
        return $tmp . '/router.php';
    }

    /** @param array<string,string> $env @return resource */
    public static function serve(string $router, int $port, array $env)
    {
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [['null'], ['null'], ['null']], $pipes, null, ['PF_TEST_APPLY_SCHEMA' => '0', 'PF_TEST_APPLY_OTP' => '0'] + $env + getenv());
        usleep(1500000);
        return $server;
    }

    /** @param resource $server */
    public static function stop($server): void
    {
        proc_terminate($server);
        proc_close($server);
    }

    /** Chrome's stderr + stdout for one page: console messages (all levels) appear as "CONSOLE" lines, --dump-dom prints the final DOM. */
    public static function dump(string $chrome, string $profile, string $url, int $width = 1280, int $height = 900, int $budgetMs = 5000): string
    {
        $err = $profile . '.err';
        $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--enable-logging=stderr', '--v=0', '--virtual-time-budget=' . $budgetMs, '--window-size=' . $width . ',' . $height,
            '--user-data-dir=' . $profile, '--dump-dom', $url], [['null'], ['pipe', 'w'], ['file', $err, 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($p);
        return $out . (string) @file_get_contents($err);
    }
}

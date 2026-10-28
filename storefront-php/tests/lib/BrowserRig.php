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
$config = Helpers::config(["SMS_ENABLED" => getenv("PF_CC_SMS") ?: "true", "SMS_MODE" => "log", "SMS_LOG_PATH" => ' . var_export($tmp . '/sms.log', true) . ', "MAIL_LOG_PATH" => ' . var_export($tmp . '/mail.log', true) . ', "OTP_PEPPER" => str_repeat("S", 48)]);
$app = Helpers::app($config, null, new Pratyangi\Http\DbRateLimitStore(static fn (): PDO => $pdo), null, new Pratyangi\Mail\LogMailer(' . var_export($tmp . '/mail.log', true) . ', new FixedClock((float) time())), static fn (): PDO => $pdo);
$headers = [];
if (getenv("PF_CC_COOKIE") && ($path === "/account" || getenv("PF_CC_ALL") === "1")) { $headers["Cookie"] = "pf_session=" . getenv("PF_CC_COOKIE"); }
$r = $app->handle(Helpers::request($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"], $headers));
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

<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_console_test
// Loads /login /signup /forgot-password /account in headless Chrome against the real App (test database, mock ERP, log mailer) for
// SMS_ENABLED true and false and fails on any console message or uncaught exception.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_console_test: Chrome not found\n";
    exit(0);
}

$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('browser_console_test');
$tmp = sys_get_temp_dir() . '/pf_console_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);

$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Console Check', 'email' => 'console' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';
$t->same(201, $signup->status(), 'test account for /account');

$port = 8190;
foreach ([true, false] as $sms) {
    $t->test('console is clean with SMS_ENABLED=' . ($sms ? 'true' : 'false'), function () use ($t, $sms, $port, $tmp, $chrome, $cookie, $router): void {
        $server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => $sms ? 'true' : 'false', 'PF_CC_COOKIE' => $cookie]);
        try {
            foreach (['/login', '/signup', '/forgot-password', '/account'] as $path) {
                $out = BrowserRig::dump($chrome, $tmp . '/profile', 'http://127.0.0.1:' . $port . $path);
                $errors = array_values(array_filter(explode("\n", $out), static fn (string $l): bool => preg_match('/:CONSOLE[:(]/', $l) === 1));
                $t->same([], $errors, $path . ' sms=' . ($sms ? 'on' : 'off'));
            }
        } finally {
            BrowserRig::stop($server);
        }
    });
    $port++;
}
exit($t->finish());

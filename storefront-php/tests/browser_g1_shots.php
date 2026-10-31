<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_g1_shots
// Not a pass/fail test: takes screenshots of the home header and /account at 390 and 1440 wide into .claude/snapshots/g1/, for a signed-in account
// with a Google picture and one without. Google's own host cannot be reached from the test, so the router swaps the picture address for a local
// image in the page it serves (the markup, size, round shape and fallback are the real ones).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_g1_shots: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$out = dirname(__DIR__) . '/.claude/snapshots/g1';
@mkdir($out, 0777, true);
$tmp = Tmp::dir('pf_g1_');
$router = BrowserRig::router($tmp);
file_put_contents($router, str_replace('echo $r->body();', 'echo str_replace("https://lh3.googleusercontent.com/a/demo", "/assets/images/figma/lotus-emblem.svg", $r->body());', (string) file_get_contents($router)));

$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$cookies = [];
foreach (['google' => 'Gita Rao', 'initial' => 'Asha Menon'] as $kind => $name) {
    $r = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => $name, 'email' => $kind . '.shot@example.com', 'password' => 'Saree2026']));
    $cookies[$kind] = preg_match('/^pf_session=([^;]*);/', $r->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';
}
$pdo->prepare("UPDATE shop_customers SET picture_url = 'https://lh3.googleusercontent.com/a/demo' WHERE email = ?")->execute(['google.shot@example.com']);

$port = 8191;
foreach ($cookies as $kind => $cookie) {
    $server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => 'false', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => '1']);
    foreach ([['/', 'header'], ['/account', 'account']] as [$path, $label]) {
        foreach ([390 => 844, 1440 => 900] as $w => $h) {
            $file = $out . '/' . $kind . '-' . $label . '-' . $w . '.png';
            $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--virtual-time-budget=5000', '--window-size=' . $w . ',' . $h, '--user-data-dir=' . Tmp::profile(),
                '--screenshot=' . $file, 'http://127.0.0.1:' . $port . $path], [['null'], ['null'], ['null']], $pipes);
            Tmp::track($p);
            proc_close($p);
            echo (is_file($file) ? 'wrote ' : 'MISSING ') . basename($file) . "\n";
        }
    }
    BrowserRig::stop($server);
}
exit(0);

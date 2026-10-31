<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway test database, see tests/lib/DbGuard.php) and Chrome. Run: tools\run_db.ps1 -Only ..\tools\h1_shots   (env PF_SHOTS_DIR = folder name under .claude/snapshots/h1, default "after")
// Screenshots at 390 and 1440 px of the header (signed out and in), /account, the address dialog, /signup, the cookie bar and the WhatsApp button.
// The page is shown in an iframe of the exact width (headless Chrome will not make a window narrower than ~500 px).
require dirname(__DIR__) . '/tests/lib/TestCase.php';
require dirname(__DIR__) . '/tests/lib/Helpers.php';
require dirname(__DIR__) . '/tests/lib/DbGuard.php';
require dirname(__DIR__) . '/tests/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome() ?? exit("Chrome not found\n");
$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$out = dirname(__DIR__) . '/.claude/snapshots/h1/' . (getenv('PF_SHOTS_DIR') ?: 'after');
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit("cannot create the output folder\n");
}
$tmp = Tmp::dir('pf_h1_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'shots' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

// [file name, signed in, path, JS run in the page first, height]
$shots = [['header-out', false, '/', '', 420], ['header-in', true, '/', '', 420], ['account', true, '/account', '', 1500], ['address-dialog', true, '/account', 'q("[data-pf-open-address]").click()', 1300],
    ['signup', false, '/signup', '', 1500], ['cookie-bar', false, '/', '', 800], ['whatsapp', false, '/', '', 800]];
$port = 8432;
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, $port, ['PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ($shots as [$name, $needsIn, $path, $js, $height]) {
        if ($needsIn !== $signedIn) {
            continue;
        }
        foreach ([390, 1440] as $width) {
            file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0;background:#888"><iframe id="f" src="' . $path . '" width="' . $width . '" height="' . $height . '" style="border:0;display:block"></iframe><script>'
                . 'const q=s=>document.getElementById("f").contentDocument.querySelector(s);document.getElementById("f").onload=()=>setTimeout(()=>{' . $js . '},800);</script>');
            $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' . Tmp::profile(), '--virtual-time-budget=6000', '--window-size=' . max($width, 500) . ',' . $height,
                '--screenshot=' . "{$out}/{$name}-{$width}.png", 'http://127.0.0.1:' . $port . '/__w'], [['null'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            Tmp::track($p);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            Tmp::kill($p);
            echo "{$name}-{$width}.png\n";
        }
    }
    BrowserRig::stop($server);
}

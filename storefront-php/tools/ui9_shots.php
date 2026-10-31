<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway test database, see tests/lib/DbGuard.php) and Chrome. Run: tools\run_db.ps1 -Only ..\tools\ui9_shots   (env PF_SHOTS_ONLY = comma list of shot names to redo)
// UI9 screenshots at 360, 390 and 1440 px, signed out and signed in, into .claude/snapshots/ui9/. The page sits in an iframe of the exact width.
require dirname(__DIR__) . '/tests/lib/TestCase.php';
require dirname(__DIR__) . '/tests/lib/Helpers.php';
require dirname(__DIR__) . '/tests/lib/DbGuard.php';
require dirname(__DIR__) . '/tests/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome() ?? exit("Chrome not found\n");
$pdo = DbGuard::connect();
$out = dirname(__DIR__) . '/.claude/snapshots/ui9';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit("cannot create the output folder\n");
}
$only = array_filter(explode(',', (string) getenv('PF_SHOTS_ONLY')));
$tmp = Tmp::dir('pf_ui9s_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui9s' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

$phones = [360, 390];
$all = [360, 390, 1440];
// [name, signed in (null = both), path, widths, JS run in the page after load (q = querySelector of the page)]
$shots = [
    ['menu-open', null, '/new-arrivals', $all, 'q("[data-pf=shop-toggle]").click()'],
    ['listing-chrome', null, '/new-arrivals', $phones, 'q("[data-pf=grid]").scrollIntoView()'],
    ['card-row', null, '/new-arrivals', $all, 'q("[data-pf=grid]").scrollIntoView({block:"start"})'],
    ['tray-home', null, '/', $phones, ''], ['tray-wishlist', null, '/wishlist', $phones, ''], ['tray-bag', null, '/bag', $phones, ''],
    ['tray-account', true, '/account', $phones, ''], ['tray-login', false, '/login', $phones, ''],
    ['acct-overview', true, '/account', [1440], ''], ['acct-orders', true, '/account/orders', [1440], ''], ['acct-wishlist', true, '/wishlist', [1440], ''],
    ['acct-addresses', true, '/account/addresses', [1440], ''], ['acct-payments', true, '/account/payments', [1440], ''],
    ['acct-notifications', true, '/account/notifications', [1440], ''], ['acct-help', true, '/account/help', [1440], ''],
    ['account-card', true, '/account', $phones, ''],
    ['help-faq-open', true, '/account/help', $all, 'const s=q("details[name=pf-faq] summary");s.click();s.scrollIntoView({block:"center"})'],
];
$port = 8434;
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, $port, ['PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ($shots as [$name, $needsIn, $path, $widths, $js]) {
        if (($needsIn !== null && $needsIn !== $signedIn) || ($only && !in_array($name, $only, true))) {
            continue;
        }
        foreach ($widths as $width) {
            $height = $width >= 768 ? 900 : 780;
            file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0;background:#888"><style>html{overflow:hidden}</style><iframe id="f" src="' . $path . '" width="' . $width . '" height="' . $height . '" style="border:0;display:block"></iframe><script>'
                . 'const q=s=>document.getElementById("f").contentDocument.querySelector(s);document.getElementById("f").onload=()=>setTimeout(()=>{const a=q("[data-pf=cookie-accept]");if(a)a.click();' . $js . '},1200);</script>');
            $file = $out . '/' . $name . '-' . ($signedIn ? 'in' : 'out') . '-' . $width . '.png';
            $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' . Tmp::profile(), '--virtual-time-budget=8000', '--window-size=' . max($width + 20, 500) . ',' . ($height + 20),
                '--screenshot=' . $file, 'http://127.0.0.1:' . $port . '/__w'], [['null'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            Tmp::track($p);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            Tmp::kill($p);
            echo basename($file) . "\n";
        }
    }
    BrowserRig::stop($server);
}

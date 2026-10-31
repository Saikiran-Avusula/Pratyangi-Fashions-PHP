<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway test database, see tests/lib/DbGuard.php) and Chrome. Run: tools\run_db.ps1 -Only ..\tools\ui9b_shots   (env PF_SHOTS_ONLY = comma list of shot names to redo)
// UI9b (Sort/Filter pill) screenshots at 360 and 390 px, signed out and signed in, into .claude/snapshots/ui9b/. The page sits in an iframe of the exact width.
require dirname(__DIR__) . '/tests/lib/TestCase.php';
require dirname(__DIR__) . '/tests/lib/Helpers.php';
require dirname(__DIR__) . '/tests/lib/DbGuard.php';
require dirname(__DIR__) . '/tests/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome() ?? exit("Chrome not found\n");
$pdo = DbGuard::connect();
$out = dirname(__DIR__) . '/.claude/snapshots/ui9b';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit("cannot create the output folder\n");
}
$only = array_filter(explode(',', (string) getenv('PF_SHOTS_ONLY')));
$tmp = Tmp::dir('pf_ui9bs_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui9s' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

$phones = [360, 390];
$all = [360, 390, 1440];
// [name, signed in (null = both), path, widths, JS run in the page after load (q = querySelector of the page)]
$shots = [
    ['top', false, '/new-arrivals', $phones, ''],
    ['mid-scroll', false, '/new-arrivals', $phones, 'const sc=(y)=>{const w=document.getElementById("f").contentWindow;w.scrollTo(0,y);w.dispatchEvent(new Event("scroll"))}; sc(700)'],
    ['scroll-up', false, '/new-arrivals', $phones, 'const sc=(y)=>{const w=document.getElementById("f").contentWindow;w.scrollTo(0,y);w.dispatchEvent(new Event("scroll"))}; sc(700);setTimeout(()=>sc(500),300)'],
    ['bottom-footer', false, '/new-arrivals', $phones, 'const sc=(y)=>{const w=document.getElementById("f").contentWindow;w.scrollTo(0,y);w.dispatchEvent(new Event("scroll"))}; sc(99999)'],
    ['filter-active', false, '/new-arrivals', $phones, 'q(".pf_qchip").click()'],
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
            $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--blink-settings=primaryHoverType=1,availableHoverTypes=1', '--user-data-dir=' . Tmp::profile(), '--virtual-time-budget=8000', '--window-size=' . max($width + 20, 500) . ',' . ($height + 20),
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

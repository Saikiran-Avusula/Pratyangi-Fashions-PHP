<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway test database, see tests/lib/DbGuard.php) and Chrome. Run: tools\run_db.ps1 -Only ..\tools\ui8_shots   (env PF_SHOTS_ONLY = comma list of shot names to redo)
// UI8 screenshots at 320, 360, 390, 425, 768 and 1440 px, signed out and signed in, into .claude/snapshots/ui8/. The page sits in an iframe of the exact width.
require dirname(__DIR__) . '/tests/lib/TestCase.php';
require dirname(__DIR__) . '/tests/lib/Helpers.php';
require dirname(__DIR__) . '/tests/lib/DbGuard.php';
require dirname(__DIR__) . '/tests/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome() ?? exit("Chrome not found\n");
$pdo = DbGuard::connect();
$out = dirname(__DIR__) . '/.claude/snapshots/ui8';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit("cannot create the output folder\n");
}
$only = array_filter(explode(',', (string) getenv('PF_SHOTS_ONLY')));
$tmp = Tmp::dir('pf_ui8s_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui8s' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

$wish = 'localStorage.setItem("pratyangi-demo-wishlist", JSON.stringify(["MOCK-SAR-001","MOCK-SAR-002","MOCK-KUR-001"]));';
// [name, signed in (null = both), path, JS run in the page after load (q = querySelector of the page), JS run in the wrapper before the page loads]
$shots = [
    ['header', null, '/', 'q(".pf_tray").style.display="none";q(".pf_wa").style.display="none"', ''], ['tray', null, '/', '', ''], ['login', false, '/login', '', ''], ['empty-bag', null, '/bag', '', ''], ['empty-wishlist', null, '/wishlist', '', ''],
    ['empty-orders', true, '/account/orders', '', ''], ['wishlist-items', null, '/wishlist', '', $wish], ['search-open', null, '/', 'q("[data-pf=search-open]").click();const i=q("#catalog-search");i.value="silk";i.dispatchEvent(new Event("input",{bubbles:true}))', ''],
    ['reset-login', false, '/login?reset=1&email=asha%40example.com', '', ''],
];
$port = 8433;
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, $port, ['PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ($shots as [$name, $needsIn, $path, $js, $pre]) {
        if (($needsIn !== null && $needsIn !== $signedIn) || ($only && !in_array($name, $only, true))) {
            continue;
        }
        foreach ([320, 360, 390, 425, 768, 1440] as $width) {
            $height = in_array($name, ['header'], true) ? 112 : ($width >= 768 ? 900 : 780);
            file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0;background:#888"><style>html{overflow:hidden}</style><script>' . $pre . '</script><iframe id="f" src="' . $path . '" width="' . $width . '" height="' . $height . '" style="border:0;display:block"></iframe><script>'
                . 'const q=s=>document.getElementById("f").contentDocument.querySelector(s);document.getElementById("f").onload=()=>setTimeout(()=>{const a=q("[data-pf=cookie-accept]");if(a)a.click();' . $js . '},1200);</script>');
            $file = $out . '/' . $name . '-' . ($signedIn ? 'in' : 'out') . '-' . $width . '.png';
            $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--user-data-dir=' . Tmp::profile(), '--virtual-time-budget=8000', '--window-size=' . max($width + 20, 500) . ',' . ($height + 20),
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

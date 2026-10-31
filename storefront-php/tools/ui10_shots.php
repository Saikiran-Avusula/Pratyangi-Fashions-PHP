<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway test database, see tests/lib/DbGuard.php) and Chrome. Run: tools\run_db.ps1 -Only ..\tools\ui10_shots   (env PF_SHOTS_ONLY = comma list of shot names to redo)
// UI10 screenshots at 390 and 1440 px, signed out, into .claude/snapshots/ui10/: wishlist item, bag item with a size, share sheet, size sheet / popover. The page sits in an iframe of the exact width.
require dirname(__DIR__) . '/tests/lib/TestCase.php';
require dirname(__DIR__) . '/tests/lib/Helpers.php';
require dirname(__DIR__) . '/tests/lib/DbGuard.php';
require dirname(__DIR__) . '/tests/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome() ?? exit("Chrome not found\n");
$pdo = DbGuard::connect();
$out = dirname(__DIR__) . '/.claude/snapshots/ui10';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit("cannot create the output folder\n");
}
$only = array_filter(explode(',', (string) getenv('PF_SHOTS_ONLY')));
$tmp = Tmp::dir('pf_ui10s_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui9s' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

$phones = [360, 390];
$all = [360, 390, 1440];
// [name, signed in (null = both), path, widths, JS run in the page after load (q = querySelector of the page)]
$seed = static fn (int $n): string => 'const x=new XMLHttpRequest();x.open("GET","/api/v1/catalog/products",false);x.send();const p=JSON.parse(x.responseText);localStorage.setItem("pratyangi-demo-bag",JSON.stringify((p.products||p).filter(i=>i.inStock).slice(0,' . $n . ').map(i=>({id:i.sku,quantity:1}))));';
$w = [390, 1440];
// SKUs of the rig's mock catalogue (tests/fixtures/mock_erp.json): MOCK-KUR-001 is size M (picker), MOCK-SAR-001 a saree (no picker)
$bag = 'localStorage.setItem("pratyangi-demo-bag",JSON.stringify([{id:"MOCK-KUR-001~M",quantity:1},{id:"MOCK-SAR-001",quantity:1}]));';
$wish = 'localStorage.setItem("pratyangi-demo-wishlist",JSON.stringify(["MOCK-KUR-001","MOCK-SAR-001"]));';
$noShare = 'delete Navigator.prototype.share;const fw=document.getElementById("f").contentWindow;delete fw.Navigator.prototype.share;';
$shots = [
    ['wishlist-item', false, '/wishlist', $w, '', $wish],
    ['bag-line-size', false, '/bag', $w, '', $bag],
    ['share-sheet', false, '/product?sku=MOCK-SAR-001', $w, $noShare . 'q("[data-pf=share]").click()', ''],
    ['size-picker', false, '/product?sku=MOCK-KUR-001', $w, 'const b=q("[data-pf=add-product]");b.scrollIntoView({block:"center"});b.click();setTimeout(()=>q(".pf_sizeChip").nextElementSibling.click(),200)', ''],
];
$port = 8434;
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, $port, ['PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ($shots as [$name, $needsIn, $path, $widths, $js, $pre]) {
        if (($needsIn !== null && $needsIn !== $signedIn) || ($only && !in_array($name, $only, true))) {
            continue;
        }
        foreach ($widths as $width) {
            $height = $width >= 768 ? 900 : 780;
            file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0;background:#888"><style>html{overflow:hidden}</style><script>' . $pre . '</script><iframe id="f" src="' . $path . '" width="' . $width . '" height="' . $height . '" style="border:0;display:block"></iframe><script>'
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

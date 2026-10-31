<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_ui9c_test
// UI9c (bag, phones): the checkout card floats 12px above the tray, nothing in it wraps (320-425), no WhatsApp on the bag,
// the item card puts the price above the actions, and at the bottom the Order Summary is not covered by the card or the tray.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_ui9c_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_ui9c_test');
$tmp = Tmp::dir('pf_ui9c_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui9c' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

/** One page in an iframe of $width x $height; $probe is JS (d = iframe document, w = its window, done(obj) reports). @return array<string,mixed> */
$measure = static function (string $page, int $width, int $height, string $probe, array $flags = []) use ($chrome, $tmp): array {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:' . $height . 'px;border:0"></iframe><script>const PAGE=' . json_encode($page)
        . ';const f=document.getElementById("f");const done=o=>{document.body.appendChild(document.createElement("pre")).textContent="@@"+JSON.stringify(o)+"@@"};'
        . 'f.onload=()=>setTimeout(()=>{const d=f.contentDocument,w=f.contentWindow;try{const a=d.querySelector("[data-pf=cookie-accept]");if(a)a.click();' . $probe . '}catch(e){done({error:String(e)})}},1500);f.src=PAGE;</script>');
    $dom = BrowserRig::dump($chrome, $tmp . '/p' . bin2hex(random_bytes(3)), 'http://127.0.0.1:8283/__w', $width + 20, $height + 20, 20000, $flags);
    return preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? (json_decode($m[1], true) ?: ['error' => 'bad json']) : ['error' => 'no answer from Chrome'];
};


$bagProbe = <<<'JS'
if (!(JSON.parse(w.localStorage.getItem('pratyangi-demo-bag') || '[]')).length) {
  fetch('/api/v1/catalog/products').then(r => r.json()).then(p => { w.localStorage.setItem('pratyangi-demo-bag', JSON.stringify((p.products || p).filter(x => x.inStock).slice(0, 3).map(x => ({ id: x.sku, quantity: 1 })))); w.location.reload(); });
  return;
}
setTimeout(() => {
  const R = e => { const r = e.getBoundingClientRect(); return { t: r.top, b: r.bottom, h: r.height, l: r.left, r: r.right }; };
  const bar = d.querySelector('.pf_cbar'), tray = d.querySelector('.pf_tray'), cb = R(bar), tb = R(tray);
  const lines = [...bar.querySelectorAll('strong, small, .pf_cbarGo')].map(e => [e.textContent.trim(), Math.round(e.getBoundingClientRect().height), parseFloat(getComputedStyle(e).lineHeight) || 0, e.tagName === "SMALL" || e.scrollWidth <= e.clientWidth + 1]); // small: its link has a wider 44px tap area by design
  const item = d.querySelector('.BpPmcW_item'), price = item.querySelector('.BpPmcW_price'), qty = item.querySelector('.BpPmcW_quantity'), sec = item.querySelector('.BpPmcW_secondary');
  const secOneRow = [...sec.children].every(b => Math.abs(b.getBoundingClientRect().top - sec.children[0].getBoundingClientRect().top) < 2);
  w.scrollTo(0, d.documentElement.scrollHeight);
  setTimeout(() => {
    const total = R(d.querySelector('[data-pf="total"]')), note = d.querySelector('.BpPmcW_status');
    const rows = [...d.querySelectorAll('.BpPmcW_breakdown > div')].map(r => Math.round(r.getBoundingClientRect().height));
    done({ gap: Math.round(tb.t - cb.b), radius: getComputedStyle(bar).borderTopLeftRadius, lines, go: Math.round(R(bar.querySelector('.pf_cbarGo')).h), wa: !!d.querySelector('.pf_wa') && d.querySelector('.pf_wa').checkVisibility(),
      priceAbove: R(price).b <= R(qty).t + 1, secOneRow, totalClear: total.b <= R(bar).t, rows, note: note.textContent.trim(), noteOneLine: note.getBoundingClientRect().height < 30, overflowX: d.documentElement.scrollWidth - d.documentElement.clientWidth });
  }, 500);
}, 600);
JS;
$server = BrowserRig::serve($router, 8283, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '0']);
foreach ([320, 360, 390, 425] as $width) {
    $t->test("bag @{$width}: checkout card, item card, summary", function () use ($t, $measure, $bagProbe, $width): void {
        $r = $measure('/bag', $width, 760, $bagProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        echo "  ok     bag @{$width}: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        $t->same(12, $r['gap'], 'card 12px above the tray');
        $t->same('16px', $r['radius']);
        foreach ($r['lines'] as [$text, $h, $lh, $fits]) {
            $t->ok($fits && ($lh === 0.0 || $h <= $lh * 1.5 || $h <= 48), "one line: {$text} ({$h}px)");
        }
        $t->ok(str_starts_with($r['lines'][0][0], '₹'), 'total uses the one price format');
        $t->ok($r['go'] >= 44, 'Checkout button ' . $r['go'] . 'px');
        $t->same(false, $r['wa'], 'no WhatsApp on the bag');
        $t->ok($r['priceAbove'], 'price row above the quantity');
        $t->ok($r['secOneRow'], 'Move to Wishlist and Remove on one row');
        $t->ok($r['totalClear'], 'summary total not covered by the card at the bottom');
        $t->same(1, count(array_unique($r['rows'])), 'summary rows one line each ' . json_encode($r['rows']));
        $t->same('Delivery charges are added at checkout', $r['note']);
        $t->ok($r['noteOneLine'], 'delivery note on one line');
        $t->ok($r['overflowX'] <= 0, 'no sideways scroll');
    });
}
BrowserRig::stop($server);
exit($t->finish());

<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_ui10_test
// UI10 fixes: the wishlist's "Move to bag" opens the size picker for a sized product (never adds it without a size) and moves a free-size one at once;
// the eye (Quick View) is on wishlist and bag pictures at phone and desktop widths.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_ui10_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_ui10_test');
$tmp = Tmp::dir('pf_ui10_');
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
    $dom = BrowserRig::dump($chrome, $tmp . '/p' . bin2hex(random_bytes(3)), 'http://127.0.0.1:8284/__w', $width + 20, $height + 20, 20000, $flags);
    return preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? (json_decode($m[1], true) ?: ['error' => 'bad json']) : ['error' => 'no answer from Chrome'];
};

// SKUs of the rig's mock catalogue: MOCK-KUR-001 is size M (picker), MOCK-SAR-001 a saree (no picker)
$wishProbe = <<<'JS'
if (!w.localStorage.getItem('pf-ui10-seeded')) {
  w.localStorage.setItem('pf-ui10-seeded', '1');
  w.localStorage.setItem('pratyangi-demo-wishlist', JSON.stringify(['MOCK-KUR-001', 'MOCK-SAR-001']));
  w.localStorage.setItem('pratyangi-demo-bag', '[]');
  w.location.reload();
  return;
}
w.localStorage.removeItem('pf-ui10-seeded');
setTimeout(() => {
  const bagIds = () => JSON.parse(w.localStorage.getItem('pratyangi-demo-bag') || '[]').map(l => l.id);
  const wished = () => JSON.parse(w.localStorage.getItem('pratyangi-demo-wishlist') || '[]');
  const rows = [...d.querySelectorAll('.pf_wishlist_row')];
  const row = sku => rows.find(r => r.getAttribute('data-qv-sku') === sku);
  const eyes = rows.map(r => { const e = r.querySelector('.pf_eye'); return !!e && e.checkVisibility() && e.getBoundingClientRect().width >= 32; });
  const kurMove = [...row('MOCK-KUR-001').querySelectorAll('button')].find(b => b.textContent === 'Move to bag');
  kurMove.click();
  setTimeout(() => {
    const picker = !!d.querySelector('.pf_sheet, .pf_pop'), afterOpen = bagIds(), addOff = d.querySelector('.pf_sheetBtn').disabled;
    d.querySelector('.pf_sizeChip:not([disabled])').click();
    d.querySelector('.pf_sheetBtn').click();
    setTimeout(() => {
      const afterSize = bagIds(), wishAfter = wished();
      const sarMove = [...d.querySelectorAll('.pf_wishlist_row')].find(r => r.getAttribute('data-qv-sku') === 'MOCK-SAR-001').querySelector('.pf_acct_btn');
      sarMove.click();
      setTimeout(() => {
        const eye = d.querySelector('.pf_wishlist_row .pf_eye');
        if (eye) eye.click();
        setTimeout(() => done({ eyes, picker, afterOpen, addOff, afterSize, wishAfter, final: bagIds(), wishFinal: wished(), qv: !!d.querySelector('.pf_qv') }), 300);
      }, 400);
    }, 400);
  }, 400);
}, 900);
JS;
$bagEyeProbe = <<<'JS'
if (!w.localStorage.getItem('pf-ui10-seeded')) {
  w.localStorage.setItem('pf-ui10-seeded', '1');
  w.localStorage.setItem('pratyangi-demo-bag', JSON.stringify([{ id: 'MOCK-KUR-001~M', quantity: 1 }, { id: 'MOCK-SAR-001', quantity: 1 }]));
  w.location.reload();
  return;
}
w.localStorage.removeItem('pf-ui10-seeded');
setTimeout(() => {
  const eyes = [...d.querySelectorAll('.BpPmcW_item')].map(i => { const e = i.querySelector('.pf_eye'); return !!e && e.checkVisibility() && e.getBoundingClientRect().width >= 32; });
  const size = (d.querySelector('.BpPmcW_details p') || {}).textContent || '';
  d.querySelector('.BpPmcW_item .pf_eye').click();
  setTimeout(() => done({ eyes, size, qv: (d.querySelector('.pf_qv h2') || {}).textContent || '' }), 300);
}, 1200);
JS;

$server = BrowserRig::serve($router, 8284, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '0']);
foreach ([390, 1440] as $width) {
    $t->test("wishlist @{$width}: Move to bag asks for a size, eye opens Quick View", function () use ($t, $measure, $wishProbe, $width): void {
        $r = $measure('/wishlist', $width, 900, $wishProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        echo "  ok     wishlist @{$width}: " . json_encode($r) . "\n";
        $t->same([true, true], $r['eyes'], 'eye on both pictures');
        $t->ok($r['picker'], 'the size picker opened');
        $t->same([], $r['afterOpen'], 'nothing added before a size is chosen');
        $t->ok($r['addOff'], 'Add to bag disabled until a size is chosen');
        $t->same(1, count($r['afterSize']), 'one line after choosing');
        $t->ok(str_starts_with($r['afterSize'][0], 'MOCK-KUR-001~'), 'the line carries the size: ' . $r['afterSize'][0]);
        $t->same(['MOCK-SAR-001'], $r['wishAfter'], 'the kurti left the wishlist');
        $t->ok(in_array('MOCK-SAR-001', $r['final'], true), 'a saree moves without a picker');
        $t->ok(!in_array('MOCK-KUR-001', $r['final'], true), 'never a sized product without a size');
        $t->same([], $r['wishFinal']);
    });
    $t->test("bag @{$width}: eye on every picture, opens Quick View; the size line shows", function () use ($t, $measure, $bagEyeProbe, $width): void {
        $r = $measure('/bag', $width, 900, $bagEyeProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        $t->same([true, true], $r['eyes']);
        $t->contains('Size M', $r['size']);
        $t->same('Printed Cotton Kurti', $r['qv']);
    });
}
BrowserRig::stop($server);
exit($t->finish());



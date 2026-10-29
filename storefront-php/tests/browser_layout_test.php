<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_layout_test
// Every page at 320 360 390 414 768 1024 1440 px, signed out and signed in (one Chrome per width, all pages in turn inside an iframe of that
// width, because headless Chrome will not make a window narrower than ~500px). FAILS on: page wider than the viewport, an element sticking
// out of it (unless a scrolling/clipping parent holds it), a tap target under 44px (inline links inside a sentence are exempt).
// The full list goes to .claude/snapshots/m1/layout-failures.txt; the output groups it by kind and selector.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_layout_test: Chrome not found\n";
    exit(0);
}
$widths = [320, 360, 390, 414, 768, 1024, 1440];
$sku = 'MOCK-SAR-001';
// [name, url, optional JS run in the page (w = its window) before measuring]
$pages = [
    ['home', '/', ''],
    ['menu-open', '/', 'w.document.querySelector("[data-pf=shop-toggle]").click()'],
    ['search-open', '/', 'w.document.querySelector("[data-pf=search-open]").click()'],
    ['new-arrivals', '/new-arrivals', ''],
    ['quick-view', '/new-arrivals', 'w.document.querySelector("[data-pf=quick-view]").click()'],
    ['collection', '/collection/sarees', ''],
    ['search', '/search?q=saree', ''],
    ['product', '/product?sku=' . $sku, ''],
    ['bag', '/bag', ''],
    ['checkout', '/checkout', ''],
    ['wishlist', '/wishlist', ''],
    ['login', '/login', ''],
    ['signup', '/signup', ''],
    ['forgot', '/forgot-password', ''],
    ['help-account', '/account-help', ''],
    ['account', '/account', ''],
    ['account-orders', '/account/orders', ''],
    ['account-addresses', '/account/addresses', ''],
    ['account-notifications', '/account/notifications', ''],
    ['account-payments', '/account/payments', ''],
    ['account-reviews', '/account/reviews', ''],
    ['account-help', '/account/help', ''],
    ['privacy', '/privacy', ''],
    ['terms', '/terms', ''],
    ['refund-policy', '/refund-policy', ''],
    ['shipping-policy', '/shipping-policy', ''],
    ['contact', '/contact', ''],
    ['error-404', '/no-such-page', ''],
    ['error-403', '/__e/403', ''],
    ['error-429', '/__e/429', ''],
    ['error-500', '/__e/500', ''],
    ['error-503', '/__e/503', ''],
];

$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('browser_layout_test');
$tmp = sys_get_temp_dir() . '/pf_layout_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);

$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Layout Check', 'email' => 'layout' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';
$t->same(201, $signup->status(), 'test account');

// the page that walks through $pages inside an iframe and prints the findings as JSON between two markers
$js = <<<'JS'
const sleep = ms => new Promise(r => setTimeout(r, ms));
const sel = el => { let s = el.tagName.toLowerCase(); if (el.id) s += '#' + el.id; const c = (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 2); return c.length ? s + '.' + c.join('.') : (el.parentElement ? sel(el.parentElement) + '>' : '') + s; };
const TAP = 'a[href],button,[role=tab],[role=button],summary,select,textarea,input:not([type=hidden])';
// a small control passes when the 44x44 square around its centre hits it (or its ::after, or its label) and nothing else: an invisible tap area
function owns44(el) {
  const w = el.ownerDocument.defaultView, d = w.document, lab = el.matches('input[type=checkbox],input[type=radio]') ? el.closest('label') : null;
  el.scrollIntoView({ block: 'center', inline: 'nearest' });
  const r = el.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
  const top = d.elementFromPoint(cx, cy);
  if (top && !(top === el || el.contains(top) || (lab && lab.contains(top)))) return null; // its centre is under something else (an open menu or dialog): not reachable now, so not a target
  for (const dx of [-21, 0, 21]) for (const dy of [-21, 0, 21]) {
    const x = cx + dx, y = cy + dy;
    if (x < 0 || y < 0 || x >= w.innerWidth || y >= w.innerHeight) continue;
    const hit = d.elementFromPoint(x, y);
    if (!hit || !(hit === el || el.contains(hit) || (lab && lab.contains(hit)))) return 'hit ' + (hit ? sel(hit) : 'nothing') + ' at ' + dx + ',' + dy;
  }
  return null;
}
function measure(w) {
  const d = w.document, W = w.innerWidth, out = [], outside = new Set();
  if (d.documentElement.scrollWidth > W) out.push(['scroll', 'html', d.documentElement.scrollWidth + '>' + W]);
  const clipped = el => { for (let p = el.parentElement; p && p !== d.body && p !== d.documentElement; p = p.parentElement) { if (w.getComputedStyle(p).overflowX !== 'visible') { const r = p.getBoundingClientRect(); if (r.left >= -0.5 && r.right <= W + 0.5) return true; } } return false; };
  for (const el of d.body.querySelectorAll('*')) {
    if (!el.checkVisibility({ checkVisibilityCSS: true })) continue;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) continue;
    const sticks = r.right > W + 0.5 || r.left < -0.5;
    if (sticks && !clipped(el)) { outside.add(el); if (!(el.parentElement && outside.has(el.parentElement))) out.push(['out', sel(el), Math.round(r.left) + '..' + Math.round(r.right) + ' of ' + W]); }
    if (r.right < 0 || r.left > W || !el.matches(TAP)) continue;
    let b = r;
    if (el.matches('input[type=checkbox],input[type=radio]') && el.closest('label')) b = el.closest('label').getBoundingClientRect();
    // a link inside a sentence (text beside it in its parent) is exempt
    if (el.tagName === 'A' && /^inline/.test(w.getComputedStyle(el).display) && Array.from(el.parentElement.childNodes).some(n => n.nodeType === 3 && n.textContent.trim())) continue;
    const bw = el === d.activeElement || b !== r ? b.width : (el.offsetWidth || b.width), bh = b !== r ? b.height : (el.offsetHeight || b.height); // offset sizes ignore an entrance animation's scale
    if (bw < 43.5 || bh < 43.5) { const bad = owns44(el); if (bad) out.push(['tap', sel(el), Math.round(bw) + 'x' + Math.round(bh) + ' ' + bad]); }
  }
  // header (stage H1): from 768px at least 24px between the logo and the menu and between the logo and the icons; never touching at any width; on phones from 390px the logo is in the middle
  const hdr = d.querySelector('.pSCCwa_headerInner');
  if (hdr) {
    const box = s => { const e = hdr.querySelector(s); return e && e.checkVisibility() ? e.getBoundingClientRect() : null; };
    const menu = box('.pSCCwa_shopToggle'), brand = box('.pSCCwa_brand'), icons = box('.pSCCwa_headerActions'), need = W >= 768 ? 24 : 0;
    if (brand && menu && brand.left - menu.right < need - 0.5) out.push(['header', 'logo-menu-gap', Math.round(brand.left - menu.right) + 'px at ' + W]);
    if (brand && icons && icons.left - brand.right < need - 0.5) out.push(['header', 'logo-icons-gap', Math.round(icons.left - brand.right) + 'px at ' + W]);
    if (brand && W >= 390 && W <= 700 && Math.abs((brand.left + brand.right) / 2 - W / 2) > 6) out.push(['header', 'logo-not-centred', Math.round((brand.left + brand.right) / 2) + ' of ' + W]);
  }
  return out;
}
(async () => {
  const f = document.getElementById('f'), res = [];
  localStorage.setItem('pratyangi-demo-bag', JSON.stringify([{ id: SKU, quantity: 2 }]));
  localStorage.setItem('pratyangi-demo-wishlist', JSON.stringify([SKU]));
  for (const [name, url, step] of PAGES) {
    await new Promise(r => { f.onload = () => r(); f.src = url; });
    await sleep(700);
    try { if (step) { new Function('w', step)(f.contentWindow); await sleep(500); } } catch (e) { res.push([name, 'step', String(e), '']); continue; }
    const signedIn = !!f.contentDocument.querySelector('.pSCCwa_accountLink');
    for (const [kind, s, info] of measure(f.contentWindow)) res.push([name, kind, s, info, signedIn]);
    res.push([name, 'seen', f.contentWindow.location.pathname, '', signedIn]);
  }
  document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify(res) + '@@';
})();
JS;

$found = [];
foreach ([false, true] as $signedIn) {
    $servers = [];
    $procs = [];
    $port = 8200;
    foreach ($widths as $w) {
        $dir = $tmp . '/w' . $w . ($signedIn ? 'in' : 'out');
        mkdir($dir);
        file_put_contents($dir . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $w . 'px;height:900px;border:0;display:block"></iframe><script>const SKU=' . json_encode($sku) . ',PAGES=' . json_encode($pages) . ';' . $js . '</script>');
        BrowserRig::router($dir);
        $servers[$w] = BrowserRig::serve($dir . '/router.php', $port, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $signedIn ? $cookie : '', 'PF_CC_ALL' => '1']);
        $procs[$w] = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--virtual-time-budget=400000', '--window-size=' . max($w + 40, 520) . ',1000',
            '--user-data-dir=' . $dir . '/profile', '--dump-dom', 'http://127.0.0.1:' . $port . '/__w'], [['null'], ['pipe', 'w'], ['file', $dir . '/err.log', 'w']], $pipes[$w]);
        $port++;
    }
    foreach ($widths as $w) {
        $dom = (string) stream_get_contents($pipes[$w][1]);
        proc_close($procs[$w]);
        BrowserRig::stop($servers[$w]);
        $rows = preg_match('/@@(\[.*\])@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? json_decode($m[1], true) : null;
        $t->ok(is_array($rows), 'width ' . $w . ($signedIn ? ' in' : ' out') . ': the measuring page returned its findings');
        foreach ($rows ?? [] as [$page, $kind, $selector, $info, $seenIn]) {
            if ($kind === 'seen') {
                // the signed-in run must really be signed in on the shop pages, the signed-out run must not be
                if ($page === 'new-arrivals') {
                    $t->same($signedIn, $seenIn, 'width ' . $w . ': signed-in state of the header');
                }
                continue;
            }
            $found[$page . '|' . $w . '|' . ($signedIn ? 'in' : 'out') . '|' . $kind . '|' . $selector] = $info;
        }
    }
}

$groups = [];
$lines = [];
foreach ($found as $key => $info) {
    [$page, $w, $state, $kind, $selector] = explode('|', $key, 5);
    $lines[] = "$page $w $state $kind $selector ($info)";
    $groups["$kind $selector"][] = "$page@$w$state";
}
@mkdir(dirname(__DIR__) . '/.claude/snapshots/m1', 0777, true);
file_put_contents(dirname(__DIR__) . '/.claude/snapshots/m1/layout-failures.txt', implode("\n", $lines) . "\n");
uasort($groups, static fn (array $a, array $b): int => count($b) <=> count($a));
foreach (array_slice($groups, 0, 40, true) as $what => $where) {
    echo 'FAIL ' . count($where) . 'x ' . $what . '  e.g. ' . implode(', ', array_slice($where, 0, 3)) . "\n";
}
echo 'FAIL total: ' . count($found) . ' findings, ' . count($groups) . ' distinct kind+selector' . "\n";
$t->test('no layout findings (full list in .claude/snapshots/m1/layout-failures.txt)', function () use ($t, $found): void {
    $t->same(0, count($found));
});
exit($t->finish());

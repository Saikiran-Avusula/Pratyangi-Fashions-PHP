<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_ui8_test
// UI8, measured in a real browser (the page sits in an iframe of the exact width; headless Chrome will not open a window under ~500px):
//  1. EMPTY STATES: the empty-state block is centred in the visible area between the page title and the footer / tab bar (|dx| <= 8, |dy| <= 24) and
//     nothing else but the title is visible in that area. Bag, wishlist (signed out and in), search, orders, addresses, payments at 360, 390, 768, 1440.
//  2. PHONE HEADER 320-425: only menu, logo, search; logo centred; emblem >= 36px, name >= 11px, tagline >= 8px (or hidden); the tab bar has 4 tabs; no badge at 0.
//  3. SEARCH: opening and closing it moves no page element by even 1px (no shake, no scrollbar jump).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_ui8_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_ui8_test');
$tmp = Tmp::dir('pf_ui8_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Empty Check', 'email' => 'ui8' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

/** One page in an iframe of $width x $height; $probe is JS (d = iframe document, w = its window, done(obj) reports). @return array<string,mixed> */
$measure = static function (string $page, int $width, int $height, string $probe) use ($chrome, $tmp): array {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:' . $height . 'px;border:0"></iframe><script>const PAGE=' . json_encode($page)
        . ';const f=document.getElementById("f");const done=o=>{document.body.appendChild(document.createElement("pre")).textContent="@@"+JSON.stringify(o)+"@@"};'
        . 'f.onload=()=>setTimeout(()=>{const d=f.contentDocument,w=f.contentWindow;try{' . $probe . '}catch(e){done({error:String(e)})}},1500);f.src=PAGE;</script>');
    $dom = BrowserRig::dump($chrome, $tmp . '/p' . bin2hex(random_bytes(3)), 'http://127.0.0.1:8281/__w', $width + 20, $height + 20, 20000);
    return preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? (json_decode($m[1], true) ?: ['error' => 'bad json']) : ['error' => 'no answer from Chrome'];
};

$emptyProbe = <<<'JS'
const vw = d.documentElement.clientWidth, vh = w.innerHeight;
const block = [...d.querySelectorAll('.pf_emptyState')].find(e => e.checkVisibility());
const title = d.querySelector('.pnvDaq_heading, .BpPmcW_intro, ._4HCqHa_introTitle');
if (!block || !title) { done({ error: 'no block (' + !!block + ') or title (' + !!title + ')' }); return; }
const foot = d.querySelector('footer'), tray = d.querySelector('.pf_tray');
const trayH = tray && getComputedStyle(tray).display !== 'none' ? tray.getBoundingClientRect().height : 0;
let top = title.getBoundingClientRect().bottom, bottom = Math.min(foot.getBoundingClientRect().top, vh - trayH);
const col = d.querySelector('.pnvDaq_content'), cr = col ? col.getBoundingClientRect() : null;
// under 961px the account tab bar sits above the content and Logout below it: they are navigation, not content, so the area is what is left between them
if (cr) { const nav = d.querySelector('.pnvDaq_sidebar nav'), out = d.querySelector('.pnvDaq_logout'); for (const x of [nav, out]) { if (!x) continue; const xr = x.getBoundingClientRect(); if (xr.bottom <= cr.top + 1) top = Math.max(top, xr.bottom); else if (xr.top >= cr.bottom - 1) bottom = Math.min(bottom, xr.top); } }
const left = cr ? cr.left : 0, right = cr ? cr.right : vw;
const b = block.getBoundingClientRect();
const dx = (b.left + b.right) / 2 - (left + right) / 2, dy = (b.top + b.bottom) / 2 - (top + bottom) / 2;
const skip = 'header, footer, nav, .pf_topbar, .pf_tray, .pf_cookie, .pf_wa, script, style, [data-pf="search-scrim"], [data-pf="menu-panel"], .pnvDaq_sidebar, .pf_acctBack, .pf_crumb';
const stray = [];
for (const e of d.querySelectorAll('main *, .pnvDaq_main *')) {
  if (e.closest(skip) || block.contains(e) || e.contains(block) || title.contains(e) || e.contains(title) || !e.checkVisibility()) continue;
  const r = e.getBoundingClientRect();
  if (r.width < 1 || r.height < 1 || r.bottom <= top || r.top >= bottom || r.right <= left || r.left >= right) continue;
  const own = [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
  if (own || /^(IMG|SVG|BUTTON|INPUT|SELECT)$/i.test(e.tagName)) stray.push(e.tagName + '.' + String(e.className).split(' ')[0]);
}
done({ dx: Math.round(dx * 10) / 10, dy: Math.round(dy * 10) / 10, area: [Math.round(top), Math.round(bottom)], col: cr ? [Math.round(cr.top), Math.round(cr.bottom)] : null, foot: Math.round(foot.getBoundingClientRect().top), stray });
JS;

$cases = [['/bag', 'bag', false], ['/wishlist', 'wishlist (signed out)', false], ['/wishlist', 'wishlist (signed in)', true], ['/search?q=zzzzqx', 'search, no result', false],
    ['/account/orders', 'orders', true], ['/account/addresses', 'addresses', true], ['/account/payments', 'payments', true]];
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, 8281, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ($cases as [$page, $label, $needsIn]) {
        if ($needsIn !== $signedIn) {
            continue;
        }
        foreach ([360, 390, 768, 1440] as $width) {
            $t->test("empty {$label} @{$width}: centred, nothing but the title", function () use ($t, $measure, $page, $width, $emptyProbe, $label): void {
                $r = $measure($page, $width, $width >= 768 ? 900 : 800, $emptyProbe);
                echo "  ok     {$label} @{$width}: " . json_encode($r) . "\n";
                $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
                $t->ok(abs($r['dx']) <= 8, 'dx ' . $r['dx']);
                $t->ok(abs($r['dy']) <= 24, 'dy ' . $r['dy']);
                $t->same([], $r['stray'], 'nothing else in the area');
            });
        }
    }
    BrowserRig::stop($server);
}

$headerProbe = <<<'JS'
const hr = d.querySelector('.pSCCwa_header').getBoundingClientRect(), vw = hr.width, vis = e => e && e.checkVisibility();
const brand = d.querySelector('.pSCCwa_brand'), br = brand.getBoundingClientRect(), img = d.querySelector('.pSCCwa_brandLogo img');
const name = d.querySelector('.pSCCwa_brandName'), tag = d.querySelector('.pSCCwa_brandTagline');
const shown = [...d.querySelectorAll('.pSCCwa_headerInner button, .pSCCwa_headerInner a, .pSCCwa_headerActions > *')].filter(e => vis(e)).map(e => e.getAttribute('data-pf') || e.className.split(' ')[0]);
// LOGO1: the gold lockup is one picture: its name is 25.2% of the picture's height (tools/logo_build.php); the tagline is part of the artwork (no text to measure)
const lock = d.querySelector('.pf_lockup img');
done({ vw, centre: Math.round(((br.left + br.right) / 2 - vw / 2) * 10) / 10, emblem: img.getBoundingClientRect().height,
  name: lock ? Math.round(lock.getBoundingClientRect().height * 0.252 * 10) / 10 : parseFloat(getComputedStyle(name).fontSize),
  tag: lock ? null : (vis(tag) ? parseFloat(getComputedStyle(tag).fontSize) : null), shown, tabs: [...d.querySelectorAll('.pf_trayTab')].filter(vis).map(e => e.querySelector('.pf_trayLabel').textContent.trim()),
  badges: [...d.querySelectorAll('.pSCCwa_count')].filter(vis).length, trayH: vis(d.querySelector('.pf_tray')) ? d.querySelector('.pf_tray').getBoundingClientRect().height : 0, overflowX: d.documentElement.scrollWidth - vw });
JS;
foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, 8281, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    foreach ([320, 360, 390, 425] as $width) {
        $t->test('phone header @' . $width . ($signedIn ? ' signed in' : ' signed out') . ': menu, centred full logo, search; tab bar', function () use ($t, $measure, $width, $headerProbe): void {
            $r = $measure('/', $width, 700, $headerProbe);
            echo "  ok     header @{$width}: " . json_encode($r) . "\n";
            $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
            $t->ok(abs($r['centre']) <= 1, 'logo centre off by ' . $r['centre']);
            $t->ok($r['emblem'] >= 36, 'emblem ' . $r['emblem']);
            $t->ok($r['name'] >= 11, 'name ' . $r['name']);
            $t->ok($r['tag'] === null || $r['tag'] >= 8, 'tagline ' . json_encode($r['tag']));
            $t->same(['shop-toggle', 'pSCCwa_brand', 'search-open'], $r['shown'], 'only menu, logo and search in the header');
            $t->same(['Home', 'Wishlist', 'Bag', 'Account'], $r['tabs']);
            $t->same(0, $r['badges'], 'no badge at 0');
            $t->ok($r['trayH'] >= 56, 'tray ' . $r['trayH']);
            $t->ok($r['overflowX'] <= 0, 'sideways overflow ' . $r['overflowX']);
        });
    }
    BrowserRig::stop($server);
}

$shakeProbe = <<<'JS'
const sel = ['.pf_topbar', '.pSCCwa_header', '.pSCCwa_brand', '.pSCCwa_headerActions', 'main h1', 'main img', 'main a', 'footer', '.f_viWW_footerColumn'];
const snap = () => sel.map(s => { const e = d.querySelector(s); if (!e) return s + ':none'; const r = e.getBoundingClientRect(); return s + ':' + [r.left, r.top, r.width, r.height].map(n => Math.round(n * 100) / 100).join(','); });
w.scrollTo(0, 300);
setTimeout(() => {
  const before = snap(), cw = d.documentElement.clientWidth;
  d.querySelector('[data-pf="search-open"]').click();
  setTimeout(() => {
    const during = snap(), cwDuring = d.documentElement.clientWidth;
    d.querySelector('[data-pf="search-close"]').click();
    setTimeout(() => done({ sy: [w.scrollY, d.body.getAttribute('style'), d.body.className], before, during, after: snap(), cw, cwDuring, scrim: d.querySelector('[data-pf="search-scrim"]').hidden }), 600);
  }, 600);
}, 400);
JS;
$server = BrowserRig::serve($router, 8281, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '0']);
foreach ([1440, 390] as $width) {
    $t->test("search open/close @{$width}: no page element moves, no scrollbar jump", function () use ($t, $measure, $shakeProbe, $width): void {
        $r = $measure('/', $width, 800, $shakeProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        $t->same($r['before'], $r['during'], 'moved while the search is open');
        $t->same($r['before'], $r['after'], 'moved after it closed ' . json_encode($r['sy']));
        $t->same($r['cw'], $r['cwDuring'], 'the usable width changed (scrollbar jump)');
        $t->ok($r['scrim'], 'the search closed');
    });
}
BrowserRig::stop($server);
exit($t->finish());

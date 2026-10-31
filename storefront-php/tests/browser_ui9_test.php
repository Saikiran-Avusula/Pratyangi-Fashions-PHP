<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_ui9_test
// UI9, measured in a real browser (the page sits in an iframe of the exact width):
//  1. MENU OPEN: the Sort/Filter bar, tray and WhatsApp button are hidden while the menu is open and back after it closes.
//  2. BOTTOM CHROME (phone listing, UI9b): Sort/Filter pill 44px, 12px above the tray; WhatsApp 40px 12px above it; both hide on scroll down / at the footer; nothing covers the last prices or footer links. Was: it.
//  3. CARD: one out-of-stock indicator, 36px icons 8px apart not over the badge, no Add to cart on the picture on a phone, prices aligned per row.
//  4. TRAY: 16px top corners, the pill sits on the active tab and slides in 220ms.
//  5. ACCOUNT SHELL at 1024 and 1440: sidebar and content x/width identical on every account page; the sidebar is as tall as its rows.
//  7. AVATAR: picture missing, 404 or fine -> always something visible.  8. HELP: one FAQ open at a time, the chevron turns.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_ui9_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_ui9_test');
$tmp = Tmp::dir('pf_ui9_');
$router = BrowserRig::router($tmp);
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Asha Rao', 'email' => 'ui9' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

/** One page in an iframe of $width x $height; $probe is JS (d = iframe document, w = its window, done(obj) reports). @return array<string,mixed> */
$measure = static function (string $page, int $width, int $height, string $probe, array $flags = []) use ($chrome, $tmp): array {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:' . $height . 'px;border:0"></iframe><script>const PAGE=' . json_encode($page)
        . ';const f=document.getElementById("f");const done=o=>{document.body.appendChild(document.createElement("pre")).textContent="@@"+JSON.stringify(o)+"@@"};'
        . 'f.onload=()=>setTimeout(()=>{const d=f.contentDocument,w=f.contentWindow;try{const a=d.querySelector("[data-pf=cookie-accept]");if(a)a.click();' . $probe . '}catch(e){done({error:String(e)})}},1500);f.src=PAGE;</script>');
    $dom = BrowserRig::dump($chrome, $tmp . '/p' . bin2hex(random_bytes(3)), 'http://127.0.0.1:8282/__w', $width + 20, $height + 20, 20000, $flags);
    return preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? (json_decode($m[1], true) ?: ['error' => 'bad json']) : ['error' => 'no answer from Chrome'];
};

$overlayProbe = <<<'JS'
const vis = s => { const e = d.querySelector(s); return !!e && e.checkVisibility(); };
const state = () => ({ bar: vis('.pf_lbar'), tray: vis('.pf_tray'), wa: vis('.pf_wa') });
const before = state();
d.querySelector('[data-pf="shop-toggle"]').click();
setTimeout(() => { const open = state(); d.querySelector('[data-pf="shop-toggle"]').click(); // waits cover the 420ms menu exit
  setTimeout(() => { const sortBtn = d.querySelector('.pf_lbar button'); const after = state(); sortBtn.click();
    setTimeout(() => done({ before, open, after, sheet: state() }), 600); }, 900); }, 900);
JS;
$chromeProbe = <<<'JS'
d.head.appendChild(d.createElement('style')).textContent = '*{transition:none!important}'; // headless runs on virtual time: read end states
const R = s => { const e = d.querySelector(s); if (!e || !e.checkVisibility({ opacityProperty: true })) return null; const r = e.getBoundingClientRect(); return { t: r.top, b: r.bottom, l: r.left, r: r.right, h: r.height, w: r.width }; };
const hid = () => d.documentElement.classList.contains('pf_chromeHide');
const bar = R('.pf_lbar'), tray = R('.pf_tray'), wa = R('.pf_wa'), radius = getComputedStyle(d.querySelector('.pf_lbar')).borderTopLeftRadius;
const go = (y, f) => { w.scrollTo(0, y); w.dispatchEvent(new Event('scroll')); setTimeout(f, 400); }; // virtual time may not fire scroll events itself
go(300, () => { const down = hid(); go(200, () => { const up = hid(); go(d.documentElement.scrollHeight, () => {
  const bottom = hid(), live = [['bar', R('.pf_lbar')], ['tray', R('.pf_tray')], ['wa', R('.pf_wa')]], covered = [];
  const cards = [...d.querySelectorAll('[data-pf="grid"] .JEjkwq_card')].filter(c => c.checkVisibility());
  for (const e of [...cards.slice(-2).flatMap(c => [...c.querySelectorAll('.JEjkwq_priceRow, button')]), ...d.querySelectorAll('footer a, footer button')]) { if (!e.checkVisibility()) continue; const r = e.getBoundingClientRect();
    if (r.bottom <= 0 || r.top >= w.innerHeight) continue;
    for (const [n, x] of live) if (x && r.bottom > x.t && r.top < x.b && r.right > x.l && r.left < x.r) covered.push(n + ':' + (e.className || e.textContent.trim())); }
  done({ waRight: getComputedStyle(d.querySelector('.pf_wa')).right, bar, tray, wa, radius, waIcon: d.querySelector('.pf_wa img').getBoundingClientRect().width, down, up, bottom, covered });
}); }); });
JS;
$cardProbe = <<<'JS'
const cards = [...d.querySelectorAll('[data-pf="grid"] .JEjkwq_card')].filter(c => c.checkVisibility());
const out = cards.map(c => { const oos = c.getAttribute('data-instock') === '0';
  const marks = [...c.querySelectorAll('*')].filter(e => e.checkVisibility() && [...e.childNodes].some(n => n.nodeType === 3 && /out of stock/i.test(n.textContent))).length;
  const btns = [...c.querySelectorAll('.JEjkwq_actionBtn')].map(b => b.getBoundingClientRect()); const badge = c.querySelector('.JEjkwq_soldOutBadge');
  const br = badge ? badge.getBoundingClientRect() : null; const add = c.querySelector('.JEjkwq_addToCart');
  return { oos, marks, sizes: btns.map(b => [Math.round(b.width), Math.round(b.height)]), gap: btns.length > 1 ? Math.round(btns[1].top - btns[0].bottom) : null,
    overBadge: !!br && btns.some(b => b.left < br.right && b.right > br.left && b.top < br.bottom && b.bottom > br.top), addShown: !!add && add.checkVisibility(),
    price: Math.round(c.querySelector('.JEjkwq_priceRow').getBoundingClientRect().top), top: Math.round(c.getBoundingClientRect().top),
    nameH: Math.round(c.querySelector('.JEjkwq_meta h3').getBoundingClientRect().height) }; });
done({ out });
JS;
$trayProbe = <<<'JS'
const tray = d.querySelector('.pf_tray'), pill = d.querySelector('.pf_trayPill'), cs = getComputedStyle(tray), on = d.querySelector('.pf_trayOn');
const pr = pill.getBoundingClientRect(), or = on.getBoundingClientRect();
done({ radius: cs.borderTopLeftRadius + ' ' + cs.borderTopRightRadius, bg: cs.backgroundColor, pillCentre: Math.round((pr.left + pr.right) / 2 - (or.left + or.right) / 2),
  dur: getComputedStyle(pill).transitionDuration, prop: getComputedStyle(pill).transitionProperty, fill: getComputedStyle(on.querySelector('svg > *') || on).fill,
  weight: getComputedStyle(on).fontWeight, hiddenBadges: [...d.querySelectorAll('.pf_tray .pSCCwa_count')].filter(e => e.checkVisibility()).length });
JS;
$shellProbe = <<<'JS'
const R = s => { const e = d.querySelector(s); if (!e || !e.checkVisibility()) return null; const r = e.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.width), Math.round(r.top + w.scrollY), Math.round(r.height)]; };
const side = d.querySelector('.pnvDaq_sidebar'); const rows = side ? [...side.children].reduce((a, e) => a + e.getBoundingClientRect().height, 0) + (side.children.length - 1) * parseFloat(getComputedStyle(side).rowGap || 0) : 0;
done({ side: R('.pnvDaq_sidebar'), content: R('.pnvDaq_content'), title: R('.pnvDaq_heading h1'), sub: R('.pnvDaq_heading p'), crumb: R('.pf_crumb'), titleSize: getComputedStyle(d.querySelector('.pnvDaq_heading h1')).fontSize, rows: Math.round(rows) });
JS;
$avatarProbe = <<<'JS'
const box = d.querySelector('.pf_acctMenuAvatar');
const check = (label, src) => new Promise(res => { box.querySelectorAll('img').forEach(i => i.remove());
  if (src !== null) { const i = d.createElement('img'); i.setAttribute('data-pf', 'avatar-img'); i.alt = ''; i.src = src; box.prepend(i); }
  setTimeout(() => { const img = box.querySelector('img'), ini = box.querySelector('.P71-SG_avatarInitial'), r = box.getBoundingClientRect();
    const picture = !!img && img.naturalWidth > 0, initial = getComputedStyle(ini).visibility === 'visible' && ini.textContent.trim() !== '' && ini.getBoundingClientRect().width > 0;
    res({ label, size: [Math.round(r.width), Math.round(r.height)], picture, initial, visible: picture || initial }); }, 700); });
(async () => { const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  done({ cases: [await check('missing', null), await check('404', '/no-such-avatar.png'), await check('ok', png)] }); })();
JS;
$faqProbe = <<<'JS'
const ds = [...d.querySelectorAll('details[name="pf-faq"]')], s = ds.map(x => x.querySelector('summary'));
d.head.appendChild(d.createElement('style')).textContent = 'summary::after{transition:none!important}'; // headless Chrome runs on virtual time: read the end state
const rot = i => getComputedStyle(s[i], '::after').transform;
const closed = rot(0); s[0].click();
setTimeout(() => { const a = ds.map(x => x.open), r0 = rot(0); s[1].click(); // (900ms: headless Chrome runs on virtual time)
  setTimeout(() => { const b = ds.map(x => x.open); s[1].click(); setTimeout(() => done({ count: ds.length, a, b, c: ds.map(x => x.open), turned: r0 !== closed, summaryH: Math.round(s[0].getBoundingClientRect().height),
    draft: parseFloat(getComputedStyle(d.querySelector('.pf_acct h3 small')).fontSize) }), 900); }, 900); }, 900);
JS;

foreach ([false, true] as $signedIn) {
    $server = BrowserRig::serve($router, 8282, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => $signedIn ? '1' : '0']);
    $who = $signedIn ? 'signed in' : 'signed out';
    foreach ([360, 390] as $width) {
        $t->test("1 menu open @{$width} {$who}: bar, tray, WhatsApp hide, then come back", function () use ($t, $measure, $overlayProbe, $width): void {
            $r = $measure('/new-arrivals', $width, 800, $overlayProbe);
            $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
            $t->same(['bar' => true, 'tray' => true, 'wa' => true], $r['before'], 'before');
            $t->same(['bar' => false, 'tray' => false, 'wa' => false], $r['open'], 'menu open');
            $t->same(['bar' => true, 'tray' => true, 'wa' => true], $r['after'], 'after close');
            $t->same(['bar' => false, 'tray' => false, 'wa' => false], $r['sheet'], 'sort sheet open');
        });
        $t->test("2 bottom chrome @{$width} {$who}", function () use ($t, $measure, $chromeProbe, $width): void {
            $r = $measure('/new-arrivals', $width, 800, $chromeProbe);
            $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
            $t->ok(abs($r['bar']['h'] - 44) < 0.5, 'bar height ' . $r['bar']['h']);
            $t->ok(abs($r['tray']['t'] - $r['bar']['b'] - 12) < 0.5 && $r['bar']['w'] <= 280 && $r['radius'] !== '0px' && $r['down'] && !$r['up'] && $r['bottom'], 'pill 12px above the tray, <= 280px, round; hides down \/ at the footer, shows up: ' . json_encode([$r['down'], $r['up'], $r['bottom'], $r['bar']['w']]) . ' ' . $r['bar']['b'] . ' vs ' . $r['tray']['t']);
            $t->ok(abs($r['wa']['w'] - 40) < 0.5 && abs($r['wa']['h'] - 40) < 0.5, 'WhatsApp 40px: ' . $r['wa']['w']);
            $t->ok(abs($r['waIcon'] - 20) < 0.5, 'icon ' . $r['waIcon']);
            $t->ok(abs(($r['bar']['t'] - $r['wa']['b']) - 12) < 0.5, 'WhatsApp 12px above the bar: ' . ($r['bar']['t'] - $r['wa']['b']));
            $t->same('12px', $r['waRight'], 'WhatsApp 12px from the right edge (the rig iframe has a classic scrollbar, so the CSS value is checked)');
            $t->same([], $r['covered'], 'last row prices / buttons covered');
        });
        $t->test("3 product cards @{$width} {$who}", function () use ($t, $measure, $cardProbe, $width): void {
            $r = $measure('/new-arrivals', $width, 1400, $cardProbe);
            $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
            $rows = [];
            foreach ($r['out'] as $c) {
                $t->same($c['oos'] ? 1 : 0, $c['marks'], 'out-of-stock indicators');
                $t->same([[36, 36], [36, 36]], $c['sizes'], 'icon sizes');
                $t->same(8, $c['gap'], 'icon gap');
                $t->ok(!$c['overBadge'], 'icon over the badge');
                $t->ok(!$c['addShown'], 'Add to cart on the picture on a phone');
                $rows[$c['top']][] = $c['price'];
            }
            $t->ok(count($r['out']) > 0, 'no cards');
            foreach ($rows as $top => $prices) {
                $t->same(1, count(array_unique($prices)), "prices aligned in the row at {$top}: " . json_encode($prices));
            }
        });
        foreach (['/' => 0, '/wishlist' => 1, '/bag' => 2, ($signedIn ? '/account' : '/login') => 3] as $page => $tab) {
            $t->test("4 tray @{$width} {$who} on {$page}", function () use ($t, $measure, $trayProbe, $width, $page): void {
                $r = $measure($page, $width, 800, $trayProbe);
                $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
                $t->same('16px 16px', $r['radius'], 'top corners');
                $t->same('rgb(255, 255, 255)', $r['bg'], 'white');
                $t->ok(abs($r['pillCentre']) <= 1, 'pill on the active tab: ' . $r['pillCentre']);
                $t->same('0.22s', $r['dur'], 'pill slide');
                $t->same('transform', $r['prop'], 'pill slides by transform');
                $t->ok($r['fill'] !== 'none', 'active icon filled');
                $t->same('500', $r['weight'], 'active label weight');
                $t->same(0, $r['hiddenBadges'], 'no badge at 0');
            });
        }
    }
    BrowserRig::stop($server);
}

$server = BrowserRig::serve($router, 8282, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => '1']);
$pages = ['/account', '/account/orders', '/wishlist', '/account/addresses', '/account/payments', '/account/notifications', '/account/help'];
foreach ([1024, 1440] as $width) {
    $t->test("5 account shell @{$width}: sidebar and content identical on every page", function () use ($t, $measure, $shellProbe, $width, $pages): void {
        $all = [];
        foreach ($pages as $page) {
            $r = $measure($page, $width, 900, $shellProbe);
            $t->ok(!isset($r['error']), $page . ' ' . ($r['error'] ?? ''));
            $all[$page] = $r;
            echo "  ok     {$width} {$page}: " . json_encode($r) . "\n";
            $t->ok(abs($r['side'][3] - $r['rows']) <= 1, "{$page}: sidebar height {$r['side'][3]} = its rows {$r['rows']}");
        }
        $first = $all['/account'];
        foreach ($all as $page => $r) {
            $t->same([$first['side'][0], $first['side'][1], $first['side'][2]], [$r['side'][0], $r['side'][1], $r['side'][2]], "{$page}: sidebar x/width/top");
            $t->same([$first['content'][0], $first['content'][1], $first['content'][2]], [$r['content'][0], $r['content'][1], $r['content'][2]], "{$page}: content x/width/top");
            $t->same($first['title'], $r['title'], "{$page}: title");
            $t->same($first['crumb'], $r['crumb'], "{$page}: breadcrumb");
            $t->same($first['titleSize'], $r['titleSize'], "{$page}: title size");
        }
    });
}
BrowserRig::stop($server);

$server = BrowserRig::serve($router, 8282, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => '1']);
foreach ([360, 390] as $width) {
    $t->test("7 phone account card @{$width}: an avatar is always visible", function () use ($t, $measure, $avatarProbe, $width): void {
        $r = $measure('/account', $width, 800, $avatarProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        foreach ($r['cases'] ?? [] as $c) {
            $t->ok($c['visible'], $c['label'] . ': nothing visible ' . json_encode($c));
            $t->same([94, 94], $c['size'], $c['label'] . ': size');
        }
        $t->ok(($r['cases'][2]['picture'] ?? false) === true, 'a good picture shows');
    });
}
foreach ([390, 1440] as $width) {
    $t->test("8 help FAQ @{$width}: one open at a time, chevron turns", function () use ($t, $measure, $faqProbe, $width): void {
        $r = $measure('/account/help', $width, 900, $faqProbe);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        $t->same(3, $r['count']);
        $t->same([true, false, false], $r['a'], 'first opened');
        $t->same([false, true, false], $r['b'], 'second opened, first closed');
        $t->same([false, false, false], $r['c'], 'second closed again');
        $t->ok($r['turned'], 'chevron turns');
        $t->ok($r['summaryH'] >= 44, 'tap height ' . $r['summaryH']);
        $t->ok($r['draft'] <= 12, 'draft note small ' . $r['draft']);
    });
}
BrowserRig::stop($server);

// touch device (hover: none, coarse pointer): the card's heart + eye are always visible; account pages have no WhatsApp button
$touch = ['--blink-settings=primaryHoverType=1,availableHoverTypes=1,primaryPointerType=4,availablePointerTypes=4'];
$touchProbe = <<<'JS'
const card = d.querySelector('[data-pf="grid"] .JEjkwq_card');
// what is under test: the touch-screen CSS shows the icon box (opacity 1) and the buttons without hover. The card's own scroll fade-in (site.js reveal)
// is a separate effect that headless virtual time sometimes never runs, so the card's opacity is reported but not part of the check.
done({ hoverNone: w.matchMedia('(hover: none)').matches, cardShown: getComputedStyle(card).opacity,
  icons: [...card.querySelectorAll('.JEjkwq_actionBtn')].map(b => b.checkVisibility({ visibilityProperty: true }) && getComputedStyle(b.parentNode).opacity === '1' && getComputedStyle(b).opacity === '1') });
JS;
$waProbe = 'done({ wa: !!d.querySelector(".pf_wa") && d.querySelector(".pf_wa").checkVisibility() });';
$server = BrowserRig::serve($router, 8282, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $cookie, 'PF_CC_ALL' => '1']);
foreach ([360, 390] as $width) {
    $t->test("touch @{$width}: card heart + eye visible without hover", function () use ($t, $measure, $touchProbe, $touch, $width): void {
        $r = $measure('/new-arrivals', $width, 800, $touchProbe, $touch);
        $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
        $t->ok($r['hoverNone'], 'the browser really emulates a touch device');
        $t->same([true, true], $r['icons'], 'heart + eye visible (card opacity ' . ($r['cardShown'] ?? '?') . ')');
    });
    foreach (['/account', '/account/payments', '/account/help', '/wishlist'] as $page) {
        $t->test("no WhatsApp button on {$page} @{$width}", function () use ($t, $measure, $waProbe, $page, $width): void {
            $r = $measure($page, $width, 800, $waProbe);
            $t->ok(!isset($r['error']), (string) ($r['error'] ?? ''));
            $t->same(false, $r['wa']);
        });
    }
}
BrowserRig::stop($server);
exit($t->finish());

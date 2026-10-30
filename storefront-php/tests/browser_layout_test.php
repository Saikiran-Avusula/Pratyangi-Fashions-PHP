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
if (getenv('PF_TEST_DEMO_UI') === 'true') { // the preview screens (UI6)
    foreach (['checkout/address', 'checkout/payment', 'checkout/review', 'checkout/demo-confirmation', 'account/payments', 'account/membership', 'account/reviews', 'account/reviews/edit?id=1'] as $demoPath) {
        $pages[] = ['demo-' . str_replace(['/', '?', '='], '-', $demoPath), '/' . $demoPath, ''];
    }
    $pages[] = ['demo-card-dialog', '/account/payments', 'const b = w.document.querySelector("[data-demo-open=card]"); if (b) b.click()' /* signed out the page is the sign-in page: nothing to open */];
}

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
    if (hit && hit.closest('[class*="scrim"],[class*="ackdrop"],[role=dialog],.pSCCwa_menuPanel,.pf_cookie') && !el.closest('[class*="scrim"],[class*="ackdrop"],[role=dialog],.pSCCwa_menuPanel,.pf_cookie')) return null; // behind an open overlay (the page is pinned while it is open): not reachable now
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
  // header (stage UI7a): left menu + logo, right search / wishlist / bag / account; at least 16px between the logo and the icons at every width; the two counts sit in the same corner of their icons
  const hdr = d.querySelector('.pSCCwa_headerInner');
  if (hdr) {
    const box = s => { const e = hdr.querySelector(s); return e && e.checkVisibility() ? e.getBoundingClientRect() : null; };
    const menu = box('.pSCCwa_shopToggle'), brand = box('.pSCCwa_brand'), icons = box('.pSCCwa_headerActions');
    if (brand && menu && brand.left < menu.right - 0.5) out.push(['header', 'logo-before-menu', Math.round(brand.left) + ' < ' + Math.round(menu.right)]);
    if (brand && icons && icons.left - brand.right < 15.5) out.push(['header', 'logo-icons-gap', Math.round(icons.left - brand.right) + 'px at ' + W]);
    const corner = k => { const c = hdr.querySelector('[data-pf=' + k + '-count]'), a = c && c.parentElement; if (!c || !a || !c.checkVisibility()) return null; const r = c.getBoundingClientRect(), q = a.getBoundingClientRect(); return [Math.round(q.right - r.right), Math.round(r.top - q.top)]; };
    const cw = corner('wish'), cb = corner('bag');
    if (cw && cb && (Math.abs(cw[0] - cb[0]) > 1 || Math.abs(cw[1] - cb[1]) > 1)) out.push(['header', 'count-corners-differ', cw + ' vs ' + cb]);
  }
  return out;
}
const START = Number(new URLSearchParams(location.search).get('s') || 0);
// one event per page goes to the router (/__p), which writes it to a file the PHP side reads while Chrome is still running
const log = o => fetch('/__p', { method: 'POST', body: JSON.stringify(o) }).catch(() => {});
(async () => {
  const f = document.getElementById('f');
  localStorage.setItem('pratyangi-demo-bag', JSON.stringify([{ id: SKU, quantity: 2 }]));
  localStorage.setItem('pratyangi-demo-wishlist', JSON.stringify([SKU]));
  for (let i = START; i < PAGES.length; i++) {
    const [name, url, step] = PAGES[i], res = [];
    await log({ e: 'begin', i, n: name });
    const loaded = await Promise.race([new Promise(r => { f.onload = () => r(true); f.src = url; }), sleep(30000).then(() => false)]);
    if (!loaded) res.push([name, 'timeout', 'the page did not finish loading in 30 s', '']);
    else {
      await sleep(700);
      let ok = true;
      try { if (step) { new Function('w', step)(f.contentWindow); await sleep(500); } } catch (e) { res.push([name, 'step', String(e), '']); ok = false; }
      if (ok) {
        const signedIn = !!f.contentDocument.querySelector('.pSCCwa_accountLink');
        for (const [kind, s, info] of measure(f.contentWindow)) res.push([name, kind, s, info, signedIn]);
        res.push([name, 'seen', f.contentWindow.location.pathname, '', signedIn]);
      }
    }
    await log({ e: 'done', i, n: name, rows: res });
  }
  await log({ e: 'end' });
})();
JS;

/** Starts (or restarts, from page $start) the headless Chrome of one width. */
function layout_launch(array &$r, string $chrome, int $w, int $start): void
{
    $r['proc'] = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--virtual-time-budget=400000', '--window-size=' . max($w + 40, 520) . ',1000',
        '--user-data-dir=' . $r['dir'] . '/profile' . $start, '--dump-dom', 'http://127.0.0.1:' . $r['port'] . '/__w?s=' . $start], [['null'], ['file', $r['dir'] . '/out' . $start . '.txt', 'w'], ['file', $r['dir'] . '/err' . $start . '.log', 'w']], $pipes); // files, not pipes: a pipe read blocks on Windows and would freeze the watchdog
    $r['last'] = microtime(true);
}

/** Stops that Chrome and everything it started. */
function layout_kill(array &$r): void
{
    if (is_resource($r['proc'])) {
        $pid = (int) (proc_get_status($r['proc'])['pid'] ?? 0);
        @proc_terminate($r['proc']);
        if ($pid > 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
        }
        @proc_close($r['proc']);
    }
}

// Quick (tools\run_db.ps1 -Quick): widths 360, 390, 1024, 1440, signed in only. A page that sends no sign of life for 30 s is killed and reported by name, the run goes on
// with the next page; the whole run stops after 15 minutes. One progress line per page and width goes to the screen and to storage/logs/layout_test.log.
$quick = getenv('PF_LAYOUT_QUICK') === '1';
$runWidths = $quick ? [360, 390, 1024, 1440] : $widths;
$deadline = microtime(true) + 900;
$logFile = dirname(__DIR__) . '/storage/logs/layout_test.log';
@mkdir(dirname($logFile), 0777, true);
$say = static function (string $line) use ($logFile): void {
    echo $line . "\n";
    @file_put_contents($logFile, date('H:i:s') . ' ' . $line . "\n", FILE_APPEND);
};
$found = [];
$say('layout test: ' . ($quick ? 'QUICK ' : '') . 'widths ' . implode(',', $runWidths) . ', ' . count($pages) . ' pages, DEMO_UI=' . (getenv('PF_TEST_DEMO_UI') ?: 'false'));
foreach ($quick ? [true] : [false, true] as $signedIn) {
    $label = $signedIn ? 'in' : 'out';
    $runs = [];
    $port = 8200;
    foreach ($runWidths as $w) {
        $dir = $tmp . '/w' . $w . $label;
        mkdir($dir);
        file_put_contents($dir . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $w . 'px;height:900px;border:0;display:block"></iframe><script>const SKU=' . json_encode($sku) . ',PAGES=' . json_encode($pages) . ';' . $js . '</script>');
        BrowserRig::router($dir);
        $runs[$w] = ['dir' => $dir, 'port' => $port, 'proc' => null, 'seen' => 0, 'last' => 0.0, 'begin' => null, 'done' => false, 'finished' => false,
            'server' => BrowserRig::serve($dir . '/router.php', $port, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $signedIn ? $cookie : '', 'PF_CC_ALL' => '1'])];
        layout_launch($runs[$w], $chrome, $w, 0);
        $port++;
    }
    while (array_filter($runs, static fn (array $r): bool => !$r['done'])) {
        $expired = microtime(true) > $deadline;
        foreach ($runs as $w => &$r) {
            if ($r['done']) {
                continue;
            }
            if ($expired) {
                layout_kill($r);
                $r['done'] = true;
                $found['whole run|' . $w . '|' . $label . '|timeout|15 minute limit'] = 'the run was stopped after 15 minutes';
                $say('TIMEOUT whole run stopped at 15 minutes (width ' . $w . ')');
                continue;
            }
            $chunk = substr((string) @file_get_contents($r['dir'] . '/progress.txt'), $r['seen']);
            $nl = strrpos($chunk, "\n");
            if ($nl !== false) {
                $r['seen'] += $nl + 1;
                foreach (explode("\n", substr($chunk, 0, $nl)) as $line) {
                    $e = json_decode($line, true);
                    $now = microtime(true);
                    if (!is_array($e)) {
                        continue;
                    }
                    $r['last'] = $now;
                    if ($e['e'] === 'begin') {
                        $r['begin'] = [$e['i'], $e['n'], $now];
                    } elseif ($e['e'] === 'done') {
                        $n = 0;
                        foreach ($e['rows'] as $row) {
                            [$page, $kind, $selector, $info] = $row + [null, null, null, ''];
                            if ($kind === 'seen') {
                                // the signed-in run must really be signed in on the shop pages, the signed-out run must not be
                                if ($page === 'new-arrivals') {
                                    $t->same($signedIn, $row[4] ?? null, 'width ' . $w . ': signed-in state of the header');
                                }
                                continue;
                            }
                            $found[$page . '|' . $w . '|' . $label . '|' . $kind . '|' . $selector] = $info;
                            $n++;
                        }
                        $say(sprintf('%-26s %4dpx %-3s %6d ms  findings=%d', $e['n'], $w, $label, (int) (($now - ($r['begin'][2] ?? $now)) * 1000), $n));
                    } elseif ($e['e'] === 'end') {
                        $r['finished'] = true;
                    }
                }
            }
            $running = (bool) (proc_get_status($r['proc'])['running'] ?? false);
            if ($r['finished']) {
                layout_kill($r);
                $r['done'] = true;
                continue;
            }
            if (microtime(true) - $r['last'] > 30 || !$running) {
                // no sign of life for 30 s (or Chrome died): that page failed; start again after it
                $i = $r['begin'][0] ?? -1;
                $name = $r['begin'][1] ?? 'start of the run';
                $found[$name . '|' . $w . '|' . $label . '|timeout|no answer in 30 s'] = $running ? 'the page hung (no progress for 30 s)' : 'Chrome stopped while measuring this page';
                $say(sprintf('HANG %-22s %4dpx %-3s no progress for 30 s: killed, continuing with the next page', $name, $w, $label));
                layout_kill($r);
                if ($i + 1 >= count($pages)) {
                    $r['finished'] = true;
                    $r['done'] = true;
                } else {
                    $r['begin'] = null;
                    layout_launch($r, $chrome, $w, $i + 1);
                }
            }
        }
        unset($r);
        usleep(250000);
    }
    foreach ($runs as $w => $r) {
        $t->ok($r['finished'], 'width ' . $w . ' ' . $label . ': every page was measured');
        layout_kill($r);
        BrowserRig::stop($r['server']);
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

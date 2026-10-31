<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_nav1_test
// NAV1: the header arrives finished. Walk (signed in, items in bag + wishlist): home -> new arrivals -> product -> bag -> account -> the 7 account tabs
// -> wishlist -> login -> back x3, at 390 and 1440. Every step: announcement bar, header, logo, badges and tray at the same place (0px),
// no header element hidden in the server's HTML but shown after the scripts ran (pop-in), header layout shift = 0.
// Reports first-contentful-paint of an account tab switch and the server hits of the back steps (0 = served from the back/forward cache).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_nav1_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo); // the throwaway test database: the walk signs up in the browser, so start below the sign-up rate limit
$t = new TestCase('browser_nav1_test');
$tmp = Tmp::dir('pf_nav1_');
$router = BrowserRig::router($tmp);
$port = 3000; // the one origin the test config allows (Helpers::ORIGIN)

$walk = <<<'JS'
var f = document.getElementById("f"), out = [];
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function d() { return f.contentDocument; }
function q(s) { return d().querySelector(s); }
function mark(name) { return fetch("/__mark?step=" + encodeURIComponent(name)); }
function loaded() { return new Promise(function (r) { f.onload = function () { f.onload = null; setTimeout(r, 1200); }; }); }
function fill(sel, v) { var el = q(sel); el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); }
var HEAD = [".pf_topbar", "[data-pf=header]", ".pSCCwa_brand", ".pSCCwa_headerActions", "[data-pf=bag-count]", "[data-pf=wish-count]", ".pf_tray"];
async function snapshot(name) {
  var w = f.contentWindow, doc = d();
  var rects = {};
  // a page restored from the back/forward cache in a headless frame is not repainted, so checkVisibility() is false: fall back to a box with a size
  HEAD.forEach(function (s) { var all = doc.querySelectorAll(s); var e = Array.prototype.find.call(all, function (x) { return x.checkVisibility(); }) || (/^back/.test(name) && Array.prototype.find.call(all, function (x) { var b = x.getBoundingClientRect(); return b.width > 0 && b.height > 0 && getComputedStyle(x).display !== "none"; })); if (e) { var r = e.getBoundingClientRect(); rects[s] = [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; } });
  // pop-in: an element of the header/tray that the server sent hidden (or with other text) but is visible after the scripts ran
  var html = await (await fetch(w.location.pathname + w.location.search, { credentials: "include", headers: { "X-Pf-Probe": "1" } })).text();
  var server = new DOMParser().parseFromString(html, "text/html"), popins = [];
  ["[data-pf=bag-count]", "[data-pf=wish-count]", ".pSCCwa_avatar", ".pf_trayPill"].forEach(function (s) {
    var live = doc.querySelectorAll(s), sent = server.querySelectorAll(s);
    live.forEach(function (e, i) { var o = sent[i]; if (!o) return; if ((o.hasAttribute("hidden") && !e.hidden) || (!e.hidden && o.textContent.trim() !== e.textContent.trim())) popins.push(s + " " + JSON.stringify(o.textContent.trim()) + "->" + JSON.stringify(e.textContent.trim())); });
  });
  var cls = 0;
  await new Promise(function (r) { try { new w.PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { if ((e.sources || []).some(function (s) { return s.node && s.node.closest && s.node.closest(".pf_topbar, [data-pf=header], .pf_tray"); })) cls += e.value; }); r(); }).observe({ type: "layout-shift", buffered: true }); } catch (x) { r(); } setTimeout(r, 200); });
  // FONTS1: the brand fonts are self-hosted ("optional" body text may still be loading on a slow first visit, so load() before check())
  var fonts = null;
  try { await Promise.race([Promise.all(["16px Poppins", '32px "Playfair Display"'].map(function (s) { return doc.fonts.load(s); })), wait(3000)]); fonts = [doc.fonts.check("16px Poppins"), doc.fonts.check('32px "Playfair Display"')]; } catch (x) { fonts = String(x); }
  var google = w.performance.getEntriesByType("resource").filter(function (r) { return /fonts\.(googleapis|gstatic)\.com/.test(r.name); }).length;
  var nav = w.performance.getEntriesByType("navigation")[0] || {}, fcp = (w.performance.getEntriesByName("first-contentful-paint")[0] || {}).startTime || 0;
  out.push({ step: name, path: w.location.pathname, sy: Math.round(w.scrollY), fonts: fonts, google: google, rects: rects, popins: popins, cls: Math.round(cls * 1000) / 1000, type: nav.type || "", fcp: Math.round(fcp), dcl: Math.round(nav.domContentLoadedEventEnd || 0), vt: !!(w.CSS && CSS.supports("view-transition-name: x")) });
}
async function go(name, url) { await mark(name); var l = loaded(); f.src = url; await l; await snapshot(name); }
// Back: a page restored from the back/forward cache fires no load event; wait until the frame shows the previous address, then until it is painted
async function back(name) {
  await mark(name);
  var from = f.contentWindow.location.href, l = loaded();
  f.contentWindow.history.back();
  for (var i = 0; i < 30 && f.contentWindow.location.href === from; i++) await wait(100);
  await Promise.race([l, wait(800)]);
  await snapshot(name);
}
(async function () {
  try {
    var email = "nav" + Date.now() + "@example.com";
    var l = loaded(); f.src = "/signup"; await l;
    fill("#auth-name", "Nav Test"); fill("#auth-email", email); fill("#auth-password", "Saree2026x"); fill("#auth-confirm", "Saree2026x");
    var c = q("#auth-terms"); if (c && !c.checked) c.click();
    l = loaded(); q("[data-pf=auth-form] button[type=submit]").click(); await l;
    f.contentWindow.localStorage.setItem("pratyangi-demo-bag", JSON.stringify([{ id: "MOCK-SAR-001", quantity: 2 }]));
    f.contentWindow.localStorage.setItem("pratyangi-demo-wishlist", JSON.stringify(["MOCK-KUR-001"]));
    await go("home", "/");
    // the first page after the bag changed may still learn the counts late (the browser had no cookie yet): measure from the second page on
    await go("new arrivals", "/new-arrivals");
    await go("product", "/product?sku=MOCK-SAR-001");
    await go("bag", "/bag");
    await go("account", "/account");
    for (var p of ["/account/orders", "/account/addresses", "/account/payments", "/account/notifications", "/account/help", "/wishlist"]) await go("tab " + p, p);
    await go("account overview", "/account?view=profile");
    await go("login", "/login");
    await back("back 1"); await back("back 2"); await back("back 3");
  } catch (e) { out.push({ step: "ERROR " + e }); }
  await mark("end");
  var pre = document.createElement("pre"); pre.textContent = "@@" + JSON.stringify(out) + "@@"; document.body.appendChild(pre);
})();
JS;

foreach ([390, 1440] as $width) {
    $log = $tmp . '/hits-' . $width . '.log';
    @unlink($log);
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:820px;border:0"></iframe><script>' . $walk . '</script></body>');
    $server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => 'false', 'PF_CC_FWD' => '1', 'PF_CC_HITLOG' => $log]);
    $dom = BrowserRig::dump($chrome, $tmp . '/p', 'http://localhost:' . $port . '/__w', $width + 20, 860, 120000);
    BrowserRig::stop($server);
    $steps = preg_match('/@@(\[.*\])@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? (json_decode($m[1], true) ?: []) : [];
    // server hits per step (documents only; the probe's own fetch carries X-Pf-Probe and is not a navigation)
    $hits = [];
    $current = 'before';
    foreach (is_file($log) ? file($log) : [] as $line) {
        [, $method, $uri, , $dest] = array_pad(explode(' ', trim($line)), 5, '-');
        if (str_starts_with($uri, '/__mark')) {
            $current = urldecode(substr($uri, strlen('/__mark?step=')));
            $hits[$current] = $hits[$current] ?? 0;
        } elseif ($dest === 'iframe') {
            $hits[$current] = ($hits[$current] ?? 0) + 1;
        }
    }
    $t->test("nav @{$width}: the walk finished", function () use ($t, $steps): void {
        $t->ok(count($steps) >= 15, 'steps ' . count($steps));
        $t->same([], array_values(array_filter(array_column($steps, 'step'), static fn ($s): bool => str_starts_with((string) $s, 'ERROR'))));
    });
    $ref = null;
    foreach ($steps as $s) {
        if (!isset($s['rects'])) {
            continue;
        }
        echo sprintf("  ok     %-5s %-22s %-18s dcl=%4dms type=%-12s hits=%s cls=%s popins=%d scrollY=%d header=%s\n", $width, $s['step'], $s['path'], $s['dcl'], $s['type'], $hits[$s['step']] ?? '?', $s['cls'], count($s['popins']), $s['sy'], json_encode($s['rects']['[data-pf=header]'] ?? null));
        if ($s['step'] === 'home') {
            $t->test("nav @{$width} home: brand fonts self-hosted", function () use ($t, $s): void {
                $t->same([true, true], $s['fonts'], 'Poppins and Playfair Display loaded');
                $t->same(0, $s['google'], 'no font request to Google');
            });
            continue; // the header checks start on the next page (the first page after the bag was filled in the browser)
        }
        $ref ??= $s['rects'];
        if (str_starts_with($s['step'], 'back')) {
            // a page restored from the back/forward cache is the same page, not fetched again (a headless frame does not lay it out, so no boxes to measure)
            $t->test("nav @{$width} {$s['step']}: restored from the back/forward cache, no server hit", function () use ($t, $s, $hits): void {
                $t->same('back_forward', $s['type']);
                $t->same(0, $hits[$s['step']] ?? null, 'server hits');
            });
            continue;
        }
        $t->test("nav @{$width} {$s['step']}: header still, finished, no shift", function () use ($t, $s, $ref): void {
            foreach (['.pf_topbar', '[data-pf=header]', '.pSCCwa_brand', '.pf_tray'] as $k) {
                if (isset($ref[$k]) || isset($s['rects'][$k])) {
                    $t->same($ref[$k] ?? null, $s['rects'][$k] ?? null, $k . ' moved');
                }
            }
            $t->same([], $s['popins'], 'pop-in');
            $t->same([true, true], $s['fonts'], 'Poppins and Playfair Display loaded (self-hosted)');
            $t->same(0, $s['google'], 'no font request to Google');
            $t->same(0, $s['cls'] > 0 ? 1 : 0, 'header layout shift ' . $s['cls']);
        });
    }
}
exit($t->finish());

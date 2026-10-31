<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_requests_test
// One user action = ONE request: walks the main flows at 390 and 1440 (sign up, home, tray / header tabs, listing, quick view, add to bag, wishlist,
// product, search typing, bag qty +/-, account tabs, address save, logout, login). The router logs every request the server sees (PF_CC_HITLOG);
// each step is fenced by a /__mark request. A step fails when the same method + URL reaches the server twice (pictures, CSS and scripts aside).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_requests_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo); // the throwaway test database: the walk signs up in the browser, so start below the sign-up rate limit
$t = new TestCase('browser_requests_test');
$tmp = Tmp::dir('pf_req_');
$router = BrowserRig::router($tmp);
$port = 3000; // the one origin the test config allows (Helpers::ORIGIN)

$walk = <<<'JS'
var f = document.getElementById("f"), steps = [];
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function d() { return f.contentDocument; }
function q(s) { return d().querySelector(s); }
function vis(s) { return Array.prototype.find.call(d().querySelectorAll(s), function (e) { return e.checkVisibility(); }); }
function mark(name) { steps.push(name); return fetch("/__mark?step=" + encodeURIComponent(name)); }
function loaded() { return new Promise(function (r) { f.onload = function () { f.onload = null; setTimeout(r, 900); }; }); }
async function step(name, action, navigates) { await mark(name); var l = navigates ? loaded() : null; try { action(); } catch (e) { steps.push("ERROR " + name + ": " + e); } if (l) await Promise.race([l, wait(6000)]); else await wait(900); }
function fill(sel, v) { var el = q(sel); el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); }
// a real tap / mouse: pointer down, touch, hover, then click (what a phone or mouse sends before a navigation)
function tap(el) { ["pointerdown", "touchstart", "mouseenter", "mouseover", "pointerup"].forEach(function (t) { el.dispatchEvent(new Event(t, { bubbles: t !== "mouseenter" })); }); el.click(); }
(async function () {
  var phone = innerWidth < 600, email = "req" + Date.now() + "@example.com";
  await step("load signup", function () { f.src = "/signup"; }, true);
  await step("sign up", function () { fill("#auth-name", "Req Test"); fill("#auth-email", email); fill("#auth-password", "Saree2026x"); fill("#auth-confirm", "Saree2026x"); var c = q("#auth-terms"); if (c && !c.checked) c.click(); q("[data-pf=auth-form] button[type=submit]").click(); }, true);
  await step("home", function () { f.src = "/"; }, true);
  await step("tab wishlist", function () { tap(vis(phone ? ".pf_tray a[href='/wishlist']" : ".pSCCwa_headerActions a[href='/wishlist']")); }, true);
  await step("tab bag", function () { tap(vis(phone ? ".pf_tray a[href='/bag']" : ".pSCCwa_headerActions a[href='/bag']")); }, true);
  await step("tab home", function () { tap(vis(phone ? ".pf_tray a[href='/']" : "a.pSCCwa_brand")); }, true);
  await step("listing", function () { f.src = "/new-arrivals"; }, true);
  await step("quick view", function () { q("[data-qv-sku='MOCK-SAR-001'] [data-pf=quick-view]").click(); }, false);
  await step("add to bag (quick view)", function () { q(".pf_qv [data-pf=add-to-bag]").click(); q(".pf_qv_close").click(); }, false);
  await step("wishlist add", function () { q("[data-qv-sku='MOCK-SAR-001'] [data-pf=wish]").click(); }, false);
  await step("wishlist remove", function () { q("[data-qv-sku='MOCK-SAR-001'] [data-pf=wish]").click(); }, false);
  await step("product", function () { tap(q("[data-qv-sku='MOCK-SAR-001'] a")); }, true);
  await step("search typing", async function () { q("[data-pf=search-open]").click(); var i = q("#catalog-search"); for (var c of "silk") { i.value += c; i.dispatchEvent(new Event("input", { bubbles: true })); await wait(120); } }, false);
  await wait(800);
  await step("bag", function () { f.src = "/bag"; }, true);
  await wait(600);
  await step("qty +", function () { vis(".BpPmcW_quantity button[aria-label='Increase quantity']").click(); }, false);
  await step("qty -", function () { vis(".BpPmcW_quantity button[aria-label='Decrease quantity']").click(); }, false);
  await step("account", function () { tap(vis(phone ? ".pf_tray a[href='/account']" : ".pSCCwa_headerActions a[href='/account']")); }, true);
  await step("account orders", function () { tap(vis("a[href='/account/orders']")); }, true);
  // a phone's account sub-pages have a back arrow, not the tabs: go back to the menu first
  if (phone) await step("account menu", function () { tap(vis("a.pf_acctBack")); }, true);
  await step("account addresses", function () { tap(vis("a[href='/account/addresses']")); }, true);
  await step("address save", function () {
    q("[data-pf-open-address]").click();
    [["name", "Req Test"], ["phone", "9876543210"], ["house", "12"], ["street", "MG Road"], ["city", "Hyderabad"], ["state", "Telangana"], ["pincode", "500081"]].forEach(function (p) { var el = q("[data-pf-field=" + p[0] + "]"); if (el) { if (el.tagName === "SELECT") { el.value = el.options[1] ? el.options[1].value : p[1]; } else el.value = p[1]; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); } });
    var save = q("[data-pf-modal-save]"); save.click(); save.click(); // a double tap must still send ONE request
  }, true);
  if (phone) await step("account menu again", function () { tap(vis("a.pf_acctBack")); }, true);
  await step("logout", function () { var b = vis("[data-pf=logout]"); b.click(); b.click(); }, true); // a double tap must still send ONE request
  await step("load login", function () { f.src = "/login"; }, true);
  await step("login", function () { fill("#auth-identifier", email); fill("#auth-password", "Saree2026x"); q("[data-pf=auth-form] button[type=submit]").click(); }, true);
  await mark("end");
  var out = document.createElement("pre"); out.textContent = "@@" + JSON.stringify({ steps: steps, path: f.contentWindow.location.pathname }) + "@@"; document.body.appendChild(out);
})();
JS;

$results = [];
foreach ([390, 1440] as $width) {
    $log = $tmp . '/hits-' . $width . '.log';
    @unlink($log);
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:820px;border:0"></iframe><script>' . $walk . '</script></body>');
    $server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => 'false', 'PF_CC_FWD' => '1', 'PF_CC_HITLOG' => $log]);
    $dom = BrowserRig::dump($chrome, $tmp . '/p', 'http://localhost:' . $port . '/__w', $width + 20, 860, 120000);
    BrowserRig::stop($server);
    $page = preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? json_decode($m[1], true) : null;
    // group the server's hits by step
    $byStep = [];
    $current = 'before';
    foreach (file(is_file($log) ? $log : 'php://memory') ?: [] as $line) {
        [, $method, $uri, $purpose, $dest] = array_pad(explode(' ', trim($line)), 5, '-');
        if (str_starts_with($uri, '/__mark')) {
            $current = urldecode(substr($uri, strlen('/__mark?step=')));
            continue;
        }
        if (str_starts_with($uri, '/__') || preg_match('#^/(assets|uploads|favicon|icon-|apple-touch)#', $uri) === 1 || preg_match('/\.(png|webp|jpe?g|svg|ico|css|js|woff2?)(\?|$)/', $uri) === 1) {
            continue;
        }
        $byStep[$current][] = $method . ' ' . $uri . ($purpose !== '-' ? ' [' . $purpose . ']' : '') . ' (' . $dest . ')';
    }
    $results[$width] = ['page' => $page, 'steps' => $byStep];
    $t->test("walk @{$width}: every step finished", function () use ($t, $page): void {
        $t->ok(is_array($page), 'the walk reported back');
        $errors = array_values(array_filter($page['steps'] ?? [], static fn (string $s): bool => str_starts_with($s, 'ERROR')));
        $t->same([], $errors);
        $t->same('/', $page['path'] ?? '', 'signed in again at the end');
    });
    foreach ($byStep as $name => $hits) {
        if ($name === 'before' || $name === 'end') {
            continue;
        }
        $keys = array_map(static fn (string $h): string => preg_replace('/ \[.*$| \(.*$/', '', $h), $hits);
        $counts = array_count_values($keys);
        $dupes = array_filter($counts, static fn (int $n): bool => $n > 1);
        echo sprintf("  ok     %-5s %-24s %d request(s)%s\n", $width, $name, count($hits), $hits === [] ? '' : ': ' . implode(', ', $hits));
        $t->test("@{$width} {$name}: one request per URL", function () use ($t, $dupes, $hits): void {
            $t->same([], $dupes, implode(' | ', $hits));
        });
    }
}
exit($t->finish());

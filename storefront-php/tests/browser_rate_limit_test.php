<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_rate_limit_test
// In headless Chrome, on the real /login page with fetch answered by a 429 (Retry-After 4): the countdown line shows, the button is off, a
// reload keeps the wait, and when it ends the button is back with "You can try again now".
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_rate_limit_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('browser_rate_limit_test');
$tmp = sys_get_temp_dir() . '/pf_rl_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);
file_put_contents($tmp . '/wrap.html', '<!doctype html><body><pre id="out">running</pre><iframe id="f" style="width:900px;height:900px"></iframe><script>
var out = document.getElementById("out"), f = document.getElementById("f"), log = [];
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function load(url) { return new Promise(function (r) { f.onload = function () { r(); }; f.src = url; }); }
function fill(id, v) { var el = f.contentDocument.getElementById(id); el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); }
function btn() { return f.contentDocument.querySelector("[data-pf=auth-form] button[type=submit]"); }
function line() { var p = f.contentDocument.querySelector("p.pf_lock"); return p ? p.textContent : "none"; }
(async function () {
  try {
    await load("/login"); await wait(300);
    f.contentWindow.fetch = function () {
      return Promise.resolve(new Response(JSON.stringify({ message: "Too many attempts. Please wait a few minutes and try again.", retryAfter: 4 }), { status: 429, headers: { "Content-Type": "application/json", "Retry-After": "4" } }));
    };
    fill("auth-identifier", "someone@example.com"); fill("auth-password", "Saree2026x");
    btn().click(); await wait(600);
    log.push("during=" + line() + " disabled=" + btn().disabled);
    await load("/login"); await wait(600);
    log.push("reload=" + line() + " disabled=" + btn().disabled);
    await wait(4500);
    log.push("after=" + line() + " disabled=" + btn().disabled);
  } catch (e) { log.push("script error " + e); }
  out.textContent = "RESULT " + log.join(" ;; ") + " END";
})();
</script></body>');
$port = 3000; // the one origin the test config allows (Helpers::ORIGIN)
$server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => 'false', 'PF_CC_FWD' => '1']);
$dom = BrowserRig::dump($chrome, $tmp . '/profile', 'http://localhost:' . $port . '/__w', 1000, 1000, 20000);
BrowserRig::stop($server);
preg_match('/RESULT (.*?) END/s', $dom, $m);
$result = html_entity_decode($m[1] ?? 'no result');
$t->test('429: live countdown, button off, survives a reload, button back at the end', function () use ($t, $result): void {
    $t->contains('during=Too many attempts. Try again in 0:0', $result, $result);
    $t->contains('disabled=true', $result, $result);
    $t->contains('reload=Too many attempts. Try again in 0:0', $result, $result);
    $t->contains('after=You can try again now disabled=false', $result, $result);
});
exit($t->finish());

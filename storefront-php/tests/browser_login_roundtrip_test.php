<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_login_roundtrip_test
// In headless Chrome, on the real pages: sign up a NEW account, sign out, sign in again with the same email and password. The sign-in must not show an error.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_login_roundtrip_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('browser_login_roundtrip_test');
$tmp = sys_get_temp_dir() . '/pf_rt_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);
$email = 'Round.Trip' . bin2hex(random_bytes(2)) . '@Example.com'; // mixed case on purpose: the form lower-cases it
file_put_contents($tmp . '/wrap.html', '<!doctype html><body><pre id="out">running</pre><iframe id="f" style="width:900px;height:900px"></iframe><script>
var out = document.getElementById("out"), f = document.getElementById("f"), log = [];
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function load(url) { return new Promise(function (r) { f.onload = function () { r(); }; f.src = url; }); }
function fill(id, v) { var el = f.contentDocument.getElementById(id); el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); }
function check(id) { var el = f.contentDocument.getElementById(id); if (el && !el.checked) el.click(); }
function submit() { f.contentDocument.querySelector("[data-pf=auth-form] button[type=submit]").click(); }
(async function () {
  try {
    await load("/signup"); await wait(300);
    fill("auth-name", "Round Trip"); fill("auth-email", ' . json_encode($email) . '); fill("auth-password", "Saree2026x"); fill("auth-confirm", "Saree2026x"); check("auth-terms");
    submit(); await wait(2500);
    log.push("after signup path=" + f.contentWindow.location.pathname);
    var lo = await fetch("/api/v1/auth/logout", { method: "POST" }); log.push("logout=" + lo.status);
    await load("/login"); await wait(300);
    fill("auth-identifier", ' . json_encode($email) . '); fill("auth-password", "Saree2026x");
    submit(); await wait(2500);
    var d = f.contentDocument;
    log.push("after login path=" + f.contentWindow.location.pathname);
    log.push("errors=" + Array.prototype.map.call(d.querySelectorAll(".neurva_error, [role=alert]"), function (e) { return e.textContent.trim(); }).filter(Boolean).join(" | "));
  } catch (e) { log.push("script error " + e); }
  out.textContent = "RESULT " + log.join(" ;; ") + " END";
})();
</script></body>');
$port = 3000; // the one origin the test config allows (Helpers::ORIGIN)
$server = BrowserRig::serve($router, $port, ['PF_CC_SMS' => 'false', 'PF_CC_FWD' => '1']);
$dom = BrowserRig::dump($chrome, $tmp . '/profile', 'http://localhost:' . $port . '/__w', 1000, 1000, 15000);
BrowserRig::stop($server);
preg_match('/RESULT (.*?) END/s', $dom, $m);
$result = $m[1] ?? 'no result';
$t->test('new account: sign up, sign out, sign in again', function () use ($t, $result): void {
    $t->contains('after login path=/', $result, $result);
    $t->ok(!str_contains($result, 'after login path=/login'), 'sign-in left /login: ' . $result);
    $t->ok(str_ends_with($result, 'errors='), 'no error text after sign-in: ' . $result);
});
exit($t->finish());

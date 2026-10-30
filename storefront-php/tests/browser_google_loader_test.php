<?php
declare(strict_types=1);

// NEEDS MySQL (the browser rig) and Google Chrome. Run: tools\run_db.ps1 -Only browser_google_loader_test
// The "Signing you in…" screen of Continue with Google, with Google's script replaced by a fake one served from this site (GOOGLE_SCRIPT_PATH, tests only):
// the fake hands the page a credential, and answers POST /auth/google with a success or an error. The loader must cover the page from the credential until the
// page changes, stay single however often the callback fires, pin the page, and on an error go away and show the message.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_google_loader_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_google_loader_test');
$tmp = sys_get_temp_dir() . '/pf_gloader_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);

file_put_contents($tmp . '/gsi.js', <<<'JS'
(function () {
  var real = window.fetch;
  window.fetch = function (url, options) {
    if (String(url).indexOf('/auth/google') === -1) { return real.apply(this, arguments); }
    var ok = window.__gsiMode === 'ok';
    var body = ok ? { user: { id: '1', name: 'Test Buyer', email: 'test@example.com', provider: 'google', emailVerified: true, createdAt: '2026-01-01T00:00:00Z' } } : { message: 'Google sign-in did not work. Please try again.' };
    return new Promise(function (resolve) { setTimeout(function () { resolve(new Response(JSON.stringify(body), { status: ok ? 200 : 401, headers: { 'Content-Type': 'application/json' } })); }, 120); });
  };
  window.google = { accounts: { id: { initialize: function (o) { window.__gsiCb = o.callback; }, renderButton: function () {} } } };
})();
JS);
$js = <<<'JS'
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const f = document.getElementById('f'), out = {};
  await new Promise(r => { f.onload = () => r(); f.src = '/login'; });
  await sleep(1500);
  const w = f.contentWindow, d = f.contentDocument;
  out.ready = typeof w.__gsiCb === 'function';
  w.__gsiMode = 'err';
  w.__gsiCb({ credential: 'fake' });
  w.__gsiCb({ credential: 'fake' }); // a second tap while it is busy
  out.errLoaders = d.querySelectorAll('.pf_signing').length;
  out.errText = d.querySelector('.pf_signing p') ? d.querySelector('.pf_signing p').textContent : '';
  out.errPinned = d.body.style.position === 'fixed';
  await sleep(500);
  const msg = d.querySelector('[data-pf=google-error]');
  out.errGone = d.querySelectorAll('.pf_signing').length === 0;
  out.errShown = !msg.hidden && msg.textContent.indexOf('did not work') !== -1;
  out.errUnpinned = d.body.style.position === '';
  w.__gsiMode = 'ok';
  w.__gsiCb({ credential: 'fake' });
  await sleep(500);
  out.okLoaders = d.querySelectorAll('.pf_signing').length;
  out.okPinned = d.body.style.position === 'fixed';
  document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify(out) + '@@';
})();
JS;
file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:1000px;height:900px;border:0"></iframe><script>' . $js . '</script>');
$server = BrowserRig::serve($router, 8290, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '1', 'PF_CC_GCLIENT' => 'test-client-12345.apps.googleusercontent.com', 'PF_CC_GSCRIPT' => '/__gsi.js']);
$dom = BrowserRig::dump($chrome, $tmp . '/profile', 'http://127.0.0.1:8290/__w', 1100, 900, 30000);
BrowserRig::stop($server);
$r = preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? json_decode($m[1], true) : null;
$t->test('Continue with Google: the loader shows from the credential on, stays single, and an error removes it and shows the message', function () use ($t, $r): void {
    $t->ok(is_array($r), 'Chrome returned its findings');
    $t->same(true, $r['ready'], 'the page used the injected sign-in script');
    $t->same([1, 'Signing you in…', true], [$r['errLoaders'], $r['errText'], $r['errPinned']], 'error case: one full-screen loader, the page pinned (two taps = one loader)');
    $t->same([true, true, true], [$r['errGone'], $r['errShown'], $r['errUnpinned']], 'error case: the loader goes, the message shows, the page is released');
    $t->same([1, true], [$r['okLoaders'], $r['okPinned']], 'success case: the loader stays until the page changes');
});
exit($t->finish());

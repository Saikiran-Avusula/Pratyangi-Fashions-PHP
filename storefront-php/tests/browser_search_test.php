<?php
declare(strict_types=1);

// NEEDS MySQL (the browser rig) and Google Chrome. Run: tools\run_db.ps1 -Only browser_search_test
// The header search and the announcement bar in a real browser: the box opens and pins the page, the placeholder rotates until the visitor types, the
// arrow keys walk the suggestions (ArrowUp from the first one goes back to the box), Escape closes and unpins, a search is remembered in this browser,
// the top bar rotates every 4 s, and with "reduce motion" it stays on the first message.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_search_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_search_test');
$tmp = sys_get_temp_dir() . '/pf_search_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);

$common = <<<'JS'
const sleep = ms => new Promise(r => setTimeout(r, ms));
const show = o => { document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify(o) + '@@'; };
JS;
$full = $common . <<<'JS'
(async () => {
  const f = document.getElementById('f'), out = {};
  await new Promise(r => { f.onload = () => r(); f.src = '/'; });
  await sleep(800);
  const w = f.contentWindow, d = f.contentDocument;
  try { w.localStorage.removeItem('pf_recent_search'); } catch (e) { /* none */ }
  const ann = [...d.querySelectorAll('[data-pf=announce] p')];
  out.annCount = ann.length;
  out.annJs = d.querySelector('[data-pf=announce]').classList.contains('pf_annJs');
  out.annFirst = ann[0].classList.contains('pf_annOn');
  await sleep(4800);
  out.annSecond = ann[1].classList.contains('pf_annOn') && !ann[0].classList.contains('pf_annOn');
  d.querySelector('[data-pf=search-open]').click();
  await sleep(300);
  const input = d.querySelector('#catalog-search');
  out.open = !d.querySelector('[data-pf=search-scrim]').hidden;
  out.pinned = d.body.style.position === 'fixed';
  const ph0 = input.placeholder;
  await sleep(3600);
  out.phRotates = input.placeholder !== ph0;
  const typeVal = v => { input.value = v; input.dispatchEvent(new w.Event('input', { bubbles: true })); };
  typeVal('ban');
  const ph1 = input.placeholder;
  await sleep(3600);
  out.phStops = input.placeholder === ph1;
  typeVal('saree');
  await sleep(1400);
  const items = () => [...d.querySelectorAll('.pf_sitem')].filter(n => n.offsetParent !== null);
  const key = k => w.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true }));
  out.items = items().length;
  key('ArrowDown'); out.down1 = d.activeElement === items()[0];
  key('ArrowDown'); out.down2 = d.activeElement === items()[1];
  key('ArrowUp'); out.up1 = d.activeElement === items()[0];
  key('ArrowUp'); out.upToBox = d.activeElement === input;
  key('Escape');
  await sleep(100);
  out.closed = d.querySelector('[data-pf=search-scrim]').hidden;
  out.unpinned = d.body.style.position === '';
  d.querySelector('[data-pf=search-open]').click();
  await sleep(200);
  typeVal('banarasi');
  d.querySelector('[data-pf=search-form]').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  await sleep(300);
  out.recent = f.contentWindow.localStorage.getItem('pf_recent_search');
  show(out);
})();
JS;
$calm = $common . <<<'JS'
(async () => {
  const f = document.getElementById('f'), out = {};
  await new Promise(r => { f.onload = () => r(); f.src = '/'; });
  await sleep(6000);
  const d = f.contentDocument, ann = [...d.querySelectorAll('[data-pf=announce] p')];
  out.js = d.querySelector('[data-pf=announce]').classList.contains('pf_annJs');
  out.opacity = ann.map(p => d.defaultView.getComputedStyle(p).opacity);
  show(out);
})();
JS;

$run = static function (string $script, array $extra) use ($chrome, $router, $tmp): ?array {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:1000px;height:900px;border:0"></iframe><script>' . $script . '</script>');
    $server = BrowserRig::serve($router, 8280, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '1']);
    $dom = BrowserRig::dump($chrome, $tmp . '/profile' . bin2hex(random_bytes(2)), 'http://127.0.0.1:8280/__w', 1100, 900, 60000, $extra);
    BrowserRig::stop($server);
    return preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? json_decode($m[1], true) : null;
};

$r = $run($full, []);
$t->test('search and announcement bar in Chrome', function () use ($t, $r): void {
    $t->ok(is_array($r), 'Chrome returned its findings');
    $t->same(3, $r['annCount'], 'three default messages');
    $t->same(true, $r['annJs'] && $r['annFirst'], 'the first message is on');
    $t->same(true, $r['annSecond'], 'after 4 s the second message replaces it');
    $t->same(true, $r['open'], 'the search opens');
    $t->same(true, $r['pinned'], 'the page behind it is pinned');
    $t->same(true, $r['phRotates'], 'the placeholder rotates while the box is empty');
    $t->same(true, $r['phStops'], 'the placeholder stops once the visitor types');
    $t->ok($r['items'] >= 2, 'there are suggestions to walk through');
    $t->same([true, true, true, true], [$r['down1'], $r['down2'], $r['up1'], $r['upToBox']], 'ArrowDown, ArrowDown, ArrowUp, ArrowUp: first, second, first, back to the box');
    $t->same([true, true], [$r['closed'], $r['unpinned']], 'Escape closes the search and releases the page');
    $t->same('["banarasi"]', $r['recent'], 'the search is remembered in this browser');
});

$c = $run($calm, ['--force-prefers-reduced-motion']);
$t->test('reduced motion: the top bar stays on its first message', function () use ($t, $c): void {
    $t->ok(is_array($c), 'Chrome returned its findings');
    $t->same(false, $c['js'], 'no rotation script runs');
    $t->same(['1', '0', '0'], $c['opacity'], 'only the first message is visible');
});
exit($t->finish());

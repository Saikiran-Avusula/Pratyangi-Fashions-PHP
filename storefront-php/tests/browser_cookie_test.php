<?php
declare(strict_types=1);

// NEEDS MySQL (see tests/lib/DbGuard.php) and Google Chrome. Run: tools\run_db.ps1 -Only browser_cookie_test
// The cookie notice in a real browser: first visit, Accept, Manage + Save, the footer link, the optional-script gate, and (at 390 px) that the bar never
// covers the WhatsApp button or the sticky Add to bag button. The page is shown in an iframe of the exact width (a headless window cannot be that narrow).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_cookie_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('browser_cookie_test');
$tmp = Tmp::dir('pf_cookie_');
$router = BrowserRig::router($tmp);

$js = <<<'JS'
const sleep = ms => new Promise(r => setTimeout(r, ms));
const f = document.getElementById('f'), log = [];
const load = url => new Promise(r => { f.onload = () => r(); f.src = url; }).then(() => sleep(600));
const ok = (name, value) => log.push([name, !!value]);
const rect = el => el.getBoundingClientRect();
const overlap = (a, b) => !(a.right <= b.left || b.right <= a.left || a.bottom <= b.top || b.bottom <= a.top);
(async () => {
  await load('/');
  let d = f.contentDocument, w = f.contentWindow;
  const q = s => d.querySelector(s);
  ok('first visit: the bar is shown', !q('[data-pf=cookie-bar]').hidden);
  ok('first visit: no consent cookie yet', !/pf_consent/.test(d.cookie));
  ok('the bar says what it should', /We use essential cookies to run this site\./.test(q('[data-pf=cookie-bar]').textContent));
  ok('the panel starts closed', q('[data-pf=cookie-panel]').hidden);
  w.PratyangiConsent.loadScript('analytics', '/assets/not-there.js');
  ok('an analytics script is held back before consent', !d.head.querySelector('script[src="/assets/not-there.js"]'));
  ok('analytics is not allowed yet', w.PratyangiConsent.allowed('analytics') === false);
  q('[data-pf=cookie-manage]').click();
  ok('Manage opens the panel with three rows', !q('[data-pf=cookie-panel]').hidden && d.querySelectorAll('[data-pf=cookie-panel] input[type=checkbox]').length === 3);
  ok('Necessary is on and locked, Analytics and Marketing are off', q('[data-pf=cookie-panel] input:disabled').checked && !q('[data-pf=cookie-analytics]').checked && !q('[data-pf=cookie-marketing]').checked);
  q('[data-pf=cookie-analytics]').checked = true;
  q('[data-pf=cookie-save]').click();
  await sleep(300);
  ok('Save: the cookie holds the choice', /pf_consent=v1\.a1\.m0/.test(d.cookie));
  ok('Save: the bar is gone', q('[data-pf=cookie-bar]').hidden);
  ok('Save: the held-back analytics script is loaded now', !!d.head.querySelector('script[src="/assets/not-there.js"]'));
  ok('Save: allowed(analytics) is true, allowed(marketing) is false', w.PratyangiConsent.allowed('analytics') === true && w.PratyangiConsent.allowed('marketing') === false);
  await load('/new-arrivals');
  d = f.contentDocument; w = f.contentWindow;
  ok('second visit, another page: no bar', d.querySelector('[data-pf=cookie-bar]').hidden);
  d.querySelector('[data-pf=cookie-settings]').click();
  const bar = d.querySelector('[data-pf=cookie-bar]');
  ok('the footer link reopens the bar on the Manage panel with the saved choice', !bar.hidden && !d.querySelector('[data-pf=cookie-panel]').hidden && d.querySelector('[data-pf=cookie-analytics]').checked);
  ok('keyboard: focus moves into the bar', bar.contains(d.activeElement));
  d.querySelector('[data-pf=cookie-accept]').click();
  await sleep(300);
  ok('Accept: essential only (a0.m0) and the bar closes', /pf_consent=v1\.a0\.m0/.test(d.cookie) && bar.hidden);
  ok('Accept: focus goes back to the footer link', d.activeElement === d.querySelector('[data-pf=cookie-settings]'));
  // phones: the bar must not cover the WhatsApp button or the sticky buy button
  d.cookie = 'pf_consent=; Max-Age=0; Path=/';
  await load('/product?sku=' + SKU);
  d = f.contentDocument; w = f.contentWindow;
  const b2 = d.querySelector('[data-pf=cookie-bar]'), wa = d.querySelector('.pf_wa'), buy = d.querySelector('.udK_nG_add:not(.pf_wishText)');
  ok('product page: the bar is shown again after the cookie is removed', !b2.hidden);
  ok('the bar does not overlap the WhatsApp button', wa && !overlap(rect(b2), rect(wa)));
  if (buy && w.innerWidth < 768) ok('the bar does not overlap the sticky Add to bag button', !overlap(rect(b2), rect(buy)));
  ok('the bar buttons are at least 44px', Array.from(b2.querySelectorAll('button')).filter(b => b.offsetParent).every(b => rect(b).height >= 43.5 && rect(b).width >= 43.5));
  ok('the bar stays inside the screen', rect(b2).left >= 0 && rect(b2).right <= w.innerWidth + 0.5);
  document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify(log) + '@@';
})();
JS;

foreach ([390, 1440] as $i => $width) {
    $dir = $tmp . '/w' . $width;
    mkdir($dir);
    file_put_contents($dir . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:' . $width . 'px;height:800px;border:0;display:block"></iframe><script>const SKU="MOCK-SAR-001";' . $js . '</script>');
    BrowserRig::router($dir);
    $server = BrowserRig::serve($dir . '/router.php', 8470 + $i, []);
    $p = proc_open([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--virtual-time-budget=60000', '--window-size=' . max($width + 40, 520) . ',900',
        '--user-data-dir=' . Tmp::profile(), '--dump-dom', 'http://127.0.0.1:' . (8470 + $i) . '/__w'], [['null'], ['pipe', 'w'], ['file', $dir . '/err.log', 'w']], $pipes);
    Tmp::track($p);
    $dom = (string) stream_get_contents($pipes[1]);
    Tmp::kill($p);
    BrowserRig::stop($server);
    $rows = preg_match('/@@(\[.*\])@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1 ? json_decode($m[1], true) : null;
    $t->test('the cookie notice at ' . $width . ' px', function () use ($t, $rows): void {
        $t->ok(is_array($rows) && count($rows) > 15, 'the test page returned its results');
        foreach ($rows ?? [] as [$name, $passed]) {
            $t->ok($passed, $name);
        }
    });
}
exit($t->finish());

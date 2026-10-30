<?php
declare(strict_types=1);

// NEEDS MySQL (tests/lib/DbGuard.php, the router reuses the browser rig) and Google Chrome. Run: tools\run_db.ps1 -Only browser_empty_state_test
// An empty bag shows the shared empty state and NOTHING else: no order summary, no coupon, no recommendations, no promises. Chrome opens /bag with an empty
// browser bag (fresh profile) and lists every visible piece of text that is outside the empty state and outside the site's header, footer, cookie bar and
// floating button. The list must be empty.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';
require __DIR__ . '/lib/BrowserRig.php';

$chrome = BrowserRig::chrome();
if ($chrome === null) {
    echo "SKIP browser_empty_state_test: Chrome not found\n";
    exit(0);
}
$pdo = DbGuard::connect();
$t = new TestCase('browser_empty_state_test');
$tmp = sys_get_temp_dir() . '/pf_empty_' . bin2hex(random_bytes(4));
mkdir($tmp);
$router = BrowserRig::router($tmp);

$js = <<<'JS'
const f = document.getElementById('f');
f.onload = () => setTimeout(() => {
  const d = f.contentDocument, main = d.querySelector('main');
  const empties = [...d.querySelectorAll('.pf_emptyState')].filter(e => e.checkVisibility());
  const skip = 'header, footer, nav, .pf_topbar, .pf_cookie, .pf_wa, .pf_emptyState, script, style, [data-pf="search-scrim"], [data-pf="menu-panel"]';
  const stray = [];
  for (const e of main.querySelectorAll('*')) {
    if (e.closest(skip) || !e.checkVisibility()) continue;
    const own = [...e.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('');
    if (own) stray.push(e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ': ' + own.slice(0, 40));
  }
  document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify({ empties: empties.length, stray }) + '@@';
}, 2500);
f.src = PAGE;
JS;
foreach (['/bag' => 'the bag'] as $page => $label) {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:1000px;height:900px;border:0"></iframe><script>const PAGE=' . json_encode($page) . ';' . $js . '</script>');
    $server = BrowserRig::serve($router, 8270, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => '', 'PF_CC_ALL' => '1']);
    $dom = BrowserRig::dump($chrome, $tmp . '/profile', 'http://127.0.0.1:8270/__w', 1100, 900, 20000);
    BrowserRig::stop($server);
    $ok = preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1;
    $r = $ok ? json_decode($m[1], true) : ['empties' => -1, 'stray' => ['no answer from Chrome']];
    $t->test($label . ': exactly one empty state, and nothing else renders beside it', function () use ($t, $r): void {
        $t->same(1, $r['empties'], 'one visible empty state');
        $t->same([], $r['stray'], 'nothing else beside it');
    });
}
exit($t->finish());

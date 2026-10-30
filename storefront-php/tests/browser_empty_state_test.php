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

use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\LogMailer;

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

// a signed-in customer without orders or addresses, for the account pages
$clock = new FixedClock((float) time());
$setup = Helpers::app(Helpers::config(['MAIL_LOG_PATH' => $tmp . '/setup.log', 'OTP_PEPPER' => str_repeat('S', 48)]), $clock, new DbRateLimitStore(static fn (): \PDO => $pdo), null, new LogMailer($tmp . '/setup.log', $clock), static fn (): \PDO => $pdo);
$signup = $setup->handle(Helpers::request('POST', '/api/v1/auth/signup', ['Origin' => Helpers::ORIGIN], ['name' => 'Empty Check', 'email' => 'empty' . bin2hex(random_bytes(3)) . '@example.com', 'password' => 'Saree2026']));
$cookie = preg_match('/^pf_session=([^;]*);/', $signup->cookies()[0] ?? '', $m) === 1 ? rawurldecode($m[1]) : '';

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
  const text = main.innerText, banned = ['Order Summary', 'Coupon', 'Subtotal', 'You May Also Like', 'Total Amount'].filter(w => text.includes(w));
  document.body.appendChild(document.createElement('pre')).textContent = '@@' + JSON.stringify({ empties: empties.length, stray, banned }) + '@@';
}, 2500);
f.src = PAGE;
JS;
// [page, label, signed in, strict]: the bag must show nothing but the empty state; the other pages must show exactly one empty state and none of the order-summary parts
$pages = [['/bag', 'the bag', false, true], ['/wishlist', 'the wishlist', false, false], ['/search?q=zzzzqx', 'a search with no result', false, false],
    ['/account/orders', 'orders', true, false], ['/account/addresses', 'addresses', true, false]];
foreach ($pages as [$page, $label, $signedIn, $strict]) {
    file_put_contents($tmp . '/wrap.html', '<!doctype html><body style="margin:0"><iframe id="f" style="width:1000px;height:900px;border:0"></iframe><script>const PAGE=' . json_encode($page) . ';' . $js . '</script>');
    $server = BrowserRig::serve($router, 8270, ['PF_CC_SMS' => 'true', 'PF_CC_COOKIE' => $signedIn ? $cookie : '', 'PF_CC_ALL' => '1']);
    $dom = BrowserRig::dump($chrome, $tmp . '/profile' . bin2hex(random_bytes(2)), 'http://127.0.0.1:8270/__w', 1100, 900, 20000);
    BrowserRig::stop($server);
    $ok = preg_match('/@@(\{.*\})@@/s', html_entity_decode($dom, ENT_QUOTES), $m) === 1;
    $r = $ok ? json_decode($m[1], true) : ['empties' => -1, 'stray' => ['no answer from Chrome'], 'banned' => ['no answer from Chrome']];
    $t->test($label . ': one shared empty state' . ($strict ? ' and nothing else beside it' : ', no order-summary parts'), function () use ($t, $r, $strict): void {
        $t->same(1, $r['empties'], 'one visible empty state');
        $t->same([], $r['banned'], 'no summary, coupon or recommendations');
        if ($strict) {
            $t->same([], $r['stray'], 'nothing else beside it');
        }
    });
}
exit($t->finish());

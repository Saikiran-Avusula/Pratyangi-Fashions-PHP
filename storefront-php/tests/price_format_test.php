<?php
declare(strict_types=1);

// No database. UI9c: one price format, PHP (View::rs / View::paise) and JS (site.js PratyangiMoney) give the same text; no "Rs." left in views or scripts.
require __DIR__ . '/lib/TestCase.php';
require dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Views\View;

$t = new TestCase('price_format_test');
$cases = [[0, '₹0'], [999, '₹999'], [1000, '₹1,000'], [100000, '₹1,00,000'], [1888.95, '₹1,888.95'], [1799, '₹1,799'], [0.5, '₹0.50']];

$t->test('PHP View::rs', function () use ($t, $cases): void {
    foreach ($cases as [$in, $out]) {
        $t->same($out, View::rs($in), (string) $in);
    }
    $t->same('₹1,888.95', View::paise(188895));
});

$t->test('JS PratyangiMoney matches PHP', function () use ($t, $cases): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/site.js');
    $t->ok(preg_match('/function money\(rupees\) \{.*?\n  \}/s', $js, $m) === 1, 'money() in site.js');
    $script = $m[0] . ';console.log(JSON.stringify(' . json_encode(array_column($cases, 0)) . '.map(money)))';
    $raw = @shell_exec('node -e ' . escapeshellarg($script));
    if (!is_string($raw) || $raw === '') {
        echo "  SKIP   node not available\n";
        return;
    }
    $got = json_decode($raw, true);
    $t->same(array_column($cases, 1), $got);
});

$t->test('no "Rs." in views or scripts', function () use ($t): void {
    $root = dirname(__DIR__);
    $hits = [];
    foreach ([...glob($root . '/app/Views/{,*/}*.php', GLOB_BRACE), ...glob($root . '/public/assets/*.js'), ...glob($root . '/app/Mail/*.php')] as $f) {
        if (preg_match('/Rs\.\s?\d|\'Rs\. \'/', (string) file_get_contents($f)) === 1) {
            $hits[] = basename($f);
        }
    }
    $t->same([], $hits);
});
exit($t->finish());

<?php
declare(strict_types=1);

// No database. Run: php tests/money_test.php
require __DIR__ . '/lib/TestCase.php';

use Pratyangi\Support\Money;

$t = new TestCase('money_test');

$t->test('rupee strings become paise without floats', function () use ($t): void {
    $t->same(129950, Money::parseRupees('1299.50'));
    $t->same(129900, Money::parseRupees('1299'));
    $t->same(129905, Money::parseRupees('1299.05'));
    $t->same(129950, Money::parseRupees('1299.5'));
    $t->same(0, Money::parseRupees('0.00'));
    $t->same(5, Money::parseRupees('0.05'));
    $t->same(100000000, Money::parseRupees('1000000.00'));
    $t->same(1149950, Money::parseRupees(' 11499.50 '));
    // 0.1 + 0.2 style traps: 19.99 is 1999, not 1998.99999
    $t->same(1999, Money::parseRupees('19.99'));
    $t->same(29, Money::parseRupees('0.29'));
});

$t->test('unreadable amounts are null', function () use ($t): void {
    foreach (['', 'abc', '12,99', '-5.00', '1.234', '.50', '1.', '12345678901', null, 12, 12.5, true, "1.\n5", '1e3'] as $bad) {
        $t->same(null, Money::parseRupees($bad), 'value ' . var_export($bad, true));
    }
});

$t->test('tax rates become basis points', function () use ($t): void {
    $t->same(1800, Money::parseTaxRateBp('18.00'));
    $t->same(500, Money::parseTaxRateBp('5'));
    $t->same(250, Money::parseTaxRateBp('2.5'));
    $t->same(0, Money::parseTaxRateBp('0'));
    $t->same(10000, Money::parseTaxRateBp('100.00'));
    $t->same(null, Money::parseTaxRateBp('100.01'));
    $t->same(null, Money::parseTaxRateBp('abc'));
    $t->same(null, Money::parseTaxRateBp(null));
    $t->same(null, Money::parseTaxRateBp('-1'));
});

$t->test('rupees in JSON are exact and match what JavaScript prints', function () use ($t): void {
    $t->same('11499.5', json_encode(Money::hundredths(1149950)));
    $t->same('11500', json_encode(Money::hundredths(1150000)), 'whole rupees must not print as 11500.0');
    $t->same('1199.5', json_encode(Money::hundredths(119950)));
    $t->same('1299.99', json_encode(Money::hundredths(129999)));
    $t->same('0.05', json_encode(Money::hundredths(5)));
    $t->same('0.07', json_encode(Money::hundredths(7)));
    $t->same('1000000', json_encode(Money::hundredths(100000000)));
    $t->same('5', json_encode(Money::hundredths(500)));
    $t->same('2.5', json_encode(Money::hundredths(250)));
    $t->same('0', json_encode(Money::hundredths(0)));
    // every amount from 0.00 to 2000.00 prints as the shortest decimal with at most two places
    for ($paise = 0; $paise <= 200000; $paise++) {
        $text = json_encode(Money::hundredths($paise));
        $expected = rtrim(rtrim(sprintf('%d.%02d', intdiv($paise, 100), $paise % 100), '0'), '.');
        if ($text !== $expected) {
            throw new RuntimeException("paise {$paise}: printed {$text}, expected {$expected}");
        }
    }
    $t->ok(true);
});

$t->test('serialize_precision is -1 (no 0.1000000000000000055 artefacts)', function () use ($t): void {
    $t->same('-1', ini_get('serialize_precision'));
    $t->same('0.1', json_encode(0.1));
});

$t->test('paise to the ERP rupee string', function () use ($t): void {
    $t->same('26772.90', Money::toRupeeString(2677290));
    $t->same('0.05', Money::toRupeeString(5));
    $t->same('1.00', Money::toRupeeString(100));
});

exit($t->finish());

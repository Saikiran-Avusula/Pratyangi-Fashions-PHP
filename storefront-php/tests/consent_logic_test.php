<?php
declare(strict_types=1);

// No database. Run: php tests/consent_logic_test.php
// Stage H1: the ORDER_OTP rule, its setting, and the unsubscribe-link helper.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Auth\NumberVerification;
use Pratyangi\Auth\Unsubscribe;
use Pratyangi\ConfigException;

$t = new TestCase('consent_logic_test');

$t->test('ORDER_OTP decides which orders need a code, and nothing needs one while SMS is off', function () use ($t): void {
    $rows = [['cod', 'cod', true], ['cod', 'online', false], ['always', 'cod', true], ['always', 'online', true], ['new_number', 'cod', true], ['new_number', 'online', true], ['off', 'cod', false], ['off', 'online', false]];
    foreach ($rows as [$mode, $method, $expect]) {
        $t->same($expect, NumberVerification::required($mode, true, $method), "$mode / $method");
        $t->same(false, NumberVerification::required($mode, false, $method), "$mode / $method with SMS off");
    }
});

$t->test('ORDER_OTP setting: cod is the default, the four words work, anything else stops start-up', function () use ($t): void {
    $t->same('cod', Helpers::config()->orderOtp);
    foreach (['cod', 'always', 'new_number', 'off'] as $mode) {
        $t->same($mode, Helpers::config(['ORDER_OTP' => strtoupper($mode)])->orderOtp);
    }
    $e = $t->throws(ConfigException::class, static fn () => Helpers::config(['ORDER_OTP' => 'sometimes']));
    $t->contains('ORDER_OTP', $e->getMessage());
});

$t->test('number digits: +91, spaces and a leading 0 all reduce to the 10 digits that the address book stores', function () use ($t): void {
    foreach (['+919876543210', '+91 98765 43210', '9876543210', '098765 43210'] as $in) {
        $t->same('9876543210', NumberVerification::digits($in));
    }
});

$t->test('unsubscribe link: signed per customer and channel; a changed id, channel or secret is refused', function () use ($t): void {
    $pepper = str_repeat('P', 48);
    $link = Unsubscribe::link('https://shop.example.test/', $pepper, 42, 'email');
    $t->contains('https://shop.example.test/unsubscribe?t=42.email.', $link);
    $token = Unsubscribe::token($pepper, 42, 'email');
    $t->same([42, 'email'], Unsubscribe::check($pepper, $token));
    $t->same(null, Unsubscribe::check($pepper, str_replace('42.', '43.', $token)));
    $t->same(null, Unsubscribe::check($pepper, str_replace('.email.', '.sms.', $token)));
    $t->same(null, Unsubscribe::check(str_repeat('Q', 48), $token));
    $t->same(null, Unsubscribe::check($pepper, 'nonsense'));
});

exit($t->finish());

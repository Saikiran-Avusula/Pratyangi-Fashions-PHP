<?php
declare(strict_types=1);

// No database. Run: php tests/tokens_test.php
require __DIR__ . '/lib/TestCase.php';

use Pratyangi\Security\Tokens;

$t = new TestCase('tokens_test');

$t->test('session token: 256 bits, base64url, no padding, never repeats', function () use ($t): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $token = Tokens::newToken();
        $t->same(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 'shape of ' . $token);
        $seen[$token] = true;
    }
    $t->same(200, count($seen));
});

$t->test('sha256 is lower-case hex of 64 characters and matches the known value', function () use ($t): void {
    $t->same('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', Tokens::sha256('abc'));
    $t->same(1, preg_match('/^[a-f0-9]{64}$/D', Tokens::sha256(Tokens::newToken())));
});

$t->test('code: always 6 digits, zero padded', function () use ($t): void {
    $min = 999999;
    for ($i = 0; $i < 3000; $i++) {
        $code = Tokens::newCode();
        $t->same(1, preg_match('/^\d{6}$/D', $code), 'code ' . $code);
        $min = min($min, (int) $code);
    }
    // 3000 draws from 1,000,000 give a leading zero about 27 times; the padding path is certainly hit
    $t->ok($min < 100000, 'saw a code below 100000 (needs zero padding)');
});

$t->test('equalHex compares in constant time and ignores case', function () use ($t): void {
    $a = Tokens::sha256('x');
    $t->same(true, Tokens::equalHex($a, $a));
    $t->same(true, Tokens::equalHex($a, strtoupper($a)));
    $t->same(false, Tokens::equalHex($a, Tokens::sha256('y')));
    $t->same(false, Tokens::equalHex($a, substr($a, 0, 63)));
    $t->same(false, Tokens::equalHex($a, ''));
});

exit($t->finish());

<?php
declare(strict_types=1);

// No database. Run: php tests/passwords_test.php
require __DIR__ . '/lib/TestCase.php';

use Pratyangi\Security\Passwords;

$t = new TestCase('passwords_test');

$t->test('hash then verify', function () use ($t): void {
    $hash = Passwords::hash('Saree2026');
    $t->ok(strlen($hash) > 30);
    $t->ok(!str_contains($hash, 'Saree2026'));
    $t->same(true, Passwords::verify($hash, 'Saree2026'));
    $t->same(false, Passwords::verify($hash, 'saree2026'));
    $t->same(false, Passwords::verify($hash, ''));
});

$t->test('uses argon2id when PHP has it, otherwise bcrypt', function () use ($t): void {
    $hash = Passwords::hash('Saree2026');
    $t->ok(str_starts_with($hash, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$'));
});

$t->test('two hashes of one password differ (salted)', function () use ($t): void {
    $t->ok(Passwords::hash('Saree2026') !== Passwords::hash('Saree2026'));
});

$t->test('verifying an empty or damaged hash is false, not an error', function () use ($t): void {
    $t->same(false, Passwords::verify('', 'x'));
    $t->same(false, Passwords::verify('not-a-hash', 'x'));
});

$t->test('decoy check runs without error (timing equaliser for unknown emails)', function () use ($t): void {
    Passwords::verifyAgainstDecoy('whatever');
    Passwords::verifyAgainstDecoy('whatever');
    $t->ok(true);
});

exit($t->finish());

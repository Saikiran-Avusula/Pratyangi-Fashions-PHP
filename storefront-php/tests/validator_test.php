<?php
declare(strict_types=1);

// No database. Run: php tests/validator_test.php
require __DIR__ . '/lib/TestCase.php';

use Pratyangi\Auth\Validator;
use Pratyangi\Http\ApiException;

$t = new TestCase('validator_test');

/** @return array{string,?string,array<string,string>} message, field, errors of the 422 */
$fails = static function (\Closure $run): array {
    try {
        $run();
    } catch (ApiException $e) {
        return [$e->getMessage(), $e->field, $e->errors ?? []];
    }
    throw new RuntimeException('expected a 422');
};

$t->test('signup accepts a good body and cleans it', function () use ($t): void {
    $v = Validator::signup(['name' => '  Test Buyer 1 ', 'email' => '  Buyer1@Example.COM ', 'password' => 'Saree2026']);
    $t->same(['name' => 'Test Buyer 1', 'email' => 'buyer1@example.com', 'password' => 'Saree2026'], $v);
});

$t->test('signup: missing body gives one message per field, first field leads', function () use ($t, $fails): void {
    [$message, $field, $errors] = $fails(fn () => Validator::signup(null));
    $t->same('Enter your full name.', $message);
    $t->same('name', $field);
    $t->same(['name' => 'Enter your full name.', 'email' => 'Enter your email address.', 'password' => 'Create a password.'], $errors);
    $t->same(['name', 'email', 'password'], array_keys($errors), 'key order');
});

$t->test('name rules', function () use ($t, $fails): void {
    $t->same('Enter your full name (at least 2 characters).', $fails(fn () => Validator::signup(['name' => 'A', 'email' => 'a@b.co', 'password' => 'Saree2026']))[0]);
    $t->same('That name is too long (80 characters at most).', $fails(fn () => Validator::signup(['name' => str_repeat('a', 81), 'email' => 'a@b.co', 'password' => 'Saree2026']))[0]);
    Validator::signup(['name' => str_repeat('अ', 80), 'email' => 'a@b.co', 'password' => 'Saree2026']);
    $t->same('That name is too long (80 characters at most).', $fails(fn () => Validator::signup(['name' => str_repeat('अ', 81), 'email' => 'a@b.co', 'password' => 'Saree2026']))[0], 'counted in characters, not bytes');
});

$t->test('email rules', function () use ($t, $fails): void {
    $bad = static fn (mixed $email): string => $fails(fn () => Validator::forgot(['email' => $email]))[0];
    $t->same('Enter your email address.', $bad(''));
    $t->same('Enter your email address.', $bad('   '));
    $t->same('Enter your email address.', $bad(5));
    $t->same('Enter a valid email address, like name@example.com.', $bad('nope'));
    $t->same('Enter a valid email address, like name@example.com.', $bad('a@b'));
    $t->same('Enter a valid email address, like name@example.com.', $bad('a@b.c'));
    $t->same('Enter a valid email address, like name@example.com.', $bad("a b@example.com"));
    $t->same('Enter a valid email address, like name@example.com.', $bad("a@exam\nple.com"));
    $t->same('That email address is too long.', $bad(str_repeat('a', 250) . '@b.co'));
    $t->same(['channel' => 'email', 'target' => 'x@y.zz'], Validator::forgot(['email' => 'X@Y.ZZ']));
    $t->same(['channel' => 'mobile', 'target' => '+919876543210'], Validator::forgot(['channel' => 'mobile', 'mobile' => '98765 43210']));
    $t->same('Enter a valid 10-digit mobile number.', $fails(fn () => Validator::forgot(['channel' => 'mobile', 'mobile' => '12345']))[0]);
    $t->same(['channel' => 'email', 'target' => 'x@y.zz'], Validator::forgot(['channel' => 'sms', 'email' => 'x@y.zz']), 'an unknown channel means email');
});

$t->test('password rules (signup)', function () use ($t, $fails): void {
    $bad = static fn (mixed $password): string => $fails(fn () => Validator::signup(['name' => 'Ab', 'email' => 'a@b.co', 'password' => $password]))[0];
    $t->same('Create a password.', $bad(null));
    $t->same('Password must be at least 8 characters.', $bad('Ab1'));
    $t->same('Password is too long (128 characters at most).', $bad(str_repeat('a1', 65)));
    $t->same('Password needs at least one letter.', $bad('12345678'));
    $t->same('Password needs at least one number.', $bad('Saree----'));
    $good = Validator::signup(['name' => 'Ab', 'email' => 'a@b.co', 'password' => ' Saree2026 ']);
    $t->same(' Saree2026 ', $good['password'], 'signup password is not trimmed');
});

$t->test('login: password only needs to be non-empty (and Node trims it)', function () use ($t, $fails): void {
    $t->same('Enter your password.', $fails(fn () => Validator::login(['email' => 'a@b.co', 'password' => '   ']))[0]);
    $t->same('Enter your password.', $fails(fn () => Validator::login(['email' => 'a@b.co']))[0]);
    $t->same(['email' => 'a@b.co', 'password' => 'x'], Validator::login(['email' => 'a@b.co', 'password' => ' x ']));
});

$t->test('verify token and reset code', function () use ($t, $fails): void {
    $t->same('This verification link is incomplete.', $fails(fn () => Validator::verifyEmail(['token' => 'short']))[0]);
    $t->same('This verification link is incomplete.', $fails(fn () => Validator::verifyEmail([]))[0]);
    $t->same('This verification link is incomplete.', $fails(fn () => Validator::verifyEmail(['token' => str_repeat('a', 201)]))[0]);
    $t->same(['token' => str_repeat('a', 20)], Validator::verifyEmail(['token' => ' ' . str_repeat('a', 20) . ' ']));
    $base = ['email' => 'a@b.co', 'password' => 'NewSaree2027'];
    $t->same('The code is 6 digits.', $fails(fn () => Validator::reset($base + ['code' => '12345']))[0]);
    $t->same('123456', Validator::reset($base + ['code' => "123456\n"])['code'], 'a trailing newline is trimmed first');
    $t->same('The code is 6 digits.', $fails(fn () => Validator::reset($base + ['code' => "12\n3456"]))[0]);
});

$t->test('reset: code missing and error order', function () use ($t, $fails): void {
    [$message, $field, $errors] = $fails(fn () => Validator::reset(['email' => 'bad', 'password' => 'x']));
    $t->same('Enter a valid email address, like name@example.com.', $message);
    $t->same('email', $field);
    $t->same(['email', 'code', 'password'], array_keys($errors));
    $t->same('Enter the 6-digit code.', $errors['code']);
    $t->same('Password must be at least 8 characters.', $errors['password']);
});

$t->test('a JSON list instead of an object is a 422 on field form', function () use ($t, $fails): void {
    [, $field] = $fails(fn () => Validator::forgot([1, 2]));
    $t->same('form', $field);
});

$t->test('catalogue query: defaults and empty values', function () use ($t): void {
    $t->same(['category' => null, 'q' => null, 'limit' => 24, 'cursor' => null], Validator::catalogQuery([]));
    $t->same(['category' => null, 'q' => null, 'limit' => 24, 'cursor' => null], Validator::catalogQuery(['category' => '', 'q' => '', 'limit' => '', 'cursor' => '']));
    $t->same(['category' => 'Sarees', 'q' => 'silk', 'limit' => 100, 'cursor' => '48'], Validator::catalogQuery(['category' => ' Sarees ', 'q' => 'silk', 'limit' => '100', 'cursor' => '48']));
});

$t->test('catalogue query: limit and cursor errors', function () use ($t, $fails): void {
    $one = static fn (array $q): string => $fails(fn () => Validator::catalogQuery($q))[0];
    $t->same('The page size cannot be more than 100.', $one(['limit' => '101']));
    $t->same('The page size must be at least 1.', $one(['limit' => '0']));
    $t->same('The page size must be a number.', $one(['limit' => 'abc']));
    $t->same('The page size must be a number.', $one(['limit' => ['1', '2']]));
    $t->same('The page size must be a whole number.', $one(['limit' => '2.5']));
    $t->same('That page marker is not valid.', $one(['cursor' => 'abc']));
    $t->same('That page marker is not valid.', $one(['cursor' => '1234567']));
    $t->same('That page marker is not valid.', $one(['cursor' => "12\n"]));
    $t->same('That category name is too long.', $one(['category' => str_repeat('a', 61)]));
    $t->same('That search is too long (80 characters at most).', $one(['q' => str_repeat('a', 81)]));
    $t->same('Choose one category.', $one(['category' => ['a', 'b']]));
    // JavaScript's Number("1e1") is 10
    $t->same(10, Validator::catalogQuery(['limit' => '1e1'])['limit']);
});

exit($t->finish());

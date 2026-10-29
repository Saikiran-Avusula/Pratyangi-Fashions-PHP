<?php
declare(strict_types=1);

// No database. Run: php tests/passwords_decoy_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeAuthPdo.php';

use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Http\ApiException;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Security\Passwords;

$t = new TestCase('passwords_decoy_test');
$argon = defined('PASSWORD_ARGON2ID');

$t->test('both decoy constants are valid hashes with the same cost as hash()', function () use ($t, $argon): void {
    $fresh = Passwords::hash('anything');
    $freshInfo = password_get_info($fresh);
    $current = Passwords::decoy();
    $info = password_get_info($current);
    $t->same($freshInfo['algo'], $info['algo'], 'same algorithm');
    $t->same($freshInfo['options'], $info['options'], 'same cost parameters');
    $t->same(false, password_needs_rehash($current, $argon ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT, $argon
        ? ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1] : ['cost' => 12]), 'no rehash needed');
    $t->same(['cost' => 12], password_get_info(Passwords::DECOY_BCRYPT)['options']);
    $t->same('2y', password_get_info(Passwords::DECOY_BCRYPT)['algoName'] === 'bcrypt' ? '2y' : '');
    if ($argon) {
        $t->same(['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1], password_get_info(Passwords::DECOY_ARGON2ID)['options']);
    }
});

$t->test('verifying against the decoy is false for ordinary passwords and does not throw', function () use ($t): void {
    foreach (['', 'Saree2026', 'decoy-password-for-timing-only', str_repeat('a', 200)] as $pw) {
        $t->same(false, password_verify($pw, Passwords::decoy()));
    }
    foreach ([Passwords::DECOY_ARGON2ID, Passwords::DECOY_BCRYPT] as $constant) {
        $t->ok(strlen($constant) > 50);
        $t->same(false, password_verify('Saree2026', $constant));
    }
});

$t->test('the unknown-email path computes no hash: Passwords::hash is not involved, only a verify', function () use ($t): void {
    // the decoy method is a constant lookup; calling it twice returns the identical string (nothing is built per call)
    $t->same(Passwords::decoy(), Passwords::decoy());
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Security/Passwords.php');
    $body = substr($source, (int) strpos($source, 'function verifyAgainstDecoy'));
    $body = substr($body, 0, (int) strpos($body, '}'));
    $t->ok(!str_contains($body, 'hash('), 'verifyAgainstDecoy must not call hash');
    $t->same(1, substr_count($body, 'password_verify('), 'exactly one password_verify');
    // and through the service: an unknown email answers 401 after touching only the customer lookup
    $db = new FakeAuthPdo();
    $svc = new AuthService($db, new CustomerRepo($db), new SessionRepo($db), new TokenRepo($db), 30, new FixedClock());
    $e = $t->throws(ApiException::class, fn () => $svc->signIn(['email' => 'ghost@example.com', 'password' => 'Saree2026'], null));
    $t->same(401, $e->status);
    $t->same(1, count($db->log), 'one lookup, nothing else');
});

$t->test('timing sanity: the decoy verify is the same order of cost as a real verify (generous bounds)', function () use ($t): void {
    $real = Passwords::hash('Saree2026');
    $time = static function (callable $fn): float {
        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $s = hrtime(true);
            $fn();
            $best = min($best, (hrtime(true) - $s) / 1e6);
        }
        return $best;
    };
    $realMs = $time(static fn () => Passwords::verify($real, 'Wrong2026'));
    $decoyMs = $time(static fn () => Passwords::verifyAgainstDecoy('Wrong2026'));
    $t->ok($decoyMs > 0.0 && $realMs > 0.0);
    // best of three, within a factor of 5 either way: catches "decoy is skipped or far cheaper", does not flake on a busy machine
    $t->ok($decoyMs * 5 > $realMs && $realMs * 5 > $decoyMs, sprintf('real %.1f ms, decoy %.1f ms', $realMs, $decoyMs));
});

exit($t->finish());

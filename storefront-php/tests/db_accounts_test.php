<?php
declare(strict_types=1);

// NEEDS MySQL (throwaway *_test database, port != 3306, env settings only; see tests/lib/DbGuard.php). NOT run by the developer.
// Run: PF_TEST_DB_PORT=... PF_TEST_DB_NAME=..._test PF_TEST_DB_USER=... PF_TEST_DB_PASS=... php tests/db_accounts_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\DuplicateEmailException;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Security\Tokens;

$pdo = DbGuard::connect();
DbGuard::clean($pdo);
$t = new TestCase('db_accounts_test');
$clock = new FixedClock((float) time());
$mailFile = sys_get_temp_dir() . '/pf_db_mail_' . bin2hex(random_bytes(4)) . '.log';
register_shutdown_function(static function () use ($mailFile): void {
    if (is_file($mailFile)) {
        unlink($mailFile);
    }
});
$customers = new CustomerRepo($pdo);
$sessions = new SessionRepo($pdo);
$tokens = new TokenRepo($pdo);
$service = static fn (): AuthService => new AuthService($pdo, $customers, $sessions, $tokens, 30, $clock);

$t->test('signup stores a hash, returns the public customer and a session', function () use ($t, $service, $pdo): void {
    $r = $service()->signUp(['name' => 'Test Buyer 1', 'email' => 'buyer1@example.com', 'password' => 'Saree2026'], 'UA/1.0');
    $t->same(['id', 'name', 'email', 'provider', 'emailVerified', 'createdAt'], array_keys($r['customer']));
    $t->same(['password', false], [$r['customer']['provider'], $r['customer']['emailVerified']]);
    $t->same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $r['customer']['createdAt']));
    $row = $pdo->query("SELECT password_hash FROM shop_customers WHERE email = 'buyer1@example.com'")->fetch();
    $t->ok(!str_contains((string) $row['password_hash'], 'Saree2026'));
    $s = $pdo->query('SELECT token_hash, user_agent FROM shop_sessions')->fetch();
    $t->same([Tokens::sha256($r['token']), 'UA/1.0'], [$s['token_hash'], $s['user_agent']], 'only the hash is stored');
});

$t->test('duplicate email: 409 with the contract text; repo raises DuplicateEmailException', function () use ($t, $service, $customers): void {
    $e = $t->throws(ApiException::class, fn () => $service()->signUp(['name' => 'Again', 'email' => 'buyer1@example.com', 'password' => 'Saree2026'], null));
    $t->same([409, 'email', 'An account with this email already exists.'], [$e->status, $e->field, $e->getMessage()]);
    $t->throws(DuplicateEmailException::class, fn () => $customers->create('X Y', 'buyer1@example.com', 'hash'));
});

$t->test('login: right password; wrong password and unknown email have their own messages', function () use ($t, $service): void {
    $ok = $service()->signIn(['email' => 'buyer1@example.com', 'password' => 'Saree2026'], null);
    $t->same('buyer1@example.com', $service()->customerForToken($ok['token'])['email']);
    $e = $t->throws(ApiException::class, fn () => $service()->signIn(['email' => 'buyer1@example.com', 'password' => 'Wrong2026'], null));
    $t->same([401, 'Email or password is incorrect', null], [$e->status, $e->getMessage(), $e->field]);
    $e = $t->throws(ApiException::class, fn () => $service()->signIn(['email' => 'nobody@example.com', 'password' => 'Saree2026'], null));
    $t->same([401, 'Email or password is incorrect', null, 'invalid_credentials'], [$e->status, $e->getMessage(), $e->field, $e->errorCode]);
    $service()->signOut($ok['token']);
    $t->same(null, $service()->customerForToken($ok['token']), 'logged out');
    $service()->signOut('never-existed');
    $t->same(null, $service()->customerForToken(''));
});

$t->test('expired sessions are not found', function () use ($t, $service, $pdo): void {
    $ok = $service()->signIn(['email' => 'buyer1@example.com', 'password' => 'Saree2026'], null);
    $pdo->prepare('UPDATE shop_sessions SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND WHERE token_hash = ?')->execute([Tokens::sha256($ok['token'])]);
    $t->same(null, $service()->customerForToken($ok['token']));
});

$t->test('old email link: still verifies, single use, removes every link', function () use ($t, $service, $tokens, $customers, $pdo): void {
    $buyer = $customers->findByEmail('buyer1@example.com');
    $token = Tokens::newToken();
    $tokens->replaceVerification((int) $buyer['id'], Tokens::sha256($token), (float) (time() + 3600));
    $customer = $service()->verifyEmail($token);
    $t->same(true, $customer['emailVerified']);
    $e = $t->throws(ApiException::class, fn () => $service()->verifyEmail($token));
    $t->same([400, 'token'], [$e->status, $e->field]);
    $t->same(0, (int) $pdo->query('SELECT COUNT(*) FROM shop_email_verifications WHERE customer_id = ' . (int) $customer['id'])->fetchColumn());
});

$t->test('rate limit store: atomic counting per window', function () use ($t, $pdo): void {
    $store = new DbRateLimitStore(static fn (): \PDO => $pdo);
    $t->same([1, 2, 3], [$store->hit('login:abc', 900, 1798000000), $store->hit('login:abc', 900, 1798000001), $store->hit('login:abc', 900, 1798000002)]);
    $t->same(1, $store->hit('login:abc', 900, 1798000900), 'next window');
    $t->same(1, $store->hit('login:other', 900, 1798000000));
    $store->purge(1798000500);
    $t->same(1, $store->hit('login:abc', 900, 1798000000), 'old window was purged');
});

exit($t->finish());

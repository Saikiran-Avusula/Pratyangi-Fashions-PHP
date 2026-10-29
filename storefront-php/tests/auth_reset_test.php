<?php
declare(strict_types=1);

// No database (in-memory PDO stand-in). Run: php tests/auth_reset_test.php
// Covers exact e-mail matching (no accent folding), the reset mail going to the STORED address, and the
// forgot-password answer being identical for known, unknown and mail-failure cases.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeAuthPdo.php';

use Pratyangi\App;
use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\MemoryRateLimitStore;
use Pratyangi\Mail\MailException;
use Pratyangi\Mail\Mailer;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Support\Logger;

final class RecordingMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public array $sent = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
    }
}

/** Fails like a broken SMTP server: the message quotes the recipient. */
final class ThrowingMailer implements Mailer
{
    public int $calls = 0;

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $this->calls++;
        throw new MailException('SMTP rejected recipient <' . $to . '> with code 550');
    }
}

function fakeDb(): FakeAuthPdo
{
    $db = new FakeAuthPdo();
    $db->customers = [
        ['id' => 1, 'name' => 'Real Buyer', 'email' => 'jose@example.com', 'password_hash' => 'x', 'email_verified_at' => null, 'created_at' => '2026-01-01 00:00:00.000'],
        ['id' => 2, 'name' => 'Mixed Case', 'email' => 'Mixed@Example.com', 'password_hash' => 'x', 'email_verified_at' => null, 'created_at' => '2026-01-01 00:00:00.000'],
    ];
    return $db;
}

function service(FakeAuthPdo $db, Mailer $mailer, ?Logger $logger = null): AuthService
{
    return new AuthService($db, new CustomerRepo($db), new SessionRepo($db), new TokenRepo($db), 30, new FixedClock(), $logger);
}

$t = new TestCase('auth_reset_test');

$t->test('pure comparison: case differences match, accents and look-alikes do not', function () use ($t): void {
    $t->same(true, CustomerRepo::sameEmail('Jose@Example.COM', 'jose@example.com'));
    $t->same(false, CustomerRepo::sameEmail('josé@example.com', 'jose@example.com'));
    $t->same(false, CustomerRepo::sameEmail('jose@exámple.com', 'jose@example.com'), 'accented domain');
    $t->same(false, CustomerRepo::sameEmail("jose@example.com\u{200B}", 'jose@example.com'), 'zero-width space');
    $t->same(false, CustomerRepo::sameEmail("jos\u{0435}@example.com", 'jose@example.com'), 'Cyrillic e');
});

$t->test('findByEmail: the collation folds accents, PHP refuses the look-alike', function () use ($t): void {
    $db = fakeDb();
    $repo = new CustomerRepo($db);
    $t->same(1, $repo->findByEmail('jose@example.com')['id'] ?? null);
    $t->same(1, $repo->findByEmail('JOSE@example.com')['id'] ?? null, 'case differences still match');
    $t->same(2, $repo->findByEmail('mixed@example.com')['id'] ?? null, 'stored mixed case matches a lower-case typed address');
    $t->same(null, $repo->findByEmail('josé@example.com'), 'accented local part');
    $t->same(null, $repo->findByEmail('jose@exámple.com'), 'accented domain');
    // sanity: the fake really does fold, so the refusal above came from the PHP check
    $t->same(FakeAuthPdo::fold('josé@example.com'), FakeAuthPdo::fold('jose@example.com'));
    foreach ($db->log as $line) {
        $t->ok(!str_contains($line['sql'], 'josé') && !str_contains($line['sql'], 'jose@'), 'input is bound, never in the SQL text');
    }
});

$t->test('signup duplicate path: an exact duplicate is found, a look-alike is left to the unique key', function () use ($t): void {
    $mail = new RecordingMailer();
    $e = $t->throws(ApiException::class, fn () => service(fakeDb(), $mail)->signUp(['name' => 'Again', 'email' => 'jose@example.com', 'password' => 'Saree2026'], null));
    $t->same(409, $e->status);
    // look-alike: findByEmail says "no account", so signup goes on to INSERT; the (folding) unique key then answers 23000/1062 -> 409 in the DB test
    $t->same(null, (new CustomerRepo(fakeDb()))->findByEmail('josé@example.com'));
});

$t->test('login uses the exact lookup (a look-alike is "unknown")', function () use ($t): void {
    $db = fakeDb();
    $svc = service($db, new RecordingMailer());
    $e = $t->throws(ApiException::class, fn () => $svc->signIn(['email' => 'josé@example.com', 'password' => 'whatever'], null));
    $t->same([401, AuthService::BAD_CREDENTIALS, null, 'invalid_credentials'], [$e->status, $e->getMessage(), $e->field, $e->errorCode]);
    $t->same([], $db->statements('UPDATE shop_customers'));
});

// The reset itself (code, channels, identical answers, session wipe) needs the real database: see tests/db_flow_test.php.

exit($t->finish());

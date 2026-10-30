<?php
declare(strict_types=1);

// No database. Run: php tests/rate_limiter_memory_test.php
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\MemoryRateLimitStore;
use Pratyangi\Http\RateLimiter;

$t = new TestCase('rate_limiter_memory_test');

$ctx = static fn (): Context => new Context(Helpers::request('POST', '/x'), 'rid', 0, static fn (): ?array => null);

$t->test('login allows 10 per 15 minutes, the 11th is refused', function () use ($t, $ctx): void {
    $limiter = new RateLimiter(new MemoryRateLimitStore(), new FixedClock(1798000000.0));
    for ($i = 1; $i <= 10; $i++) {
        $c = $ctx();
        $limiter->consume('login', '203.0.113.1', $c);
        $t->same((string) (10 - $i), $c->headers['RateLimit-Remaining']);
        $t->same('10;w=900', $c->headers['RateLimit-Policy']);
    }
    $c = $ctx();
    $e = $t->throws(ApiException::class, fn () => $limiter->consume('login', '203.0.113.1', $c));
    $t->same(429, $e->status);
    $t->same('Too many attempts. Please wait a few minutes and try again.', $e->getMessage());
    $t->ok(isset($c->headers['Retry-After']));
});

$t->test('windows are fixed: a new window starts clean', function () use ($t, $ctx): void {
    $clock = new FixedClock(1798000000.0 - (1798000000 % 900)); // start of a window
    $limiter = new RateLimiter(new MemoryRateLimitStore(), $clock, null, ['reset' => 2]);
    $c = $ctx();
    $limiter->consume('reset', '1.1.1.1', $c);
    $limiter->consume('reset', '1.1.1.1', $c);
    $t->throws(ApiException::class, fn () => $limiter->consume('reset', '1.1.1.1', $c));
    $clock->advance(900);
    $c = $ctx();
    $limiter->consume('reset', '1.1.1.1', $c);
    $t->same('1', $c->headers['RateLimit-Remaining']);
    $t->same('900', $c->headers['RateLimit-Reset']);
});

$t->test('buckets and addresses are separate; verify and reset are shared by their routes', function () use ($t, $ctx): void {
    $limiter = new RateLimiter(new MemoryRateLimitStore(), new FixedClock(), null, ['reset' => 1, 'login' => 1]);
    $limiter->consume('reset', '1.1.1.1', $ctx());
    $limiter->consume('reset', '2.2.2.2', $ctx());
    $limiter->consume('login', '1.1.1.1', $ctx());
    $t->throws(ApiException::class, fn () => $limiter->consume('reset', '1.1.1.1', $ctx()));
});

$t->test('table of limits matches the contract', function () use ($t): void {
    $t->same([10, 3600, false], RateLimiter::BUCKETS['signup']);
    $t->same([10, 900, false], RateLimiter::BUCKETS['login']);
    $t->same([20, 900, false], RateLimiter::BUCKETS['verify']);
    $t->same([5, 900, false], RateLimiter::BUCKETS['reset']);
    $t->same([120, 60, true], RateLimiter::BUCKETS['catalog']);
});

$t->test('IPv6 is limited per /56; IPv4-mapped counts as IPv4', function () use ($t): void {
    $t->same(RateLimiter::subnet('2001:db8:1:2:aaaa::1'), RateLimiter::subnet('2001:db8:1:2:bbbb::9'));
    $t->ok(RateLimiter::subnet('2001:db8:1:2::1') !== RateLimiter::subnet('2001:db9:1:2::1'));
    $t->same('1.2.3.4', RateLimiter::subnet('::ffff:1.2.3.4'));
    $t->same('1.2.3.4', RateLimiter::subnet('1.2.3.4'));
});

$t->test('a failing store: catalogue fails open, account buckets fail closed', function () use ($t, $ctx): void {
    $broken = new class () implements Pratyangi\Http\RateLimitStore {
        public function hit(string $key, int $windowSeconds, int $now): int
        {
            throw new RuntimeException('down');
        }

        public function release(string $key, int $windowSeconds, int $now): void
        {
        }

        public function purge(int $olderThan): void
        {
        }
    };
    $limiter = new RateLimiter($broken, new FixedClock());
    $limiter->consume('catalog', '1.1.1.1', $ctx());
    $t->throws(RuntimeException::class, fn () => $limiter->consume('login', '1.1.1.1', $ctx()));
});

$t->test('client address honours TRUST_PROXY hops counted from the right', function () use ($t): void {
    $r = Helpers::request('GET', '/', ['X-Forwarded-For' => '6.6.6.6, 9.9.9.9'], null, '10.0.0.1');
    $t->same('10.0.0.1', $r->clientIp(0));
    $t->same('9.9.9.9', $r->clientIp(1));
    $t->same('6.6.6.6', $r->clientIp(2));
    $t->same('6.6.6.6', $r->clientIp(5), 'more hops than the chain: the leftmost');
    $t->same('10.0.0.1', Helpers::request('GET', '/', [], null, '10.0.0.1')->clientIp(1));
    $t->same('10.0.0.1', Helpers::request('GET', '/', ['X-Forwarded-For' => 'garbage'], null, '10.0.0.1')->clientIp(1));
});

exit($t->finish());

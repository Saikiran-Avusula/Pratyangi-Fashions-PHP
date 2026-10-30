<?php
declare(strict_types=1);

namespace Pratyangi\Http;

use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Fixed-window limits per client address and bucket. Header set and refusal text are Node's (express-rate-limit draft-6):
 * RateLimit-Policy, RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset, plus Retry-After when refused.
 */
final class RateLimiter
{
    public const MESSAGE = 'Too many attempts. Please wait a few minutes and try again.';

    /**
     * bucket => [limit, window seconds, fail open?]. verify and reset are shared by the two routes that use them (contract 1.7).
     * Catalogue reads fail open (a broken counter must not hide the shop); account routes fail closed.
     */
    public const BUCKETS = [
        'signup' => [10, 3600, false],
        'login' => [10, 900, false],
        'google' => [20, 900, false],
        'verify' => [20, 900, false],
        'reset' => [5, 900, false],
        'catalog' => [120, 60, true],
        // PHP-only mobile OTP: requests per client address per hour, verify attempts per client address, requests per number per day
        'otp_ip' => [10, 3600, false],
        'otp_verify' => [20, 900, false],
        'otp_mobile' => [5, 86400, false],
        // PHP-only: codes per email address per day; one reset request per target per 45 s (counted for every target, known or not);
        // email sign-in attempts per address per 15 minutes (counted for every address, known or not)
        'otp_email' => [5, 86400, false],
        'code_cooldown' => [1, 45, false],
        'login_email' => [10, 900, false],
        // online payment: starting a payment 10 per 15 minutes, verifying one 30 per 15 minutes (Node: limiter("payment", 10|30, 15))
        'payment_order' => [10, 900, false],
        'payment_verify' => [30, 900, false],
    ];

    /** Longest window in BUCKETS (a row is kept until its window and one more have passed). */
    private const LONGEST_WINDOW = 86400;

    /** Refusal text where the generic one would mislead. Identical for every number, so it says nothing about accounts. */
    private const MESSAGES = [
        'otp_mobile' => 'Too many codes have been requested for this number today. Please try again tomorrow.',
        'otp_email' => 'Too many codes have been requested for this address today. Please try again tomorrow.',
        'code_cooldown' => 'Please wait a moment before asking for another code.',
    ];

    /** @param array<string,int> $overrides bucket => limit (tests raise or lower limits) */
    public function __construct(
        private RateLimitStore $store,
        private Clock $clock,
        private ?Logger $logger = null,
        private array $overrides = [],
    ) {
    }

    /** Counts this request. Adds the RateLimit-* headers to $context and throws 429 when over the limit. */
    public function consume(string $bucket, string $clientIp, Context $context): void
    {
        $this->consumeSubject($bucket, self::subnet($clientIp), $context);
    }

    /** The same count for any other subject than the client address (a fingerprint of a mobile number, for example). Hashed before it is stored. */
    /** Gives back the hit consumeSubject() just counted (the code could not be sent, so the allowance stays). */
    public function giveBack(string $bucket, string $subject): void
    {
        [, $window] = self::BUCKETS[$bucket];
        try {
            $this->store->release($bucket . ':' . hash('sha256', $subject), $window, (int) floor($this->clock->now()));
        } catch (\Throwable $e) {
            $this->logger?->exception('rate limit give-back failed', $e, 'warn');
        }
    }

    /** "45 seconds", "12 minutes", "5 hours" (rounded up) for the countdown in a limit message. */
    public static function wait(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 120) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        if ($seconds < 7200) {
            return (int) ceil($seconds / 60) . ' minutes';
        }
        return (int) ceil($seconds / 3600) . ' hours';
    }

    public function consumeSubject(string $bucket, string $subject, Context $context): void
    {
        [$limit, $window, $failOpen] = self::BUCKETS[$bucket];
        $limit = $this->overrides[$bucket] ?? $limit;
        $now = (int) floor($this->clock->now());
        try {
            $hits = $this->store->hit($bucket . ':' . hash('sha256', $subject), $window, $now);
        } catch (\Throwable $e) {
            $this->logger?->exception('rate limit store failed', $e);
            if ($failOpen) {
                return;
            }
            throw $e;
        }
        if (random_int(1, 100) === 1) {
            try {
                $this->store->purge($now - 2 * self::LONGEST_WINDOW); // a window must never be purged while it still counts
            } catch (\Throwable $e) {
                $this->logger?->exception('rate limit clean-up failed', $e, 'warn');
            }
        }
        $reset = max(0, (intdiv($now, $window) + 1) * $window - $now);
        $context->headers['RateLimit-Policy'] = $limit . ';w=' . $window;
        $context->headers['RateLimit-Limit'] = (string) $limit;
        $context->headers['RateLimit-Remaining'] = (string) max($limit - $hits, 0);
        $context->headers['RateLimit-Reset'] = (string) $reset;
        if ($hits > $limit) {
            $context->headers['Retry-After'] = (string) $reset;
            // the email and cooldown messages say how long to wait (the windows are fixed, so $reset is the time to the next one)
            $message = match ($bucket) {
                'otp_email' => 'Too many codes have been requested for this address today. Please try again in ' . self::wait($reset) . '.',
                'code_cooldown' => 'Please wait ' . self::wait($reset) . ' before asking for another code.',
                default => self::MESSAGES[$bucket] ?? self::MESSAGE,
            };
            throw new ApiException(429, $message);
        }
    }

    /** IPv4 as is; IPv6 reduced to its /56 (the express-rate-limit default), so one visitor cannot dodge a limit with many addresses. */
    public static function subnet(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12)); // IPv4-mapped
        }
        return (string) inet_ntop(substr($packed, 0, 7) . str_repeat("\0", 9)) . '/56';
    }
}

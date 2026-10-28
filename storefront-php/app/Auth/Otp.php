<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/** The one-time code: made from a secure random source, stored only as an HMAC-SHA256 keyed with the OTP_PEPPER setting. */
final class Otp
{
    public const LENGTH = 6;
    public const TTL_SECONDS = 300;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 45;

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /** Lower-case hex. The number is part of the message, so a stored hash only fits the number it was made for. */
    public static function hash(string $pepper, string $mobile, string $code): string
    {
        return hash_hmac('sha256', $mobile . '|' . $code, $pepper);
    }

    public static function matches(string $pepper, string $mobile, string $code, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($pepper, $mobile, $code));
    }

    /**
     * The hash stored for a code made for one channel, purpose and target (address or number). All three are part of the message, so a
     * hash made for one purpose or channel never matches another, even with the same digits and target.
     */
    public static function hashFor(string $pepper, string $channel, string $purpose, string $target, string $code): string
    {
        return hash_hmac('sha256', $channel . '|' . $purpose . '|' . $target . '|' . $code, $pepper);
    }

    public static function matchesFor(string $pepper, string $channel, string $purpose, string $target, string $code, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hashFor($pepper, $channel, $purpose, $target, $code));
    }

    /** A keyed fingerprint of a number or address (rate-limit keys, the ip_hash column), so the raw value is not stored there. */
    public static function fingerprint(string $pepper, string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind . '|' . $value, $pepper);
    }
}

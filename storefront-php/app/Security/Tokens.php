<?php
declare(strict_types=1);

namespace Pratyangi\Security;

/** Random tokens and codes, and the hashes that are stored instead of them. */
final class Tokens
{
    /** 256 random bits as base64url without padding (43 characters). Only sha256() of it is stored. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Lower-case hex SHA-256. */
    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /** A 6-digit code from a secure random source, zero padded. */
    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Constant-time comparison of two hex strings. */
    public static function equalHex(string $a, string $b): bool
    {
        return hash_equals(strtolower($a), strtolower($b));
    }
}

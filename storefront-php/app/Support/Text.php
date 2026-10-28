<?php
declare(strict_types=1);

namespace Pratyangi\Support;

/** Small string helpers that behave like the JavaScript the Node API used. */
final class Text
{
    /** JavaScript's String#trim: also strips Unicode spaces and the byte order mark. */
    public static function trim(string $value): string
    {
        $result = preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $value);
        return $result ?? $value;
    }

    /** Characters, not bytes. */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
    }

    /** A random version 4 UUID. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * JavaScript's Number(string) for the shapes a query string can hold; null stands for NaN.
     * "" and white space are 0, "1e1" is 10, "0x10" is 16.
     */
    public static function jsNumber(string $value): ?float
    {
        $t = self::trim($value);
        if ($t === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D', $t) === 1) {
            return (float) $t;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $t) === 1) {
            return (float) hexdec(substr($t, 2));
        }
        if (preg_match('/^0[bB][01]+$/D', $t) === 1) {
            return (float) bindec(substr($t, 2));
        }
        if (preg_match('/^0[oO][0-7]+$/D', $t) === 1) {
            return (float) octdec(substr($t, 2));
        }
        if (preg_match('/^[+]?Infinity$/D', $t) === 1) {
            return INF;
        }
        if ($t === '-Infinity') {
            return -INF;
        }
        return null;
    }
}

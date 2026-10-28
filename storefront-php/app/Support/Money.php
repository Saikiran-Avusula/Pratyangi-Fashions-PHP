<?php
declare(strict_types=1);

namespace Pratyangi\Support;

/** Money is whole paise inside the server. Rupees exist only as the ERP's exact strings and as numbers in JSON. */
final class Money
{
    /** "1299.50" -> 129950. No floats. Null for anything but a plain non-negative amount with at most two decimals. */
    public static function parseRupees(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', Text::trim($value), $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** "18.00" -> 1800 (basis points). Null unless a plain rate of 0 to 100 with at most two decimals. */
    public static function parseTaxRateBp(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', Text::trim($value), $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $bp <= 10000 ? $bp : null;
    }

    /**
     * A count of hundredths (paise, or basis points) as the JSON number JavaScript would print: 1149950 -> 11499.5, 1150000 -> 11500.
     * A whole value is returned as an int, because PHP would print the float 11500.0 as "11500.0".
     */
    public static function hundredths(int $value): int|float
    {
        return $value % 100 === 0 ? intdiv($value, 100) : $value / 100;
    }

    /** 129950 -> "1299.50" (the text form the ERP expects). */
    public static function toRupeeString(int $paise): string
    {
        return intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}

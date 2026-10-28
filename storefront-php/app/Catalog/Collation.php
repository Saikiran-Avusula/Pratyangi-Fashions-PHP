<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

/**
 * Sorting like JavaScript's localeCompare(b, "en"). Uses ICU (the intl extension) when present. Otherwise an approximation:
 * compare letter by letter ignoring case, with punctuation before digits before letters; when only case differs, lower case first.
 * UNVERIFIED against ICU for names with accents or unusual symbols.
 */
final class Collation
{
    private static ?\Collator $collator = null;
    private static bool $checked = false;

    public static function compare(string $a, string $b): int
    {
        if (!self::$checked) {
            self::$checked = true;
            if (class_exists('Collator')) {
                self::$collator = new \Collator('en');
            }
        }
        if (self::$collator !== null) {
            $r = self::$collator->compare($a, $b);
            return $r === false ? 0 : $r;
        }
        return self::approximate($a, $b);
    }

    private static function approximate(string $a, string $b): int
    {
        $x = mb_str_split(mb_strtolower($a, 'UTF-8'), 1, 'UTF-8');
        $y = mb_str_split(mb_strtolower($b, 'UTF-8'), 1, 'UTF-8');
        $n = min(count($x), count($y));
        for ($i = 0; $i < $n; $i++) {
            if ($x[$i] !== $y[$i]) {
                $ka = self::key($x[$i]);
                $kb = self::key($y[$i]);
                return $ka <=> $kb;
            }
        }
        if (count($x) !== count($y)) {
            return count($x) <=> count($y);
        }
        // same letters: lower case sorts before upper case at the first difference
        $ca = mb_str_split($a, 1, 'UTF-8');
        $cb = mb_str_split($b, 1, 'UTF-8');
        foreach ($ca as $i => $ch) {
            if ($ch !== $cb[$i]) {
                return $ch === mb_strtolower($ch, 'UTF-8') ? -1 : 1;
            }
        }
        return 0;
    }

    /** @return array{0:int,1:int} [class, code point]: space/punctuation, then digits, then letters */
    private static function key(string $ch): array
    {
        if (preg_match('/^\p{L}$/u', $ch) === 1) {
            return [2, mb_ord($ch, 'UTF-8')];
        }
        if (preg_match('/^\p{N}$/u', $ch) === 1) {
            return [1, mb_ord($ch, 'UTF-8')];
        }
        return [0, mb_ord($ch, 'UTF-8')];
    }
}

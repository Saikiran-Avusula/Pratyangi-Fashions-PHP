<?php
declare(strict_types=1);

namespace Pratyangi\Support;

/** The one place the code asks for the time, so tests can replace it. */
class Clock
{
    /** Unix time in seconds with microseconds. */
    public function now(): float
    {
        return microtime(true);
    }

    /** UTC "Y-m-d H:i:s.v" text for a DATETIME(3) column. */
    public static function sql(float $unix): string
    {
        [$seconds, $ms] = self::split($unix);
        return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT);
    }

    /** ISO 8601 with milliseconds and Z, like JavaScript's toISOString(). */
    public static function iso(float $unix): string
    {
        [$seconds, $ms] = self::split($unix);
        return gmdate('Y-m-d\TH:i:s', $seconds) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    /** Reads a DATETIME(3) value (UTC) into unix seconds. */
    public static function fromSql(string $value): float
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $value, $m) !== 1) {
            throw new \UnexpectedValueException('bad datetime value');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
        if ($dt === false) {
            throw new \UnexpectedValueException('bad datetime value');
        }
        $fraction = isset($m[2]) ? (int) str_pad($m[2], 6, '0') / 1000000 : 0.0;
        return (float) $dt->getTimestamp() + $fraction;
    }

    /** @return array{0:int,1:int} whole seconds and milliseconds (nearest, so a DATETIME(3) value survives the float round trip) */
    private static function split(float $unix): array
    {
        $ms = (int) round($unix * 1000);
        $seconds = intdiv($ms, 1000);
        return [$seconds, $ms - $seconds * 1000];
    }
}

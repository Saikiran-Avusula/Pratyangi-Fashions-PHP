<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** Request counts in the shop_rate_limits table (see sql/002_rate_limits.sql). One atomic upsert per hit. */
final class DbRateLimitStore implements RateLimitStore
{
    /** @param \Closure():\PDO $pdo connection is made only when a limit is actually checked */
    public function __construct(private \Closure $pdo)
    {
    }

    public function hit(string $key, int $windowSeconds, int $now): int
    {
        $pdo = ($this->pdo)();
        $start = intdiv($now, $windowSeconds) * $windowSeconds;
        $pdo->prepare('INSERT INTO shop_rate_limits (bucket_key, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$key, $start]);
        $select = $pdo->prepare('SELECT hits FROM shop_rate_limits WHERE bucket_key = ? AND window_start = ?');
        $select->execute([$key, $start]);
        $hits = $select->fetchColumn();
        return $hits === false ? 1 : (int) $hits;
    }

    public function release(string $key, int $windowSeconds, int $now): void
    {
        $start = intdiv($now, $windowSeconds) * $windowSeconds;
        ($this->pdo)()->prepare('UPDATE shop_rate_limits SET hits = hits - 1 WHERE bucket_key = ? AND window_start = ? AND hits > 0')->execute([$key, $start]);
    }

    public function purge(int $olderThan): void
    {
        ($this->pdo)()->prepare('DELETE FROM shop_rate_limits WHERE window_start < ?')->execute([$olderThan]);
    }
}

<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** In-process counts, for tests. */
final class MemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string,int> "key|windowStart" => hits */
    private array $hits = [];

    public function hit(string $key, int $windowSeconds, int $now): int
    {
        $start = intdiv($now, $windowSeconds) * $windowSeconds;
        $id = $key . '|' . $start;
        $this->hits[$id] = ($this->hits[$id] ?? 0) + 1;
        return $this->hits[$id];
    }

    public function release(string $key, int $windowSeconds, int $now): void
    {
        $id = $key . '|' . intdiv($now, $windowSeconds) * $windowSeconds;
        if (($this->hits[$id] ?? 0) > 0) {
            $this->hits[$id]--;
        }
    }

    public function purge(int $olderThan): void
    {
        foreach (array_keys($this->hits) as $id) {
            $start = (int) substr($id, (int) strrpos($id, '|') + 1);
            if ($start < $olderThan) {
                unset($this->hits[$id]);
            }
        }
    }

    public function count(): int
    {
        return count($this->hits);
    }
}

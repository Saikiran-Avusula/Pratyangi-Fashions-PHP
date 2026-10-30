<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** Where request counts live. The database one is real; tests use the in-memory one. */
interface RateLimitStore
{
    /**
     * Counts one hit in the fixed window that contains $now and returns the total for that window.
     * @param string $key bucket + hashed client address
     * @param int $windowSeconds length of the window
     * @param int $now unix seconds
     * @return int hits so far in this window, including this one
     */
    public function hit(string $key, int $windowSeconds, int $now): int;

    /** Takes back one hit of the window that contains $now (a code that could not be sent must not use up the allowance). Never goes below 0. */
    public function release(string $key, int $windowSeconds, int $now): void;

    /** Forgets windows that ended before $olderThan (unix seconds). */
    public function purge(int $olderThan): void;
}

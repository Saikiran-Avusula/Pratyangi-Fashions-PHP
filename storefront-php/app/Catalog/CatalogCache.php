<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

use Pratyangi\Erp\ErpNotConfiguredException;
use Pratyangi\Http\ApiException;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * File cache for the catalogue list, with the Node service's behaviour:
 *  - a copy younger than the TTL is served as is (Cache-Control max-age = whole seconds left; 0 left means no-store);
 *  - one ERP refresh at a time (a lock file; others wait up to 10 s for it and then use the fresh copy);
 *  - when the ERP fails, the last copy is served for up to 10 more minutes (never browser-cached) and a 5 s cool-down starts;
 *  - a refresh lock older than 60 s is ignored, so one hung request cannot block everyone;
 *  - if the cache folder cannot be used the catalogue is still served, just not cached (fails open).
 * Files: catalog.json (the copy, written to a temp file and renamed), failed.json (cool-down marker), refresh.lock.
 */
final class CatalogCache
{
    public const STALE_GRACE_SECONDS = 600;
    public const FAILURE_COOLDOWN_SECONDS = 5;
    public const LOCK_EXPIRY_SECONDS = 60;
    public const WAIT_SECONDS = 10;
    public const TEMP_UNAVAILABLE = 'The catalogue is temporarily unavailable. Please try again in a moment.';

    public function __construct(
        private ?string $dir,
        private int $ttlSeconds,
        private Clock $clock,
        private ?Logger $logger = null,
    ) {
    }

    /**
     * @param \Closure():list<array<string,mixed>> $loader asks the ERP and returns the finished list
     * @return array{products:list<array<string,mixed>>,maxAge:int}
     * @throws ErpNotConfiguredException when there is no ERP (nothing is cached, no cool-down)
     * @throws ApiException 503 when the ERP failed and no usable copy exists
     */
    public function get(\Closure $loader): array
    {
        if ($this->dir === null || !$this->prepareDir()) {
            return $this->loadWithoutCache($loader);
        }
        $copy = $this->readCopy();
        if ($copy !== null && ($fresh = $this->freshMaxAge($copy)) !== null) {
            return ['products' => $copy['products'], 'maxAge' => $fresh];
        }
        if ($this->recentFailure()) {
            return $this->staleOrFail($copy);
        }

        $lock = $this->acquireLock();
        try {
            // somebody else may have refreshed (or failed) while we waited
            $copy = $this->readCopy();
            if ($copy !== null && ($fresh = $this->freshMaxAge($copy)) !== null) {
                return ['products' => $copy['products'], 'maxAge' => $fresh];
            }
            if ($this->recentFailure()) {
                return $this->staleOrFail($copy);
            }
            try {
                $products = $loader();
            } catch (ErpNotConfiguredException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->markFailure();
                $usable = $copy !== null && $this->ageOf($copy) < $this->ttlSeconds + self::STALE_GRACE_SECONDS;
                $this->logger?->exception($usable ? 'catalogue refresh failed, serving the last copy' : 'catalogue could not be loaded', $e);
                return $this->staleOrFail($copy);
            }
            $this->clearFailure();
            if ($this->ttlSeconds > 0) {
                $this->writeCopy($products);
            }
            return ['products' => $products, 'maxAge' => $this->ttlSeconds];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param array{at:float,products:list<array<string,mixed>>} $copy */
    private function ageOf(array $copy): float
    {
        $age = $this->clock->now() - $copy['at'];
        return $age < 0 ? INF : $age; // a clock that went backwards counts as "long ago"
    }

    /** Seconds the browser may keep a fresh copy, or null when the copy is older than the TTL. @param array{at:float,products:list<array<string,mixed>>} $copy */
    private function freshMaxAge(array $copy): ?int
    {
        $age = $this->ageOf($copy);
        if ($age < $this->ttlSeconds) {
            return (int) floor($this->ttlSeconds - $age);
        }
        return null;
    }

    /** @param array{at:float,products:list<array<string,mixed>>}|null $copy @return array{products:list<array<string,mixed>>,maxAge:int} */
    private function staleOrFail(?array $copy): array
    {
        if ($copy !== null && $this->ageOf($copy) < $this->ttlSeconds + self::STALE_GRACE_SECONDS) {
            // old data is never given a browser cache lifetime: the next look must ask us again
            return ['products' => $copy['products'], 'maxAge' => 0];
        }
        throw ApiException::unavailable(self::TEMP_UNAVAILABLE);
    }

    /** @param \Closure():list<array<string,mixed>> $loader @return array{products:list<array<string,mixed>>,maxAge:int} */
    private function loadWithoutCache(\Closure $loader): array
    {
        try {
            return ['products' => $loader(), 'maxAge' => $this->ttlSeconds];
        } catch (ErpNotConfiguredException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->exception('catalogue could not be loaded (no usable cache folder)', $e);
            throw ApiException::unavailable(self::TEMP_UNAVAILABLE);
        }
    }

    private function prepareDir(): bool
    {
        try {
            if (!is_dir((string) $this->dir) && !mkdir((string) $this->dir, 0775, true) && !is_dir((string) $this->dir)) {
                throw new \RuntimeException('cannot create the cache folder');
            }
            if (!is_writable((string) $this->dir)) {
                throw new \RuntimeException('the cache folder is not writable');
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger?->exception('catalogue cache folder unusable, serving without a cache', $e, 'warn');
            return false;
        }
    }

    /** @return array{at:float,products:list<array<string,mixed>>}|null */
    private function readCopy(): ?array
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'catalog.json';
        try {
            if (!is_file($file)) {
                return null;
            }
            $text = file_get_contents($file);
            $data = $text === false ? null : json_decode($text, true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_int($data['at'] ?? null) && !is_float($data['at'] ?? null) || !is_array($data['products'] ?? null)) {
            return null; // missing or damaged: treat as no copy
        }
        return ['at' => (float) $data['at'], 'products' => $data['products']];
    }

    /** @param list<array<string,mixed>> $products */
    private function writeCopy(array $products): void
    {
        $target = $this->dir . DIRECTORY_SEPARATOR . 'catalog.json';
        $tmp = null;
        try {
            $tmp = tempnam((string) $this->dir, 'tmp');
            if ($tmp === false) {
                throw new \RuntimeException('cannot create a temp file');
            }
            $json = json_encode(['v' => 1, 'at' => $this->clock->now(), 'products' => $products], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json) === false) {
                throw new \RuntimeException('cannot write the cache');
            }
            // atomic swap: readers see the old complete file or the new complete file
            if (!rename($tmp, $target)) {
                throw new \RuntimeException('cannot replace the cache file');
            }
        } catch (\Throwable $e) {
            $this->logger?->exception('catalogue cache could not be written', $e, 'warn');
            if (is_string($tmp) && is_file($tmp)) {
                try {
                    unlink($tmp);
                } catch (\Throwable) {
                    // nothing more to do
                }
            }
        }
    }

    private function recentFailure(): bool
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'failed.json';
        try {
            if (!is_file($file)) {
                return false;
            }
            $at = (float) trim((string) file_get_contents($file));
        } catch (\Throwable) {
            return false;
        }
        $age = $this->clock->now() - $at;
        return $age >= 0 && $age < self::FAILURE_COOLDOWN_SECONDS;
    }

    private function markFailure(): void
    {
        try {
            file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'failed.json', (string) $this->clock->now(), LOCK_EX);
        } catch (\Throwable) {
            // the cool-down is a courtesy to the ERP; losing it is harmless
        }
    }

    private function clearFailure(): void
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'failed.json';
        try {
            if (is_file($file)) {
                unlink($file);
            }
        } catch (\Throwable) {
            // see markFailure
        }
    }

    /**
     * Takes the single-refresh lock. Waits up to WAIT_SECONDS for another refresh to end; a lock file untouched for
     * LOCK_EXPIRY_SECONDS is treated as dead and ignored. Returns the open handle, or null when we go on without the lock.
     * @return resource|null
     */
    private function acquireLock(): mixed
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'refresh.lock';
        try {
            $handle = fopen($path, 'c+');
        } catch (\Throwable) {
            return null;
        }
        if ($handle === false) {
            return null;
        }
        $waited = 0.0;
        while (true) {
            try {
                $got = flock($handle, LOCK_EX | LOCK_NB);
            } catch (\Throwable) {
                $got = false;
            }
            if ($got) {
                try {
                    touch($path); // marks when this refresh started
                } catch (\Throwable) {
                    // only used to detect a hung holder
                }
                return $handle;
            }
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            $startedAgo = $mtime === false ? 0.0 : $this->clock->now() - $mtime;
            if ($startedAgo >= self::LOCK_EXPIRY_SECONDS || $waited >= self::WAIT_SECONDS) {
                fclose($handle);
                return null;
            }
            usleep(50000);
            $waited += 0.05;
        }
    }
}

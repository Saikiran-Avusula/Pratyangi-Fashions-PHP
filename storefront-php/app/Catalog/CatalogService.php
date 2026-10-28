<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

use Pratyangi\Erp\ErpNotConfiguredException;
use Pratyangi\Erp\ErpPort;
use Pratyangi\Http\ApiException;
use Pratyangi\Support\Logger;
use Pratyangi\Support\Text;

/** The catalogue list: asks the ERP (products, then stock) through the cache, filters, pages. */
final class CatalogService
{
    /** An ERP refresh that has not finished by then counts as failed. */
    public const REFRESH_SECONDS = 10;

    public function __construct(
        private ErpPort $erp,
        private CatalogCache $cache,
        private ?Logger $logger = null,
        private float $refreshSeconds = self::REFRESH_SECONDS,
    ) {
    }

    /**
     * @param array{category:?string,q:?string,limit:int,cursor:?string} $query already validated
     * @return array{page:array{products:list<array<string,mixed>>,total:int,nextCursor:?string},maxAge:int}
     */
    public function list(array $query): array
    {
        try {
            $all = $this->cache->get(fn (): array => $this->load());
        } catch (ErpNotConfiguredException) {
            throw ApiException::unavailable('The catalogue is not available yet. Please try again later.');
        }
        $wantedCategory = ($query['category'] ?? null) !== null && $query['category'] !== '' ? Text::lower($query['category']) : null;
        $needle = ($query['q'] ?? null) !== null && $query['q'] !== '' ? Text::lower($query['q']) : null;
        $matches = [];
        foreach ($all['products'] as $item) {
            if ($wantedCategory !== null && ($item['category'] === null || Text::lower($item['category']) !== $wantedCategory)) {
                continue;
            }
            if ($needle !== null && !self::matches($item, $needle)) {
                continue;
            }
            $matches[] = $item;
        }
        $start = $query['cursor'] !== null ? (int) $query['cursor'] : 0;
        $end = $start + $query['limit'];
        return [
            'page' => [
                'products' => array_slice($matches, $start, $query['limit']),
                'total' => count($matches),
                'nextCursor' => $end < count($matches) ? (string) $end : null,
            ],
            'maxAge' => $all['maxAge'],
        ];
    }

    /** One ERP refresh: product pages, then stock for the sellable ones. @return list<array<string,mixed>> */
    private function load(): array
    {
        $deadline = microtime(true) + $this->refreshSeconds;
        $listed = $this->erp->listProducts($deadline);
        if ($listed['skipped'] > 0) {
            $this->logger?->warn('the ERP sent product rows that were unusable or repeated, and they were skipped', ['skipped' => $listed['skipped']]);
        }
        $sellable = array_values(array_filter($listed['products'], static fn ($p): bool => CatalogMapper::isSellable($p)));
        $availability = $this->erp->getAvailability(array_map(static fn ($p): string => $p->sku, $sellable), $deadline);
        return CatalogMapper::buildList($sellable, $availability);
    }

    /** @param array<string,mixed> $item */
    private static function matches(array $item, string $needle): bool
    {
        foreach (['name', 'sku', 'category', 'fabric', 'color'] as $field) {
            if (is_string($item[$field]) && Text::contains(Text::lower($item[$field]), $needle)) {
                return true;
            }
        }
        return false;
    }
}

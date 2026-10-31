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
        $needle = ($query['q'] ?? null) !== null && $query['q'] !== '' ? self::tokens($query['q']) : null;
        $matches = [];
        $rank = [];
        foreach ($all['products'] as $item) {
            if ($wantedCategory !== null && ($item['category'] === null || Text::lower($item['category']) !== $wantedCategory)) {
                continue;
            }
            if ($needle !== null) {
                $score = self::score($item, $needle);
                if ($score === null) {
                    continue;
                }
                $rank[count($matches)] = $score;
            }
            $matches[] = $item;
        }
        if ($needle !== null) { // best first; equal scores keep the catalogue order
            $order = array_keys($matches);
            usort($order, static fn (int $a, int $b): int => [$rank[$a], $a] <=> [$rank[$b], $b]);
            $matches = array_map(static fn (int $i): array => $matches[$i], $order);
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

    /** Lower-case words of a text; a typed query keeps only words of 2+ characters. @return list<string> */
    private static function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', Text::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Every typed word (2+ characters) must start a word of the name, fabric, category or colour. Lower is better:
     * 0 name starts with it, 1 a name word, 2 fabric/category, 3 colour; an item scores its worst word. Null = no match.
     * shortcut: catalogue items carry no tags, so tags are not searched; add them here when the ERP sends them.
     * @param array<string,mixed> $item @param list<string> $words
     */
    private static function score(array $item, array $words): ?int
    {
        $words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w, 'UTF-8') >= 2));
        if ($words === []) {
            return null;
        }
        $worst = 0;
        foreach ($words as $w) {
            $best = null;
            foreach (['name', 'fabric', 'category', 'color'] as $field) {
                foreach (is_string($item[$field] ?? null) ? self::tokens($item[$field]) : [] as $pos => $token) {
                    if (str_starts_with($token, $w)) {
                        $best = min($best ?? 9, $field === 'name' ? ($pos === 0 ? 0 : 1) : ($field === 'color' ? 3 : 2));
                    }
                }
            }
            if ($best === null) {
                return null;
            }
            $worst = max($worst, $best);
        }
        return $worst;
    }
}

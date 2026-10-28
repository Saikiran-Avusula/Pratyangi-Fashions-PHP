<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

use Pratyangi\Erp\ErpProduct;
use Pratyangi\Support\Money;

/**
 * What the website may know about a sellable item: exactly 12 keys, one entry per ERP SKU (no grouping yet).
 * Wholesale/cost prices never appear, and stock is only a capped quantity.
 */
final class CatalogMapper
{
    public const MAX_REPORTED_QUANTITY = 10;
    /** Rs 10,00,000: a sanity bound against bad ERP data. An item priced above it (or at 0) is left out. */
    public const MAX_PRICE_PAISE = 100000000;

    public static function isSellable(ErpProduct $p): bool
    {
        return $p->sellingPricePaise > 0 && $p->sellingPricePaise <= self::MAX_PRICE_PAISE;
    }

    /** @return array<string,mixed> */
    public static function toDto(ErpProduct $p, int $pieces): array
    {
        $pieces = max($pieces, 0);
        // an MRP that is missing, invalid, above the price bound or below the selling price cannot be a real discount
        $mrpUsable = $p->mrpPaise >= $p->sellingPricePaise && $p->mrpPaise <= self::MAX_PRICE_PAISE;
        return [
            'sku' => $p->sku,
            'name' => $p->name,
            'category' => $p->category,
            'fabric' => $p->fabric,
            'color' => $p->color,
            'size' => $p->size,
            'price' => Money::hundredths($p->sellingPricePaise),
            'mrp' => Money::hundredths($mrpUsable ? $p->mrpPaise : $p->sellingPricePaise),
            'taxPercent' => Money::hundredths($p->taxRateBp),
            'imagePath' => self::verifiedImagePath($p->imagePath),
            'inStock' => $pieces > 0,
            'availableQuantity' => min($pieces, self::MAX_REPORTED_QUANTITY),
        ];
    }

    /** Only picture files under uploads/products/ are exposed; URLs, other folders, other file types and ".." segments are dropped. */
    public static function verifiedImagePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $shape = '/^uploads\/products\/[A-Za-z0-9_-][A-Za-z0-9._-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$/D';
        return preg_match($shape, $path) === 1 && preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/Di', $path) === 1 ? $path : null;
    }

    /** Builds the cached list: sellable items only, with stock, sorted by name then SKU. @param list<ErpProduct> $products @param array<string,int> $availability @return list<array<string,mixed>> */
    public static function buildList(array $products, array $availability): array
    {
        $dtos = [];
        foreach ($products as $p) {
            if (self::isSellable($p)) {
                $dtos[] = self::toDto($p, $availability[$p->sku] ?? 0);
            }
        }
        usort($dtos, static fn (array $a, array $b): int => Collation::compare($a['name'], $b['name']) ?: Collation::compare($a['sku'], $b['sku']));
        return $dtos;
    }
}

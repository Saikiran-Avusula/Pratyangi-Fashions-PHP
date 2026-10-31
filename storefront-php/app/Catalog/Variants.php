<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

/**
 * The ONE place that says which sizes a product comes in (UI10). DEMO: the ERP has no variant field yet, so sizes are mocked here.
 * shortcut: mock sizes (S-XXL, one sold out per product, picked from the SKU), replace with the ERP variant group (design_model/pattern or a new field) when it exists.
 * Only a product whose size is one of S-XXL gets a picker; "Free"/"Free Size", kids' ages, no size, or a saree: null.
 */
final class Variants
{
    public const SIZES = ['S', 'M', 'L', 'XL', 'XXL'];

    /** @param array<string,mixed> $p a catalogue product @return list<array{size:string,inStock:bool}>|null */
    public static function sizes(array $p): ?array
    {
        $size = trim((string) ($p['size'] ?? ''));
        $saree = stripos((string) ($p['category'] ?? '') . ' ' . (string) ($p['name'] ?? ''), 'saree') !== false;
        if (!in_array(strtoupper($size), self::SIZES, true) || $saree || !($p['inStock'] ?? false)) { // "Free", "4-5Y", empty: no picker
            return null;
        }
        $soldOut = crc32((string) $p['sku']) % count(self::SIZES);
        return array_map(static fn (string $s, int $i): array => ['size' => $s, 'inStock' => $i !== $soldOut], self::SIZES, array_keys(self::SIZES));
    }

    /** "S:1,M:1,L:0,..." for the data-sizes attribute site.js reads; '' = no picker. */
    public static function attr(array $p): string
    {
        $sizes = self::sizes($p);
        return $sizes === null ? '' : implode(',', array_map(static fn (array $v): string => $v['size'] . ':' . ($v['inStock'] ? '1' : '0'), $sizes));
    }
}

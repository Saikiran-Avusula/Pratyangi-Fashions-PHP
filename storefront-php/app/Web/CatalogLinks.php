<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Catalog\CatalogService;

/** The addresses of the catalogue pages, built in one place like the web's lib/catalog.ts (productHref, catalogLink). */
final class CatalogLinks
{
    /** Where "View bag" and the header bag icon go. */
    public const BAG = '/bag';

    /** Fabric names the Shop menu and the search box link to (the web's `fabrics`). */
    public const FABRICS = [
        'Uppada Pattu Saree',
        'Pattu Saree',
        'Pure Silk Saree',
        'Kanchi Pattu Saree',
        'Bangalore Silk Saree',
        'Banarasi Saree',
        'Dharmavaram Saree',
    ];

    /** /product?sku=<encodeURIComponent(sku)>. Not yet HTML-escaped: pass it through View::e when printing it. */
    public static function productHref(string $sku): string
    {
        // encodeURIComponent keeps ! * ' ( ) as they are; rawurlencode does not
        return '/product?sku=' . strtr(rawurlencode($sku), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    private static ?CatalogService $catalog = null;

    /** App gives the links the catalogue (cached) so a link can be checked against it. */
    public static function init(?CatalogService $catalog): void
    {
        self::$catalog = $catalog;
    }

    /**
     * Where a footer collection goes: its listing ($fabric '' = all of New Arrivals); when the catalogue has no product of that fabric (or the
     * collection has none, $fabric null) the search for its name; null when that finds nothing either, so the link is not shown.
     * An unreadable catalogue changes nothing: the plain link stays.
     */
    public static function collectionLink(string $name, ?string $fabric): ?string
    {
        if ($fabric === '') {
            return self::catalogLink();
        }
        $plain = $fabric === null ? '/search?q=' . rawurlencode($name) : self::catalogLink($fabric);
        if (self::$catalog === null) {
            return $plain;
        }
        try {
            if ($fabric !== null && self::matching($fabric, static fn (array $p): bool => ($p['fabric'] ?? null) === $fabric)) {
                return $plain;
            }
            return self::matching($name, static fn (): bool => true) ? '/search?q=' . rawurlencode($name) : null;
        } catch (\Throwable) {
            return $plain;
        }
    }

    public static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    /** slug => category name for every category the catalogue has a product in (empty when it cannot be read). */
    public static function collections(): array
    {
        if (self::$catalog === null) {
            return [];
        }
        try {
            $out = [];
            foreach (self::$catalog->list(['category' => null, 'q' => null, 'limit' => 2000, 'cursor' => null])['page']['products'] as $p) {
                $name = trim((string) ($p['category'] ?? ''));
                if (self::slug($name) !== '') {
                    $out[self::slug($name)] = $name;
                }
            }
            ksort($out);
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param \Closure(array<string,mixed>):bool $ok */
    private static function matching(string $q, \Closure $ok): bool
    {
        foreach (self::$catalog->list(['category' => null, 'q' => $q, 'limit' => 100, 'cursor' => null])['page']['products'] as $p) {
            if ($ok($p)) {
                return true;
            }
        }
        return false;
    }

    /** /new-arrivals with a fabric or an occasion ticked (URLSearchParams spelling: a space is "+"). */
    public static function catalogLink(?string $fabric = null, ?string $occasion = null): string
    {
        $params = [];
        if ($fabric !== null && $fabric !== '') {
            $params[] = 'fabric=' . self::form($fabric);
        }
        if ($occasion !== null && $occasion !== '') {
            $params[] = 'occasion=' . self::form($occasion);
        }
        return $params === [] ? '/new-arrivals' : '/new-arrivals?' . implode('&', $params);
    }

    private static function form(string $value): string
    {
        return strtr(urlencode($value), ['%2A' => '*']);
    }
}

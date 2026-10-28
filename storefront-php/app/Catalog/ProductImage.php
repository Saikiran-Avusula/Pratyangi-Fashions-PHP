<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

/**
 * Where a product picture is loaded from. The ERP serves its pictures at its own address: picture address = ERP base address + "/" + image_path
 * (image_path as the catalogue gives it, already checked by CatalogMapper::verifiedImagePath). A product without a picture, or no ERP address
 * (mock or off), shows the placeholder.
 */
final class ProductImage
{
    public const PLACEHOLDER = '/assets/images/reference/product-1.webp';

    private static ?string $base = null;

    public static function init(?string $erpBaseUrl): void
    {
        self::$base = $erpBaseUrl === null || $erpBaseUrl === '' ? null : rtrim($erpBaseUrl, '/');
    }

    /** The ERP base address without a trailing slash, or '' when pictures are not loaded from the ERP. */
    public static function base(): string
    {
        return self::$base ?? '';
    }

    public static function url(?string $imagePath): string
    {
        return self::$base === null || $imagePath === null || $imagePath === '' ? self::PLACEHOLDER : self::$base . '/' . ltrim($imagePath, '/');
    }

    /** "https://erp.example.com" (scheme, host and port) for the page's Content-Security-Policy, or null. */
    public static function origin(): ?string
    {
        if (self::$base === null) {
            return null;
        }
        $p = parse_url(self::$base);
        return is_array($p) && isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : null;
    }
}

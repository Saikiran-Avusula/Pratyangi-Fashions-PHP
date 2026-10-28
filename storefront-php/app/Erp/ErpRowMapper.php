<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

use Pratyangi\Support\Money;
use Pratyangi\Support\Text;

/**
 * One ERP product row -> ErpProduct, or null when the row cannot be used (it is then skipped and counted, never fatal).
 *
 * Business rules decided here (placeholders until the shop confirms them, settings ERP_PRICE_RULE and ERP_TAX_MODE):
 *  - selling price: a positive offer_price, otherwise retail_price ("retail-only": always retail_price);
 *  - tax: tax_rate is added ON TOP of the price at checkout; with tax mode "none" it is ignored (0);
 *  - wholesale_price, dealer price and cost are never read.
 */
final class ErpRowMapper
{
    /** The 13 keys every row must carry, each a string or null. */
    private const KEYS = ['sku', 'name', 'category', 'fabric', 'pattern_name', 'design_model', 'color', 'size', 'mrp', 'retail_price', 'offer_price', 'tax_rate', 'image_path'];

    public static function map(mixed $raw, string $priceRule, string $taxMode): ?ErpProduct
    {
        if (!is_array($raw)) {
            return null;
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $raw) || ($raw[$key] !== null && !is_string($raw[$key]))) {
                return null;
            }
        }
        $sku = Text::trim((string) $raw['sku']);
        $skuLength = Text::length($sku);
        if ($skuLength < 1 || $skuLength > 60) {
            return null;
        }
        $name = Text::trim((string) $raw['name']);
        if ($name === '') {
            return null;
        }
        $retail = Money::parseRupees($raw['retail_price']);
        $offer = Money::parseRupees($raw['offer_price']);
        $mrp = Money::parseRupees($raw['mrp']);
        if ($retail === null && $offer === null) {
            return null;
        }
        // with tax on, a product whose tax rate cannot be read is unusable: selling it would quietly leave the tax out
        $taxBp = $taxMode === 'none' ? 0 : Money::parseTaxRateBp($raw['tax_rate']);
        if ($taxBp === null) {
            return null;
        }
        $selling = ($priceRule === 'offer-else-retail' && $offer !== null && $offer > 0) ? $offer : ($retail ?? 0);
        return new ErpProduct(
            $sku,
            $name,
            self::nonEmpty($raw['category']),
            self::nonEmpty($raw['fabric']),
            self::nonEmpty($raw['color']),
            self::nonEmpty($raw['size']),
            $mrp ?? 0,
            $selling,
            $taxBp,
            self::nonEmpty($raw['image_path']),
        );
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $t = Text::trim($value);
        return $t === '' ? null : $t;
    }
}

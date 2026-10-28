<?php
declare(strict_types=1);

namespace Pratyangi\Orders;

use Pratyangi\Http\ApiException;

/** The customer-facing order errors (docs/API_CONTRACT.md 2.5/2.6): message, code, skus, retrySafe. */
final class OrderProblem
{
    public static function invalid(string $message): ApiException
    {
        return new ApiException(422, $message, null, null, 'invalid');
    }

    /** @param list<string> $skus */
    public static function unknownSku(array $skus): ApiException
    {
        return new ApiException(409, 'Some items in your bag are no longer sold.', null, null, 'unknown-sku', $skus);
    }

    /** @param list<string> $skus */
    public static function priceUnavailable(array $skus): ApiException
    {
        return new ApiException(409, 'Some items in your bag cannot be priced right now.', null, null, 'price-unavailable', $skus);
    }

    /** @param list<string> $skus */
    public static function outOfStock(array $skus): ApiException
    {
        return new ApiException(409, 'Sorry, some items are no longer available in the quantity you asked for.', null, null, 'out-of-stock', $skus);
    }

    public static function erpUnavailable(string $message = 'We could not check prices right now. Nothing was ordered; please try again in a moment.'): ApiException
    {
        return new ApiException(503, $message, null, null, 'erp-unavailable', null, true);
    }

    public static function keyReused(string $message): ApiException
    {
        return new ApiException(409, $message, null, null, 'key-reused');
    }

    public static function disabled(string $code, string $message): ApiException
    {
        return new ApiException(503, $message, null, null, $code);
    }
}

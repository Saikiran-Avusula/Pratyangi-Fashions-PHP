<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/**
 * The ERP understood an order and said no. The ERP's own words are only used to pick the kind and never leave this class.
 * out-of-stock / unknown-sku / product-unavailable are final for a paid order (refund by hand); retryable / config / rejected / invalid are not.
 */
final class ErpOrderRejectedException extends \RuntimeException
{
    /**
     * @param 'out-of-stock'|'unknown-sku'|'product-unavailable'|'unprocessable'|'retryable'|'config'|'rejected'|'invalid' $kind
     * @param list<string> $skus
     */
    public function __construct(string $message, public readonly string $kind, public readonly array $skus = [])
    {
        parent::__construct($message);
    }

    public function isFinal(): bool
    {
        return in_array($this->kind, ['out-of-stock', 'unknown-sku', 'product-unavailable', 'unprocessable'], true);
    }
}

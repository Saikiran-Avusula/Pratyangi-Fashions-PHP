<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/**
 * A sellable item as the website needs it. The ERP owns every field. Money is whole paise; wholesale, dealer and cost prices
 * are never read. The selling price does NOT include tax; taxRateBp (1800 = 18.00%) is added on top at checkout.
 */
final class ErpProduct
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $category,
        public readonly ?string $fabric,
        public readonly ?string $color,
        public readonly ?string $size,
        public readonly int $mrpPaise,
        public readonly int $sellingPricePaise,
        public readonly int $taxRateBp,
        public readonly ?string $imagePath,
    ) {
    }
}

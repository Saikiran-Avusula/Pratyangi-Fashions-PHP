<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/**
 * Development and test ONLY (Config refuses ERP_MODE=mock in production). Reads a local JSON fixture shaped like the ERP's answers:
 *   {"products":[ {ERP product row}, ... ], "stock": {"SKU": pieces, ...}}
 * Rows go through the same ErpRowMapper as real ones, so the fixture exercises the real rules. Nothing here is real stock or a real price.
 */
final class MockErp implements ErpPort
{
    public function __construct(
        private string $file,
        private string $priceRule = 'offer-else-retail',
        private string $taxMode = 'exclusive',
    ) {
    }

    public function listProducts(?float $deadline = null): array
    {
        $data = $this->read();
        $rows = $data['products'] ?? null;
        if (!is_array($rows)) {
            throw new ErpException('the mock ERP fixture has no products list', 'bad-response');
        }
        $products = [];
        $seen = [];
        $skipped = 0;
        foreach ($rows as $raw) {
            $product = ErpRowMapper::map($raw, $this->priceRule, $this->taxMode);
            if ($product === null || isset($seen[$product->sku])) {
                $skipped++;
                continue;
            }
            $seen[$product->sku] = true;
            $products[] = $product;
        }
        return ['products' => $products, 'skipped' => $skipped];
    }

    public function getAvailability(array $skus, ?float $deadline = null): array
    {
        $stock = $this->read()['stock'] ?? [];
        $out = [];
        foreach (array_unique($skus) as $sku) {
            if (is_array($stock) && isset($stock[$sku]) && is_int($stock[$sku]) && $stock[$sku] >= 0) {
                $out[$sku] = $stock[$sku];
            }
        }
        return $out;
    }

    /** A fixture ERP accepts every order and invents an id. Only for development and tests (Config refuses ERP_MODE=mock in production). */
    public function createOrder(array $order): array
    {
        return ["erpOrderId" => 1, "erpOrderNo" => $order["orderNo"], "status" => "ALLOCATED", "duplicate" => false];
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        $text = is_file($this->file) ? file_get_contents($this->file) : false;
        $data = $text === false ? null : json_decode($text, true);
        if (!is_array($data)) {
            throw new ErpException('the mock ERP fixture file is missing or is not valid JSON', 'bad-response');
        }
        return $data;
    }
}

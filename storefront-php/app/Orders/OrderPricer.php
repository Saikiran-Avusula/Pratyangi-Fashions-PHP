<?php
declare(strict_types=1);

namespace Pratyangi\Orders;

use Pratyangi\Erp\ErpException;
use Pratyangi\Erp\ErpNotConfiguredException;
use Pratyangi\Erp\ErpPort;

/**
 * Prices a bag from the ERP at this moment (never from the browser): offer-else-retail price per unit, tax added on top line by line
 * (floor((unit x qty x rateBp + 5000) / 10000) paise, all integers), the same rule as the web's lib/pricing.ts.
 */
final class OrderPricer
{
    public const MAX_PRICE_PAISE = 100000000;
    public const MAX_ORDER_PAISE = 2000000000;

    /**
     * @param list<array{sku:string,quantity:int}> $rawLines already shape-checked: 1 to 20 lines, quantity 1 to 10
     * @return array{lines:list<array{sku:string,name:string,quantity:int,unitPricePaise:int,taxRateBp:int}>,itemsTotalPaise:int,taxPaise:int,totalPaise:int}
     * @throws \Pratyangi\Http\ApiException
     */
    public static function price(ErpPort $erp, array $rawLines): array
    {
        $wanted = [];
        foreach ($rawLines as $line) {
            $wanted[$line['sku']] = ($wanted[$line['sku']] ?? 0) + $line['quantity']; // the same SKU twice becomes one line
        }
        if (max($wanted) > 10) {
            throw OrderProblem::invalid('You can order up to 10 of one item.');
        }
        try {
            $products = $erp->listProducts()['products'];
        } catch (ErpException|ErpNotConfiguredException) {
            throw OrderProblem::erpUnavailable();
        }
        $bySku = [];
        foreach ($products as $p) {
            $bySku[$p->sku] = $p;
        }
        $missing = array_values(array_filter(array_keys($wanted), static fn (string $sku): bool => !isset($bySku[$sku])));
        if ($missing !== []) {
            throw OrderProblem::unknownSku($missing);
        }
        $unpriced = array_values(array_filter(array_keys($wanted), static fn (string $sku): bool => $bySku[$sku]->sellingPricePaise < 1 || $bySku[$sku]->sellingPricePaise > self::MAX_PRICE_PAISE));
        if ($unpriced !== []) {
            throw OrderProblem::priceUnavailable($unpriced);
        }
        $lines = [];
        $items = 0;
        $tax = 0;
        foreach ($wanted as $sku => $quantity) {
            $p = $bySku[$sku];
            $lines[] = ['sku' => $sku, 'name' => $p->name, 'quantity' => $quantity, 'unitPricePaise' => $p->sellingPricePaise, 'taxRateBp' => $p->taxRateBp];
            $items += $p->sellingPricePaise * $quantity;
            $tax += intdiv($p->sellingPricePaise * $quantity * $p->taxRateBp + 5000, 10000);
        }
        if ($items + $tax > self::MAX_ORDER_PAISE) {
            throw OrderProblem::invalid('This order is too large to place online. Please contact the shop.');
        }
        return ['lines' => $lines, 'itemsTotalPaise' => $items, 'taxPaise' => $tax, 'totalPaise' => $items + $tax];
    }
}

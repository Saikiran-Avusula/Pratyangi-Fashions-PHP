<?php
declare(strict_types=1);

namespace Pratyangi\Orders;

use Pratyangi\Support\Clock;
use Pratyangi\Support\Money;

/** One row of shop_orders with its items. Money is whole paise; times are unix seconds (UTC). */
final class StoredOrder
{
    public const PENDING = 'PAYMENT_PENDING';
    public const PAID_PLACING = 'PAID_PLACING';
    public const PLACED = 'PLACED';
    public const NOT_FULFILLED = 'PAID_NOT_FULFILLED';
    /** States after a verified payment. */
    public const PAID_STATES = [self::PAID_PLACING, self::PLACED, self::NOT_FULFILLED];

    private const DTO_STATUS = [
        'PLACED' => 'placed', 'FAILED' => 'failed', 'PLACING' => 'confirming', 'UNCONFIRMED' => 'confirming',
        'PAYMENT_PENDING' => 'awaiting-payment', 'PAID_PLACING' => 'paid-confirming', 'PAID_NOT_FULFILLED' => 'paid-unfulfilled',
    ];

    /**
     * @param array<string,string> $shipTo name, phone, house, street, city, state, pincode
     * @param list<array{sku:string,name:string,quantity:int,unitPricePaise:int}> $items
     */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNo,
        public readonly int $customerId,
        public readonly string $idempotencyKey,
        public readonly string $paymentMethod,
        public readonly string $status,
        public readonly int $itemsTotalPaise,
        public readonly int $taxPaise,
        public readonly int $totalPaise,
        public readonly array $shipTo,
        public readonly array $items,
        public readonly ?string $paymentRef,
        public readonly ?int $paidAmountPaise,
        public readonly ?float $paidAt,
        public readonly ?string $razorpayOrderId,
        public readonly ?string $razorpayKeyTag,
        public readonly ?float $razorpayOrderAt,
        public readonly float $createdAt,
        public readonly ?float $placedAt,
    ) {
    }

    /** @param array<string,mixed> $r @param list<array{sku:string,name:string,quantity:int,unitPricePaise:int}> $items */
    public static function fromRow(array $r, array $items): self
    {
        $time = static fn (mixed $v): ?float => $v === null ? null : Clock::fromSql((string) $v);
        return new self(
            (int) $r['id'], (string) $r['order_no'], (int) $r['customer_id'], (string) $r['idempotency_key'], (string) $r['payment_method'], (string) $r['status'],
            (int) $r['items_total_paise'], (int) $r['tax_paise'], (int) $r['total_paise'],
            ['name' => (string) $r['ship_name'], 'phone' => (string) $r['ship_phone'], 'house' => (string) $r['ship_house'], 'street' => (string) $r['ship_street'],
                'city' => (string) $r['ship_city'], 'state' => (string) $r['ship_state'], 'pincode' => (string) $r['ship_pincode']],
            $items,
            $r['payment_ref'] === null ? null : (string) $r['payment_ref'],
            $r['paid_amount_paise'] === null ? null : (int) $r['paid_amount_paise'],
            $time($r['paid_at']),
            $r['razorpay_order_id'] === null ? null : (string) $r['razorpay_order_id'],
            $r['razorpay_key_tag'] === null ? null : (string) $r['razorpay_key_tag'],
            $time($r['razorpay_order_at']),
            (float) $time($r['created_at']),
            $time($r['placed_at']),
        );
    }

    /** "house, street, city, state - pincode" (the one address text the ERP keeps). */
    public function addressText(): string
    {
        $s = $this->shipTo;
        return $s['house'] . ', ' . $s['street'] . ', ' . $s['city'] . ', ' . $s['state'] . ' - ' . $s['pincode'];
    }

    /**
     * The order as the browser sees it (docs/API_CONTRACT.md 2.5). No ERP ids, attempts, failure codes, customer id or idempotency key.
     * @return array<string,mixed>
     */
    public function toDto(): array
    {
        return [
            'orderNo' => $this->orderNo,
            'status' => self::DTO_STATUS[$this->status] ?? 'confirming',
            'paymentMethod' => $this->paymentMethod === 'RAZORPAY' ? 'RAZORPAY' : 'COD',
            'paid' => $this->paidAt !== null,
            'paidAt' => $this->paidAt === null ? null : Clock::iso($this->paidAt),
            'itemsTotal' => Money::hundredths($this->itemsTotalPaise),
            'tax' => Money::hundredths($this->taxPaise),
            'total' => Money::hundredths($this->totalPaise),
            'items' => array_map(static fn (array $i): array => [
                'sku' => $i['sku'], 'name' => $i['name'], 'quantity' => $i['quantity'],
                'unitPrice' => Money::hundredths($i['unitPricePaise']), 'lineTotal' => Money::hundredths($i['unitPricePaise'] * $i['quantity']),
            ], $this->items),
            'shipTo' => ['name' => $this->shipTo['name'], 'phone' => $this->shipTo['phone'], 'address' => $this->addressText()],
            'createdAt' => Clock::iso($this->createdAt),
            'placedAt' => $this->placedAt === null ? null : Clock::iso($this->placedAt),
        ];
    }
}

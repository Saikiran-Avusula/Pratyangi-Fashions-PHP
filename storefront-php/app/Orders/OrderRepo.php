<?php
declare(strict_types=1);

namespace Pratyangi\Orders;

use Pratyangi\Support\Clock;

/** All SQL for shop_orders, shop_order_items and shop_razorpay_orders. Prepared statements only. Every state change is one conditional UPDATE. */
final class OrderRepo
{
    public function __construct(private \PDO $pdo, private Clock $clock)
    {
    }

    public function findByKey(int $customerId, string $key): ?StoredOrder
    {
        return $this->one('SELECT * FROM shop_orders WHERE customer_id = ? AND idempotency_key = ? LIMIT 1', [$customerId, $key]);
    }

    /** The orders the customer can see (a payment that never started or failed is not an order to them, as in GET /orders). */
    public function countForCustomer(int $customerId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM shop_orders WHERE customer_id = ? AND status NOT IN ('FAILED', 'PAYMENT_PENDING')");
        $st->execute([$customerId]);
        return (int) $st->fetchColumn();
    }

    public function findById(int $id): ?StoredOrder
    {
        return $this->one('SELECT * FROM shop_orders WHERE id = ? LIMIT 1', [$id]);
    }

    /** By ANY Razorpay order id ever issued for the order (the history table), so a late payment on a replaced id still finds it. $customerId null = any customer. */
    public function findByRazorpayOrderId(?int $customerId, string $razorpayOrderId): ?StoredOrder
    {
        return $this->one(
            'SELECT o.* FROM shop_razorpay_orders r JOIN shop_orders o ON o.id = r.order_id WHERE r.razorpay_order_id = ?' . ($customerId === null ? '' : ' AND o.customer_id = ?') . ' LIMIT 1',
            $customerId === null ? [$razorpayOrderId] : [$razorpayOrderId, $customerId],
        );
    }

    /**
     * Saves a new PAYMENT_PENDING order with its items. If this customer already used the key (two requests at once) the stored order comes back with created = false.
     * @param array<string,mixed> $o orderNo, customerId, key, itemsTotalPaise, taxPaise, totalPaise, shipTo, items
     * @return array{order:StoredOrder,created:bool}
     * @throws OrderNumberTakenException
     */
    public function create(array $o): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO shop_orders (order_no, customer_id, idempotency_key, payment_method, status, items_total_paise, tax_paise, total_paise,
                    ship_name, ship_phone, ship_house, ship_street, ship_city, ship_state, ship_pincode, created_at)
                 VALUES (?, ?, ?, \'RAZORPAY\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $o['orderNo'], $o['customerId'], $o['key'], StoredOrder::PENDING, $o['itemsTotalPaise'], $o['taxPaise'], $o['totalPaise'],
                $o['shipTo']['name'], $o['shipTo']['phone'], $o['shipTo']['house'], $o['shipTo']['street'], $o['shipTo']['city'], $o['shipTo']['state'], $o['shipTo']['pincode'],
                Clock::sql($this->clock->now()),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $item = $this->pdo->prepare('INSERT INTO shop_order_items (order_id, sku, name, quantity, unit_price_paise) VALUES (?, ?, ?, ?, ?)');
            foreach ($o['items'] as $i) {
                $item->execute([$id, $i['sku'], $i['name'], $i['quantity'], $i['unitPricePaise']]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1062) {
                if (str_contains((string) $e->getMessage(), 'uq_shop_orders_order_no')) {
                    throw new OrderNumberTakenException();
                }
                $existing = $this->findByKey($o['customerId'], $o['key']);
                if ($existing !== null) {
                    return ['order' => $existing, 'created' => false];
                }
            }
            throw $e;
        }
        return ['order' => $this->findById($id) ?? throw new \RuntimeException('order disappeared'), 'created' => true];
    }

    /** First Razorpay order of an order. False when another request saved one first. */
    public function setRazorpayOrder(int $id, string $razorpayOrderId, string $keyTag): bool
    {
        $this->history($id, $razorpayOrderId, $keyTag);
        return $this->change(
            'UPDATE shop_orders SET razorpay_order_id = ?, razorpay_key_tag = ?, razorpay_order_at = ? WHERE id = ? AND razorpay_order_id IS NULL AND status = ?',
            [$razorpayOrderId, $keyTag, Clock::sql($this->clock->now()), $id, StoredOrder::PENDING],
        );
    }

    /** Swaps the current Razorpay order for a new one; the old id stays in the history. False when the order moved on or was swapped already. */
    public function replaceRazorpayOrder(int $id, string $oldId, string $newId, string $keyTag): bool
    {
        $this->history($id, $newId, $keyTag);
        return $this->change(
            'UPDATE shop_orders SET razorpay_order_id = ?, razorpay_key_tag = ?, razorpay_order_at = ? WHERE id = ? AND razorpay_order_id = ? AND status = ?',
            [$newId, $keyTag, Clock::sql($this->clock->now()), $id, $oldId, StoredOrder::PENDING],
        );
    }

    /** PAYMENT_PENDING -> PAID_PLACING in one conditional update. True = this caller won and owns the ERP leg. */
    public function markPaid(int $id, string $razorpayOrderId, string $paymentId, int $amountPaise): bool
    {
        return $this->change(
            'UPDATE shop_orders SET status = ?, payment_provider = \'razorpay\', payment_ref = ?, paid_amount_paise = ?, paid_at = ?, razorpay_order_id = ? WHERE id = ? AND status = ?',
            [StoredOrder::PAID_PLACING, $paymentId, $amountPaise, Clock::sql($this->clock->now()), $razorpayOrderId, $id, StoredOrder::PENDING],
        );
    }

    public function recordAttempt(int $id): void
    {
        $this->pdo->prepare('UPDATE shop_orders SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
    }

    public function markPlaced(int $id, int $erpOrderId, string $erpStatus): bool
    {
        return $this->change(
            'UPDATE shop_orders SET status = ?, erp_order_id = ?, erp_status = ?, placed_at = ? WHERE id = ? AND status = ?',
            [StoredOrder::PLACED, $erpOrderId, $erpStatus, Clock::sql($this->clock->now()), $id, StoredOrder::PAID_PLACING],
        );
    }

    /** @param list<string> $skus */
    public function markNotFulfilled(int $id, string $reason, array $skus): bool
    {
        return $this->change(
            'UPDATE shop_orders SET status = ?, failure_code = ?, failed_skus = ? WHERE id = ? AND status = ?',
            [StoredOrder::NOT_FULFILLED, $reason, json_encode($skus), $id, StoredOrder::PAID_PLACING],
        );
    }

    private function history(int $id, string $razorpayOrderId, string $keyTag): void
    {
        $order = $this->findById($id);
        $this->pdo->prepare('INSERT IGNORE INTO shop_razorpay_orders (order_id, razorpay_order_id, amount_paise, key_tag, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $razorpayOrderId, $order?->totalPaise ?? 0, $keyTag, Clock::sql($this->clock->now())]);
    }

    /** @param list<mixed> $params */
    private function change(string $sql, array $params): bool
    {
        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false; // the payment id or Razorpay order id already belongs to another order
            }
            throw $e;
        }
        return $stmt->rowCount() === 1;
    }

    /** @param list<mixed> $params */
    private function one(string $sql, array $params): ?StoredOrder
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT sku, name, quantity, unit_price_paise FROM shop_order_items WHERE order_id = ? ORDER BY id');
        $items->execute([$row['id']]);
        $list = array_map(static fn (array $i): array => ['sku' => (string) $i['sku'], 'name' => (string) $i['name'], 'quantity' => (int) $i['quantity'], 'unitPricePaise' => (int) $i['unit_price_paise']], $items->fetchAll(\PDO::FETCH_ASSOC));
        return StoredOrder::fromRow($row, $list);
    }
}

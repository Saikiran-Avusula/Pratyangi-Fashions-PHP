<?php
declare(strict_types=1);

namespace Pratyangi\Payments;

use Pratyangi\Address\AddressRepo;
use Pratyangi\Erp\ErpException;
use Pratyangi\Erp\ErpNotConfiguredException;
use Pratyangi\Erp\ErpOrderRejectedException;
use Pratyangi\Erp\ErpPort;
use Pratyangi\Http\ApiException;
use Pratyangi\Orders\OrderNumberTakenException;
use Pratyangi\Orders\OrderPricer;
use Pratyangi\Orders\OrderProblem;
use Pratyangi\Orders\OrderRepo;
use Pratyangi\Orders\StoredOrder;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Online payment with Razorpay (the Node payments/service.ts, same rules): the customer pays FIRST, then the order goes to the ERP as PAID.
 *  start   save the order as PAYMENT_PENDING (ERP prices, a copy of the address), make ONE Razorpay order for it. Nothing reaches the ERP.
 *  verify  the browser's word is never believed: signature, then Razorpay is asked for the payment itself (amount, currency, order, captured).
 *          One conditional update moves PAYMENT_PENDING to PAID_PLACING (one winner); only the winner asks the ERP, with payment_status PAID.
 *  ERP     placed -> PLACED. Out of stock / unknown SKU / product inactive -> PAID_NOT_FULFILLED (refund by hand, logged payment.refund_needed).
 *          Anything else (outage, lost answer, config, other refusal) leaves PAID_PLACING ("confirming") to be retried with the SAME order number. Never "sold out".
 */
final class PaymentService
{
    private const REUSE_SECONDS = 1800;
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // no look-alikes (0/O, 1/I/L)

    /** @param \Closure():string|null $newOrderNo tests pass their own @param \Closure(StoredOrder):void|null $onPlaced called once when the ERP has taken the order (the confirmation email) */
    public function __construct(
        private OrderRepo $orders,
        private ErpPort $erp,
        private RazorpayPort $razorpay,
        private AddressRepo $addresses,
        private string $keyId,
        private string $keySecret,
        private Clock $clock,
        private Logger $logger,
        private ?\Closure $newOrderNo = null,
        private ?\Closure $onPlaced = null,
    ) {
    }

    /** hex HMAC-SHA256 of "order_id|payment_id" with the key secret, compared in constant time. */
    public static function signatureOk(string $orderId, string $paymentId, string $signature, string $secret): bool
    {
        return $orderId !== '' && $paymentId !== '' && $signature !== '' && $secret !== ''
            && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    /**
     * @param list<array{sku:string,quantity:int}> $lines
     * @return array{order:StoredOrder,razorpay:?array{keyId:string,orderId:string,amountPaise:int,currency:string},replay:bool}
     */
    public function start(int $customerId, string $key, int $addressId, array $lines): array
    {
        $existing = $this->orders->findByKey($customerId, $key);
        if ($existing !== null) {
            return $this->resume($existing, $lines);
        }
        $address = $this->addresses->findById($customerId, $addressId);
        if ($address === null) {
            throw OrderProblem::invalid('Choose a delivery address from your saved addresses.');
        }
        $priced = OrderPricer::price($this->erp, $lines);

        // a soft early check so nobody is sent to pay for something already gone; the ERP's own atomic check at the ERP leg is the real one
        try {
            $available = $this->erp->getAvailability(array_column($priced['lines'], 'sku'));
            $short = array_values(array_map(static fn (array $l): string => $l['sku'], array_filter($priced['lines'], static fn (array $l): bool => ($available[$l['sku']] ?? 0) < $l['quantity'])));
            if ($short !== []) {
                throw OrderProblem::outOfStock($short);
            }
        } catch (ErpException|ErpNotConfiguredException) {
            $this->logger->channel('payment')->warn('payment.stock_precheck_unavailable');
        }

        $saved = null;
        for ($attempt = 0; $attempt < 3 && $saved === null; $attempt++) {
            try {
                $saved = $this->orders->create([
                    'orderNo' => $this->newOrderNo !== null ? ($this->newOrderNo)() : self::makeOrderNo(),
                    'customerId' => $customerId,
                    'key' => $key,
                    'itemsTotalPaise' => $priced['itemsTotalPaise'],
                    'taxPaise' => $priced['taxPaise'],
                    'totalPaise' => $priced['totalPaise'],
                    'shipTo' => ['name' => $address->recipientName, 'phone' => $address->phone, 'house' => $address->house, 'street' => $address->street,
                        'city' => $address->city, 'state' => $address->state, 'pincode' => $address->pincode],
                    'items' => array_map(static fn (array $l): array => ['sku' => $l['sku'], 'name' => mb_substr($l['name'], 0, 200), 'quantity' => $l['quantity'], 'unitPricePaise' => $l['unitPricePaise']], $priced['lines']),
                ]);
            } catch (OrderNumberTakenException) {
            }
        }
        if ($saved === null) {
            throw new \RuntimeException('could not make a free order number');
        }
        if (!$saved['created']) {
            return $this->resume($saved['order'], $lines); // two requests with the same key at once: follow the first one's order
        }
        return ['replay' => false] + $this->ensureRazorpayOrder($saved['order'], $saved['order']->totalPaise);
    }

    /**
     * @param array{orderId:string,paymentId:string,signature:string} $input
     * @return array{order:StoredOrder,outcome:string}
     */
    public function verify(int $customerId, array $input): array
    {
        // someone else's order, or none: the same answer
        $order = $this->orders->findByRazorpayOrderId($customerId, $input['orderId']);
        if ($order === null) {
            throw ApiException::notFound('We could not find that order.');
        }
        $signatureOk = self::signatureOk($input['orderId'], $input['paymentId'], $input['signature'], $this->keySecret);

        if (in_array($order->status, StoredOrder::PAID_STATES, true)) {
            // already paid: no call to Razorpay. A paid order whose ERP leg is unfinished is tried again now.
            if ($signatureOk) {
                $this->noteDuplicate($order, $input['paymentId']);
            }
            $done = $this->completeErp($order);
            return ['order' => $done, 'outcome' => self::outcomeOf($done)];
        }
        if (!$signatureOk) {
            throw new ApiException(400, 'We could not verify this payment. Please try again.', null, null, 'payment-signature');
        }
        try {
            $payment = $this->razorpay->fetchPayment($input['paymentId']);
        } catch (RazorpayException $e) {
            $this->logger->channel('payment')->warn('payment.fetch_failed', ['orderNo' => $order->orderNo, 'kind' => $e->kind]);
            throw new ApiException(503, 'We could not confirm your payment just now. If money was deducted, do not pay again: try again in a moment.', null, null, 'provider-unavailable', null, true);
        }
        $verdict = $this->judge($order, $input['orderId'], $payment, $input['paymentId']);
        if ($verdict === 'mismatch') {
            throw $this->mismatch($order);
        }
        if ($verdict === 'pending') {
            return ['order' => $order, 'outcome' => 'pending'];
        }
        $won = $this->orders->markPaid($order->id, $input['orderId'], $payment['id'], $payment['amountPaise']);
        $current = $this->orders->findById($order->id) ?? throw new \RuntimeException('order disappeared');
        if (!$won) {
            if ($current->status === StoredOrder::PENDING) {
                $this->logger->channel('payment')->error('payment.mismatch', ['orderNo' => $current->orderNo, 'paymentId' => $payment['id']]); // the payment id is on another order
                throw $this->mismatch($current);
            }
            $this->noteDuplicate($current, $payment['id']);
            return ['order' => $current, 'outcome' => self::outcomeOf($current)]; // the loser never calls the ERP; the winner does
        }
        $done = $this->completeErp($current);
        return ['order' => $done, 'outcome' => self::outcomeOf($done)];
    }

    /** The ERP leg of an order that is PAID_PLACING. Records the ERP's answer; an ERP failure never throws. */
    public function completeErp(StoredOrder $order): StoredOrder
    {
        if ($order->status !== StoredOrder::PAID_PLACING) {
            return $order;
        }
        $latest = $this->orders->findById($order->id); // another request may have finished the job meanwhile
        if ($latest === null || $latest->status !== StoredOrder::PAID_PLACING) {
            return $latest ?? $order;
        }
        $this->orders->recordAttempt($order->id);
        $log = $this->logger->channel('payment');
        try {
            $created = $this->erp->createOrder([
                'orderNo' => $order->orderNo,
                'customerName' => $order->shipTo['name'],
                'customerMobile' => $order->shipTo['phone'],
                'shippingAddress' => $order->addressText(),
                'items' => array_map(static fn (array $i): array => ['sku' => $i['sku'], 'quantity' => $i['quantity']], $order->items),
                'orderAmountPaise' => $order->totalPaise,
                'paymentStatus' => 'PAID', // verified and recorded: never COD for an online order
            ]);
            $this->orders->markPlaced($order->id, $created['erpOrderId'], $created['status']);
            try {
                ($this->onPlaced)?->__invoke($this->orders->findById($order->id) ?? $order);
            } catch (\Throwable) {
                $log->warn('payment.placed_hook_failed', ['orderNo' => $order->orderNo]);
            }
        } catch (ErpOrderRejectedException $e) {
            if ($e->isFinal()) {
                $this->orders->markNotFulfilled($order->id, $e->kind, $e->skus);
                $log->error('payment.refund_needed', ['orderNo' => $order->orderNo, 'paymentId' => $order->paymentRef, 'amountPaise' => $order->paidAmountPaise ?? $order->totalPaise, 'reason' => $e->kind]);
            } else {
                // not proof that nothing can be shipped, and the money is taken: stays "confirming"; a person is told. Never shown as "sold out".
                $log->error('payment.erp_refused_paid_order', ['orderNo' => $order->orderNo, 'paymentId' => $order->paymentRef, 'reason' => $e->kind, 'class' => $e->kind === 'retryable' ? 'retryable' : 'config']);
            }
        } catch (ErpException|ErpNotConfiguredException $e) {
            // not sent, down, or the answer was lost: stays PAID_PLACING and is retried with the SAME order number
            $kind = $e instanceof ErpException ? $e->kind : 'not-configured';
            if (in_array($kind, ['auth', 'disabled', 'not-configured'], true)) {
                // 401 / 503 / no order key: a configuration problem on our side or the ERP's, never "sold out"; the order stays paid and waits
                $log->error('payment.erp_config_error', ['orderNo' => $order->orderNo, 'paymentId' => $order->paymentRef, 'kind' => $kind]);
            } else {
                $log->warn('payment.erp_leg_pending', ['orderNo' => $order->orderNo, 'outageKind' => $kind]);
            }
        }
        return $this->orders->findById($order->id) ?? $order;
    }

    public static function outcomeOf(StoredOrder $o): string
    {
        return match ($o->status) {
            StoredOrder::PLACED => 'placed',
            StoredOrder::NOT_FULFILLED => 'not-fulfilled',
            StoredOrder::PAID_PLACING => 'confirming',
            default => 'pending',
        };
    }

    /**
     * @param list<array{sku:string,quantity:int}> $lines
     * @return array{order:StoredOrder,razorpay:?array{keyId:string,orderId:string,amountPaise:int,currency:string},replay:bool}
     */
    private function resume(StoredOrder $existing, array $lines): array
    {
        if ($existing->paymentMethod !== 'RAZORPAY') {
            throw OrderProblem::keyReused('This checkout was started with a different payment method. Please go back and start again.');
        }
        if (!self::sameBag($existing, $lines)) {
            throw OrderProblem::keyReused('This checkout was already started with a different bag. Please go back to your bag and start again.');
        }
        if ($existing->status !== StoredOrder::PENDING) {
            return ['order' => $existing, 'razorpay' => null, 'replay' => true];
        }
        return ['replay' => true] + $this->ensureRazorpayOrder($existing, $this->freshTotal($lines));
    }

    /**
     * Makes or reuses the Razorpay order of a PAYMENT_PENDING order. A replaced order is swapped in on the SAME local order (one conditional
     * update); every id ever issued stays in shop_razorpay_orders.
     * @return array{order:StoredOrder,razorpay:?array{keyId:string,orderId:string,amountPaise:int,currency:string}}
     */
    private function ensureRazorpayOrder(StoredOrder $order, ?int $freshTotalPaise): array
    {
        $keyTag = substr($this->keyId, -6);
        $current = $order;
        if ($current->razorpayOrderId === null) {
            $id = $this->makeRazorpayOrder($current);
            // first writer wins: a second request that made its own Razorpay order leaves an unpaid orphan, and uses the saved one
            $this->orders->setRazorpayOrder($current->id, $id, $keyTag);
            $current = $this->orders->findById($current->id) ?? throw new \RuntimeException('order disappeared');
        } elseif (!$this->canReuse($current, $freshTotalPaise, $keyTag)) {
            $id = $this->makeRazorpayOrder($current);
            if ($this->orders->replaceRazorpayOrder($current->id, (string) $current->razorpayOrderId, $id, $keyTag)) {
                $this->logger->channel('payment')->info('payment.razorpay_order_replaced', ['orderNo' => $current->orderNo]);
            }
            $current = $this->orders->findById($current->id) ?? throw new \RuntimeException('order disappeared');
            if ($current->status !== StoredOrder::PENDING) {
                return ['order' => $current, 'razorpay' => null]; // it moved on meanwhile (paid)
            }
        }
        return ['order' => $current, 'razorpay' => ['keyId' => $this->keyId, 'orderId' => (string) $current->razorpayOrderId, 'amountPaise' => $current->totalPaise, 'currency' => 'INR']];
    }

    private function makeRazorpayOrder(StoredOrder $order): string
    {
        try {
            return $this->razorpay->createOrder($order->totalPaise, $order->orderNo, ['order_no' => $order->orderNo])['id'];
        } catch (RazorpayException $e) {
            $this->logger->channel('payment')->warn('payment.razorpay_order_failed', ['orderNo' => $order->orderNo, 'kind' => $e->kind]);
            throw new ApiException(503, 'We could not start the payment right now. Nothing has been charged. Please try again in a moment.', null, null, 'provider-unavailable', null, true);
        }
    }

    /**
     * May the saved Razorpay order be handed out again? Only if: made with the CURRENT key; Razorpay, asked now, still has it created/attempted with our
     * amount and INR (a 4xx or any other state: no; unreachable: the other checks decide); it is under 30 minutes old; and the freshly priced bag still
     * equals the stored total. A Razorpay order already PAID is never replaced.
     */
    private function canReuse(StoredOrder $order, ?int $freshTotalPaise, string $keyTag): bool
    {
        if ($order->razorpayKeyTag !== $keyTag) {
            return false;
        }
        try {
            $seen = $this->razorpay->fetchOrder((string) $order->razorpayOrderId);
            if ($seen['status'] === 'paid') {
                $this->logger->channel('payment')->warn('payment.retry_but_razorpay_order_paid', ['orderNo' => $order->orderNo]);
                throw new ApiException(409, 'We have received your payment and are confirming your order. Please do not pay again.', null, null, 'payment-received');
            }
            if (!in_array($seen['status'], ['created', 'attempted'], true) || $seen['amountPaise'] !== $order->totalPaise || $seen['currency'] !== 'INR') {
                return false;
            }
        } catch (RazorpayException $e) {
            if ($e->kind === 'rejected' || $e->kind === 'auth') {
                return false;
            }
            $this->logger->channel('payment')->warn('payment.razorpay_order_fetch_failed', ['orderNo' => $order->orderNo, 'kind' => $e->kind]);
        }
        $age = $order->razorpayOrderAt === null ? null : $this->clock->now() - $order->razorpayOrderAt;
        if ($age === null || $age >= self::REUSE_SECONDS || $age < -60) {
            return false;
        }
        return $freshTotalPaise !== null && $freshTotalPaise === $order->totalPaise;
    }

    /** What the bag prices at now, for the reuse check. A failure is not an error here: the order is simply not reused. */
    private function freshTotal(array $lines): ?int
    {
        try {
            return OrderPricer::price($this->erp, $lines)['totalPaise'];
        } catch (ApiException) {
            $this->logger->channel('payment')->warn('payment.retry_price_unavailable');
            return null;
        }
    }

    /**
     * @param array{id:string,orderId:?string,amountPaise:int,currency:string,status:string} $payment
     * @return 'ok'|'pending'|'mismatch'
     */
    private function judge(StoredOrder $order, string $razorpayOrderId, array $payment, string $expectedPaymentId): string
    {
        if ($payment['orderId'] !== $razorpayOrderId || $payment['amountPaise'] !== $order->totalPaise || $payment['currency'] !== 'INR' || $payment['id'] !== $expectedPaymentId) {
            $this->logger->channel('payment')->error('payment.mismatch', ['orderNo' => $order->orderNo, 'paymentId' => $payment['id']]);
            return 'mismatch';
        }
        if ($payment['status'] === 'captured') {
            return 'ok';
        }
        if ($payment['status'] === 'authorized') {
            // taken from the customer but not captured: nothing is paid yet, and this server never captures
            $this->logger->channel('payment')->warn('payment.authorized_not_captured', ['orderNo' => $order->orderNo, 'paymentId' => $payment['id']]);
            return 'pending';
        }
        $this->logger->channel('payment')->warn('payment.not_captured', ['orderNo' => $order->orderNo, 'paymentId' => $payment['id'], 'status' => $payment['status']]);
        return 'mismatch';
    }

    private function mismatch(StoredOrder $order): ApiException
    {
        return new ApiException(409, 'We could not confirm this payment. If money was deducted, contact us with order number ' . $order->orderNo . '.', null, null, 'payment-mismatch');
    }

    /** A captured payment id different from the one already recorded: keep the first, say so loudly (the second needs a manual refund). */
    private function noteDuplicate(StoredOrder $order, string $paymentId): void
    {
        if ($order->paymentRef !== null && $order->paymentRef !== $paymentId) {
            $this->logger->channel('payment')->error('payment.duplicate', ['orderNo' => $order->orderNo, 'keptPaymentId' => $order->paymentRef, 'otherPaymentId' => $paymentId]);
        }
    }

    /** @param list<array{sku:string,quantity:int}> $lines */
    private static function sameBag(StoredOrder $stored, array $lines): bool
    {
        $wanted = [];
        foreach ($lines as $l) {
            $wanted[$l['sku']] = ($wanted[$l['sku']] ?? 0) + $l['quantity'];
        }
        if (count($wanted) !== count($stored->items)) {
            return false;
        }
        foreach ($stored->items as $item) {
            if (($wanted[$item['sku']] ?? null) !== $item['quantity']) {
                return false;
            }
        }
        return true;
    }

    private static function makeOrderNo(): string
    {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return 'PF-' . $code;
    }
}

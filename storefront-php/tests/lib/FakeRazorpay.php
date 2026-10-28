<?php
declare(strict_types=1);

use Pratyangi\Payments\RazorpayException;
use Pratyangi\Payments\RazorpayPort;

/** An in-memory Razorpay for tests ONLY: no network, no real key. The test plays the customer with pay(). */
final class FakeRazorpay implements RazorpayPort
{
    /** @var array<string,array{amount:int,status:string}> */
    public array $orders = [];
    /** @var array<string,array{orderId:?string,amount:int,currency:string,status:string}> */
    public array $payments = [];
    public int $createCalls = 0;
    public int $fetchPaymentCalls = 0;
    public bool $failCreate = false;
    public bool $failFetchPayment = false;
    private int $seq = 0;

    public function createOrder(int $amountPaise, string $receipt, array $notes): array
    {
        $this->createCalls++;
        if ($this->failCreate) {
            throw new RazorpayException('Razorpay could not be reached', 'unreachable');
        }
        $id = 'order_Fake' . str_pad((string) ++$this->seq, 10, '0', STR_PAD_LEFT);
        $this->orders[$id] = ['amount' => $amountPaise, 'status' => 'created'];
        return ['id' => $id, 'amountPaise' => $amountPaise, 'currency' => 'INR'];
    }

    public function fetchOrder(string $orderId): array
    {
        if (!isset($this->orders[$orderId])) {
            throw new RazorpayException('Razorpay refused the request (HTTP 400)', 'rejected');
        }
        return ['id' => $orderId, 'amountPaise' => $this->orders[$orderId]['amount'], 'currency' => 'INR', 'status' => $this->orders[$orderId]['status']];
    }

    public function fetchPayment(string $paymentId): array
    {
        $this->fetchPaymentCalls++;
        if ($this->failFetchPayment) {
            throw new RazorpayException('Razorpay could not be reached', 'unreachable');
        }
        if (!isset($this->payments[$paymentId])) {
            throw new RazorpayException('Razorpay refused the request (HTTP 400)', 'rejected');
        }
        $p = $this->payments[$paymentId];
        return ['id' => $paymentId, 'orderId' => $p['orderId'], 'amountPaise' => $p['amount'], 'currency' => $p['currency'], 'status' => $p['status']];
    }

    /** The customer pays on Razorpay's page: returns the payment id. A captured payment makes the Razorpay order "paid". */
    public function pay(string $orderId, string $status = 'captured', ?int $amount = null, string $currency = 'INR'): string
    {
        $id = 'pay_Fake' . str_pad((string) ++$this->seq, 10, '0', STR_PAD_LEFT);
        $this->payments[$id] = ['orderId' => $orderId, 'amount' => $amount ?? $this->orders[$orderId]['amount'], 'currency' => $currency, 'status' => $status];
        if ($status === 'captured') {
            $this->orders[$orderId]['status'] = 'paid';
        }
        return $id;
    }

    public static function signature(string $orderId, string $paymentId, string $secret): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    }
}

<?php
declare(strict_types=1);

namespace Pratyangi\Payments;

/** What the shop asks of Razorpay. Implementations: HttpRazorpay (real API, test keys outside production) and a fake in the tests. */
interface RazorpayPort
{
    /**
     * @param array<string,string> $notes
     * @return array{id:string,amountPaise:int,currency:string}
     * @throws RazorpayException
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes): array;

    /**
     * @return array{id:string,amountPaise:int,currency:string,status:string} status: created, attempted or paid
     * @throws RazorpayException
     */
    public function fetchOrder(string $orderId): array;

    /**
     * @return array{id:string,orderId:?string,amountPaise:int,currency:string,status:string} status: created, authorized, captured, refunded or failed
     * @throws RazorpayException
     */
    public function fetchPayment(string $paymentId): array;
}

<?php
declare(strict_types=1);

namespace Pratyangi\Payments;

/**
 * Razorpay over HTTPS with curl, no SDK: create an order, read an order back, read a payment back. Basic auth is built here only.
 * Redirects are refused (they would carry the key elsewhere). No auto-capture setting is sent: the shop owner must confirm Razorpay's
 * capture setting first (an uncaptured payment keeps the order "awaiting-payment").
 */
final class HttpRazorpay implements RazorpayPort
{
    private const MAX_BODY_BYTES = 1000000;

    private string $base;

    public function __construct(string $baseUrl, private string $keyId, private string $keySecret, private int $timeoutMs = 8000)
    {
        $this->base = rtrim($baseUrl, '/') . '/';
    }

    public function createOrder(int $amountPaise, string $receipt, array $notes): array
    {
        $b = $this->send('POST', 'v1/orders', ['amount' => $amountPaise, 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes]);
        // an order for a different amount must never be used to take money
        if (!is_array($b) || !is_string($b['id'] ?? null) || preg_match('/^order_[A-Za-z0-9]{10,30}$/D', $b['id']) !== 1 || ($b['amount'] ?? null) !== $amountPaise || ($b['currency'] ?? null) !== 'INR') {
            throw new RazorpayException("Razorpay's order answer was not in the expected format", 'bad-response');
        }
        return ['id' => $b['id'], 'amountPaise' => $amountPaise, 'currency' => 'INR'];
    }

    public function fetchOrder(string $orderId): array
    {
        if (preg_match('/^order_[A-Za-z0-9]{10,30}$/D', $orderId) !== 1) {
            throw new RazorpayException('the order id is not valid', 'rejected');
        }
        $b = $this->send('GET', 'v1/orders/' . $orderId, null);
        if (!is_array($b) || ($b['id'] ?? null) !== $orderId || !is_int($b['amount'] ?? null) || !is_string($b['currency'] ?? null) || !in_array($b['status'] ?? null, ['created', 'attempted', 'paid'], true)) {
            throw new RazorpayException("Razorpay's order answer was not in the expected format", 'bad-response');
        }
        return ['id' => $orderId, 'amountPaise' => $b['amount'], 'currency' => $b['currency'], 'status' => $b['status']];
    }

    public function fetchPayment(string $paymentId): array
    {
        if (preg_match('/^pay_[A-Za-z0-9]{10,30}$/D', $paymentId) !== 1) {
            throw new RazorpayException('the payment id is not valid', 'rejected');
        }
        $b = $this->send('GET', 'v1/payments/' . $paymentId, null);
        $orderId = is_array($b) ? ($b['order_id'] ?? null) : null;
        if (!is_array($b) || !is_string($b['id'] ?? null) || !is_int($b['amount'] ?? null) || !is_string($b['currency'] ?? null)
            || !in_array($b['status'] ?? null, ['created', 'authorized', 'captured', 'refunded', 'failed'], true) || ($orderId !== null && !is_string($orderId))) {
            throw new RazorpayException("Razorpay's payment answer was not in the expected format", 'bad-response');
        }
        return ['id' => $b['id'], 'orderId' => $orderId, 'amountPaise' => $b['amount'], 'currency' => $b['currency'], 'status' => $b['status']];
    }

    /**
     * Returns the decoded JSON answer of a 2xx reply (null when it was not JSON).
     * @param array<string,mixed>|null $json
     */
    private function send(string $method, string $path, ?array $json): mixed
    {
        $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->keySecret), 'Expect:'];
        $buffer = '';
        $tooBig = false;
        $ch = curl_init($this->base . $path);
        if ($ch === false) {
            throw new RazorpayException('the Razorpay request could not be prepared', 'unreachable');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$buffer, &$tooBig): int {
                if (strlen($buffer) + strlen($data) > self::MAX_BODY_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                $buffer .= $data;
                return strlen($data);
            },
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($ok === false) {
            if ($tooBig) {
                throw new RazorpayException("Razorpay's answer was too large", 'bad-response');
            }
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new RazorpayException($timedOut ? 'Razorpay did not answer in time' : 'Razorpay could not be reached', $timedOut ? 'timeout' : 'unreachable');
        }
        if ($status >= 200 && $status < 300) {
            $decoded = trim($buffer) === '' ? null : json_decode($buffer, true, 32);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        if ($status === 401 || $status === 403) {
            throw new RazorpayException('Razorpay refused the API key', 'auth');
        }
        if ($status === 429 || $status >= 500) {
            throw new RazorpayException('Razorpay failed (HTTP ' . $status . ')', 'unreachable');
        }
        throw new RazorpayException('Razorpay refused the request (HTTP ' . $status . ')', $status >= 400 ? 'rejected' : 'bad-response');
    }
}

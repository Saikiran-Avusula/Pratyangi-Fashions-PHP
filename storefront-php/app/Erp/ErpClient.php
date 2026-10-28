<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

use Pratyangi\Support\Logger;
use Pratyangi\Support\Money;

/**
 * The real ERP adapter (ERP_MODE=http). Server to server only: the key never reaches a browser, and a browser never calls the ERP.
 *   GET  api/catalog_products.php?limit=200&after_id=<n>   products, paged by next_cursor
 *   POST api/catalog_stock.php  {"skus":[...],"warehouse":"..."}   available pieces, batches of 100
 * Every answer is checked before use. A bad envelope fails the whole call; a single bad product row is skipped and counted.
 * Error messages never contain the API key or the ERP's raw answer. Redirects are refused (they would carry the key elsewhere).
 */
final class ErpClient implements ErpPort
{
    private const PAGE_LIMIT = 200;
    private const MAX_PAGES = 200;
    private const STOCK_BATCH = 100;
    public const MAX_BODY_BYTES = 8000000;

    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private string $apiKey,
        private ?string $warehouse = null,
        private string $priceRule = 'offer-else-retail',
        private string $taxMode = 'exclusive',
        private int $timeoutMs = 8000,
        private ?string $caBundle = null,
        private ?Logger $logger = null,
        private ?string $orderApiKey = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \InvalidArgumentException('ERP_BASE_URL must be an http(s) address');
        }
    }

    public function listProducts(?float $deadline = null): array
    {
        $products = [];
        $seen = [];
        $skipped = 0;
        $after = '0';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $reply = $this->call('GET', 'api/catalog_products.php', ['limit' => self::PAGE_LIMIT, 'after_id' => $after], null, $deadline);
            $body = $this->expectOk($reply, 'product list');
            $rows = $body['products'] ?? null;
            $cursor = $body['next_cursor'] ?? null;
            if (!is_array($rows) || !array_key_exists('next_cursor', $body) || ($cursor !== null && (!is_string($cursor) || preg_match('/^\d{1,10}$/D', $cursor) !== 1))) {
                throw new ErpException('the ERP\'s product list answer was not in the agreed format', 'bad-response');
            }
            foreach ($rows as $raw) {
                $product = ErpRowMapper::map($raw, $this->priceRule, $this->taxMode);
                if ($product === null || isset($seen[$product->sku])) {
                    $skipped++;
                    continue;
                }
                $seen[$product->sku] = true;
                $products[] = $product;
            }
            if ($cursor === null) {
                return ['products' => $products, 'skipped' => $skipped];
            }
            // a cursor that does not move forward would loop for ever
            if ((int) $cursor <= (int) $after) {
                throw new ErpException('the ERP\'s page cursor did not advance', 'bad-response');
            }
            $after = $cursor;
        }
        throw new ErpException('the ERP\'s catalogue is larger than this adapter will read', 'bad-response');
    }

    public function getAvailability(array $skus, ?float $deadline = null): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($skus)), self::STOCK_BATCH) as $batch) {
            $payload = ['skus' => $batch] + ($this->warehouse !== null && $this->warehouse !== '' ? ['warehouse' => $this->warehouse] : []);
            $body = $this->expectOk($this->call('POST', 'api/catalog_stock.php', [], $payload, $deadline), 'stock');
            $rows = $body['stock'] ?? null;
            if (!is_array($rows)) {
                throw new ErpException('the ERP\'s stock answer was not in the agreed format', 'bad-response');
            }
            $asked = array_flip($batch);
            foreach ($rows as $row) {
                $pieces = is_array($row) ? ($row['available_pieces'] ?? null) : null;
                if (is_float($pieces) && $pieces >= 0 && $pieces == floor($pieces) && $pieces < 9007199254740992.0) {
                    $pieces = (int) $pieces;
                }
                if (!is_array($row) || !isset($row['sku']) || !is_string($row['sku']) || $row['sku'] === '' || !is_int($pieces) || $pieces < 0) {
                    throw new ErpException('the ERP\'s stock answer was not in the agreed format', 'bad-response');
                }
                // only SKUs we asked about are accepted; anything else the ERP volunteers is ignored
                if (isset($asked[$row['sku']])) {
                    $out[$row['sku']] = $pieces;
                }
            }
        }
        return $out;
    }

    public function createOrder(array $order): array
    {
        // the website's own mistakes: fail before anything is sent
        if (preg_match('/^[A-Za-z0-9._-]{1,60}$/D', $order['orderNo']) !== 1 || $order['orderAmountPaise'] < 0 || $order['items'] === [] || count($order['items']) > 50) {
            throw new ErpOrderRejectedException('the order is not valid', 'invalid');
        }
        $payload = [
            'source' => 'WEBSITE',
            // the order number doubles as the ERP's external id, so a repeated request returns the same order
            'order_no' => $order['orderNo'],
            'external_order_id' => $order['orderNo'],
            'customer_name' => $order['customerName'],
            'customer_mobile' => $order['customerMobile'],
            'shipping_address' => $order['shippingAddress'],
            'items' => array_map(static fn (array $i): array => ['sku' => $i['sku'], 'quantity' => $i['quantity']], $order['items']),
            'order_amount' => Money::toRupeeString($order['orderAmountPaise']),
            'payment_status' => $order['paymentStatus'],
            'notes' => 'Website order',
        ] + ($this->warehouse !== null && $this->warehouse !== '' ? ['warehouse' => $this->warehouse] : []);
        if ($this->orderApiKey === null || $this->orderApiKey === '') {
            throw new ErpNotConfiguredException('order key'); // ERP_ORDER_KEY is missing: nothing is sent, and it is never shown as "sold out"
        }
        // from here on any failure may have happened after the ERP saved the order: an ErpException means "outcome unknown"
        $reply = $this->call('POST', 'api/order_create.php', [], $payload, null, $this->orderApiKey);
        $status = $reply['status'];
        $body = $reply['body'];
        $this->logger?->channel('erp')->info('erp order call', ['status' => $status, 'duration_ms' => (int) ($reply['duration'] ?? 0)]);
        if ($status === 201 && is_array($body) && ($body['ok'] ?? null) === true && is_int($body['order_id'] ?? null) && is_string($body['order_no'] ?? null)) {
            return ['erpOrderId' => $body['order_id'], 'erpOrderNo' => $body['order_no'], 'status' => (string) ($body['status'] ?? ''), 'duplicate' => false];
        }
        $existing = is_array($body) ? ($body['order'] ?? null) : null;
        if ($status === 200 && ($body['ok'] ?? null) === true && ($body['duplicate'] ?? null) === true && is_array($existing) && is_int($existing['id'] ?? null) && is_string($existing['order_no'] ?? null)) {
            return ['erpOrderId' => $existing['id'], 'erpOrderNo' => $existing['order_no'], 'status' => (string) ($existing['status'] ?? ''), 'duplicate' => true];
        }
        if ($status === 422 || $status === 400) {
            // the ERP's wording only picks the kind; it never reaches a customer
            $text = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : '';
            if (preg_match('/insufficient sellable stock/i', $text) === 1) {
                throw new ErpOrderRejectedException('the ERP does not have enough stock', 'out-of-stock', preg_match('/stock for (.+?) · /iu', $text, $m) === 1 ? [$m[1]] : []);
            }
            if (preg_match('/unknown sku/i', $text) === 1) {
                throw new ErpOrderRejectedException('the ERP does not know a SKU', 'unknown-sku', preg_match('/unknown sku:\s*(.+)$/i', $text, $m) === 1 ? [trim($m[1])] : []);
            }
            if (preg_match('#product is missing/inactive#i', $text) === 1) {
                throw new ErpOrderRejectedException('a product is missing or inactive', 'product-unavailable');
            }
            if (preg_match('/reserved by another order/i', $text) === 1) {
                throw new ErpOrderRejectedException('a garment was reserved by another order', 'retryable');
            }
            if (preg_match('/multiple fulfilment warehouses|unknown or disabled fulfilment/i', $text) === 1) {
                throw new ErpOrderRejectedException('the ERP fulfilment location is not set up', 'config');
            }
            // the ERP says 422 means out of stock or an unknown SKU, whatever the wording: final. A 400 is our own bad request: not final.
            throw new ErpOrderRejectedException('the ERP refused the order', $status === 422 ? 'unprocessable' : 'rejected');
        }
        // key refused, licence off, 429/5xx, a redirect or an answer we cannot read: the outcome is unknown
        $kind = $status === 401 || $status === 403 ? 'auth' : ($status === 402 || $status === 503 ? 'disabled' : ($status === 429 || $status >= 500 ? 'unavailable' : 'bad-response'));
        throw new ErpException('the ERP answered the order with HTTP ' . $status, $kind);
    }

    /**
     * @param array<string,scalar> $query
     * @param array<mixed>|null $json
     * @return array{status:int,body:mixed}
     */
    private function call(string $method, string $path, array $query, ?array $json, ?float $deadline, ?string $key = null): array
    {
        $timeoutMs = $this->timeoutMs;
        if ($deadline !== null) {
            $left = (int) floor(($deadline - microtime(true)) * 1000);
            if ($left <= 0) {
                throw new ErpException('the ERP did not answer in time', 'timeout');
            }
            $timeoutMs = min($timeoutMs, $left);
        }
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . ($key ?? $this->apiKey), 'Expect:'];
        $buffer = '';
        $size = 0;
        $tooBig = false;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ErpException('the ERP request could not be prepared', 'unreachable');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$buffer, &$size, &$tooBig): int {
                $size += strlen($data);
                if ($size > self::MAX_BODY_BYTES) {
                    $tooBig = true;
                    return 0; // abort the transfer
                }
                $buffer .= $data;
                return strlen($data);
            },
        ];
        if ($this->caBundle !== null && $this->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $started = microtime(true);
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($ok === false) {
            $kind = $tooBig ? 'bad-response' : ($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable');
            $this->logger?->channel('erp')->warn('erp call failed', [
                'status' => $status,
                'status_code' => $status,
                'duration' => $durationMs,
                'duration_ms' => $durationMs,
                'failure' => $kind,
                'failure_kind' => $kind,
            ]);
            if ($tooBig) {
                throw new ErpException('the ERP\'s answer was too large', 'bad-response');
            }
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new ErpException('the ERP did not answer within ' . $timeoutMs . ' ms', 'timeout');
            }
            throw new ErpException('the ERP could not be reached', 'unreachable');
        }
        $body = null;
        if (trim($buffer) !== '') {
            $decoded = json_decode($buffer, true, 64, JSON_BIGINT_AS_STRING);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return ['status' => $status, 'body' => $body, 'duration' => $durationMs];
    }

    /**
     * Turns the HTTP status into the right failure and returns the decoded {"ok":true,...} body.
     * @param array{status:int,body:mixed,duration?:int} $reply
     * @return array<string,mixed>
     */
    private function expectOk(array $reply, string $what): array
    {
        $status = $reply['status'];
        $durationMs = (int) ($reply['duration'] ?? 0);
        $kind = null;
        $exc = null;
        if ($status === 401 || $status === 403) {
            $kind = 'auth';
            $exc = new ErpException('the ERP refused the API key', 'auth');
        } elseif ($status === 503) {
            $kind = 'disabled';
            $exc = new ErpException('the ERP\'s API is switched off (no key configured on the ERP side)', 'disabled');
        } elseif ($status === 402) {
            $kind = 'disabled';
            $exc = new ErpException('the ERP refused the request because its licence is not active', 'disabled');
        } elseif ($status === 429 || $status >= 500) {
            $kind = 'unavailable';
            $exc = new ErpException('the ERP failed (HTTP ' . $status . ')', 'unavailable');
        } elseif ($status >= 300 && $status < 400) {
            $kind = 'unreachable';
            $exc = new ErpException('the ERP tried to redirect, which is refused', 'unreachable');
        } elseif ($status !== 200) {
            $kind = 'bad-response';
            $exc = new ErpException('the ERP answered the ' . $what . ' request with HTTP ' . $status, 'bad-response');
        } else {
            $body = $reply['body'];
            if (!is_array($body) || ($body['ok'] ?? null) !== true) {
                $kind = 'bad-response';
                $exc = new ErpException('the ERP\'s ' . $what . ' answer was not in the agreed format', 'bad-response');
            }
        }
        if ($exc !== null) {
            $this->logger?->channel('erp')->warn('erp call failed', [
                'status' => $status,
                'status_code' => $status,
                'duration' => $durationMs,
                'duration_ms' => $durationMs,
                'failure' => $kind,
                'failure_kind' => $kind,
            ]);
            throw $exc;
        }
        $this->logger?->channel('erp')->info('erp call ok', [
            'status' => $status,
            'status_code' => $status,
            'duration' => $durationMs,
            'duration_ms' => $durationMs,
            'failure' => null,
            'failure_kind' => null,
        ]);
        return $reply['body'];
    }
}

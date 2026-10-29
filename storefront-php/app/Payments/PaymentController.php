<?php
declare(strict_types=1);

namespace Pratyangi\Payments;

use Pratyangi\Auth\NumberVerification;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Orders\OrderProblem;

/** GET /payments/config, POST /payments/razorpay/order and /verify (docs/API_CONTRACT.md 2.6). Every answer, errors included, is no-store (set in App). */
final class PaymentController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    /**
     * @param \Closure():PaymentService $service built only when a route needs it
     */
    public function __construct(
        private bool $paymentsEnabled,
        private bool $checkoutEnabled,
        private bool $smsEnabled,
        private ?string $mode,
        private \Closure $service,
        private string $orderOtp = 'off',
        /** @var \Closure(int,int):string|null customer id, address id -> that address's phone */
        private ?\Closure $addressPhone = null,
        private ?\Closure $numbers = null,
    ) {
    }

    public function config(Request $request, Context $context): Response
    {
        return Response::json(['enabled' => $this->paymentsEnabled, 'mode' => $this->paymentsEnabled ? $this->mode : null]);
    }

    public function start(Request $request, Context $context): Response
    {
        $this->requirePayments();
        // the switch is OFF unless CHECKOUT_ENABLED=true: nothing is validated, saved or sent anywhere
        if (!$this->checkoutEnabled) {
            throw OrderProblem::disabled('checkout-disabled', 'Online ordering is not open yet. Please check back soon.');
        }
        $key = $request->header('idempotency-key') ?? '';
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(422, 'This checkout attempt could not be identified. Please reload the page and try again.');
        }
        $body = self::startBody($request->jsonBody());
        $customer = $context->customer() ?? throw ApiException::unauthorized();
        // the shop must be able to reach the customer about delivery: a mobile number proven by a code while SMS is on, otherwise a verified email
        if ($this->smsEnabled && (($customer['mobile'] ?? null) === null || ($customer['mobile_verified_at'] ?? null) === null)) {
            throw new ApiException(403, 'Please verify your mobile number before placing an order.', null, null, 'mobile-required');
        }
        if (!$this->smsEnabled && (($customer['email'] ?? null) === null || ($customer['email_verified_at'] ?? null) === null)) {
            throw new ApiException(403, 'Please verify your email address before placing an order.', null, null, 'email-required');
        }
        // ORDER_OTP: online payment is not cash on delivery, so only 'always' and 'new_number' ask for a code here
        if ($this->numbers !== null && $this->addressPhone !== null && NumberVerification::required($this->orderOtp, $this->smsEnabled, 'online')) {
            ($this->numbers)()->assertForOrder($this->orderOtp, $this->smsEnabled, (int) $customer['id'], (string) ($this->addressPhone)((int) $customer['id'], $body['addressId']), 'online');
        }
        $result = ($this->service)()->start((int) $customer['id'], $key, $body['addressId'], $body['lines']);
        return Response::json(['order' => $result['order']->toDto(), 'razorpay' => $result['razorpay']], $result['replay'] ? 200 : 201);
    }

    public function verify(Request $request, Context $context): Response
    {
        $this->requirePayments();
        $input = self::verifyBody($request->jsonBody());
        $customer = $context->customer() ?? throw ApiException::unauthorized();
        $result = ($this->service)()->verify((int) $customer['id'], $input);
        return Response::json(['order' => $result['order']->toDto(), 'outcome' => $result['outcome']], $result['outcome'] === 'pending' ? 202 : 200);
    }

    private function requirePayments(): void
    {
        if (!$this->paymentsEnabled) {
            throw OrderProblem::disabled('payments-disabled', 'Online payment is not open yet. Please try again later.');
        }
    }

    /**
     * @param array<mixed> $body
     * @return array{addressId:int,lines:list<array{sku:string,quantity:int}>}
     */
    private static function startBody(array $body): array
    {
        $errors = [];
        $addr = $body['addressId'] ?? null;
        if (is_string($addr) && preg_match('/^\s*\d{1,9}\s*$/D', $addr) === 1) {
            $addr = (int) trim($addr);
        }
        if (!is_int($addr) || $addr < 1 || $addr > 999999999) {
            $errors['addressId'] = 'Choose a delivery address.';
        }
        $lines = [];
        $raw = $body['lines'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            $errors['lines'] = 'Invalid input: expected array, received ' . ($raw === null ? 'undefined' : get_debug_type($raw));
        } elseif ($raw === []) {
            $errors['lines'] = 'Your bag is empty.';
        } elseif (count($raw) > 20) {
            $errors['lines'] = 'An order can have at most 20 different items.';
        } else {
            foreach ($raw as $line) {
                $sku = is_array($line) && is_string($line['sku'] ?? null) ? trim($line['sku']) : null;
                $qty = is_array($line) ? ($line['quantity'] ?? null) : null;
                if ($sku === null || $sku === '' || mb_strlen($sku) > 60) {
                    $errors['lines'] ??= 'Too small: expected string to have >=1 characters';
                } elseif (!is_int($qty) && !(is_float($qty) && $qty == floor($qty))) {
                    $errors['lines'] ??= 'Invalid input: expected number, received ' . ($qty === null ? 'undefined' : get_debug_type($qty));
                } elseif ($qty < 1) {
                    $errors['lines'] ??= 'Too small: expected number to be >=1';
                } elseif ($qty > 10) {
                    $errors['lines'] ??= 'Too big: expected number to be <=10';
                } else {
                    $lines[] = ['sku' => $sku, 'quantity' => (int) $qty];
                }
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return ['addressId' => $addr, 'lines' => $lines];
    }

    /**
     * The three values Razorpay's checkout hands the page, and nothing else (a strict body: an extra key is refused).
     * @param array<mixed> $body
     * @return array{orderId:string,paymentId:string,signature:string}
     */
    private static function verifyBody(array $body): array
    {
        $patterns = [
            'razorpay_order_id' => '/^order_[A-Za-z0-9]{10,30}$/D',
            'razorpay_payment_id' => '/^pay_[A-Za-z0-9]{10,30}$/D',
            'razorpay_signature' => '/^[a-f0-9]{64}$/D',
        ];
        $errors = [];
        foreach ($patterns as $name => $pattern) {
            $value = $body[$name] ?? null;
            if (!is_string($value)) {
                $errors[$name] = 'Invalid input: expected string, received ' . ($value === null ? 'undefined' : get_debug_type($value));
            } elseif (preg_match($pattern, $value) !== 1) {
                $errors[$name] = 'The payment details could not be read.';
            }
        }
        foreach (array_keys($body) as $name) {
            if (!isset($patterns[$name])) {
                $errors['form'] ??= 'Unrecognized key: "' . $name . '"';
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return ['orderId' => $body['razorpay_order_id'], 'paymentId' => $body['razorpay_payment_id'], 'signature' => $body['razorpay_signature']];
    }
}

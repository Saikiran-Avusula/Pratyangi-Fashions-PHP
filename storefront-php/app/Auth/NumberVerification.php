<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Support\Clock;

/**
 * Verifies the delivery NUMBER (not the address). A code sent to the number (purpose "order_number") proves the customer holds it; the number is then
 * stored for that customer in shop_verified_numbers and never asked again, unless ORDER_OTP asks for a code with every order.
 * ORDER_OTP (needed only while SMS_ENABLED=true): cod = a fresh code before every cash-on-delivery order, always = before every order,
 * new_number = only for a number not verified yet, off = never. assertForOrder() is the server-side check the order route calls before it creates the order.
 */
final class NumberVerification
{
    /** How long a code entered for an order stays good for that order. */
    public const PROOF_SECONDS = 900;

    public function __construct(private \PDO $pdo, private OtpService $otp, private Clock $clock)
    {
    }

    /** True when this order must be backed by a code sent to its number. $method is cod | online. */
    public static function required(string $mode, bool $smsEnabled, string $method): bool
    {
        return $smsEnabled && ($mode === 'always' || ($mode === 'cod' && $method === 'cod') || $mode === 'new_number');
    }

    /** @return array{message:string,resendAfter:int,expiresIn:int} */
    public function request(int $customerId, string $mobile, string $clientIp, Context $context): array
    {
        return $this->otp->issue('mobile', 'order_number', $mobile, $customerId, $clientIp, $context);
    }

    public function verify(int $customerId, string $mobile, string $code): void
    {
        $now = $this->clock->now();
        $this->otp->consume('mobile', 'order_number', $mobile, $code, $customerId, function () use ($customerId, $mobile, $now): void {
            $stamp = self::datetime($now);
            $this->pdo->prepare('INSERT INTO shop_verified_numbers (customer_id, mobile, verified_at, otp_passed_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE otp_passed_at = VALUES(otp_passed_at)')
                ->execute([$customerId, self::digits($mobile), $stamp, $stamp]);
        });
    }

    public function isVerified(int $customerId, string $mobile): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM shop_verified_numbers WHERE customer_id = ? AND mobile = ?');
        $stmt->execute([$customerId, self::digits($mobile)]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        // the number the account signed up with was proved with a code too
        $stmt = $this->pdo->prepare('SELECT 1 FROM shop_customers WHERE id = ? AND mobile = ? AND mobile_verified_at IS NOT NULL');
        $stmt->execute([$customerId, '+91' . self::digits($mobile)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Throws 422 {code:"otp-required", field:"phone"} when ORDER_OTP asks for a code this order does not have. $consume = true ends the proof, so the
     * next cash-on-delivery order asks again (call it once the order exists).
     */
    public function assertForOrder(string $mode, bool $smsEnabled, int $customerId, string $mobile, string $method, bool $consume = false): void
    {
        if (!self::required($mode, $smsEnabled, $method)) {
            return;
        }
        $digits = self::digits($mobile);
        if ($mode === 'new_number') {
            $ok = $this->isVerified($customerId, $digits);
        } else {
            $stmt = $this->pdo->prepare('SELECT otp_passed_at FROM shop_verified_numbers WHERE customer_id = ? AND mobile = ?');
            $stmt->execute([$customerId, $digits]);
            $at = $stmt->fetchColumn();
            $ok = is_string($at) && strtotime($at . ' UTC') >= $this->clock->now() - self::PROOF_SECONDS;
        }
        if (!$ok) {
            throw new ApiException(422, 'Please confirm your mobile number with the code we send you.', 'phone', null, 'otp-required');
        }
        if ($consume && $mode !== 'new_number') {
            $this->pdo->prepare('UPDATE shop_verified_numbers SET otp_passed_at = NULL WHERE customer_id = ? AND mobile = ?')->execute([$customerId, $digits]);
        }
    }

    /** +919876543210 or 98765 43210 -> 9876543210 */
    public static function digits(string $mobile): string
    {
        return substr((string) preg_replace('/\D/', '', $mobile), -10);
    }

    private static function datetime(float $seconds): string
    {
        return gmdate('Y-m-d H:i:s', (int) $seconds) . '.' . str_pad((string) (int) (($seconds - floor($seconds)) * 1000), 3, '0', STR_PAD_LEFT);
    }
}

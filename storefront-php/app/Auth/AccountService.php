<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Security\Passwords;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Email verification by code, adding a mobile number or an email to an account, and password reset by code (a PHP-only addition,
 * docs/API_CONTRACT.md 11.3). The code machinery is OtpService; this class decides who may ask for and use which code:
 *  - verifying and linking need the signed-in account, and the code is made for that account only;
 *  - a number or an address is stored on an account only after a code sent to it was entered, and belongs to one account;
 *  - a reset code goes only to the verified email or verified mobile already stored on the account, and the answer never says whether
 *    such an account exists. Only a verified identifier can reset a password; a typed or unverified one never can.
 */
final class AccountService
{
    public const MOBILE_TAKEN = 'This mobile number is already linked to another account.';
    public const EMAIL_LINKED = 'This email is already linked to another account.';

    public function __construct(
        private CustomerRepo $customers,
        private SessionRepo $sessions,
        private TokenRepo $tokens,
        private OtpService $otp,
        private Clock $clock,
        private ?Logger $logger = null,
        private bool $smsEnabled = true,
    ) {
    }

    /* ---------------------------------------------------------------- the signed-in account's own email */

    /** Sends the account's own, not yet verified email a code. @return array{alreadyVerified:true}|array{message:string,resendAfter:int,expiresIn:int} */
    public function requestEmailVerify(int $customerId, string $clientIp, Context $context): array
    {
        $row = $this->account($customerId);
        $email = (string) ($row['email'] ?? '');
        if ($email === '') {
            // an account made with a mobile number has no email to verify (a typed contact email is never verified)
            throw ApiException::badRequest('This account has no email address to confirm.');
        }
        if ($row['email_verified_at'] !== null) {
            return ['alreadyVerified' => true];
        }
        return $this->otp->issue('email', 'email_verify', $email, $customerId, $clientIp, $context);
    }

    /** @return array<string,mixed> the public customer */
    public function verifyEmail(int $customerId, string $code): array
    {
        $row = $this->account($customerId);
        $email = (string) ($row['email'] ?? '');
        if ($email === '') {
            throw OtpService::invalid();
        }
        $this->otp->consume('email', 'email_verify', $email, $code, $customerId, function () use ($customerId): void {
            $this->customers->markVerified($customerId, $this->clock->now());
            $this->tokens->deleteVerificationsFor($customerId);
        });
        return PublicCustomer::fromRow($this->account($customerId));
    }

    /* ---------------------------------------------------------------- adding a mobile number or an email */

    /** @return array{message:string,resendAfter:int,expiresIn:int} */
    public function requestMobileLink(int $customerId, string $mobile, string $clientIp, Context $context): array
    {
        $row = $this->account($customerId);
        if (($row['mobile'] ?? null) !== null) {
            throw ApiException::conflict('This account already has a mobile number.', 'mobile');
        }
        $owner = $this->customers->findByMobile($mobile);
        if ($owner !== null && (int) $owner['id'] !== $customerId) {
            throw ApiException::conflict(self::MOBILE_TAKEN, 'mobile');
        }
        $res = $this->otp->issue('mobile', 'mobile_verify', $mobile, $customerId, $clientIp, $context);
        $this->logger?->channel('auth')->info('mobile link requested', ['channel' => 'mobile', 'customer_id' => $customerId, 'outcome' => 'requested']);
        return $res;
    }

    /** @return array<string,mixed> the public customer */
    public function verifyMobileLink(int $customerId, string $mobile, string $code): array
    {
        $this->account($customerId);
        $this->otp->consume('mobile', 'mobile_verify', $mobile, $code, $customerId, function () use ($customerId, $mobile): void {
            try {
                if (!$this->customers->linkMobile($customerId, $mobile, $this->clock->now())) {
                    throw ApiException::conflict('This account already has a mobile number.', 'mobile');
                }
            } catch (DuplicateMobileException) {
                throw ApiException::conflict(self::MOBILE_TAKEN, 'mobile');
            }
        });
        $this->logger?->channel('auth')->info('mobile link verified', ['channel' => 'mobile', 'customer_id' => $customerId, 'outcome' => 'success']);
        return PublicCustomer::fromRow($this->account($customerId));
    }

    /** @return array{message:string,resendAfter:int,expiresIn:int} */
    public function requestEmailLink(int $customerId, string $email, string $clientIp, Context $context): array
    {
        $row = $this->account($customerId);
        if (($row['email'] ?? null) !== null && $row['email'] !== '') {
            throw ApiException::conflict('This account already has an email address.', 'email');
        }
        if ($this->customers->findByEmail($email) !== null) {
            throw ApiException::conflict(self::EMAIL_LINKED, 'email');
        }
        $res = $this->otp->issue('email', 'email_verify', $email, $customerId, $clientIp, $context);
        $this->logger?->channel('auth')->info('email link requested', ['channel' => 'email', 'customer_id' => $customerId, 'outcome' => 'requested']);
        return $res;
    }

    /** @return array<string,mixed> the public customer */
    public function verifyEmailLink(int $customerId, string $email, string $code): array
    {
        $this->account($customerId);
        $this->otp->consume('email', 'email_verify', $email, $code, $customerId, function () use ($customerId, $email): void {
            try {
                if (!$this->customers->linkEmail($customerId, $email, $this->clock->now())) {
                    throw ApiException::conflict('This account already has an email address.', 'email');
                }
            } catch (DuplicateEmailException) {
                throw ApiException::conflict(self::EMAIL_LINKED, 'email');
            }
        });
        $this->logger?->channel('auth')->info('email link verified', ['channel' => 'email', 'customer_id' => $customerId, 'outcome' => 'success']);
        return PublicCustomer::fromRow($this->account($customerId));
    }

    /** @return array<string,mixed> the public customer */
    public function setName(int $customerId, string $name): array
    {
        $this->account($customerId);
        if (!$this->customers->setName($customerId, $name)) {
            throw ApiException::conflict('This account already has a name.', 'name');
        }
        return PublicCustomer::fromRow($this->account($customerId));
    }

    /* ---------------------------------------------------------------- forgot password */

    /**
     * Sends a reset code to the email or mobile number when it is the verified one stored on an account that can use it. The answer, its
     * headers and the limits are the same for every target, so nothing says whether an account exists.
     */
    public function requestReset(string $channel, string $target, string $clientIp, Context $context): void
    {
        $this->requireSmsFor($channel);
        if (!$this->otp->isAvailable($channel)) {
            throw ApiException::unavailable($channel === 'mobile'
                ? 'Password reset by mobile code is not set up on the server yet.'
                : 'Password reset by email is not set up on the server yet.');
        }
        $row = $this->resetAccount($channel, $target);
        try {
            $this->otp->issue($channel, 'reset', $target, $row === null ? null : (int) $row['id'], $clientIp, $context, $row !== null);
            $this->logger?->channel('auth')->info('reset requested', ['channel' => $channel, 'outcome' => 'requested']);
        } catch (ApiException $e) {
            if ($e->status !== 503) {
                throw $e;
            }
            // a failed send must not look different from the "no such account" answer
            $this->logger?->warn('could not send the password reset code', ['channel' => $channel]);
        }
    }

    /**
     * Checks the code and sets the new password. Every session of that account ends, so it has to sign in again; orders, addresses and the
     * mobile number are not touched.
     */
    public function reset(string $channel, string $target, string $code, string $password): void
    {
        $this->requireSmsFor($channel);
        $row = $this->resetAccount($channel, $target);
        if ($row === null) {
            $this->logger?->channel('auth')->warn('reset failure', ['channel' => $channel, 'reason' => 'account_not_found', 'outcome' => 'failure']);
            throw OtpService::invalid();
        }
        $customerId = (int) $row['id'];
        $this->otp->consume($channel, 'reset', $target, $code, $customerId, function () use ($customerId, $password): void {
            $this->customers->setPassword($customerId, Passwords::hash($password));
            $this->sessions->deleteAllFor($customerId);
            $this->otp->endResetCodes($customerId);
        });
        $this->logger?->channel('auth')->info('reset success', ['channel' => $channel, 'customer_id' => $customerId, 'outcome' => 'success']);
    }

    /**
     * The account a reset code may be sent to or used for, or null. The identifier must be stored on the account AND verified. A mobile
     * number with nothing to reset (no password and no email on the account) is skipped: that account signs in with a mobile code.
     * @return array<string,mixed>|null
     */
    private function resetAccount(string $channel, string $target): ?array
    {
        if ($channel === 'mobile') {
            $row = $this->customers->findByMobile($target);
            if ($row === null || $row['mobile_verified_at'] === null) {
                return null;
            }
            $hasPassword = ($row['password_hash'] ?? null) !== null && $row['password_hash'] !== '';
            // a verified email address takes the reset: the mobile code is only for an account without one
            if ($row['email_verified_at'] !== null) {
                return null;
            }
            return $hasPassword || (string) ($row['email'] ?? '') !== '' ? $row : null;
        }
        $row = $this->customers->findByEmail($target);
        return $row !== null && $row['email_verified_at'] !== null ? $row : null;
    }

    private function requireSmsFor(string $channel): void
    {
        if ($channel === 'mobile' && !$this->smsEnabled) {
            throw ApiException::unavailable('Mobile sign-in coming soon');
        }
    }

    /** @return array<string,mixed> */
    private function account(int $customerId): array
    {
        $row = $this->customers->findById($customerId);
        if ($row === null) {
            throw ApiException::unauthorized();
        }
        return $row;
    }
}

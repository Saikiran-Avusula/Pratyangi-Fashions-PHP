<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\RateLimiter;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Mail\Mailer;
use Pratyangi\Sms\SmsGateway;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Every 6-digit code the shop sends, by channel (mobile | email) and purpose (login | email_verify | mobile_verify | reset), a PHP-only
 * addition (docs/API_CONTRACT.md 11.1 and 11.3). issue() makes and sends a code; consume() checks one and uses it up; request() and verify()
 * are the mobile sign-in (login) built on those two.
 * A code only opens the (channel, purpose, target) it was made for. Every failure to use one is the same 400 "Invalid or expired code".
 * Nothing here logs a code, an address or a full number.
 */
final class OtpService
{
    public const REQUEST_MESSAGE = 'If this number can receive SMS, a 6-digit code is on its way.';
    public const EMAIL_REQUEST_MESSAGE = 'If this address can receive email, a 6-digit code is on its way.';
    public const NOT_FOUND = 'No account found with this number.';
    public const REGISTERED = 'This number is already registered.';
    public const SEND_FAILED = 'Could not send code, try again.';

    public function __construct(
        private \PDO $pdo,
        private OtpRepo $codes,
        private CustomerRepo $customers,
        private AuthService $auth,
        private ?SmsGateway $sms,
        private RateLimiter $limiter,
        private string $pepper,
        private Clock $clock,
        private ?Logger $logger = null,
        private ?Mailer $mailer = null,
    ) {
    }

    /**
     * Sends a fresh code and ends the older ones of the same channel, purpose and target. The per-address hourly limit is checked by the route.
     * $deliver = false does every check and the same amount of work but stores and sends nothing (password reset for an address or number
     * that has no eligible account), so the answer, its headers and its limits are the same either way.
     * @return array{message:string,resendAfter:int,expiresIn:int}
     */
    public function issue(string $channel, string $purpose, string $target, ?int $customerId, string $clientIp, Context $context, bool $deliver = true): array
    {
        $now = $this->clock->now();
        // reset requests are counted on their own, so a limit's headers do not show whether sign-up or sign-in codes were sent to the target before
        $fingerprint = Otp::fingerprint($this->pepper, $purpose === 'reset' ? $channel . '-reset' : $channel, $target);
        if ($purpose === 'reset') {
            // a reset request must not look different for a known and an unknown target, and the table holds nothing for an unknown one
            try {
                $this->limiter->consumeSubject('code_cooldown', $fingerprint, $context);
            } catch (ApiException $e) {
                $this->logger?->channel('auth')->warn('otp request rejected', [
                    'channel' => $channel,
                    'reason' => 'cooldown',
                    'mobile' => $channel === 'mobile' ? Mobile::mask($target) : null,
                    'outcome' => 'rejected',
                ]);
                throw $e;
            }
        } else {
            $last = $this->codes->latestCreatedAt($channel, $purpose, $target);
            if ($last !== null && $now - $last < Otp::RESEND_SECONDS) {
                $wait = max(1, (int) ceil(Otp::RESEND_SECONDS - ($now - $last)));
                $context->headers['Retry-After'] = (string) $wait;
                $this->logger?->channel('auth')->warn('otp request rejected', [
                    'channel' => $channel,
                    'reason' => 'cooldown',
                    'mobile' => $channel === 'mobile' ? Mobile::mask($target) : null,
                    'outcome' => 'rejected',
                ]);
                throw new ApiException(429, 'Please wait ' . $wait . ' seconds before asking for another code.');
            }
        }
        try {
            $this->limiter->consumeSubject($channel === 'mobile' ? 'otp_mobile' : 'otp_email', $fingerprint, $context);
        } catch (ApiException $e) {
            $this->logger?->channel('auth')->warn('otp request rejected', [
                'channel' => $channel,
                'reason' => 'daily_limit',
                'mobile' => $channel === 'mobile' ? Mobile::mask($target) : null,
                'outcome' => 'rejected',
            ]);
            throw $e;
        }
        $answer = [
            'message' => $channel === 'mobile' ? self::REQUEST_MESSAGE : self::EMAIL_REQUEST_MESSAGE,
            'resendAfter' => Otp::RESEND_SECONDS,
            'expiresIn' => Otp::TTL_SECONDS,
        ];

        $code = Otp::newCode();
        $hash = Otp::hashFor($this->pepper, $channel, $purpose, $target, $code);
        if (!$deliver) {
            return $answer;
        }
        $id = $this->codes->insert($channel, $purpose, $target, $customerId, $hash, $now, $now + Otp::TTL_SECONDS, Otp::fingerprint($this->pepper, 'ip', RateLimiter::subnet($clientIp)));
        try {
            $this->deliver($channel, $purpose, $target, $code, $customerId);
        } catch (ApiException $e) {
            $this->codes->delete($id);
            $this->logger?->channel('auth')->warn('otp request rejected', [
                'channel' => $channel,
                'reason' => 'gateway_failure',
                'mobile' => $channel === 'mobile' ? Mobile::mask($target) : null,
                'outcome' => 'rejected',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            // the code was never delivered, so it must not stay usable (the customer's earlier code still is); the account is untouched either way
            $this->codes->delete($id);
            $this->logger?->channel('auth')->warn('otp request rejected', [
                'channel' => $channel,
                'reason' => 'gateway_failure',
                'mobile' => $channel === 'mobile' ? Mobile::mask($target) : null,
                'outcome' => 'rejected',
            ]);
            $this->logger?->warn('could not send the code', ['exception' => get_class($e), 'channel' => $channel, 'purpose' => $purpose, 'to' => $channel === 'mobile' ? Mobile::mask($target) : null]);
            throw ApiException::unavailable(self::SEND_FAILED);
        }
        $this->codes->endOlderThan($channel, $purpose, $target, $id, $now);
        $this->logger?->channel('auth')->info('otp requested', ['channel' => $channel, 'purpose' => $purpose, 'outcome' => 'requested']);
        if (random_int(1, 100) === 1) {
            try {
                $this->codes->purge($now - 86400);
            } catch (\Throwable $e) {
                $this->logger?->exception('could not delete old codes', $e, 'warn');
            }
        }
        return $answer;
    }

    /**
     * Checks a code for this channel, purpose and target (and, when given, the account it was made for) and uses it up. $effect runs in the
     * same transaction with the code's row; if it throws, nothing is used up and the customer can fix the problem and try again.
     * Wrong tries are counted and kept even though the call then fails.
     * @param (\Closure(array<string,mixed>):mixed)|null $effect
     * @return mixed what $effect returned, or the code's row
     */
    public function consume(string $channel, string $purpose, string $target, string $code, ?int $customerId = null, ?\Closure $effect = null): mixed
    {
        $now = $this->clock->now();
        $failure = null;
        $result = null;
        $this->pdo->beginTransaction();
        try {
            $live = $this->codes->findLiveForUpdate($channel, $purpose, $target, $customerId, $now);
            if ($live === null || (int) $live['attempts'] >= Otp::MAX_ATTEMPTS) {
                $failure = self::invalid();
            } elseif (!Otp::matchesFor($this->pepper, $channel, $purpose, $target, $code, (string) $live['code_hash'])) {
                $this->codes->addAttempt((int) $live['id']);
                $failure = self::invalid();
            } elseif (!$this->codes->consume((int) $live['id'], $now)) {
                $failure = self::invalid(); // used by another request a moment ago
            } else {
                $result = $effect === null ? $live : $effect($live);
            }
            // wrong-attempt counts and used-up codes must survive a refusal, so commit before throwing
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($failure !== null) {
            $this->logger?->channel('auth')->warn('otp verify failed', ['channel' => $channel, 'purpose' => $purpose, 'outcome' => 'failure']);
            throw $failure;
        }
        $this->logger?->channel('auth')->info('otp verified', ['channel' => $channel, 'purpose' => $purpose, 'outcome' => 'success']);
        return $result;
    }

    /** True when codes can be sent through this channel (an SMS gateway for mobile, a mailer for email). */
    public function isAvailable(string $channel): bool
    {
        return $channel === 'mobile' ? $this->sms !== null : ($this->mailer !== null && $this->mailer->isAvailable());
    }

    /** Ends every open reset code made for an account (a password reset through one channel kills the other channel's code too). */
    public function endResetCodes(int $customerId): void
    {
        $this->codes->endAllFor($customerId, 'reset', $this->clock->now());
    }

    /**
     * Mobile step one, for purpose 'login' (sign in) or 'signup' (create account). A number the purpose cannot use (no account to sign in to,
     * or one already registered) goes through every limit and the same work but sends nothing, then gets 404 / 409.
     * @return array{message:string,resendAfter:int,expiresIn:int}
     */
    public function request(string $mobile, string $clientIp, Context $context, string $purpose = 'login'): array
    {
        $known = $this->customers->findByMobile($mobile) !== null;
        $usable = $purpose === 'login' ? $known : !$known;
        $answer = $this->issue('mobile', $purpose, $mobile, null, $clientIp, $context, $usable);
        if (!$usable) {
            $this->logger?->channel('auth')->warn('otp request rejected', ['channel' => 'mobile', 'purpose' => $purpose, 'reason' => $known ? 'already_registered' : 'no_account', 'mobile' => Mobile::mask($mobile), 'outcome' => 'rejected']);
            throw $known ? ApiException::conflict(self::REGISTERED) : ApiException::notFound(self::NOT_FOUND);
        }
        return $answer;
    }

    /**
     * Mobile sign-in, step two. A correct code signs the number's account in; for a number without one the account is created now, with no
     * name and no email. Every failure is the same 400 "Invalid or expired code".
     * @param array{mobile:string,code:string} $input
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float,created:bool}
     */
    public function verify(array $input, ?string $userAgent, string $purpose = 'login'): array
    {
        $mobile = $input['mobile'];
        $created = false;
        /** @var array<string,mixed> $row */
        $row = $this->consume('mobile', $purpose, $mobile, $input['code'], null, function () use ($mobile, $purpose, &$created): array {
            $found = $this->customers->findByMobile($mobile, true);
            if ($purpose === 'login') {
                if ($found === null) {
                    throw self::invalid(); // a sign-in code never creates an account
                }
            } else {
                if ($found !== null) {
                    throw ApiException::conflict(self::REGISTERED);
                }
                try {
                    $id = $this->customers->createWithMobile($mobile, $this->clock->now());
                    $created = true;
                } catch (DuplicateMobileException) {
                    throw ApiException::conflict(self::REGISTERED); // two sign-ups at once: the unique number lets one win
                }
                $found = $this->customers->findById($id);
                if ($found === null) {
                    throw new \RuntimeException('the new customer could not be read back');
                }
            }
            if (($found['mobile_verified_at'] ?? null) === null) {
                throw self::invalid(); // an unverified number never signs anybody in
            }
            return $found;
        });
        $this->logger?->channel('auth')->info('sign-in success', ['method' => 'mobile_otp', 'customer_id' => (int) $row['id'], 'outcome' => 'success']);
        return $this->auth->startSession($row, $userAgent) + ['created' => $created];
    }

    private function deliver(string $channel, string $purpose, string $target, string $code, ?int $customerId = null): void
    {
        if ($channel === 'mobile') {
            if ($this->sms === null) {
                throw ApiException::unavailable('Mobile sign-in is not set up yet. Please sign in with your email.');
            }
            $this->sms->sendOtp($target, $code);
            return;
        }
        if ($this->mailer === null || !$this->mailer->isAvailable()) {
            throw ApiException::unavailable('Email is not set up on the server yet, so we cannot send a code right now.');
        }
        $mail = MailTemplates::code($purpose, $code, intdiv(Otp::TTL_SECONDS, 60), $this->nameOf($customerId));
        $this->mailer->send($target, $mail['subject'], $mail['text'], $mail['html']);
    }

    /** The account's name for the email greeting; null when there is none (the greeting is then "Hi there,"). A failed lookup never stops the email. */
    private function nameOf(?int $customerId): ?string
    {
        try {
            $name = $customerId === null ? null : ($this->customers->findById($customerId)['name'] ?? null);
        } catch (\Throwable) {
            return null;
        }
        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    public static function invalid(): ApiException
    {
        return ApiException::badRequest(Validator::OTP_INVALID);
    }
}

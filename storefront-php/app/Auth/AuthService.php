<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Mail\EventMail;
use Pratyangi\Security\Passwords;
use Pratyangi\Security\Tokens;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/** Sign-up, sign-in, sessions and the old email-link verification. Messages follow the Node API except where docs/API_CONTRACT.md 11.3 says otherwise. */
final class AuthService
{
    // one answer for an unknown email and a wrong password: no account enumeration
    public const BAD_CREDENTIALS = 'Email or password is incorrect';
    public const GOOGLE_ONLY = 'This account uses Google sign-in';
    public const EMAIL_TAKEN = 'An account with this email already exists.';

    public function __construct(
        private \PDO $pdo,
        private CustomerRepo $customers,
        private SessionRepo $sessions,
        private TokenRepo $tokens,
        private int $sessionDays,
        private Clock $clock,
        private ?Logger $logger = null,
        private ?EventMail $mail = null,
    ) {
    }

    /**
     * Email + password signup. One account per email address, whichever way it was first created. The 6-digit email code is sent by the caller.
     * @param array{name:string,email:string,password:string} $input
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float}
     */
    public function signUp(array $input, ?string $userAgent): array
    {
        $existing = $this->customers->findByEmail($input['email']);
        if ($existing !== null) {
            throw ApiException::conflict(
                ($existing['password_hash'] ?? null) !== null
                    ? self::EMAIL_TAKEN
                    : 'This email is already registered through Google. Please use Continue with Google.',
                'email',
            );
        }
        $hash = Passwords::hash($input['password']);
        try {
            $id = $this->customers->create($input['name'], $input['email'], $hash);
        } catch (DuplicateEmailException) {
            // two signups with the same email at the same moment: the UNIQUE key lets exactly one through
            throw ApiException::conflict(self::EMAIL_TAKEN, 'email');
        }
        $row = $this->customers->findById($id);
        if ($row === null) {
            throw new \RuntimeException('the new customer could not be read back');
        }
        $started = $this->start($row, $userAgent);
        $this->logger?->channel('auth')->info('signup success', ['customer_id' => $id, 'outcome' => 'success']);
        $this->mail?->welcome($id, $input['email'], $input['name']);
        return $started;
    }

    /**
     * @param array{email:string,password:string} $input
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float}
     */
    public function signIn(array $input, ?string $userAgent): array
    {
        $row = $this->customers->findByEmail($input['email']);
        if ($row === null) {
            Passwords::verifyAgainstDecoy($input['password']);
            $this->logger?->channel('auth')->warn('sign-in failure', ['method' => 'password', 'reason' => 'account_not_found', 'outcome' => 'failure']);
            throw new ApiException(401, self::BAD_CREDENTIALS, null, null, 'invalid_credentials');
        }
        if (($row['password_hash'] ?? null) === null) {
            $this->logger?->channel('auth')->warn('sign-in failure', ['method' => 'password', 'reason' => 'no_password', 'outcome' => 'failure']);
            // a mobile-code account has no Google link: say nothing more than the generic answer
            throw ($row['mobile'] ?? null) !== null
                ? new ApiException(401, self::BAD_CREDENTIALS, null, null, 'invalid_credentials')
                : new ApiException(401, self::GOOGLE_ONLY, null, null, 'google_account');
        }
        if (!Passwords::verify((string) $row['password_hash'], $input['password'])) {
            $this->logger?->channel('auth')->warn('sign-in failure', ['method' => 'password', 'reason' => 'wrong_password', 'outcome' => 'failure']);
            throw new ApiException(401, self::BAD_CREDENTIALS, null, null, 'invalid_credentials');
        }
        $started = $this->start($row, $userAgent);
        $this->logger?->channel('auth')->info('sign-in success', ['method' => 'password', 'customer_id' => (int) $row['id'], 'outcome' => 'success']);
        if (random_int(1, 100) === 1) {
            // nothing else schedules this clean-up (the contract notes the gap)
            try {
                $this->sessions->purgeExpired();
            } catch (\Throwable $e) {
                $this->logger?->exception('could not delete expired sessions', $e, 'warn');
            }
        }
        return $started;
    }

    /** Ends the session behind a cookie token. An unknown token is fine: the customer is signed out either way. */
    public function signOut(?string $token): void
    {
        if ($token !== null && $token !== '') {
            $this->sessions->delete(Tokens::sha256($token));
        }
    }

    /**
     * Google sign-in with a token GoogleVerifier has already accepted. Match order: the Google user id, then the (Google-verified) email, so one
     * email is never two accounts; no match creates the account. An unverified password account found by email is taken over: its password and
     * its sessions are deleted (whoever typed that address in first never proved they own it).
     * @param array{sub:string,email:string,name:string,picture:?string} $g
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float}
     */
    public function googleSignIn(array $g, ?string $userAgent): array
    {
        $linked = 'This Google account is already linked. Please try again.';
        $now = $this->clock->now();
        $created = false;
        $this->pdo->beginTransaction();
        try {
            $row = $this->customers->findByIdentity('google', $g['sub']);
            if ($row === null) {
                $row = $this->customers->findByEmail($g['email']);
                if ($row !== null) {
                    if (($row['password_hash'] ?? null) !== null && ($row['email_verified_at'] ?? null) === null) {
                        $this->customers->clearPassword((int) $row['id']);
                        $this->sessions->deleteAllFor((int) $row['id']);
                    }
                    $id = (int) $row['id'];
                    if (!$this->customers->linkIdentity($id, 'google', $g['sub'])) {
                        throw ApiException::conflict($linked);
                    }
                    $this->customers->markVerified($id, $now);
                } else {
                    $name = $g['name'] !== '' ? $g['name'] : explode('@', $g['email'])[0];
                    try {
                        $id = $this->customers->createGoogle(mb_substr($name, 0, 80), $g['email'], $g['picture'], $now);
                    } catch (DuplicateEmailException) {
                        throw ApiException::conflict($linked); // the same address was created a moment ago: try again and it is found
                    }
                    if (!$this->customers->linkIdentity($id, 'google', $g['sub'])) {
                        throw ApiException::conflict($linked);
                    }
                    $created = true;
                }
                $row = $this->customers->findById($id);
            }
            if ($row !== null && $g['picture'] !== null && $g['picture'] !== ($row['picture_url'] ?? null)) {
                $this->customers->setPicture((int) $row['id'], $g['picture']); // refreshed on every Google sign-in
                $row = $this->customers->findById((int) $row['id']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($row === null) {
            throw new \RuntimeException('the Google customer could not be read back');
        }
        $this->logger?->channel('auth')->info('sign-in success', ['method' => 'google', 'customer_id' => (int) $row['id'], 'outcome' => 'success']);
        if ($created) {
            $this->mail?->welcome((int) $row['id'], $g['email'], (string) ($row['name'] ?? ''));
        }
        return $this->start($row, $userAgent);
    }

    /** The customer row behind a cookie token, or null for unknown, expired or missing. @return array<string,mixed>|null */
    public function customerForToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        return $this->sessions->findCustomer(Tokens::sha256($token));
    }

    /** Marks the email as verified when the link's token is valid, unused and unexpired. @return array<string,mixed> the public customer */
    public function verifyEmail(string $token): array
    {
        $row = null;
        $this->pdo->beginTransaction();
        try {
            $row = $this->tokens->findVerificationCustomerForUpdate(Tokens::sha256($token));
            if ($row !== null) {
                if ($row['email_verified_at'] === null) {
                    $this->customers->markVerified((int) $row['id'], $this->clock->now());
                    $row = $this->customers->findById((int) $row['id']) ?? $row;
                }
                $this->tokens->deleteVerificationsFor((int) $row['id']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($row === null) {
            throw ApiException::badRequest(
                'This verification link is invalid, has expired or was already used. If your email is not verified yet, sign in and choose Send code on your account page.',
                'token',
            );
        }
        return PublicCustomer::fromRow($row);
    }

    /**
     * Opens a session for an account that has already proved who it is (the one-time code route uses this).
     * @param array<string,mixed> $row
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float}
     */
    public function startSession(array $row, ?string $userAgent): array
    {
        return $this->start($row, $userAgent);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{customer:array<string,mixed>,token:string,expiresAt:float}
     */
    private function start(array $row, ?string $userAgent): array
    {
        $token = Tokens::newToken();
        $expiresAt = $this->clock->now() + $this->sessionDays * 86400;
        $this->sessions->create((int) $row['id'], Tokens::sha256($token), $expiresAt, $userAgent);
        return ['customer' => PublicCustomer::fromRow($row), 'token' => $token, 'expiresAt' => $expiresAt];
    }
}

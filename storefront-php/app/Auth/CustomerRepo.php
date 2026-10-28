<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Support\Clock;
use Pratyangi\Support\Text;

/** shop_customers. Prepared statements only. Rows come back as column => value arrays. */
final class CustomerRepo
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Exact lookup: case differences match, accent or look-alike differences never do. The column's collation
     * (utf8mb4_unicode_ci) folds accents, so the query only finds candidates (using the index) and PHP decides.
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shop_customers WHERE email = ? LIMIT 10');
        $stmt->execute([$email]);
        return self::pickExact($stmt->fetchAll(), $email);
    }

    /** True when both addresses are equal apart from letter case (no accent folding). */
    public static function sameEmail(string $a, string $b): bool
    {
        return Text::lower($a) === Text::lower($b);
    }

    /**
     * @param array<int,array<string,mixed>> $rows candidate rows
     * @return array<string,mixed>|null the row whose stored email equals $email apart from case
     */
    public static function pickExact(array $rows, string $email): ?array
    {
        foreach ($rows as $row) {
            if (self::sameEmail((string) ($row['email'] ?? ''), $email)) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shop_customers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @throws DuplicateEmailException when the email is taken (SQLSTATE 23000, driver code 1062) */
    public function create(string $name, string $email, string $passwordHash): int
    {
        try {
            $this->pdo->prepare('INSERT INTO shop_customers (name, email, password_hash) VALUES (?, ?, ?)')->execute([$name, $email, $passwordHash]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateEmailException('email already registered', 0, $e);
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null the account that owns this +91XXXXXXXXXX number */
    public function findByMobile(string $mobile, bool $lock = false): ?array
    {
        // a locking read sees rows other transactions committed after this one's snapshot began
        $stmt = $this->pdo->prepare('SELECT * FROM shop_customers WHERE mobile = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$mobile]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * An account made by a mobile code: the number (already proved with a code, so verified now), no name, no email, no password.
     * @throws DuplicateMobileException when the number already has an account (SQLSTATE 23000, driver code 1062)
     */
    public function createWithMobile(string $mobile, float $now): int
    {
        try {
            $this->pdo->prepare("INSERT INTO shop_customers (name, email, mobile, mobile_verified_at, password_hash) VALUES ('', NULL, ?, ?, NULL)")
                ->execute([$mobile, Clock::sql($now)]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateMobileException('mobile already registered', 0, $e);
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Stores a number that was just proved with a code, on an account that has none. False when the account already has one.
     * @throws DuplicateMobileException when another account owns the number
     */
    public function linkMobile(int $id, string $mobile, float $now): bool
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE shop_customers SET mobile = ?, mobile_verified_at = ? WHERE id = ? AND mobile IS NULL');
            $stmt->execute([$mobile, Clock::sql($now), $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateMobileException('mobile already registered', 0, $e);
            }
            throw $e;
        }
        return $stmt->rowCount() === 1;
    }

    /**
     * Stores an address that was just proved with a code, on an account that has none. False when the account already has one.
     * @throws DuplicateEmailException when another account owns the address
     */
    public function linkEmail(int $id, string $email, float $now): bool
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE shop_customers SET email = ?, email_verified_at = ? WHERE id = ? AND email IS NULL');
            $stmt->execute([$email, Clock::sql($now), $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateEmailException('email already registered', 0, $e);
            }
            throw $e;
        }
        return $stmt->rowCount() === 1;
    }

    /** Adds a name to an account that has none. False when it already has one. */
    public function setName(int $id, string $name): bool
    {
        $stmt = $this->pdo->prepare("UPDATE shop_customers SET name = ? WHERE id = ? AND name = ''");
        $stmt->execute([$name, $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string,mixed>|null the account this sign-in identity (provider + the provider's user id) is linked to */
    public function findByIdentity(string $provider, string $subject): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.* FROM shop_customer_identities i JOIN shop_customers c ON c.id = i.customer_id WHERE i.provider = ? AND i.provider_user_id = ? LIMIT 1');
        $stmt->execute([$provider, $subject]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** False when the identity is already linked (to this or another account: the pair is unique). */
    public function linkIdentity(int $id, string $provider, string $subject): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO shop_customer_identities (customer_id, provider, provider_user_id) VALUES (?, ?, ?)')->execute([$id, $provider, $subject]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
        return true;
    }

    /** A Google account: no password, the email verified by Google. @throws DuplicateEmailException */
    public function createGoogle(string $name, string $email, ?string $picture, float $now): int
    {
        try {
            $this->pdo->prepare('INSERT INTO shop_customers (name, email, password_hash, email_verified_at, picture_url) VALUES (?, ?, NULL, ?, ?)')->execute([$name, $email, Clock::sql($now), $picture]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateEmailException('email already registered', 0, $e);
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function setPicture(int $id, string $url): void
    {
        $this->pdo->prepare('UPDATE shop_customers SET picture_url = ? WHERE id = ? AND picture_url IS NULL')->execute([$url, $id]);
    }

    public function clearPassword(int $id): void
    {
        $this->pdo->prepare('UPDATE shop_customers SET password_hash = NULL WHERE id = ?')->execute([$id]);
    }

    public function markVerified(int $id, float $now): void
    {
        $this->pdo->prepare('UPDATE shop_customers SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL')->execute([Clock::sql($now), $id]);
    }

    public function setPassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE shop_customers SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $id]);
    }
}

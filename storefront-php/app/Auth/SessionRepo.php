<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Support\Clock;

/** shop_sessions. Only the SHA-256 of the cookie token is stored. */
final class SessionRepo
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $customerId, string $tokenHash, float $expiresAt, ?string $userAgent): void
    {
        if ($userAgent !== null) {
            $userAgent = mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, 255, 'UTF-8');
        }
        $this->pdo->prepare('INSERT INTO shop_sessions (customer_id, token_hash, expires_at, user_agent) VALUES (?, ?, ?, ?)')
            ->execute([$customerId, $tokenHash, Clock::sql($expiresAt), $userAgent]);
    }

    /** The customer a token hash belongs to; null when unknown or expired. @return array<string,mixed>|null */
    public function findCustomer(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM shop_sessions s JOIN shop_customers c ON c.id = s.customer_id WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP(3) LIMIT 1',
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function delete(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM shop_sessions WHERE token_hash = ?')->execute([$tokenHash]);
    }

    public function deleteAllFor(int $customerId): void
    {
        $this->pdo->prepare('DELETE FROM shop_sessions WHERE customer_id = ?')->execute([$customerId]);
    }

    /** Housekeeping: removes sessions that have already expired. */
    public function purgeExpired(): void
    {
        $this->pdo->prepare('DELETE FROM shop_sessions WHERE expires_at <= UTC_TIMESTAMP(3)')->execute();
    }
}

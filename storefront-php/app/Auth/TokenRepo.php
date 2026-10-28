<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Support\Clock;

/** shop_email_verifications: the links of the old email-link verification (no new link is made; links already sent still work). Only hashes are stored. */
final class TokenRepo
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** One live verification link per customer: the old ones are removed. */
    public function replaceVerification(int $customerId, string $tokenHash, float $expiresAt): void
    {
        $this->inTransaction(function () use ($customerId, $tokenHash, $expiresAt): void {
            $this->pdo->prepare('DELETE FROM shop_email_verifications WHERE customer_id = ?')->execute([$customerId]);
            $this->pdo->prepare('INSERT INTO shop_email_verifications (customer_id, token_hash, expires_at) VALUES (?, ?, ?)')
                ->execute([$customerId, $tokenHash, Clock::sql($expiresAt)]);
        });
    }

    /** The customer a live (unexpired) verification link belongs to, row locked; call inside a transaction. @return array<string,mixed>|null */
    public function findVerificationCustomerForUpdate(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM shop_email_verifications v JOIN shop_customers c ON c.id = v.customer_id WHERE v.token_hash = ? AND v.expires_at > UTC_TIMESTAMP(3) LIMIT 1 FOR UPDATE',
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function deleteVerificationsFor(int $customerId): void
    {
        $this->pdo->prepare('DELETE FROM shop_email_verifications WHERE customer_id = ?')->execute([$customerId]);
    }

    private function inTransaction(\Closure $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

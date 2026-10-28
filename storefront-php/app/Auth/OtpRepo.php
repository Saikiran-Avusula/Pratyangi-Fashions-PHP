<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Support\Clock;

/**
 * shop_verification_codes (sql/003b). Every lookup is for one (channel, purpose, target), so a code can only be found, counted and used by
 * the flow it was made for. Prepared statements only. Times come from the caller's clock (UTC unix seconds).
 */
final class OtpRepo
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** When the newest code for this channel, purpose and target was made, or null. */
    public function latestCreatedAt(string $channel, string $purpose, string $target): ?float
    {
        $stmt = $this->pdo->prepare('SELECT created_at FROM shop_verification_codes WHERE channel = ? AND purpose = ? AND target = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$channel, $purpose, $target]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : Clock::fromSql((string) $value);
    }

    /** Stores a new code. Returns its row id. Only the newest open code is ever checked, so it already outranks the older ones. */
    public function insert(string $channel, string $purpose, string $target, ?int $customerId, string $codeHash, float $now, float $expiresAt, ?string $ipHash): int
    {
        $this->pdo->prepare('INSERT INTO shop_verification_codes (channel, purpose, target, customer_id, code_hash, expires_at, created_at, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$channel, $purpose, $target, $customerId, $codeHash, Clock::sql($expiresAt), Clock::sql($now), $ipHash]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Ends every older open code of the same channel, purpose and target (a new request invalidates the earlier ones). */
    public function endOlderThan(string $channel, string $purpose, string $target, int $newId, float $now): void
    {
        $this->pdo->prepare('UPDATE shop_verification_codes SET consumed_at = ? WHERE channel = ? AND purpose = ? AND target = ? AND id < ? AND consumed_at IS NULL')
            ->execute([Clock::sql($now), $channel, $purpose, $target, $newId]);
    }

    /** Ends every open code of one purpose that was made for an account (after a password reset: the other channel's code dies too). */
    public function endAllFor(int $customerId, string $purpose, float $now): void
    {
        $this->pdo->prepare('UPDATE shop_verification_codes SET consumed_at = ? WHERE customer_id = ? AND purpose = ? AND consumed_at IS NULL')
            ->execute([Clock::sql($now), $customerId, $purpose]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM shop_verification_codes WHERE id = ?')->execute([$id]);
    }

    /**
     * The newest code that is unused and unexpired for this channel, purpose and target, locked for this transaction.
     * @param int|null $customerId when given, only a code made for that account
     * @return array<string,mixed>|null
     */
    public function findLiveForUpdate(string $channel, string $purpose, string $target, ?int $customerId, float $now): ?array
    {
        $sql = 'SELECT * FROM shop_verification_codes WHERE channel = ? AND purpose = ? AND target = ? AND consumed_at IS NULL AND expires_at > ?';
        $params = [$channel, $purpose, $target, Clock::sql($now)];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = ?';
            $params[] = $customerId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function addAttempt(int $id): void
    {
        $this->pdo->prepare('UPDATE shop_verification_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
    }

    /** Uses the code up. False when something else already did (single use). */
    public function consume(int $id, float $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE shop_verification_codes SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $stmt->execute([Clock::sql($now), $id]);
        return $stmt->rowCount() === 1;
    }

    /** Housekeeping: rows that ended before $olderThan. */
    public function purge(float $olderThan): void
    {
        $this->pdo->prepare('DELETE FROM shop_verification_codes WHERE expires_at < ?')->execute([Clock::sql($olderThan)]);
    }
}

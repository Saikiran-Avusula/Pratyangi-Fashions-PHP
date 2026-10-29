<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/**
 * Marketing consent per channel (DPDP / TRAI). Every change is a new row in shop_consent_log with the time, the page it was given on and the IP;
 * the newest row of a channel is the current answer, and no row means "no". A row is only added when the answer changes.
 */
final class ConsentRepo
{
    public const CHANNELS = ['sms', 'whatsapp', 'email'];
    public const SOURCES = ['signup', 'address', 'checkout', 'account', 'unsubscribe'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array{sms:bool,whatsapp:bool,email:bool} */
    public function current(int $customerId): array
    {
        $out = array_fill_keys(self::CHANNELS, false);
        $stmt = $this->pdo->prepare('SELECT channel, granted FROM shop_consent_log WHERE id IN (SELECT MAX(id) FROM shop_consent_log WHERE customer_id = ? GROUP BY channel)');
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['channel'], $out)) {
                $out[$row['channel']] = (int) $row['granted'] === 1;
            }
        }
        return $out;
    }

    /**
     * @param array<string,bool> $changes channel => yes/no; other keys are ignored
     * @return array{sms:bool,whatsapp:bool,email:bool} the answers after the change
     */
    public function set(int $customerId, array $changes, string $source, string $ip): array
    {
        $now = $this->current($customerId);
        $insert = $this->pdo->prepare('INSERT INTO shop_consent_log (customer_id, channel, granted, source, ip) VALUES (?, ?, ?, ?, ?)');
        foreach (self::CHANNELS as $channel) {
            if (array_key_exists($channel, $changes) && $changes[$channel] !== $now[$channel]) {
                $insert->execute([$customerId, $channel, $changes[$channel] ? 1 : 0, $source, substr($ip, 0, 45)]);
                $now[$channel] = $changes[$channel];
            }
        }
        return $now;
    }
}

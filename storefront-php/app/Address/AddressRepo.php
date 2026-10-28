<?php
declare(strict_types=1);

namespace Pratyangi\Address;

final class AddressRepo
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Address>
     */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM shop_customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $addresses = [];
        foreach ($rows as $row) {
            $addresses[] = Address::fromRow($row);
        }
        return $addresses;
    }

    public function findById(int $customerId, int $addressId): ?Address
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM shop_customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$addressId, $customerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return Address::fromRow($row);
    }

    /**
     * @param array<string,mixed> $data
     * @throws AddressLimitException
     */
    public function create(int $customerId, array $data): Address
    {
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            // Lock customer row to prevent concurrent race conditions
            $lockStmt = $this->pdo->prepare('SELECT id FROM shop_customers WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$customerId]);

            // Count existing addresses
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_customer_addresses WHERE customer_id = ? FOR UPDATE');
            $countStmt->execute([$customerId]);
            $count = (int) $countStmt->fetchColumn();

            if ($count >= 10) {
                throw new AddressLimitException('You can save up to 10 addresses. Please delete one to add another.');
            }

            // First address is always default; otherwise follow isDefault
            $isDefault = ($count === 0 || !empty($data['isDefault'])) ? 1 : 0;

            if ($isDefault === 1 && $count > 0) {
                $clearStmt = $this->pdo->prepare('UPDATE shop_customer_addresses SET is_default = 0 WHERE customer_id = ?');
                $clearStmt->execute([$customerId]);
            }

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO shop_customer_addresses 
                    (customer_id, type, label, recipient_name, phone, alternate_phone, house, street, city, state, pincode, is_default, created_at, updated_at)
                 VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
            );
            $insertStmt->execute([
                $customerId,
                $data['type'],
                $data['label'],
                $data['recipient_name'],
                $data['phone'],
                $data['alternate_phone'],
                $data['house'],
                $data['street'],
                $data['city'],
                $data['state'],
                $data['pincode'],
                $isDefault,
            ]);

            $newId = (int) $this->pdo->lastInsertId();

            if (!$inTx) {
                $this->pdo->commit();
            }

            return new Address(
                $newId,
                $customerId,
                (string) $data['type'],
                (string) $data['label'],
                (string) $data['recipient_name'],
                (string) $data['phone'],
                $data['alternate_phone'],
                (string) $data['house'],
                (string) $data['street'],
                (string) $data['city'],
                (string) $data['state'],
                (string) $data['pincode'],
                $isDefault === 1
            );
        } catch (\Throwable $e) {
            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $customerId, int $addressId, array $data): ?Address
    {
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockStmt = $this->pdo->prepare('SELECT id FROM shop_customers WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$customerId]);

            $findStmt = $this->pdo->prepare('SELECT * FROM shop_customer_addresses WHERE id = ? AND customer_id = ? FOR UPDATE');
            $findStmt->execute([$addressId, $customerId]);
            $existing = $findStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$existing) {
                if (!$inTx) {
                    $this->pdo->rollBack();
                }
                return null;
            }

            $isDefault = (int) $existing['is_default'];
            if (!empty($data['isDefault'])) {
                $clearStmt = $this->pdo->prepare('UPDATE shop_customer_addresses SET is_default = 0 WHERE customer_id = ?');
                $clearStmt->execute([$customerId]);
                $isDefault = 1;
            }

            $updateStmt = $this->pdo->prepare(
                'UPDATE shop_customer_addresses SET
                    type = ?,
                    label = ?,
                    recipient_name = ?,
                    phone = ?,
                    alternate_phone = ?,
                    house = ?,
                    street = ?,
                    city = ?,
                    state = ?,
                    pincode = ?,
                    is_default = ?,
                    updated_at = NOW(3)
                WHERE id = ? AND customer_id = ?'
            );
            $updateStmt->execute([
                $data['type'],
                $data['label'],
                $data['recipient_name'],
                $data['phone'],
                $data['alternate_phone'],
                $data['house'],
                $data['street'],
                $data['city'],
                $data['state'],
                $data['pincode'],
                $isDefault,
                $addressId,
                $customerId,
            ]);

            if (!$inTx) {
                $this->pdo->commit();
            }

            return new Address(
                $addressId,
                $customerId,
                (string) $data['type'],
                (string) $data['label'],
                (string) $data['recipient_name'],
                (string) $data['phone'],
                $data['alternate_phone'],
                (string) $data['house'],
                (string) $data['street'],
                (string) $data['city'],
                (string) $data['state'],
                (string) $data['pincode'],
                $isDefault === 1
            );
        } catch (\Throwable $e) {
            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $customerId, int $addressId): bool
    {
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockStmt = $this->pdo->prepare('SELECT id FROM shop_customers WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$customerId]);

            $findStmt = $this->pdo->prepare('SELECT is_default FROM shop_customer_addresses WHERE id = ? AND customer_id = ? FOR UPDATE');
            $findStmt->execute([$addressId, $customerId]);
            $existing = $findStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$existing) {
                if (!$inTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $wasDefault = (int) $existing['is_default'] === 1;

            $delStmt = $this->pdo->prepare('DELETE FROM shop_customer_addresses WHERE id = ? AND customer_id = ?');
            $delStmt->execute([$addressId, $customerId]);

            if ($wasDefault) {
                // If default was deleted, the newest remaining address becomes default
                $newestStmt = $this->pdo->prepare('SELECT id FROM shop_customer_addresses WHERE customer_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $newestStmt->execute([$customerId]);
                $newestId = $newestStmt->fetchColumn();
                if ($newestId !== false && $newestId !== null) {
                    $setStmt = $this->pdo->prepare('UPDATE shop_customer_addresses SET is_default = 1 WHERE id = ?');
                    $setStmt->execute([(int) $newestId]);
                }
            }

            if (!$inTx) {
                $this->pdo->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<Address>|null null if address not found
     */
    public function setDefault(int $customerId, int $addressId): ?array
    {
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockStmt = $this->pdo->prepare('SELECT id FROM shop_customers WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$customerId]);

            $findStmt = $this->pdo->prepare('SELECT id FROM shop_customer_addresses WHERE id = ? AND customer_id = ? FOR UPDATE');
            $findStmt->execute([$addressId, $customerId]);
            if (!$findStmt->fetch()) {
                if (!$inTx) {
                    $this->pdo->rollBack();
                }
                return null;
            }

            $clearStmt = $this->pdo->prepare('UPDATE shop_customer_addresses SET is_default = 0 WHERE customer_id = ?');
            $clearStmt->execute([$customerId]);

            $setStmt = $this->pdo->prepare('UPDATE shop_customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?');
            $setStmt->execute([$addressId, $customerId]);

            $addresses = $this->listForCustomer($customerId);

            if (!$inTx) {
                $this->pdo->commit();
            }

            return $addresses;
        } catch (\Throwable $e) {
            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

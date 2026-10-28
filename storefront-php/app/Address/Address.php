<?php
declare(strict_types=1);

namespace Pratyangi\Address;

final class Address
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $type,
        public string $label,
        public string $recipientName,
        public string $phone,
        public ?string $alternatePhone,
        public string $house,
        public string $street,
        public string $city,
        public string $state,
        public string $pincode,
        public bool $isDefault,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['customer_id'],
            (string) ($row['type'] ?? 'Home'),
            (string) ($row['label'] ?? ''),
            (string) ($row['recipient_name'] ?? ''),
            (string) ($row['phone'] ?? ''),
            isset($row['alternate_phone']) && $row['alternate_phone'] !== '' ? (string) $row['alternate_phone'] : null,
            (string) ($row['house'] ?? ''),
            (string) ($row['street'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['state'] ?? ''),
            (string) ($row['pincode'] ?? ''),
            (bool) ($row['is_default'] ?? false),
        );
    }

    /**
     * Formats phone with +91 prefix and space: "+91 9876543210".
     */
    public static function formatPhone(string $digits): string
    {
        $clean = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
            $clean = substr($clean, 2);
        }
        return '+91 ' . $clean;
    }

    /**
     * Returns DTO matching API contract Section 2.4.
     *
     * @return array<string,mixed>
     */
    public function toDto(): array
    {
        $dto = [
            'id' => (string) $this->id,
            'type' => $this->type,
            'label' => $this->label !== '' ? $this->label : $this->type,
            'isDefault' => $this->isDefault,
            'name' => $this->recipientName,
            'phone' => self::formatPhone($this->phone),
        ];

        if ($this->alternatePhone !== null && $this->alternatePhone !== '') {
            $dto['alternatePhone'] = self::formatPhone($this->alternatePhone);
        }

        $dto['house'] = $this->house;
        $dto['street'] = $this->street;
        $dto['city'] = $this->city;
        $dto['state'] = $this->state;
        $dto['pincode'] = $this->pincode;

        return $dto;
    }
}

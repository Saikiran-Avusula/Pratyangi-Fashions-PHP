<?php
declare(strict_types=1);

namespace Pratyangi\Address;

final class AddressValidator
{
    public const TYPES = ['Home', 'Office', 'Other'];

    public const STATES = [
        'Andaman and Nicobar Islands',
        'Andhra Pradesh',
        'Arunachal Pradesh',
        'Assam',
        'Bihar',
        'Chandigarh',
        'Chhattisgarh',
        'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi',
        'Goa',
        'Gujarat',
        'Haryana',
        'Himachal Pradesh',
        'Jammu and Kashmir',
        'Jharkhand',
        'Karnataka',
        'Kerala',
        'Ladakh',
        'Lakshadweep',
        'Madhya Pradesh',
        'Maharashtra',
        'Manipur',
        'Meghalaya',
        'Mizoram',
        'Nagaland',
        'Odisha',
        'Puducherry',
        'Punjab',
        'Rajasthan',
        'Sikkim',
        'Tamil Nadu',
        'Telangana',
        'Tripura',
        'Uttar Pradesh',
        'Uttarakhand',
        'West Bengal',
    ];

    /**
     * @param array<string,mixed> $body
     * @return array{data:array<string,mixed>|null, errors:array<string,string>, firstField:string|null, firstMessage:string|null}
     */
    public static function validate(array $body): array
    {
        $errors = [];

        // type
        $type = isset($body['type']) && is_string($body['type']) ? trim($body['type']) : 'Home';
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Choose an address type (Home, Office or Other).';
        }

        // label
        $label = isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '';
        if (mb_strlen($label) > 30) {
            $errors['label'] = 'Label cannot be more than 30 characters.';
        }

        // name
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '' || mb_strlen($name) < 2) {
            $errors['name'] = "Enter the recipient's name.";
        } elseif (mb_strlen($name) > 80) {
            $errors['name'] = 'Name cannot be more than 80 characters.';
        }

        // phone
        $rawPhone = isset($body['phone']) ? (string) $body['phone'] : '';
        $cleanPhone = self::cleanPhone($rawPhone);
        if ($cleanPhone === '') {
            $errors['phone'] = 'Enter a mobile number.';
        } elseif (preg_match('/^[6-9]\d{9}$/D', $cleanPhone) !== 1) {
            $errors['phone'] = 'Enter a valid 10-digit mobile number.';
        }

        // alternatePhone (optional)
        $cleanAltPhone = null;
        if (isset($body['alternatePhone']) && $body['alternatePhone'] !== null && trim((string) $body['alternatePhone']) !== '') {
            $rawAlt = (string) $body['alternatePhone'];
            $cleanAlt = self::cleanPhone($rawAlt);
            if (preg_match('/^[6-9]\d{9}$/D', $cleanAlt) !== 1) {
                $errors['alternatePhone'] = 'Enter a valid 10-digit mobile number.';
            } else {
                $cleanAltPhone = $cleanAlt;
            }
        }

        // house
        $house = isset($body['house']) && is_string($body['house']) ? trim($body['house']) : '';
        if ($house === '') {
            $errors['house'] = 'Enter the house, flat or building.';
        } elseif (mb_strlen($house) > 120) {
            $errors['house'] = 'House/flat cannot be more than 120 characters.';
        }

        // street
        $street = isset($body['street']) && is_string($body['street']) ? trim($body['street']) : '';
        if ($street === '') {
            $errors['street'] = 'Enter the street address or area.';
        } elseif (mb_strlen($street) > 160) {
            $errors['street'] = 'Street address cannot be more than 160 characters.';
        }

        // city
        $city = isset($body['city']) && is_string($body['city']) ? trim($body['city']) : '';
        if ($city === '' || mb_strlen($city) < 2) {
            $errors['city'] = 'Enter the city.';
        } elseif (mb_strlen($city) > 60) {
            $errors['city'] = 'City cannot be more than 60 characters.';
        }

        // state
        $state = isset($body['state']) && is_string($body['state']) ? trim($body['state']) : '';
        if (!in_array($state, self::STATES, true)) {
            $errors['state'] = 'Choose a state or union territory.';
        }

        // pincode
        $pincode = isset($body['pincode']) && is_string($body['pincode']) ? trim($body['pincode']) : '';
        if (preg_match('/^[1-9]\d{5}$/D', $pincode) !== 1) {
            $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
        }

        // isDefault
        $isDefault = isset($body['isDefault']) ? (bool) $body['isDefault'] : false;

        if ($errors !== []) {
            $firstField = array_key_first($errors);
            return [
                'data' => null,
                'errors' => $errors,
                'firstField' => $firstField,
                'firstMessage' => $errors[$firstField],
            ];
        }

        return [
            'data' => [
                'type' => $type,
                'label' => $label,
                'recipient_name' => $name,
                'phone' => $cleanPhone,
                'alternate_phone' => $cleanAltPhone,
                'house' => $house,
                'street' => $street,
                'city' => $city,
                'state' => $state,
                'pincode' => $pincode,
                'isDefault' => $isDefault,
            ],
            'errors' => [],
            'firstField' => null,
            'firstMessage' => null,
        ];
    }

    public static function cleanPhone(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $raw = preg_replace('/[\s\-]/', '', trim($value)) ?? '';
        if (str_starts_with($raw, '+91')) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, '91') && strlen($raw) === 12) {
            $raw = substr($raw, 2);
        } elseif (str_starts_with($raw, '0') && strlen($raw) === 11) {
            $raw = substr($raw, 1);
        }
        return $raw;
    }
}

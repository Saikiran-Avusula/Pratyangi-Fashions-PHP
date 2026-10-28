<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Support\Clock;

/** The customer object the API returns everywhere. Never contains the password hash. */
final class PublicCustomer
{
    /**
     * @param array<string,mixed> $row a shop_customers row
     * @return array<string,mixed> id (string), name, email, provider, emailVerified, createdAt (ISO, ms, Z), mobile and picture when set
     */
    public static function fromRow(array $row): array
    {
        $mobile = (string) ($row['mobile'] ?? '');
        $email = (string) ($row['email'] ?? '');
        if (($row['password_hash'] ?? null) !== null && $row['password_hash'] !== '') {
            $provider = 'password';
        } else {
            // no password: an account made or extended by a mobile code (PHP-only addition) or, otherwise, a Google account
            $provider = $mobile !== '' ? 'mobile' : 'google';
        }
        $out = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'email' => $email,
            'provider' => $provider,
            'emailVerified' => ($row['email_verified_at'] ?? null) !== null,
            'createdAt' => Clock::iso(Clock::fromSql((string) $row['created_at'])),
        ];
        if ($mobile !== '') {
            $out['mobile'] = $mobile; // PHP-only additions: present only for an account that has a number
            $out['mobileVerified'] = ($row['mobile_verified_at'] ?? null) !== null;
        }
        $picture = $row['picture_url'] ?? null;
        if ($picture !== null && $picture !== '') {
            $out['picture'] = (string) $picture;
        }
        return $out;
    }
}

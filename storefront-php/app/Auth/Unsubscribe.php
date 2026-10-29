<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/**
 * The unsubscribe link every future promotional email must carry (DPDP / TRAI): link() makes it, check() reads it back. The token is
 * "<customer>.<channel>.<signature>", signed with the OTP pepper, so nobody can unsubscribe somebody else by guessing. The page that
 * receives the link is added with the first promotional email; order and account emails never need one.
 */
final class Unsubscribe
{
    public static function link(string $siteUrl, string $pepper, int $customerId, string $channel = 'email'): string
    {
        return rtrim($siteUrl, '/') . '/unsubscribe?t=' . rawurlencode(self::token($pepper, $customerId, $channel));
    }

    public static function token(string $pepper, int $customerId, string $channel = 'email'): string
    {
        return $customerId . '.' . $channel . '.' . self::signature($pepper, $customerId, $channel);
    }

    /** @return array{0:int,1:string}|null customer id and channel, or null when the token is not ours */
    public static function check(string $pepper, string $token): ?array
    {
        if (preg_match('/^(\d{1,9})\.(sms|whatsapp|email)\.([0-9a-f]{40})$/D', $token, $m) !== 1) {
            return null;
        }
        return hash_equals(self::signature($pepper, (int) $m[1], $m[2]), $m[3]) ? [(int) $m[1], $m[2]] : null;
    }

    private static function signature(string $pepper, int $customerId, string $channel): string
    {
        return substr(hash_hmac('sha256', "unsubscribe|$customerId|$channel", $pepper), 0, 40);
    }
}

<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/** Indian mobile numbers: typed as 10 digits (6-9 first), optionally with +91 or 91 in front and spaces; stored as +91XXXXXXXXXX. */
final class Mobile
{
    /** @return string|null +91XXXXXXXXXX, or null when the text is not an Indian mobile number */
    public static function normalise(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 40) {
            return null;
        }
        $compact = str_replace(' ', '', trim($value));
        // no /u flag: \d is ASCII only, so other scripts' digits never pass
        if (preg_match('/^(?:\+?91)?([6-9]\d{9})$/D', $compact, $m) !== 1) {
            return null;
        }
        return '+91' . $m[1];
    }

    /** +91******3210: for log lines and messages. A stored number is never printed in full. */
    public static function mask(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';
        return '+91******' . substr($digits, -4);
    }
}

<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Support\Text;

/**
 * Every rule the website checks in the browser is checked again here. Messages are the Node API's, word for word.
 * Each field reports its first failing rule; the response carries the first field's message plus one message per field.
 * Methods return the cleaned values (email lower-cased and trimmed, and so on) or throw a 422.
 */
final class Validator
{
    /** The only message the one-time code route gives for a wrong, expired, used, over-tried or malformed code. */
    public const OTP_INVALID = 'Invalid or expired code';

    private const EMAIL_RE = '/^[^\s\p{Z}@\x{FEFF}]+@[^\s\p{Z}@\x{FEFF}]+\.[^\s\p{Z}@\x{FEFF}]{2,}$/Du';

    /** @return array{name:string,email:string,password:string} */
    public static function signup(mixed $body): array
    {
        return self::run($body, [
            'name' => fn (mixed $v): array => self::name($v),
            'email' => fn (mixed $v): array => self::email($v),
            'password' => fn (mixed $v): array => self::newPassword($v),
        ]);
    }

    /** @return array{email:string,password:string} */
    public static function login(mixed $body): array
    {
        return self::run($body, [
            'email' => fn (mixed $v): array => self::email($v),
            // Node trims the login password too (zod .trim() keeps its output)
            'password' => fn (mixed $v): array => self::required($v, 'Enter your password.'),
        ]);
    }

    /**
     * Password reset request: by email (the default, as in Node) or, with "channel": "mobile", by mobile number.
     * @return array{channel:string,target:string}
     */
    public static function forgot(mixed $body): array
    {
        $channel = self::channel($body);
        $values = self::run($body, [$channel => self::targetRule($channel)]);
        return ['channel' => $channel, 'target' => $values[$channel]];
    }

    /** @return array{email:string} */
    public static function emailOnly(mixed $body): array
    {
        return self::run($body, ['email' => fn (mixed $v): array => self::email($v)]);
    }

    /** @return array{credential:string} */
    public static function googleCredential(mixed $body): array
    {
        return self::run($body, ['credential' => static fn (mixed $v): array => is_string($v) && strlen($v) >= 20 && strlen($v) <= 4096 ? [$v, null] : [null, 'Google sign-in did not send a sign-in token.']]);
    }

    /** @return array{code:string} */
    public static function codeOnly(mixed $body): array
    {
        return self::run($body, ['code' => fn (mixed $v): array => self::code($v)]);
    }

    /** @return array{name:string} */
    public static function profileName(mixed $body): array
    {
        return self::run($body, ['name' => fn (mixed $v): array => self::name($v)]);
    }

    /** @return array{mobile:string,code:string} */
    public static function mobileCode(mixed $body): array
    {
        return self::run($body, [
            'mobile' => fn (mixed $v): array => self::mobile($v),
            'code' => fn (mixed $v): array => self::code($v),
        ]);
    }

    /** @return array{email:string,code:string} */
    public static function emailCode(mixed $body): array
    {
        return self::run($body, [
            'email' => fn (mixed $v): array => self::email($v),
            'code' => fn (mixed $v): array => self::code($v),
        ]);
    }

    /** @return array{token:string} */
    public static function verifyEmail(mixed $body): array
    {
        return self::run($body, ['token' => static function (mixed $v): array {
            $message = 'This verification link is incomplete.';
            if (!is_string($v)) {
                return [null, $message];
            }
            $t = Text::trim($v);
            $len = Text::length($t);
            return $len < 20 || $len > 200 ? [null, $message] : [$t, null];
        }]);
    }

    /** @return array{channel:string,target:string,code:string,password:string} */
    public static function reset(mixed $body): array
    {
        $channel = self::channel($body);
        $values = self::run($body, [
            $channel => self::targetRule($channel),
            'code' => fn (mixed $v): array => self::code($v),
            'password' => fn (mixed $v): array => self::newPassword($v),
        ]);
        return ['channel' => $channel, 'target' => $values[$channel], 'code' => $values['code'], 'password' => $values['password']];
    }

    /**
     * Which of email and mobile a reset body is about. A missing or unknown "channel" means email, as in Node.
     * @param mixed $body
     */
    private static function channel(mixed $body): string
    {
        return is_array($body) && ($body['channel'] ?? null) === 'mobile' ? 'mobile' : 'email';
    }

    /** @return \Closure(mixed):array{0:?string,1:?string} */
    private static function targetRule(string $channel): \Closure
    {
        return $channel === 'mobile' ? fn (mixed $v): array => self::mobile($v) : fn (mixed $v): array => self::email($v);
    }

    /** @return array{0:?string,1:?string} +91XXXXXXXXXX */
    public static function mobile(mixed $v): array
    {
        $mobile = Mobile::normalise($v);
        return $mobile === null ? [null, 'Enter a valid 10-digit mobile number.'] : [$mobile, null];
    }

    /** @return array{0:?string,1:?string} */
    public static function code(mixed $v): array
    {
        if (!is_string($v)) {
            return [null, 'Enter the 6-digit code.'];
        }
        $t = Text::trim($v);
        return preg_match('/^\d{6}$/D', $t) === 1 ? [$t, null] : [null, 'The code is 6 digits.'];
    }

    /**
     * Catalogue query (strings or lists of strings from the URL). Empty values are ignored.
     * @param array<string,string|list<string>> $query
     * @return array{category:?string,q:?string,limit:int,cursor:?string}
     */
    public static function catalogQuery(array $query): array
    {
        $errors = [];
        $out = ['category' => null, 'q' => null, 'limit' => 24, 'cursor' => null];

        $category = $query['category'] ?? null;
        if ($category !== null && $category !== '') {
            if (!is_string($category)) {
                $errors['category'] = 'Choose one category.';
            } else {
                $t = Text::trim($category);
                if (Text::length($t) > 60) {
                    $errors['category'] = 'That category name is too long.';
                } else {
                    $out['category'] = $t;
                }
            }
        }

        $q = $query['q'] ?? null;
        if ($q !== null && $q !== '') {
            if (!is_string($q)) {
                $errors['q'] = 'Enter one search phrase.';
            } else {
                $t = Text::trim($q);
                if (Text::length($t) > 80) {
                    $errors['q'] = 'That search is too long (80 characters at most).';
                } else {
                    $out['q'] = $t;
                }
            }
        }

        $limit = $query['limit'] ?? null;
        if ($limit !== null && $limit !== '') {
            $n = is_string($limit) ? Text::jsNumber($limit) : null;
            if ($n === null || is_infinite($n)) {
                $errors['limit'] = 'The page size must be a number.';
            } elseif ($n !== floor($n)) {
                $errors['limit'] = 'The page size must be a whole number.';
            } elseif ($n < 1) {
                $errors['limit'] = 'The page size must be at least 1.';
            } elseif ($n > 100) {
                $errors['limit'] = 'The page size cannot be more than 100.';
            } else {
                $out['limit'] = (int) $n;
            }
        }

        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && $cursor !== '') {
            if (!is_string($cursor) || preg_match('/^\d{1,6}$/D', $cursor) !== 1) {
                $errors['cursor'] = 'That page marker is not valid.';
            } else {
                $out['cursor'] = $cursor;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $out;
    }

    /** @return array{0:?string,1:?string} [value, error] */
    public static function email(mixed $v): array
    {
        if (!is_string($v)) {
            return [null, 'Enter your email address.'];
        }
        $t = Text::lower(Text::trim($v));
        if ($t === '') {
            return [null, 'Enter your email address.'];
        }
        if (Text::length($t) > 254) {
            return [null, 'That email address is too long.'];
        }
        if (preg_match(self::EMAIL_RE, $t) !== 1) {
            return [null, 'Enter a valid email address, like name@example.com.'];
        }
        return [$t, null];
    }

    /** @return array{mobile:string} +91XXXXXXXXXX */
    public static function otpRequest(mixed $body): array
    {
        return self::run($body, ['mobile' => fn (mixed $v): array => self::mobile($v)]);
    }

    /**
     * The verify route answers every failure alike, so a bad shape is the same refusal as a wrong code. Anything else in the body
     * (a name or an email from an old page, say) is ignored: an account made by a code has neither.
     * @return array{mobile:string,code:string}
     */
    public static function otpVerify(mixed $body): array
    {
        $refuse = static fn (): ApiException => ApiException::badRequest(self::OTP_INVALID);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw $refuse();
        }
        $mobile = Mobile::normalise($body['mobile'] ?? null);
        $code = $body['code'] ?? null;
        $code = is_string($code) ? Text::trim($code) : '';
        if ($mobile === null || preg_match('/^\d{6}$/D', $code) !== 1) {
            throw $refuse();
        }
        return ['mobile' => $mobile, 'code' => $code];
    }

    /** @return array{0:?string,1:?string} */
    public static function name(mixed $v): array
    {
        if (!is_string($v)) {
            return [null, 'Enter your full name.'];
        }
        $t = Text::trim($v);
        $len = Text::length($t);
        if ($len < 2) {
            return [null, 'Enter your full name (at least 2 characters).'];
        }
        if ($len > 80) {
            return [null, 'That name is too long (80 characters at most).'];
        }
        return [$t, null];
    }

    /** @return array{0:?string,1:?string} */
    private static function newPassword(mixed $v): array
    {
        if (!is_string($v)) {
            return [null, 'Create a password.'];
        }
        $len = Text::length($v);
        if ($len < 8) {
            return [null, 'Password must be at least 8 characters.'];
        }
        if ($len > 128) {
            return [null, 'Password is too long (128 characters at most).'];
        }
        if (preg_match('/[A-Za-z]/', $v) !== 1) {
            return [null, 'Password needs at least one letter.'];
        }
        if (preg_match('/\d/', $v) !== 1) {
            return [null, 'Password needs at least one number.'];
        }
        return [$v, null];
    }

    /** @return array{0:?string,1:?string} */
    private static function required(mixed $v, string $message): array
    {
        if (!is_string($v)) {
            return [null, $message];
        }
        $t = Text::trim($v);
        return $t === '' ? [null, $message] : [$t, null];
    }

    /**
     * @param array<string,\Closure(mixed):array{0:mixed,1:?string}> $fields in schema order
     * @return array<string,mixed>
     */
    private static function run(mixed $body, array $fields): array
    {
        if ($body === null) {
            $body = [];
        }
        if (!is_array($body)) {
            throw new ApiException(422, 'Invalid input: expected object, received ' . get_debug_type($body), 'form', ['form' => 'Invalid input: expected object, received ' . get_debug_type($body)]);
        }
        if ($body !== [] && array_is_list($body)) {
            throw new ApiException(422, 'Invalid input: expected object, received array', 'form', ['form' => 'Invalid input: expected object, received array']);
        }
        $values = [];
        $errors = [];
        foreach ($fields as $name => $rule) {
            [$value, $error] = $rule($body[$name] ?? null);
            if ($error !== null) {
                $errors[$name] = $error;
            } else {
                $values[$name] = $value;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $values;
    }
}

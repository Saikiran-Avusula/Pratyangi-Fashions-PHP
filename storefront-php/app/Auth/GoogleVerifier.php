<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Support\Clock;

/**
 * Checks a Google ID token (the "credential" Google Identity Services hands the browser) the way google-auth-library does: RS256 signature against
 * Google's published keys (JWKS, read with openssl), issuer, audience = our GOOGLE_CLIENT_ID, expiry and a verified email.
 * Every failure is the same 401 (the reason goes to the log, never to the visitor). The keys come from $keys (production: Google's certs address,
 * kept in a file for an hour; tests: a fake set), so a test needs no network.
 */
final class GoogleVerifier
{
    public const FAILED = 'Google sign-in could not be verified. Please try again.';
    public const UNVERIFIED_EMAIL = 'Your Google email address is not verified, so we cannot sign you in with it.';
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const SKEW = 60; // seconds of clock difference allowed on exp

    /** @param \Closure(bool):array<string,mixed> $keys returns the JWKS ({"keys":[...]}); true = ignore any saved copy */
    public function __construct(private string $clientId, private \Closure $keys, private Clock $clock, private ?\Pratyangi\Support\Logger $logger = null)
    {
    }

    /** Production key source: Google's certs, saved in $file for an hour (shortcut: a fixed hour, not Google's own max-age; fine while Google rotates daily). */
    public static function certsFrom(string $file, Clock $clock): \Closure
    {
        return static function (bool $fresh) use ($file, $clock): array {
            if (!$fresh && is_file($file) && $clock->now() - (int) filemtime($file) < 3600) {
                $saved = json_decode((string) file_get_contents($file), true);
                if (is_array($saved) && isset($saved['keys'])) {
                    return $saved;
                }
            }
            $body = @file_get_contents(self::CERTS_URL, false, stream_context_create(['http' => ['timeout' => 5]]));
            $json = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($json) || !isset($json['keys'])) {
                throw new \RuntimeException('could not read Google\'s keys');
            }
            @file_put_contents($file, $body);
            return $json;
        };
    }

    /**
     * @return array{sub:string,email:string,name:string,picture:?string}
     * @throws ApiException 401 for any token that is not a valid, current Google token for this shop
     */
    public function verify(string $credential): array
    {
        try {
            return $this->check($credential);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->channel('auth')->warn('google token refused', ['reason' => $e->getMessage(), 'outcome' => 'rejected']);
            throw new ApiException(401, self::FAILED);
        }
    }

    /** @return array{sub:string,email:string,name:string,picture:?string} */
    private function check(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('not a JWT');
        }
        $header = self::json($parts[0]);
        $claims = self::json($parts[1]);
        $signature = self::b64($parts[2]);
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw new \RuntimeException('algorithm or key id');
        }
        $pem = $this->pemFor($header['kid']);
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('bad signature');
        }
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new \RuntimeException('issuer');
        }
        $aud = $claims['aud'] ?? null;
        if ($aud !== $this->clientId) {
            throw new \RuntimeException('audience');
        }
        if (!is_numeric($claims['exp'] ?? null) || (float) $claims['exp'] + self::SKEW < $this->clock->now()) {
            throw new \RuntimeException('expired');
        }
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 191 || !is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('subject or email');
        }
        if (($claims['email_verified'] ?? null) !== true && ($claims['email_verified'] ?? null) !== 'true') {
            throw new ApiException(401, self::UNVERIFIED_EMAIL);
        }
        $name = is_string($claims['name'] ?? null) ? trim($claims['name']) : '';
        $picture = PublicCustomer::safePicture($claims['picture'] ?? null);
        return ['sub' => $sub, 'email' => strtolower($email), 'name' => $name, 'picture' => $picture];
    }

    /** The public key with this id; a key id that is not in the saved set makes one fresh read (Google rotates its keys). */
    private function pemFor(string $kid): string
    {
        foreach ([false, true] as $fresh) {
            foreach (($this->keys)($fresh)['keys'] ?? [] as $jwk) {
                if (($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                    return self::pem(self::b64($jwk['n']), self::b64($jwk['e']));
                }
            }
        }
        throw new \RuntimeException('unknown key id');
    }

    /** An RSA public key (modulus, exponent) as a PEM "SubjectPublicKeyInfo". */
    private static function pem(string $n, string $e): string
    {
        $int = static fn (string $b): string => "\x02" . self::len(strlen($b) + (ord($b[0]) > 127 ? 1 : 0)) . (ord($b[0]) > 127 ? "\0" : '') . $b;
        $rsa = "\x30" . self::len(strlen($int($n)) + strlen($int($e))) . $int($n) . $int($e);
        $bits = "\x03" . self::len(strlen($rsa) + 1) . "\0" . $rsa;
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $der = "\x30" . self::len(strlen($algorithm) + strlen($bits)) . $algorithm . $bits;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        return $n < 128 ? chr($n) : (($n < 256) ? "\x81" . chr($n) : "\x82" . chr($n >> 8) . chr($n & 255));
    }

    private static function b64(string $s): string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        if ($out === false || $out === '') {
            throw new \RuntimeException('not base64url');
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function json(string $part): array
    {
        $v = json_decode(self::b64($part), true);
        if (!is_array($v)) {
            throw new \RuntimeException('not JSON');
        }
        return $v;
    }
}

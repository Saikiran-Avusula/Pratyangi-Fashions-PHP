<?php
declare(strict_types=1);

/** A stand-in for Google in tests: an RSA key pair, its JWKS, and ID tokens signed with it. No network. */
final class GoogleFake
{
    public const CLIENT_ID = '123456789-testclient.apps.googleusercontent.com';
    public \OpenSSLAsymmetricKey $key;
    public int $fetches = 0;
    /** @var list<array<string,string>> */
    public array $keys = [];

    public function __construct(public string $kid = 'test-key-1')
    {
        $this->key = self::newKey();
        $this->keys = [self::jwk($this->key, $kid)];
    }

    /** A fresh 2048-bit RSA key. A machine whose OPENSSL_CONF points nowhere (seen on Windows) falls back to the openssl.cnf next to php. */
    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($options);
        if ($key === false && is_file($cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
            $key = openssl_pkey_new($options + ['config' => $cnf]);
        }
        if ($key === false) {
            throw new RuntimeException('openssl could not make a key');
        }
        return $key;
    }

    /** @return array<string,string> */
    public static function jwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];
        return ['kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
    }

    /** The key source GoogleVerifier takes; counts the reads so a test can see the "unknown key id, read once more" rule. */
    public function source(): \Closure
    {
        return function (bool $fresh): array {
            $this->fetches++;
            return ['keys' => $this->keys];
        };
    }

    /** @param array<string,mixed> $claims overrides (a null value removes the claim) */
    public function token(array $claims = [], ?\OpenSSLAsymmetricKey $signWith = null, ?string $kid = null, string $alg = 'RS256'): string
    {
        $claims = array_filter($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'sub' => '110169484474386276334', 'email' => 'asha@example.com',
            'email_verified' => true, 'name' => 'Asha Rao', 'picture' => 'https://lh3.googleusercontent.com/a/asha', 'iat' => time(), 'exp' => time() + 3600,
        ], static fn ($v) => $v !== null);
        $head = self::b64(json_encode(['alg' => $alg, 'kid' => $kid ?? $this->kid, 'typ' => 'JWT']));
        $body = self::b64(json_encode($claims));
        openssl_sign($head . '.' . $body, $sig, $signWith ?? $this->key, OPENSSL_ALGO_SHA256);
        return $head . '.' . $body . '.' . self::b64($sig);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

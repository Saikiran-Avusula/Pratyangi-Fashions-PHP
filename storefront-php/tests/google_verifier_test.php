<?php
declare(strict_types=1);

// No database, no network. Run: php tests/google_verifier_test.php
// GoogleVerifier against a fake Google (tests/lib/GoogleFake.php): real RS256 signatures checked with openssl, every refusal the same 401.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/GoogleFake.php';

use Pratyangi\Auth\GoogleVerifier;
use Pratyangi\Http\ApiException;

$t = new TestCase('google_verifier_test');
$clock = new FixedClock((float) time());
$google = new GoogleFake();
$verifier = new GoogleVerifier(GoogleFake::CLIENT_ID, $google->source(), $clock);
$refused = static function (string $token, ?string $message = null) use ($t, $verifier): void {
    try {
        $verifier->verify($token);
    } catch (ApiException $e) {
        $t->same([401, $message ?? GoogleVerifier::FAILED], [$e->status, $e->getMessage()]);
        return;
    }
    throw new RuntimeException('the token was accepted');
};

$t->test('a good token: the claims come back (email lower-cased)', function () use ($t, $verifier, $google): void {
    $c = $verifier->verify($google->token(['email' => 'Asha@Example.com']));
    $t->same(['110169484474386276334', 'asha@example.com', 'Asha Rao', 'https://lh3.googleusercontent.com/a/asha'], [$c['sub'], $c['email'], $c['name'], $c['picture']]);
    $t->same('110169484474386276334', $verifier->verify($google->token(['iss' => 'accounts.google.com']))['sub'], 'the bare issuer is accepted too');
    $c = $verifier->verify($google->token(['email_verified' => 'true', 'picture' => 'http://insecure.example/p.png', 'name' => null]));
    $t->same([null, ''], [$c['picture'], $c['name']], 'a picture that is not https is dropped');
});

$t->test('wrong signature, wrong audience, wrong issuer, expired, no subject: all the same 401', function () use ($t, $google, $refused): void {
    $other = GoogleFake::newKey();
    $refused($google->token([], $other)); // signed by another key
    $refused($google->token(['aud' => 'someone-elses.apps.googleusercontent.com']));
    $refused($google->token(['iss' => 'https://evil.example.com']));
    $refused($google->token(['exp' => time() - 3600]));
    $refused($google->token(['sub' => null]));
    $refused($google->token(['email' => 'not an address']));
    $refused('abc.def');
    $refused('');
    $refused(substr($google->token(), 0, -4) . 'AAAA');
});

$t->test('an unverified Google email is refused with its own words', function () use ($t, $google, $refused): void {
    $refused($google->token(['email_verified' => false]), GoogleVerifier::UNVERIFIED_EMAIL);
    $refused($google->token(['email_verified' => null]), GoogleVerifier::UNVERIFIED_EMAIL);
});

$t->test('only RS256: alg none and HS256 tokens are refused even when the rest is right', function () use ($google, $refused): void {
    $refused($google->token([], null, null, 'none'));
    $refused($google->token([], null, null, 'HS256'));
    $head = GoogleFake::b64(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $refused($head . '.' . GoogleFake::b64(json_encode(['iss' => 'https://accounts.google.com', 'aud' => GoogleFake::CLIENT_ID, 'sub' => '1', 'email' => 'a@example.com', 'email_verified' => true, 'exp' => time() + 99])) . '.');
});

$t->test('an unknown key id makes one fresh read of the keys (Google rotates them); a key that is not there at all is refused', function () use ($t, $google, $clock, $refused): void {
    $rotating = new GoogleFake('old-key');
    $reads = [];
    $fresh = GoogleFake::newKey();
    $v = new GoogleVerifier(GoogleFake::CLIENT_ID, static function (bool $newest) use ($rotating, $fresh, &$reads): array {
        $reads[] = $newest;
        return ['keys' => $newest ? [GoogleFake::jwk($fresh, 'new-key')] : $rotating->keys];
    }, $clock);
    $v->verify($rotating->token());
    $t->same([false], $reads, 'a known key id: one read, from the saved copy');
    $reads = [];
    $v->verify($rotating->token([], $fresh, 'new-key'));
    $t->same([false, true], $reads, 'a new key id: the saved copy, then a fresh read');
    try {
        $v->verify($rotating->token([], null, 'nobody'));
        throw new RuntimeException('accepted');
    } catch (ApiException $e) {
        $t->same(401, $e->status);
    }
});

$t->test('a failure to reach Google is the same 401, never a 500', function () use ($t, $clock, $google): void {
    $v = new GoogleVerifier(GoogleFake::CLIENT_ID, static function (bool $fresh): array { throw new RuntimeException('network down'); }, $clock);
    try {
        $v->verify($google->token());
        throw new RuntimeException('accepted');
    } catch (ApiException $e) {
        $t->same([401, GoogleVerifier::FAILED], [$e->status, $e->getMessage()]);
    }
});

exit($t->finish());

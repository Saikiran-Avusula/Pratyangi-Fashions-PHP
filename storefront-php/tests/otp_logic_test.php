<?php
declare(strict_types=1);

// No database, no real SMS provider. Run: php tests/otp_logic_test.php
// Mobile OTP sign-in, the parts that need no MySQL: number normalisation, code generation and hashing, the settings rules, the redirect rule,
// the log and http SMS gateways (the http one against a fake gateway on 127.0.0.1), the rate limits, and the routes' answers before the database.
// The flow itself (expiry, attempts, cooldown, identical answers, sign-in) is tests/db_otp_test.php.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/FakeSms.php';
require __DIR__ . '/lib/FakeAuthPdo.php';

use Pratyangi\Auth\Mobile;
use Pratyangi\Auth\Otp;
use Pratyangi\Auth\Validator;
use Pratyangi\Config;
use Pratyangi\ConfigException;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\MemoryRateLimitStore;
use Pratyangi\Http\RateLimiter;
use Pratyangi\Sms\HttpSmsGateway;
use Pratyangi\Sms\LogSmsGateway;
use Pratyangi\Sms\SmsException;
use Pratyangi\Support\Logger;
use Pratyangi\Web\SafeNext;

$t = new TestCase('otp_logic_test');
$pepper = str_repeat('k', 40);

$t->test('mobile: 10 digits starting 6-9, optional +91 / 91 and spaces, stored as +91XXXXXXXXXX', function () use ($t): void {
    foreach (['9876543210', '98765 43210', '+91 98765 43210', '+919876543210', '919876543210', '91 9876543210', ' 9876543210 ', '6000000000', '7999999999', '8123456789'] as $ok) {
        $t->same('+91' . substr(str_replace([' ', '+'], '', $ok), -10), Mobile::normalise($ok), $ok);
    }
    foreach (['5876543210', '0876543210', '987654321', '98765432100', '+9198765432', '+92 9876543210', '9198765432101', '98765-43210', '(98765) 43210', '+91+9876543210',
        '', '+91', 'abcdefghij', "9876543210\n9", 'Ã¯Â¼â„¢Ã¯Â¼ËœÃ¯Â¼â€”Ã¯Â¼â€“Ã¯Â¼â€¢Ã¯Â¼â€Ã¯Â¼â€œÃ¯Â¼â€™Ã¯Â¼â€˜Ã¯Â¼Â', '+9876543210', '0919876543210', "98765\t43210"] as $bad) {
        $t->same(null, Mobile::normalise($bad), json_encode($bad));
    }
    $t->same(null, Mobile::normalise(null));
    $t->same(null, Mobile::normalise(9876543210));
    $t->same(null, Mobile::normalise(['9876543210']));
    $t->same(null, Mobile::normalise(str_repeat('9', 5000)));
    $t->same('+919876543210', Mobile::normalise('9876543210') === '+919876543210' ? '+919876543210' : 'x');
    // 91 followed by eight more digits is a 10-digit number that happens to start with 91
    $t->same('+919198765432', Mobile::normalise('9198765432'));
    $t->same('+91******3210', Mobile::mask('+919876543210'));
    $t->ok(!str_contains(Mobile::mask('+919876543210'), '98765'));
});

$t->test('code: six digits, zero padded, from random_int; hash is an HMAC-SHA256 keyed by the pepper and bound to the number', function () use ($t, $pepper): void {
    $seen = [];
    for ($i = 0; $i < 300; $i++) {
        $code = Otp::newCode();
        $t->same(1, preg_match('/^\d{6}$/D', $code), $code);
        $seen[$code] = true;
    }
    $t->ok(count($seen) > 250, 'codes vary');
    $h = Otp::hash($pepper, '+919876543210', '012345');
    $t->same(1, preg_match('/^[0-9a-f]{64}$/D', $h));
    $t->same($h, Otp::hash($pepper, '+919876543210', '012345'), 'deterministic');
    $t->same(hash_hmac('sha256', '+919876543210|012345', $pepper), $h, 'HMAC-SHA256');
    $t->ok(!str_contains($h, '012345'), 'the code is not in the stored value');
    foreach ([Otp::hash($pepper, '+919876543211', '012345'), Otp::hash($pepper, '+919876543210', '012346'), Otp::hash(str_repeat('z', 40), '+919876543210', '012345')] as $other) {
        $t->ok($other !== $h, 'any change gives another hash');
    }
    $t->same(true, Otp::matches($pepper, '+919876543210', '012345', $h));
    $t->same(false, Otp::matches($pepper, '+919876543210', '012346', $h));
    $t->same(false, Otp::matches($pepper, '+919876543211', '012345', $h), 'a hash only fits its own number');
    $t->same(false, Otp::matches($pepper, '+919876543210', '012345', strtoupper($h)), 'exact comparison');
    $t->ok(Otp::fingerprint($pepper, 'mobile', '+919876543210') !== Otp::fingerprint($pepper, 'ip', '+919876543210'), 'kinds do not mix');
    $t->same([300, 45, 5, 6], [Otp::TTL_SECONDS, Otp::RESEND_SECONDS, Otp::MAX_ATTEMPTS, Otp::LENGTH], 'the agreed limits');
});

$t->test('settings: pepper and SMS rules, production refuses log mode and a missing pepper', function () use ($t, $pepper): void {
    $base = ['DB_NAME' => 'pf_unit_test', 'DB_USER' => 'unit', 'ERP_MODE' => 'off', 'MAIL_MODE' => 'smtp', 'SMS_ENABLED' => 'true', 'WEB_ORIGIN' => 'https://shop.example.com'];
    $http = ['SMS_MODE' => 'http', 'SMS_API_URL' => 'https://sms.example.com/api', 'SMS_USERNAME' => 'u', 'SMS_APIKEY' => 'k', 'SMS_SENDER_ID' => 'S', 'SMS_OTP_TEMPLATE_ID' => '1'];
    $problem = function (array $values) use ($t, $base): string {
        $e = $t->throws(ConfigException::class, fn () => new Config($values + $base));
        return $e->getMessage();
    };
    // development and test: log mode is the default, the pepper is optional (without it the routes answer 503)
    foreach (['development', 'test'] as $env) {
        $c = new Config(['APP_ENV' => $env] + $base);
        $t->same(['log', null], [$c->smsMode, $c->otpPepper()], $env);
        $t->contains('sms.log', str_replace('\\', '/', $c->smsLogPath));
    }
    $c = new Config(['APP_ENV' => 'test', 'OTP_PEPPER' => $pepper, 'SMS_MODE' => 'log', 'SMS_LOG_PATH' => '/tmp/x.log'] + $base);
    $t->same([$pepper, '/tmp/x.log'], [$c->otpPepper(), $c->smsLogPath]);
    // production
    $m = $problem(['APP_ENV' => 'production'] + $http);
    $t->contains('OTP_PEPPER: required in production', $m);
    $m = $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => $pepper, 'SMS_MODE' => 'log']);
    $t->contains('SMS_MODE: "log"', $m);
    $m = $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => $pepper]); // http is the production default
    foreach (['SMS_API_URL', 'SMS_USERNAME', 'SMS_APIKEY', 'SMS_SENDER_ID', 'SMS_OTP_TEMPLATE_ID'] as $name) {
        $t->contains($name . ': required when SMS_MODE=http', $m);
    }
    $t->contains('OTP_PEPPER: must be at least 32 characters', $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => 'short'] + $http));
    $t->contains('SMS_MODE: must be log or http', $problem(['APP_ENV' => 'test', 'SMS_MODE' => 'smtp']));
    $ok = new Config(['APP_ENV' => 'production', 'OTP_PEPPER' => $pepper] + $http + $base);
    $t->same(['http', 'https://sms.example.com/api', 'k'], [$ok->smsMode, $ok->smsApiUrl, $ok->smsApiKey()]);
    // the address: https only; plain http only for this machine and outside production
    foreach (['http://sms.example.com/api', 'ftp://sms.example.com', 'sms.example.com', 'https://', 'https://user:pw@sms.example.com/api'] as $bad) {
        $t->contains('SMS_API_URL', $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => $pepper, 'SMS_API_URL' => $bad] + $http), $bad);
    }
    $t->contains('SMS_API_URL: must be an https', $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => $pepper, 'SMS_API_URL' => 'http://127.0.0.1:9/x'] + $http), 'no plain http in production, even to localhost');
    $t->contains('SMS_API_URL: must be an https', $problem(['APP_ENV' => 'test', 'OTP_PEPPER' => $pepper, 'SMS_API_URL' => 'http://sms.example.com/x'] + $http), 'plain http only to this machine');
    $local = new Config(['APP_ENV' => 'test', 'OTP_PEPPER' => $pepper, 'SMS_API_URL' => 'http://127.0.0.1:9/x'] + $http + $base);
    $t->same('http://127.0.0.1:9/x', $local->smsApiUrl);
    // the template: {#var#} exactly once
    foreach (['no placeholder', 'twice {#var#} and {#var#}', str_repeat('x', 301) . '{#var#}'] as $bad) {
        $t->contains('SMS_OTP_TEMPLATE_TEXT', $problem(['APP_ENV' => 'test', 'SMS_OTP_TEMPLATE_TEXT' => $bad]), $bad);
    }
    // the message names settings, never values
    $m = $problem(['APP_ENV' => 'production', 'OTP_PEPPER' => 'short-secret-value'] + $http);
    $t->ok(!str_contains($m, 'short-secret-value'));
    ob_start();
    var_dump($ok);
    $dump = (string) ob_get_clean();
    $t->ok(!str_contains(print_r($ok, true), $pepper) && !str_contains($dump, $pepper) && !str_contains($dump, 'k"'), 'the pepper and the key are not in print_r / var_dump');
});

$t->test('redirect rule: the landing page unless next is a safe same-site path', function () use ($t): void {
    foreach ([null, '', '/', 'checkout', 'account', '/account', '/product?sku=ABC-1', '/bag#top', '//evil.example', '//evil.example/x', '///x', 'http://evil.example', 'https://evil.example/', 'javascript:alert(1)',
        '/\\evil.example', '/x\\y', "/x\ty", "/x\ny", "/x\0y", '/x y', ' /x', '/redirect?u=https://evil.example', '/a://b', '\\evil', 'data:text/html,x', str_repeat('/a', 300)] as $bad) {
        $t->same('/', SafeNext::path($bad), json_encode($bad));
    }
    foreach (['/checkout'] as $good) {
        $t->same($good, SafeNext::path($good), $good);
    }
    $t->same('/', SafeNext::DEFAULT);
    $t->ok(!in_array(SafeNext::path(null), ['/account'], true), 'never /account by default');
});

$t->test('validators: request shape errors name the field; verify gives one refusal for every bad shape', function () use ($t): void {
    $t->same(['mobile' => '+919876543210'], Validator::otpRequest(['mobile' => '+91 98765 43210', 'extra' => 'ignored']));
    foreach ([null, [], ['mobile' => ''], ['mobile' => 5], ['mobile' => '12345'], ['mobile' => ['9876543210']], 'text', ['9876543210']] as $bad) {
        $e = $t->throws(ApiException::class, fn () => Validator::otpRequest($bad), json_encode($bad));
        $t->same(422, $e->status);
    }
    $e = $t->throws(ApiException::class, fn () => Validator::otpRequest(['mobile' => '123']));
    $t->same(['mobile', ['mobile' => 'Enter a valid 10-digit mobile number.']], [$e->field, $e->errors]);

    $v = Validator::otpVerify(['mobile' => '98765 43210', 'code' => ' 012345 ', 'name' => '  Asha Rao ', 'email' => 'a@b.co']);
    $t->same(['mobile' => '+919876543210', 'code' => '012345'], $v, 'a name or email in the body is ignored: a mobile account has neither');
    foreach ([null, [], 'x', [1, 2], ['mobile' => '9876543210'], ['code' => '123456'], ['mobile' => '12345', 'code' => '123456'], ['mobile' => '9876543210', 'code' => '12345'],
        ['mobile' => '9876543210', 'code' => '1234567'], ['mobile' => '9876543210', 'code' => 'abcdef'], ['mobile' => '9876543210', 'code' => 123456], ['mobile' => '9876543210', 'code' => ['123456']],
        ['mobile' => '9876543210', 'code' => 'Ã¯Â¼â€˜Ã¯Â¼â€™Ã¯Â¼â€œÃ¯Â¼â€Ã¯Â¼â€¢Ã¯Â¼â€“']] as $bad) {
        $e = $t->throws(ApiException::class, fn () => Validator::otpVerify($bad), json_encode($bad));
        $t->same([400, 'Invalid or expired code', null, null], [$e->status, $e->getMessage(), $e->field, $e->errors], 'one refusal for every shape: ' . json_encode($bad));
    }
});

$t->test('log gateway: writes the code and a masked number under the given folder, creates the folder', function () use ($t): void {
    $dir = Helpers::tempDir('pf_sms_');
    try {
        $path = $dir . '/storage/sms.log';
        $gw = new LogSmsGateway($path, new FixedClock(1798000000.0));
        $gw->sendOtp('+919876543210', '012345');
        $gw->sendOtp('+919876543211', '654321');
        $text = (string) file_get_contents($path);
        $t->contains('2026-', $text);
        $t->contains('to=+91******3210 otp=012345', $text);
        $t->contains('to=+91******3211 otp=654321', $text);
        $t->ok(!str_contains($text, '9876543210'), 'the full number is not written');
        $t->same(2, substr_count($text, "\n"));
        $e = $t->throws(SmsException::class, fn () => (new LogSmsGateway($dir . '/storage/sms.log/inside/x.log'))->sendOtp('+919876543210', '111111'));
        $t->ok(!str_contains($e->getMessage(), '111111') && !str_contains($e->getMessage(), '9876543210'));
    } finally {
        Helpers::rmTree($dir);
    }
});

$fake = new FakeSms();
$fake->start();
register_shutdown_function(static fn () => $fake->stop());
$http = static fn (array $o = []): HttpSmsGateway => new HttpSmsGateway(
    $o['url'] ?? $fake->url(), $o['user'] ?? 'shop-user', $o['key'] ?? FakeSms::KEY, $o['sender'] ?? 'PRATYA', $o['template'] ?? '1707000000000000001',
    $o['text'] ?? 'Your Pratyangi Fashions verification code is {#var#}. It is valid for 5 minutes.', 3, null,
);

$t->test('http gateway: one GET to the configured address with username, apikey, senderid, mobile (91 + 10 digits), message with the code, templateid', function () use ($t, $fake, $http): void {
    $http()->sendOtp('+919876543210', '012345');
    $reqs = $fake->requests();
    $t->same(1, count($reqs));
    $t->same(['GET', '/v3/api.php'], [$reqs[0]['method'], $reqs[0]['path']]);
    $t->same([
        'username' => 'shop-user', 'apikey' => FakeSms::KEY, 'senderid' => 'PRATYA', 'mobile' => '919876543210',
        'message' => 'Your Pratyangi Fashions verification code is 012345. It is valid for 5 minutes.', 'templateid' => '1707000000000000001',
    ], $reqs[0]['query']);
    $t->ok(!str_contains($reqs[0]['raw'], '{#var#}') && !str_contains($reqs[0]['raw'], '%7B%23var%23%7D'), 'the placeholder is replaced');
});

$t->test('http gateway: every failure is an SmsException that holds no code, number, key or address', function () use ($t, $fake, $http): void {
    $secrets = ['654321', '9876543210', FakeSms::KEY, '127.0.0.1', 'shop-user'];
    $check = function (string $note, \Closure $send) use ($t, $secrets): void {
        $e = $t->throws(SmsException::class, $send, $note);
        foreach ($secrets as $secret) {
            $t->ok(!str_contains($e->getMessage(), $secret), $note . ': message holds ' . $secret);
        }
    };
    $check('wrong key (200 with Error)', fn () => $http(['key' => 'not-the-key'])->sendOtp('+919876543210', '654321'));
    foreach (['fail' => 'HTTP 500', 'error' => 'refused', 'empty' => 'empty', 'redirect' => 'redirect (not followed)'] as $flag => $note) {
        $fake->flag($flag);
        $check($note, fn () => $http()->sendOtp('+919876543210', '654321'));
        $fake->flag($flag, false);
    }
    $check('nothing listening', fn () => $http(['url' => 'http://127.0.0.1:1/v3/api.php'])->sendOtp('+919876543210', '654321'));
    $before = count($fake->requests());
    $http()->sendOtp('+919876543210', '111111'); // and it works again afterwards
    $t->same($before + 1, count($fake->requests()));
    $t->same('Invalid API key', 'Invalid API key'); // (the fake's refusal text is never repeated by the gateway: asserted above)
});

$t->test('http gateway: the answer parser (Reports = sent, Error = refused, empty = failure)', function () use ($t): void {
    HttpSmsGateway::assertAccepted('{"Reports":[{"status":"queued"}]}');
    HttpSmsGateway::assertAccepted("{'reports': [{'status': 'ok'}]}");
    HttpSmsGateway::assertAccepted('success');
    HttpSmsGateway::assertAccepted('Message sent');
    $t->ok(true);
    foreach (['', '   ', '{"Error":"Invalid API key"}', "{'Error':'Template not matched'}", '{"error":"x"}', 'invalid template', 'Request rejected', 'Authentication failed', 'Insufficient balance'] as $bad) {
        $t->throws(SmsException::class, fn () => HttpSmsGateway::assertAccepted($bad), json_encode($bad));
    }
});

$t->test('rate limits: per number 5 a day with its own message, per address 10 an hour, windows are not purged while they count', function () use ($t, $pepper): void {
    $clock = new FixedClock(1798000000.0);
    $store = new MemoryRateLimitStore();
    $limiter = new RateLimiter($store, $clock, null);
    $ctx = static fn (): Context => new Context(Helpers::request('POST', '/x'), 'rid', 0, static fn (): ?array => null);
    $mobile = Otp::fingerprint($pepper, 'mobile', '+919876543210');
    for ($i = 1; $i <= 5; $i++) {
        $c = $ctx();
        $limiter->consumeSubject('otp_mobile', $mobile, $c);
        $t->same((string) (5 - $i), $c->headers['RateLimit-Remaining'], 'request ' . $i);
        $t->same('5;w=86400', $c->headers['RateLimit-Policy']);
    }
    $c = $ctx();
    $e = $t->throws(ApiException::class, fn () => $limiter->consumeSubject('otp_mobile', $mobile, $c));
    $t->same([429, 'Too many codes have been requested for this number today. Please try again tomorrow.'], [$e->status, $e->getMessage()]);
    $t->ok(isset($c->headers['Retry-After']));
    // another number is counted on its own; the same number is counted again in the next day
    $limiter->consumeSubject('otp_mobile', Otp::fingerprint($pepper, 'mobile', '+919876543211'), $ctx());
    $clock->advance(86400);
    $limiter->consumeSubject('otp_mobile', $mobile, $ctx());
    $t->ok(true, 'a new day starts a new count');
    // per address
    $clock2 = new FixedClock(1798000000.0);
    $byIp = new RateLimiter(new MemoryRateLimitStore(), $clock2, null);
    for ($i = 1; $i <= 10; $i++) {
        $byIp->consume('otp_ip', '203.0.113.9', $ctx());
    }
    $e = $t->throws(ApiException::class, fn () => $byIp->consume('otp_ip', '203.0.113.9', $ctx()));
    $t->same([429, RateLimiter::MESSAGE], [$e->status, $e->getMessage()]);
    $byIp->consume('otp_ip', '203.0.113.10', $ctx());
    $clock2->advance(3600);
    $byIp->consume('otp_ip', '203.0.113.9', $ctx());
    $t->ok(true, 'the hour window rolls over');
    // the number is stored only as a hash
    $stored = new class implements \Pratyangi\Http\RateLimitStore {
        /** @var list<string> */
        public array $keys = [];
        public ?int $purgedBefore = null;
        public function hit(string $key, int $windowSeconds, int $now): int
        {
            $this->keys[] = $key;
            return 1;
        }
        public function purge(int $olderThan): void
        {
            $this->purgedBefore = $olderThan;
        }
    };
    $limiter3 = new RateLimiter($stored, new FixedClock(1798000000.0), null);
    for ($i = 0; $i < 400 && $stored->purgedBefore === null; $i++) {
        $limiter3->consumeSubject('otp_mobile', $mobile, $ctx());
    }
    $t->ok(!str_contains(implode(',', $stored->keys), '9876543210'));
    $t->same(1798000000 - 2 * 86400, $stored->purgedBefore, 'rows are kept for two daily windows');
});

$t->test('routes before the database: origin check, field errors, 503 without a gateway, one refusal on verify, the per-address count', function () use ($t): void {
    $origin = ['Origin' => Helpers::ORIGIN];
    // (the controller builds the account service, which wants a PDO object; FakeAuthPdo has no connection and none of these answers uses it)
    $noDb = static fn (): \PDO => new FakeAuthPdo();
    $app = Helpers::app(null, null, null, null, null, $noDb, ['otp_ip' => 4, 'otp_verify' => 3]);
    // CSRF: an Origin is needed, like every other POST
    foreach (['/api/v1/auth/otp/request', '/api/v1/auth/otp/verify'] as $path) {
        $r = $app->handle(Helpers::request('POST', $path, [], ['mobile' => '9876543210']));
        $t->same(403, $r->status(), $path);
        $r = $app->handle(Helpers::request('POST', $path, ['Origin' => 'https://evil.example'], ['mobile' => '9876543210']));
        $t->same(403, $r->status(), $path . ' foreign origin');
    }
    // CORS preflight works for the new routes
    $r = $app->handle(Helpers::request('OPTIONS', '/api/v1/auth/otp/request', ['Origin' => Helpers::ORIGIN, 'Access-Control-Request-Method' => 'POST']));
    $t->same(204, $r->status());
    $t->same(Helpers::ORIGIN, $r->header('Access-Control-Allow-Origin'));
    // field errors use the shared error format
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/request', $origin, ['mobile' => '12345']));
    $t->same(422, $r->status());
    $t->same(['message' => 'Enter a valid 10-digit mobile number.', 'field' => 'mobile', 'errors' => ['mobile' => 'Enter a valid 10-digit mobile number.']], json_decode($r->body(), true));
    // a valid number but no gateway / no pepper: 503, before any database work
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/request', $origin, ['mobile' => '9876543210']));
    $t->same(503, $r->status());
    $t->contains('Mobile sign-in is not set up yet', json_decode($r->body(), true)['message']);
    // verify: every malformed shape gets the one refusal, and no database is touched
    $bodies = [['mobile' => 'nope', 'code' => '123456'], ['mobile' => '9876543210', 'code' => 'abc'], [], ['x' => 1]];
    $seen = [];
    $last = null;
    foreach ($bodies as $i => $body) {
        $last = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/verify', $origin, $body));
        $seen[] = [$last->status(), $last->body()];
        if ($i === 2) {
            break; // three verify calls are allowed in this test's limit
        }
    }
    $t->same([[400, '{"message":"Invalid or expired code"}'], [400, '{"message":"Invalid or expired code"}'], [400, '{"message":"Invalid or expired code"}']], $seen);
    $r = $app->handle(Helpers::request('POST', '/api/v1/auth/otp/verify', $origin, $bodies[3]));
    $t->same(429, $r->status(), 'the fourth verify from one address is refused');
    $t->ok($r->header('Retry-After') !== null);
    // the request route: the 5th call in the hour is refused (limit lowered to 4 for this test); failures count too
    $app2 = Helpers::app(null, null, null, null, null, $noDb, ['otp_ip' => 4]);
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = $app2->handle(Helpers::request('POST', '/api/v1/auth/otp/request', $origin, ['mobile' => 'bad']))->status();
    }
    $t->same([422, 422, 422, 422, 429], $codes);
    // a GET is not a route
    $t->same(404, Helpers::app()->handle(Helpers::request('GET', '/api/v1/auth/otp/request'))->status());
});

$t->test('the answer of the request route does not depend on an account: no lookup exists in the request path', function () use ($t): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Auth/OtpService.php');
    $request = substr($source, (int) strpos($source, 'public function request('), (int) strpos($source, 'public function verify(') - (int) strpos($source, 'public function request('));
    // login-split: only a 'login' request may look the number up (to know whether a code can be used); signup refuses a known number with 409 and sends nothing (db_otp_test)
    $t->ok($request !== '' && substr_count($request, 'findByMobile') === 1 && !str_contains($request, 'findById'), 'request() looks up the mobile exactly once and nothing else');
    $t->ok(str_contains($request, '? $known : !$known') && str_contains($request, 'ApiException::conflict'), 'login needs a known number, signup refuses a known one with 409');
    $t->ok(!str_contains($request, 'logger?->info') && !str_contains($request, '$code]'), 'the code is never given to the logger');
    foreach (['OtpService.php', 'OtpRepo.php', 'Otp.php'] as $file) {
        $text = (string) file_get_contents(dirname(__DIR__) . '/app/Auth/' . $file);
        $t->ok(preg_match('/logger\?->\w+\([^;]*\$(code|mobile)\b[^;]*\)/', preg_replace('/Mobile::mask\(\$mobile\)/', '', $text) ?? '') !== 1, $file . ': no logger call with a code or a raw number');
    }
});

$t->test('masked email hint: first letter, five stars, last two before the @, then the domain', function () use ($t): void {
    $t->same('j*****an@gmail.com',\Pratyangi\Auth\Mobile::maskEmail('jonathan@gmail.com'));
    $t->same('a*****@x.in', \Pratyangi\Auth\Mobile::maskEmail('ab@x.in'), 'a short name shows only its first letter');
    $t->same('a*****bc@x.in', \Pratyangi\Auth\Mobile::maskEmail('a.abc@x.in'));
});

exit($t->finish());

<?php
declare(strict_types=1);

// No database: the pure rules of the account flow (docs/API_CONTRACT.md 11.3) - purpose separation of the code hash, the new validators,
// the redirect rule on the pages, and the shape of the pages and scripts that must not ask for a name or email.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Auth\Otp;
use Pratyangi\Auth\Validator;
use Pratyangi\Http\ApiException;
use Pratyangi\Web\SafeNext;

$t = new TestCase('flow_logic_test');
$pepper = str_repeat('P', 48);

$t->test('purpose and channel separation: the stored hash covers channel, purpose and target', function () use ($t, $pepper): void {
    $base = Otp::hashFor($pepper, 'email', 'email_verify', 'a@b.co', '123456');
    $t->same(1, preg_match('/^[0-9a-f]{64}$/D', $base));
    $t->same(hash_hmac('sha256', 'email|email_verify|a@b.co|123456', $pepper), $base, 'HMAC-SHA256 keyed with the pepper');
    $others = [
        Otp::hashFor($pepper, 'email', 'reset', 'a@b.co', '123456'),
        Otp::hashFor($pepper, 'mobile', 'email_verify', 'a@b.co', '123456'),
        Otp::hashFor($pepper, 'email', 'email_verify', 'c@d.co', '123456'),
        Otp::hashFor($pepper, 'email', 'email_verify', 'a@b.co', '123457'),
        Otp::hashFor(str_repeat('Q', 48), 'email', 'email_verify', 'a@b.co', '123456'),
    ];
    foreach ($others as $other) {
        $t->ok($other !== $base, 'any change gives another hash');
    }
    foreach (['login', 'mobile_verify', 'email_verify', 'reset'] as $purpose) {
        foreach (['login', 'mobile_verify', 'email_verify', 'reset'] as $used) {
            $t->same($purpose === $used, Otp::matchesFor($pepper, 'mobile', $used, '+919876543210', '000000', Otp::hashFor($pepper, 'mobile', $purpose, '+919876543210', '000000')), "made for $purpose, used for $used");
        }
    }
    $t->same(false, Otp::matchesFor($pepper, 'email', 'reset', '+919876543210', '000000', Otp::hashFor($pepper, 'mobile', 'reset', '+919876543210', '000000')), 'channel');
});

$t->test('validators of the new routes', function () use ($t): void {
    $fails = static function (callable $f): ApiException {
        try {
            $f();
        } catch (ApiException $e) {
            return $e;
        }
        throw new RuntimeException('expected a refusal');
    };
    $t->same(['code' => '012345'], Validator::codeOnly(['code' => ' 012345 ']));
    foreach ([[], ['code' => ''], ['code' => '12345'], ['code' => 123456], ['code' => 'abcdef'], null] as $bad) {
        $t->same(422, $fails(fn () => Validator::codeOnly($bad))->status);
    }
    $t->same(['mobile' => '+919876543210', 'code' => '123456'], Validator::mobileCode(['mobile' => '98765 43210', 'code' => '123456']));
    $t->same(['email' => 'a@b.co', 'code' => '123456'], Validator::emailCode(['email' => ' A@B.co ', 'code' => '123456']));
    $t->same(['email' => 'a@b.co'], Validator::emailOnly(['email' => 'A@b.CO']));
    $t->same(['name' => 'Asha Rao'], Validator::profileName(['name' => ' Asha Rao ']));
    $t->same(422, $fails(fn () => Validator::profileName(['name' => 'A']))->status);
    $t->same(['channel' => 'mobile', 'target' => '+919876543210', 'code' => '123456', 'password' => 'NewSaree2027'], Validator::reset(['channel' => 'mobile', 'mobile' => '9876543210', 'code' => '123456', 'password' => 'NewSaree2027']));
    $t->same(['channel' => 'email', 'target' => 'a@b.co', 'code' => '123456', 'password' => 'NewSaree2027'], Validator::reset(['email' => 'a@b.co', 'code' => '123456', 'password' => 'NewSaree2027']));
    $t->same(['mobile', 'code', 'password'], array_keys($fails(fn () => Validator::reset(['channel' => 'mobile']))->errors ?? []));
    // an email in a mobile reset body is not looked at (and the reverse)
    $t->same('mobile', $fails(fn () => Validator::forgot(['channel' => 'mobile', 'email' => 'a@b.co']))->field);
});

$t->test('redirect rule: every page prints "/" unless ?next=/checkout', function () use ($t): void {
    $app = Helpers::app();
    $safe = ['/checkout'];
    $unsafe = ['/account', '/', '/new-arrivals?x=1', '//evil.example', 'https://evil.example', '/\\evil', 'javascript:alert(1)', '/a b', 'checkout', '/x://y'];
    foreach (['/login', '/signup', '/forgot-password'] as $page) {
        $plain = $app->handle(Helpers::request('GET', $page))->body();
        $t->contains('data-pf-next="/"', $plain, $page . ' without next');
        foreach ($safe as $next) {
            $b = $app->handle(Helpers::request('GET', $page . '?next=' . rawurlencode($next)))->body();
            $t->contains('data-pf-next="' . htmlspecialchars($next, ENT_QUOTES) . '"', $b, $page . ' next=' . $next);
        }
        foreach ($unsafe as $next) {
            $b = $app->handle(Helpers::request('GET', $page . '?next=' . rawurlencode($next)))->body();
            $t->contains('data-pf-next="/"', $b, $page . ' next=' . $next . ' falls back to the landing page');
            $t->ok(!str_contains($b, 'evil.example'), 'nothing hostile is printed');
        }
    }
    foreach ($safe as $next) {
        $t->same($next, SafeNext::path($next));
    }
    foreach ($unsafe as $next) {
        $t->same('/', SafeNext::path($next));
    }
});

$t->test('the mobile form asks for no name and no email; sign-up by email keeps its form; the scripts follow the same rule', function () use ($t): void {
    $app = Helpers::app();
    foreach (['/login', '/signup'] as $page) {
        $b = $app->handle(Helpers::request('GET', $page))->body();
        $form = substr($b, (int) strpos($b, 'data-pf="otp-form"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));
        $t->ok(!str_contains($form, 'otp-name') && !str_contains($form, 'otp-email') && !str_contains($form, 'Full name'), $page . ': no name or email in the mobile form');
        $t->contains('id="otp-mobile"', $form);
        $t->contains('id="otp-code"', $form);
    }
    $signup = $app->handle(Helpers::request('GET', '/signup'))->body();
    $t->contains('id="auth-name"', $signup);
    $t->contains('id="auth-email"', $signup);
    $t->contains('<template data-pf-tpl="verify-code">', $signup, 'the email code step');
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/auth-otp.js');
    $t->ok(!str_contains($js, 'nameRequired') && !str_contains($js, 'otp-name'), 'auth-otp.js: no name step');
    $api = (string) file_get_contents(dirname(__DIR__) . '/app/Auth/AuthController.php') . (string) file_get_contents(dirname(__DIR__) . '/app/Auth/OtpService.php');
    $t->ok(!str_contains($api, 'NAME_REQUIRED') && !str_contains($api, 'nameRequired'), 'the API has no name_required step');
    $forgot = $app->handle(Helpers::request('GET', '/forgot-password'))->body();
    $t->contains('Email code</button>', $forgot);
    $t->contains('Mobile code</button>', $forgot);
    $t->contains('Sign in with OTP</a>', $forgot, 'mobile-only accounts without a password are pointed to the OTP sign-in');
    $login = $app->handle(Helpers::request('GET', '/login'))->body();
    $t->contains('Create an account</a>', $login);
});

exit($t->finish());

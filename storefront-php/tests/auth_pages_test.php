<?php
declare(strict_types=1);

// No database, no network. Run: php tests/auth_pages_test.php
// The sign-in pages (/login, /signup, /forgot-password, /verify-email, /account), the header state, StaticFiles and the mail link paths.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Http\Request;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Mail\Mailer;
use Pratyangi\Support\Logger;
use Pratyangi\Web\AuthPages;
use Pratyangi\Web\StaticFiles;

$t = new TestCase('auth_pages_test');
$root = dirname(__DIR__);

/** AuthPages with a fake session lookup; $calls counts how often the lookup ran. */
$pages = static function (?Closure $lookup): AuthPages {
    return new AuthPages($lookup, new Logger('silent'));
};
$signedIn = static fn (string $token): ?array => ['name' => 'asha Rao', 'email' => 'asha@example.com'];
$get = static fn (string $uri, array $headers = []): Request => Helpers::request('GET', $uri, $headers);

/** @return array<string,array{0:string,1:string,2:list<string>}> path => [data-pf-auth, title, ids that must exist] */
$routes = [
    '/login' => ['login', 'Sign In | Pratyangi Fashions', ['auth-identifier', 'auth-password']],
    '/signup' => ['signup', 'Create Account | Pratyangi Fashions', ['auth-name', 'auth-email', 'auth-password', 'auth-confirm', 'auth-terms']],
    '/forgot-password' => ['forgot', 'Reset Password | Pratyangi Fashions', ['auth-email']],
    '/verify-email' => ['verify', 'Verify Email | Pratyangi Fashions', []],
];

$t->test('each page: 200, html, Node markup root, form ids, exactly two deferred external scripts, nothing inline', function () use ($t, $routes): void {
    $app = Helpers::app();
    foreach ($routes as $path => [$key, $title, $ids]) {
        $r = $app->handle(Helpers::request('GET', $path));
        $t->same(200, $r->status(), $path);
        $t->same('text/html; charset=utf-8', $r->header('Content-Type'), $path);
        $b = $r->body();
        $t->contains('<title>' . $title . '</title>', $b, $path);
        $t->contains('<main class="neurva_page" data-pf-auth="' . $key . '" data-pf-next="/">', $b, $path);
        $t->contains('<section class="neurva_stage">', $b, $path);
        $t->contains('<aside class="neurva_brand"', $b, $path);
        $t->contains('<div class="neurva_card" data-pf="auth-card">', $b, $path);
        $t->contains('class="pSCCwa_header"', $b, $path);
        $t->contains('class="f_viWW_footer"', $b, $path);
        foreach ($ids as $id) {
            $t->contains('id="' . $id . '"', $b, $path . ' ' . $id);
        }
        // the mobile code form (auth-otp.js) is on the sign-in and sign-up pages only
        $otp = in_array($key, ['login', 'signup'], true);
        $t->same(($otp ? 3 : 2) + ($key === 'signup' ? 1 : 0) + 1, substr_count($b, '<script'), $path . ': script tags');
        $t->contains('<script defer src="/assets/site.js"></script>' . ($key === 'signup' ? '<script defer src="/assets/offers.js"></script>' : '') . '<script defer src="/assets/auth.js"></script>' . ($otp ? '<script defer src="/assets/auth-otp.js"></script>' : '') . '<script defer src="/assets/cookies.js"></script>', $b, $path);
        $t->same(0, preg_match('/<script(?![^>]*\ssrc=)/i', $b), $path . ': no inline script');
        $t->same(0, preg_match('/\son[a-z]+="/i', $b), $path . ': no inline event handlers');
        $t->same(0, preg_match('#(src|href)="(https?:)?//#', $b), $path . ': no external resources');
        $t->ok(!str_contains($b, '/_next/'), $path . ': no Next.js urls');
        $t->contains('/assets/css/1_e1s2q5g68a2.css', $b, $path);
        $t->contains('/assets/css/3wh3hbs4o_ca8.css', $b, $path);
        $t->ok(!str_contains($b, '31pkxlgg4o4yb.css'), $path . ': home-only sheet not loaded (the web loads two)');
    }
});

$t->test('login markup: classes, labels, links, password reveal, no Google', function () use ($t): void {
    $b = Helpers::app()->handle(Helpers::request('GET', '/login'))->body();
    foreach (['<h1 class="neurva_title">Welcome back</h1>', 'Sign in to track your orders and save addresses.',
        'data-pf-mode="email"', 'data-pf-mode="otp">Mobile OTP</button>',
        '<span class="neurva_prefix" aria-hidden="true">+91</span>', 'data-pf="otp-form"', 'data-pf-signup="0"', 'Send OTP', '<form class="neurva_form" novalidate',
        'Email or mobile number', 'placeholder="you@example.com"', 'autocomplete="username"', 'Your password', 'autocomplete="current-password"',
        '<a class="neurva_inlineLink" href="/forgot-password">Forgot password?</a>', 'aria-label="Show password"', 'class="neurva_reveal"',
        '<button type="submit" class="neurva_submit">Sign In</button>', 'New to Pratyangi Fashions? <a href="/signup">Create an account</a>',
        '<template data-pf-tpl="welcome">', '<template data-pf-tpl="icon-mobile">', 'Where Tradition Meets Elegance'] as $needle) {
        $t->contains($needle, $b);
    }
    $t->ok(!str_contains($b, 'next=/checkout'));
    $t->ok(!str_contains($b, 'Please sign in to continue to checkout'));
    $t->ok(stripos($b, 'google') === false && !str_contains($b, 'neurva_divider'), 'no Google button, text or divider');
});

$t->test('signup markup', function () use ($t): void {
    $b = Helpers::app()->handle(Helpers::request('GET', '/signup'))->body();
    foreach (['<h1 class="neurva_title">Create your account</h1>', 'Create an account to track orders and save addresses.',
        'data-pf-mode="otp">Mobile OTP</button>', 'data-pf-signup="1"', 'id="otp-terms"', 'id="auth-name"', 'Full name', 'placeholder="Gaurav Kumar"', 'Email address', 'Create a password',
        'Confirm password', 'Re-enter your password', 'class="neurva_strength" data-score="0"', 'Use at least 8 characters with a letter and a number.',
        'At least 8 characters', 'A letter', 'A number', 'class="neurva_check"', 'I agree to the <span class="neurva_strong">Terms &amp; Conditions</span> and <span class="neurva_strong">Privacy Policy</span>.',
        '<button type="submit" class="neurva_submit">Create Account</button>', 'Already have an account? <a href="/login">Sign in</a>'] as $needle) {
        $t->contains($needle, $b);
    }
});

$t->test('forgot-password markup: first step inline, code step and done step as templates', function () use ($t): void {
    $b = Helpers::app()->handle(Helpers::request('GET', '/forgot-password'))->body();
    foreach (['Forgot your password?', 'We will send a code to the verified email or mobile number on your account', 'data-pf-mode="mobile">Mobile code</button>', 'Sign in with OTP</a>',
        '<button type="submit" class="neurva_submit">Send code</button>', '<a href="/login">Back to sign in</a>',
        '<template data-pf-tpl="reset">', 'Set a new password', 'If an account matches, we sent a 6-digit code to', 'class="neurva_otp"',
        'autocomplete="one-time-code"', 'maxlength="6"', 'Resend code', 'New password', 'Create a new password', 'Confirm new password', 'Re-enter your new password',
        'Reset Password</button>', '<template data-pf-tpl="done">', '<h2>Password updated</h2>', 'Your password is changed and you are signed out everywhere. Taking you to the home page…'] as $needle) {
        $t->contains($needle, $b);
    }
    $t->same(1, substr_count($b, '<h1 class="neurva_title">Forgot your password?</h1>'));
});

$t->test('verify-email: "checking" with a token, "open the link" without; outcomes are templates', function () use ($t): void {
    $app = Helpers::app();
    $with = $app->handle(Helpers::request('GET', '/verify-email?token=abc123'));
    $b = $with->body();
    $t->contains('Checking your link…', $b);
    $t->contains('role="status" aria-live="polite"', $b);
    $t->contains('<div class="neurva_card" data-pf="auth-card"><div class="neurva_success" role="status" aria-live="polite">', $b, 'the card starts as "checking"');
    $t->ok(!str_contains($b, 'Open the link from your email'), 'the "missing" state is not on a page with a token');
    $t->same('no-store', $with->header('Cache-Control'), 'the address carries a one-time token');
    $t->contains('<template data-pf-tpl="verified">', $b);
    $t->contains('Email verified', $b);
    $t->contains('<template data-pf-tpl="failed">', $b);
    $t->contains('This link did not work', $b);
    $t->contains('<a class="neurva_submit" href="/login?next=/account">Sign in to get a code</a>', $b);
    $t->contains('<a class="neurva_submit" href="/login">Sign In</a>', $b);
    $t->ok(!str_contains($b, 'data-pf="resend-link"'), 'signed out: no resend button (the old link page no longer sends anything)');

    foreach (['/verify-email', '/verify-email?token='] as $uri) {
        $r = $app->handle(Helpers::request('GET', $uri));
        $m = $r->body();
        $t->contains('<h1 class="neurva_title">Open the link from your email</h1>', $m, $uri);
        $t->contains('This page confirms your email address. Use the link in the message we emailed you.', $m, $uri);
        $t->ok(!str_contains($m, '<div class="neurva_card" data-pf="auth-card"><div class="neurva_success" role="status" aria-live="polite">'), $uri . ': not checking');
        $t->ok($r->header('Cache-Control') !== 'no-store', $uri);
    }
});

$t->test('verify-email signed in: account link and resend button', function () use ($t, $pages, $signedIn, $get): void {
    $r = $pages($signedIn)->verify($get('/verify-email?token=zz', ['Cookie' => 'pf_session=tok']));
    $b = $r->body();
    $t->contains('<a class="neurva_submit" href="/">Continue shopping</a>', $b); // the landing page, never /account by default
    $t->contains('<a class="neurva_submit" href="/account">Verify with a code on your account page</a>', $b);
    $t->ok(!str_contains($b, 'Sign in to get a code'));
    $t->same('no-store', $r->header('Cache-Control'));
    $t->contains('class="pSCCwa_accountLink"', $b);
});

$t->test('next=/checkout changes the wording and keeps the query on the links (exactly /checkout only)', function () use ($t): void {
    $app = Helpers::app();
    $b = $app->handle(Helpers::request('GET', '/login?next=/checkout'))->body();
    $t->contains('Please sign in to continue to checkout. Your bag is saved.', $b);
    $t->contains('href="/forgot-password?next=/checkout"', $b);
    $t->contains('href="/signup?next=/checkout"', $b);
    $b = $app->handle(Helpers::request('GET', '/signup?next=/checkout'))->body();
    $t->contains('Create an account to continue to checkout. Your bag is saved.', $b);
    $t->contains('href="/login?next=/checkout"', $b);
    $b = $app->handle(Helpers::request('GET', '/forgot-password?next=/checkout'))->body();
    $t->same(3, substr_count($b, 'href="/login?next=/checkout"'), 'Back to sign in + the mobile note + the done step');
    // first value wins, like URLSearchParams.get
    $t->contains('Please sign in to continue to checkout', $app->handle(Helpers::request('GET', '/login?next=/checkout&next=/x'))->body());
    foreach (['/login?next=/checkout2', '/login?next=/Checkout', '/login?next=//evil.example', '/login?next[]=/checkout', '/login?next='] as $uri) {
        $b = $app->handle(Helpers::request('GET', $uri))->body();
        $t->ok(!str_contains($b, 'continue to checkout'), $uri);
        $t->ok(!str_contains($b, 'next=/checkout'), $uri);
    }
});

$t->test('hostile query values are never printed', function () use ($t, $routes): void {
    $app = Helpers::app();
    $evil = rawurlencode('"><script>alert(1)</script>');
    foreach (array_keys($routes) as $path) {
        $r = $app->handle(Helpers::request('GET', $path . '?next=' . $evil . '&token=' . $evil . '&email=' . $evil . '&x=' . $evil));
        $t->same(200, $r->status(), $path);
        $b = $r->body();
        $t->ok(!str_contains($b, 'alert(1)'), $path . ': value not reflected');
        $t->ok(!str_contains($b, '"><script'), $path);
        $t->same((in_array($path, ['/login', '/signup'], true) ? 4 : 3) + ($path === '/signup' ? 1 : 0), substr_count($b, '<script'), $path . ': still only the page scripts');
        $t->contains('data-pf-next="/"', $b, $path . ': a hostile next falls back to the landing page');
    }
    // a hostile token still shows the "checking" card (auth.js asks the API; the server never prints it)
    $b = $app->handle(Helpers::request('GET', '/verify-email?token=' . $evil))->body();
    $t->contains('Checking your link…', $b);
});

$t->test('routes: case and trailing slash like the router, HEAD works, other methods are 404', function () use ($t): void {
    $app = Helpers::app();
    $t->same(200, $app->handle(Helpers::request('GET', '/Login/'))->status());
    $t->same(200, $app->handle(Helpers::request('HEAD', '/signup'))->status());
    foreach (['/login', '/signup', '/forgot-password', '/verify-email', '/account'] as $path) {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $r = $app->handle(Helpers::request($method, $path, [], []));
            $t->same(404, $r->status(), $method . ' ' . $path);
        }
    }
    $t->same(404, $app->handle(Helpers::request('GET', '/reset-password'))->status(), 'the web has no /reset-password page (reset is a code on /forgot-password)');
    $t->same(404, $app->handle(Helpers::request('GET', '/login/extra'))->status());
});

$t->test('header: signed out links to /login and has no account dropdown', function () use ($t): void {
    $b = Helpers::app()->handle(Helpers::request('GET', '/login'))->body();
    $t->contains('<a class="pSCCwa_iconButton" aria-label="Sign in" href="/login">', $b);
    $t->ok(!str_contains($b, 'pSCCwa_accountLink'));
    $t->ok(!str_contains($b, 'data-pf="logout"'));
    $home = Helpers::app()->handle(Helpers::request('GET', '/'))->body();
    $t->contains('<a class="pSCCwa_iconButton" aria-label="Sign in" href="/login">', $home);
});

$t->test('header: signed in shows the avatar, first name, link to /account, per-visitor cache headers', function () use ($t, $pages, $signedIn, $get): void {
    $r = $pages($signedIn)->login($get('/login', ['Cookie' => 'pf_session=tok']));
    $b = $r->body();
    $t->contains('<span class="pSCCwa_avatar" aria-hidden="true">A</span>', $b);
    $t->contains('<span class="pSCCwa_accountHello">Hello,</span><span class="pSCCwa_accountName">asha</span>', $b);
    $t->contains('<a class="pSCCwa_accountLink" href="/account"', $b);
    $t->contains('aria-label="My account, signed in as asha Rao"', $b);
    $t->ok(!str_contains($b, 'aria-label="Sign in"'));
    $t->same('private, no-store', $r->header('Cache-Control'));
    $t->contains('Cookie', (string) $r->header('Vary'));
    // the page is still the sign-in page for a signed-in visitor (the web does not redirect)
    $t->contains('<h1 class="neurva_title">Welcome back</h1>', $b);
});

$t->test('header: a hostile first name is escaped on every sign-in page', function () use ($t, $pages, $get): void {
    $lookup = static fn (string $token): ?array => ['name' => '<script>"x"&', 'email' => 'e"<@x.com'];
    $p = $pages($lookup);
    foreach (['login', 'signup', 'forgot', 'verify'] as $method) {
        $b = $p->$method($get('/x', ['Cookie' => 'pf_session=tok']))->body();
        $t->contains('&lt;script&gt;&quot;x&quot;&amp;', $b, $method);
        $t->contains('<span class="pSCCwa_avatar" aria-hidden="true">&lt;</span>', $b, $method);
        $t->contains('title="e&quot;&lt;@x.com"', $b, $method);
        $t->same((in_array($method, ['login', 'signup'], true) ? 4 : 3) + ($method === 'signup' ? 1 : 0), substr_count($b, '<script'), $method . ': only the page scripts');
    }
});

$t->test('session lookup: not called without a cookie, failure gives the signed-out page', function () use ($t, $pages, $get): void {
    $calls = 0;
    $counting = static function (string $token) use (&$calls): ?array {
        $calls++;
        return null;
    };
    $pages($counting)->login($get('/login'));
    $pages($counting)->verify($get('/verify-email?token=a'));
    $t->same(0, $calls, 'no cookie, no lookup');
    $pages($counting)->login($get('/login', ['Cookie' => 'pf_session=tok']));
    $t->same(1, $calls);
    $pages($counting)->verify($get('/verify-email?token=a', ['Cookie' => 'pf_session=tok']));
    $t->same(2, $calls, 'verify looks the session up once');
    $boom = static function (string $token): ?array {
        throw new RuntimeException('database down: secret-detail');
    };
    $r = $pages($boom)->login($get('/login', ['Cookie' => 'pf_session=tok']));
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
    $t->ok(!str_contains($r->body(), 'secret-detail'));
    // through the whole app (its lookup needs a database that is not there): still the signed-out page
    $r = Helpers::app()->handle(Helpers::request('GET', '/signup', ['Cookie' => 'pf_session=tok']));
    $t->same(200, $r->status());
    $t->contains('aria-label="Sign in"', $r->body());
});

$t->test('/account and /account/orders: the coming page; Logout only when signed in', function () use ($t, $pages, $signedIn, $get): void {
    $app = Helpers::app();
    // /account is a real page now (tests/shop_pages_test.php): signed out it goes to the sign-in page. Only /account/orders is still the coming page.
    $r = $app->handle(Helpers::request('GET', '/account'));
    $t->same([302, '/login?next=/account'], [$r->status(), $r->header('Location')], '/account signed out');
    $r = $app->handle(Helpers::request('GET', '/account/orders'));
    $t->same([302, '/login?next=/account/orders'], [$r->status(), $r->header('Location')], '/account/orders signed out');
    $b = $pages($signedIn)->accountSoon($get('/account', ['Cookie' => 'pf_session=tok']))->body();
    $t->contains('<button type="button" class="pnvDaq_logout" data-pf="logout">', $b);
    $t->contains('Logout</button>', $b);
    $t->same(2, substr_count($b, '<script'));
    $t->contains('<script defer src="/assets/site.js"></script>', $b);
});

$t->test('StaticFiles: auth.js and site.js are JavaScript, any other .js is refused', function () use ($t, $root): void {
    $app = Helpers::app();
    foreach (['auth.js', 'site.js'] as $name) {
        $r = $app->handle(Helpers::request('GET', '/assets/' . $name));
        $t->same(200, $r->status(), $name);
        $t->same('text/javascript; charset=utf-8', $r->header('Content-Type'), $name);
        $t->same(file_get_contents($root . '/public/assets/' . $name), $r->body(), $name);
        $t->contains("script-src 'self'", (string) $r->header('Content-Security-Policy'));
    }
    $t->same(404, $app->handle(Helpers::request('GET', '/assets/other.js'))->status());
    $t->same(404, $app->handle(Helpers::request('GET', '/assets/css/auth.js'))->status());

    $dir = Helpers::tempDir('pf_static_');
    try {
        mkdir($dir . '/sub');
        foreach (['auth.js', 'site.js', 'evil.js', 'sub/auth.js', 'sub/site.js', 'Auth.JS.txt', 'x.mjs'] as $file) {
            file_put_contents($dir . '/' . $file, '// test');
        }
        $static = new StaticFiles($dir);
        $t->same('text/javascript; charset=utf-8', $static->serve('/assets/auth.js')?->header('Content-Type'));
        $t->same('text/javascript; charset=utf-8', $static->serve('/assets/site.js')?->header('Content-Type'));
        foreach (['/assets/evil.js', '/assets/sub/auth.js', '/assets/sub/site.js', '/assets/x.mjs', '/assets/Auth.JS.txt'] as $path) {
            $t->same(null, $static->serve($path), $path);
        }
    } finally {
        Helpers::rmTree($dir);
    }
});

$t->test('mail wording: the codes are plain text, the reset mail and the verify mail differ, neither carries a link', function () use ($t): void {
    $reset = MailTemplates::code('reset', '123456', 5);
    $t->contains('Your code is 123456.', $reset['text']);
    $t->contains('password reset code', $reset['subject']);
    $verify = MailTemplates::code('email_verify', '654321', 5);
    $t->contains('Your code is 654321.', $verify['text']);
    $t->contains('email verification code', $verify['subject']);
    foreach ([$reset, $verify] as $mail) {
        $t->ok(!str_contains($mail['text'], 'http'), 'a code, not a link');
    }
});

exit($t->finish());

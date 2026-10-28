<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;

/**
 * GET /login, /signup, /forgot-password, /verify-email (the pages of the web's app/login, signup, forgot-password, verify-email)
 * and GET /account (not built yet: the "coming" page, with the web's Logout button for a signed-in visitor).
 * The pages are plain HTML; public/assets/auth.js does the work with the same /api/v1 calls as the web.
 * Nothing from the address is printed into a page: only the fixed text "?next=/checkout" is added to links when next is exactly /checkout.
 */
final class AuthPages
{
    private const COOKIE = 'pf_session';
    private const STYLES =['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css']; // the two sheets the web's sign-in pages load

    /**
     * @param (\Closure(string):(array<string,mixed>|null))|null $sessionLookup cookie token => customer row or null; only called when a pf_session cookie is sent
     */
    public function __construct(private ?\Closure $sessionLookup = null, private ?Logger $logger = null, private bool $smsEnabled = true, private ?string $googleClientId = null)
    {
    }

    public function login(Request $request): Response
    {
        $fromCheckout = self::fromCheckout($request);
        $card = View::render('auth/login', ['fromCheckout' => $fromCheckout, 'query' => $fromCheckout ? '?next=/checkout' : '', 'smsEnabled' => $this->smsEnabled, 'googleClientId' => $this->googleClientId]);
        return $this->page($request, 'login', 'Sign In | Pratyangi Fashions',
            'Sign in to your Pratyangi Fashions account to track orders and save addresses.',
            $card, AuthHtml::loginTemplates(), $this->account($request->cookie(self::COOKIE)));
    }

    public function signup(Request $request): Response
    {
        $fromCheckout = self::fromCheckout($request);
        $card = View::render('auth/signup', ['fromCheckout' => $fromCheckout, 'query' => $fromCheckout ? '?next=/checkout' : '', 'smsEnabled' => $this->smsEnabled, 'googleClientId' => $this->googleClientId]);
        return $this->page($request, 'signup', 'Create Account | Pratyangi Fashions',
            'Create your Pratyangi Fashions account for faster checkout, order tracking and AI try-on.',
            $card, AuthHtml::welcomeTemplate() . AuthHtml::emailCodeTemplate(), $this->account($request->cookie(self::COOKIE)));
    }

    public function forgot(Request $request): Response
    {
        $query = self::fromCheckout($request) ? '?next=/checkout' : '';
        return $this->page($request, 'forgot', 'Reset Password | Pratyangi Fashions',
            'Reset your Pratyangi Fashions password with a code sent to your email or mobile number.',
            View::render('auth/forgot', ['query' => $query, 'smsEnabled' => $this->smsEnabled]), View::render('auth/forgot_templates', ['query' => $query]), $this->account($request->cookie(self::COOKIE)));
    }

    public function verify(Request $request): Response
    {
        $token = self::first($request, 'token');
        $hasToken = $token !== null && $token !== '';
        $account = $this->account($request->cookie(self::COOKIE)); // one lookup serves the header and the links
        $signedIn = $account !== null;
        $response = $this->page($request, 'verify', 'Verify Email | Pratyangi Fashions',
            'Confirm your email address for your Pratyangi Fashions account.',
            View::render('auth/verify', ['hasToken' => $hasToken, 'signedIn' => $signedIn]),
            View::render('auth/verify_templates', ['signedIn' => $signedIn]), $account);
        if ($hasToken) {
            $response->setHeader('Cache-Control', 'no-store'); // the address carries a one-time token
        }
        return $response;
    }

    /** GET /account and /account/orders: not built yet. */
    public function accountSoon(Request $request): Response
    {
        $token = $request->cookie(self::COOKIE);
        $response = Response::html(View::render('layout', [
            'title' => 'Coming soon | Pratyangi Fashions',
            'content' => View::render('account_soon', ['signedIn' => $this->account($token) !== null]),
        ]));
        return self::perVisitor($response, $token);
    }

    /** The signed-in visitor, or null. Never throws: a page must render whatever happens to the lookup. */
    private function account(?string $token): ?HeaderAccount
    {
        if ($token === null || $token === '' || $this->sessionLookup === null) {
            return null;
        }
        try {
            $row = ($this->sessionLookup)($token);
            return is_array($row) ? HeaderAccount::fromRow($row) : null;
        } catch (\Throwable $e) {
            $this->logger?->warn('auth pages: session lookup failed', ['exception' => get_class($e)]); // class only, never the cookie
            return null;
        }
    }

    /** @param HeaderAccount|null $account the signed-in visitor for the header (looked up once by the caller) */
    private function page(Request $request, string $page, string $title, string $description, string $card, string $templates, ?HeaderAccount $account): Response
    {
        $token = $request->cookie(self::COOKIE);
        $content = View::render('auth/shell', [
            'page' => $page,
            'card' => $card,
            'templates' => $templates,
            'account' => $account,
            'next' => SafeNext::path(self::first($request, 'next')), // where auth.js goes after a sign-in
            'smsEnabled' => $this->smsEnabled,
        ]);
        // the mobile one-time-code form is part of the sign-in and sign-up pages only, and only while SMS_ENABLED=true
        $scripts = in_array($page, ['login', 'signup'], true) && $this->smsEnabled ? ['site.js', 'auth.js', 'auth-otp.js'] : ['site.js', 'auth.js'];
        $response = Response::html(View::render('layout', [
            'title' => $title,
            'description' => $description,
            'content' => $content,
            'styles' => self::STYLES,
            'scripts' => $scripts,
        ]));
        return self::perVisitor($response, $token);
    }

    /** The header differs per visitor when a session cookie is sent. */
    private static function perVisitor(Response $response, ?string $token): Response
    {
        if ($token !== null && $token !== '') {
            $response->setHeader('Cache-Control', 'private, no-store')->appendHeader('Vary', 'Cookie');
        }
        return $response;
    }

    /** The first value of a query parameter, like URLSearchParams.get (null when absent). */
    private static function first(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        return is_string($value) ? $value : null;
    }

    /** useAuthFlow: the pages talk about checkout only when ?next is exactly /checkout. */
    private static function fromCheckout(Request $request): bool
    {
        return self::first($request, 'next') === '/checkout';
    }
}

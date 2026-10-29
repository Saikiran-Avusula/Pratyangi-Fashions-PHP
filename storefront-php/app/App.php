<?php
declare(strict_types=1);

namespace Pratyangi;

use Pratyangi\Address\AddressController;
use Pratyangi\Address\AddressRepo;
use Pratyangi\Auth\AccountService;
use Pratyangi\Auth\AuthController;
use Pratyangi\Auth\ConsentController;
use Pratyangi\Auth\ConsentRepo;
use Pratyangi\Auth\NumberController;
use Pratyangi\Auth\NumberVerification;
use Pratyangi\Auth\GoogleVerifier;
use Pratyangi\Auth\AuthService;
use Pratyangi\Auth\CustomerRepo;
use Pratyangi\Auth\OtpRepo;
use Pratyangi\Auth\OtpService;
use Pratyangi\Auth\SessionRepo;
use Pratyangi\Auth\TokenRepo;
use Pratyangi\Catalog\CatalogCache;
use Pratyangi\Catalog\CatalogController;
use Pratyangi\Catalog\CatalogService;
use Pratyangi\Catalog\ProductImage;
use Pratyangi\Erp\ErpClient;
use Pratyangi\Erp\ErpPort;
use Pratyangi\Erp\MockErp;
use Pratyangi\Erp\NotConnectedErp;
use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\Cors;
use Pratyangi\Http\Csrf;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Http\RateLimiter;
use Pratyangi\Http\RateLimitStore;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Http\Router;
use Pratyangi\Mail\EventMail;
use Pratyangi\Mail\LogMailer;
use Pratyangi\Mail\Mailer;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Mail\SmtpMailer;
use Pratyangi\Orders\OrderRepo;
use Pratyangi\Payments\HttpRazorpay;
use Pratyangi\Payments\PaymentController;
use Pratyangi\Payments\PaymentService;
use Pratyangi\Payments\RazorpayPort;
use Pratyangi\Sms\HttpSmsGateway;
use Pratyangi\Sms\LogSmsGateway;
use Pratyangi\Sms\SmsGateway;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;
use Pratyangi\Support\Text;
use Pratyangi\Web\AuthPages;
use Pratyangi\Web\CatalogLinks;
use Pratyangi\Web\CatalogPages;
use Pratyangi\Views\ShopInfo;
use Pratyangi\Web\ErrorPages;
use Pratyangi\Web\LandingController;
use Pratyangi\Web\PolicyPages;
use Pratyangi\Web\ShopPages;
use Pratyangi\Web\StaticFiles;

/**
 * The application: wires the pieces, runs one request through them and returns a Response.
 * Order for /api/v1 (as in the Node API): preflight, body read, CSRF Origin check, route match, sign-in check, rate limit, handler.
 */
final class App
{
    private const SECURITY_HEADERS = [
        // what Node's helmet() sends by default
        'Content-Security-Policy' => "default-src 'self';base-uri 'self';font-src 'self' https: data:;form-action 'self';frame-ancestors 'self';img-src 'self' data: https://*.googleusercontent.com;object-src 'none';script-src 'self';script-src-attr 'none';style-src 'self' https: 'unsafe-inline';upgrade-insecure-requests",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Origin-Agent-Cluster' => '?1',
        'Referrer-Policy' => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
        'X-DNS-Prefetch-Control' => 'off',
        'X-Download-Options' => 'noopen',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'X-XSS-Protection' => '0',
    ];

    private ?\PDO $pdo = null;
    private ?AuthService $auth = null;
    private Router $router;
    private Cors $cors;
    private Csrf $csrf;
    private RateLimiter $limiter;
    private CatalogController $catalog;
    private LandingController $landing;
    private StaticFiles $staticFiles;
    private AuthPages $authPages;
    private CatalogPages $catalogPages;
    private ShopPages $shopPages;
    private PolicyPages $policyPages;
    private ?AuthController $authController = null;
    private ?OtpService $otpService = null;
    private ?AccountService $accountService = null;
    private ?AddressRepo $addressRepo = null;
    private ?AddressController $addressController = null;
    private ?PaymentService $paymentService = null;
    private ?EventMail $eventMail = null;
    private string $csp;
    private PaymentController $payments;

    /**
     * @param \Closure():\PDO $pdoFactory called at most once, and only when a route needs the database
     * @param array<string,int> $rateLimitOverrides bucket => limit (tests)
     */
    public function __construct(
        private Config $config,
        private Logger $logger,
        private Clock $clock,
        private \Closure $pdoFactory,
        private Mailer $mailer,
        private ErpPort $erp,
        RateLimitStore $rateLimitStore,
        array $rateLimitOverrides = [],
        ?string $catalogCacheDir = null,
        private ?SmsGateway $sms = null,
        private ?RazorpayPort $razorpay = null,
        private ?GoogleVerifier $google = null, // tests give a fake; otherwise it is built from GOOGLE_CLIENT_ID
    ) {
        $this->cors = new Cors($config->webOrigins);
        $this->csrf = new Csrf($config->webOrigins);
        $this->limiter = new RateLimiter($rateLimitStore, $clock, $logger, $rateLimitOverrides);
        $cache = new CatalogCache($catalogCacheDir ?? $config->catalogCacheDir, $config->catalogCacheSeconds, $clock, $logger);
        $catalogService = new CatalogService($erp, $cache, $logger);
        $this->catalog = new CatalogController($catalogService);
        CatalogLinks::init($catalogService);
        // the header's signed-in state reuses the phase 1 session lookup; the database is only reached when a pf_session cookie is sent
        $this->landing = new LandingController($catalogService, fn (string $token): ?array => $this->auth()->customerForToken($token), $logger);
        $this->authPages = new AuthPages(fn (string $token): ?array => $this->auth()->customerForToken($token), $logger, $config->smsEnabled, $config->googleClientId);
        $this->catalogPages = new CatalogPages($catalogService, fn (string $token): ?array => $this->auth()->customerForToken($token), $logger);
        $this->shopPages = new ShopPages($catalogService, fn (string $token): ?array => $this->auth()->customerForToken($token), $logger, fn (): AddressRepo => $this->addressRepo(), $config->smsEnabled, fn (): OrderRepo => new OrderRepo($this->pdo(), $clock));
        ProductImage::init($config->erpMode === 'http' ? $config->erpBaseUrl : null);
        // product pictures are loaded from the ERP's own address, so only that address is added to img-src
        $origin = ProductImage::origin();
        $this->csp = $origin === null ? self::SECURITY_HEADERS['Content-Security-Policy'] : str_replace("img-src 'self' data: https://*.googleusercontent.com", "img-src 'self' data: https://*.googleusercontent.com " . $origin, self::SECURITY_HEADERS['Content-Security-Policy']);
        if ($config->googleClientId !== null) {
            // Google Identity Services: its script, its button frame and its calls (and, below, a sign-in popup that keeps its opener)
            $this->csp = str_replace(["script-src 'self'", 'upgrade-insecure-requests'], ["script-src 'self' https://accounts.google.com/gsi/client", "frame-src https://accounts.google.com/gsi/;connect-src 'self' https://accounts.google.com/gsi/;upgrade-insecure-requests"], $this->csp);
        }
        ShopInfo::init($config); // the company details of the footer, the policy pages and the emails
        $this->policyPages = new PolicyPages(fn (string $token): ?array => $this->auth()->customerForToken($token), $logger);
        $this->payments = new PaymentController($config->paymentsEnabled, $config->checkoutEnabled, $config->smsEnabled, $config->razorpayMode(), fn (): PaymentService => $this->paymentService(), $config->orderOtp,
            fn (int $customerId, int $addressId): string => $this->addressRepo()->findById($customerId, $addressId)?->phone ?? '',
            fn (): NumberVerification => new NumberVerification($this->pdo(), $this->mobileOtpService(), $this->clock));
        $this->staticFiles = new StaticFiles(dirname(__DIR__) . '/public/assets');
        $this->router = new Router();
        $this->routes();
    }

    /** Production wiring from the settings. */
    public static function create(Config $config): self
    {
        $clock = new Clock();
        $logger = new Logger($config->logLevel, $config->logDir, null, 'app', $config->appEnv, $clock);
        // one connection per request, shared by the rate limiter and the account code
        $pdo = null;
        $pdoFactory = static function () use ($config, &$pdo): \PDO {
            return $pdo ??= Db::connect($config);
        };

        if ($config->mailMode === 'log') {
            $mailer = new LogMailer($config->mailLogPath, $clock);
        } elseif ($config->smtpHost !== null) {
            $mailer = new SmtpMailer($config->smtpHost, $config->smtpPort, $config->smtpSecure, $config->smtpUser, $config->smtpPassword(), $config->mailFrom);
        } else {
            $mailer = new NullMailer();
        }

        $erp = match ($config->erpMode) {
            'http' => new ErpClient((string) $config->erpBaseUrl, (string) $config->erpApiKey(), $config->erpWarehouse, $config->erpPriceRule, $config->erpTaxMode, $config->erpTimeoutMs, $config->erpCaBundle, $logger, $config->erpOrderApiKey()),
            'mock' => new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
            default => new NotConnectedErp(),
        };

        // mobile sign-in: without the pepper there is no gateway, and the two routes answer 503
        $sms = null;
        if ($config->otpPepper() !== null) {
            $sms = $config->smsMode === 'http'
                ? new HttpSmsGateway((string) $config->smsApiUrl, (string) $config->smsUsername, (string) $config->smsApiKey(), (string) $config->smsSenderId, (string) $config->smsOtpTemplateId, $config->smsOtpTemplateText)
                : new LogSmsGateway($config->smsLogPath, $clock);
        }

        $razorpay = $config->paymentsEnabled
            ? new HttpRazorpay($config->razorpayBaseUrl, (string) $config->razorpayKeyId, (string) $config->razorpayKeySecret(), $config->razorpayTimeoutMs)
            : null;

        return new self($config, $logger, $clock, $pdoFactory, $mailer, $erp, new DbRateLimitStore($pdoFactory), [], null, $sms, $razorpay);
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);
        $requestId = self::requestId($request);
        $this->logger->setRequestId($requestId);
        $context = new Context($request, $requestId, $this->config->trustProxy, function (Request $r): ?array {
            $token = $r->cookie(AuthController::COOKIE);
            // no cookie = signed out, without touching the database
            return ($token === null || $token === '') ? null : $this->auth()->customerForToken($token);
        });
        try {
            $response = $this->dispatch($request, $context);
        } catch (ApiException $e) {
            $response = Response::fromException($e);
        } catch (\Throwable $e) {
            $this->logger->channel('error')->exception('unhandled error: ' . $e->getMessage(), $e);
            $response = Response::json(['message' => ApiException::GENERIC_500], 500);
        }
        // browser pages get the branded HTML for a JSON error; /api/* keeps its JSON
        if (preg_match('#^/api(/|$)#', $request->path) !== 1 && str_starts_with((string) $response->header('Content-Type'), 'application/json') && in_array($response->status(), ErrorPages::statuses(), true)) {
            $response = ErrorPages::response($response->status());
        } elseif ($response->status() >= 400 && str_starts_with((string) $response->header('Content-Type'), 'text/html')) {
            $response->setHeader('X-Robots-Tag', 'noindex'); // page-level 404/503 (unknown product, products being updated)
        }
        foreach ($context->headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        $this->cors->apply($request, $response);
        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response->setHeader($name, $name === 'Content-Security-Policy' ? $this->csp : ($name === 'Cross-Origin-Opener-Policy' && $this->config->googleClientId !== null ? 'same-origin-allow-popups' : $value));
        }
        // pictures may be embedded by other sites: the logo in an email opened in a webmail page is loaded from here
        if (str_starts_with($request->path, '/assets/images/')) {
            $response->setHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        }
        // On plain-http local runs "upgrade-insecure-requests" would turn the page's same-origin CSS/image requests into https and break them.
        if (!$this->config->isProduction && str_starts_with((string) $response->header('Content-Type'), 'text/html')) {
            $response->setHeader('Content-Security-Policy', str_replace(';upgrade-insecure-requests', '', $this->csp));
        }
        $response->setHeader('X-Request-Id', $requestId);
        if ($this->config->staging) {
            $response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        // money and payment state are never cached, errors included
        if (str_starts_with((string) Router::apiPath($request->path), '/payments')) {
            $response->setHeader('Cache-Control', 'no-store');
        }

        $status = $response->status();
        if ($status >= 500) {
            $this->logger->channel('error')->error('5xx response', [
                'method' => $request->method,
                'path' => Router::normalise($request->path),
                'status' => $status,
                'trace' => (new \Exception())->getTraceAsString(),
            ]);
        }
        $this->logger->log($status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info'), 'request', [
            'method' => $request->method,
            'path' => Router::normalise($request->path),
            'status' => $status,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        return $response;
    }

    private function dispatch(Request $request, Context $context): Response
    {
        $preflight = $this->cors->preflight($request);
        if ($preflight !== null) {
            return $preflight;
        }
        // like express.json: a bad or oversized body is refused before routing and before the CSRF check
        $request->jsonBody();

        if ($this->config->maintenance && !in_array($context->clientIp(), $this->config->maintenanceAllowIp, true) && !str_starts_with($request->path, '/assets/') && Router::normalise($request->path) !== '/health') {
            throw ApiException::unavailable("We'll be right back. The shop is being updated.");
        }
        if (($request->method === 'GET' || $request->method === 'HEAD') && Router::normalise($request->path) === '/health') {
            return Response::json(['ok' => true]);
        }
        // the web pages and their static files (GET/HEAD only; anything else outside /api/v1 stays a 404)
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            $page = $this->webPage($request);
            if ($page !== null) {
                return $page;
            }
        }
        $apiPath = Router::apiPath($request->path);
        if ($apiPath === null) {
            throw ApiException::notFound();
        }
        $this->csrf->check($request); // before the session lookup, so 403 comes before 401
        $route = $this->router->find($request->method, $apiPath);
        if ($route === null) {
            throw ApiException::notFound();
        }
        if ($route['auth'] && $context->customer() === null) {
            throw ApiException::unauthorized();
        }
        if ($route['bucket'] !== null) {
            try {
                $this->limiter->consume($route['bucket'], $context->clientIp(), $context);
            } catch (ApiException $e) {
                if ($route['bucket'] === 'otp_ip') {
                    $masked = null;
                    try {
                        $body = $request->jsonBody();
                        if (is_array($body) && isset($body['mobile']) && is_string($body['mobile'])) {
                            $norm = \Pratyangi\Auth\Mobile::normalise($body['mobile']);
                            if ($norm !== null) {
                                $masked = \Pratyangi\Auth\Mobile::mask($norm);
                            }
                        }
                    } catch (\Throwable) {
                    }
                    $this->logger->channel('auth')->warn('otp request rejected', [
                        'channel' => 'mobile',
                        'reason' => 'ip_limit',
                        'mobile' => $masked,
                        'outcome' => 'rejected',
                    ]);
                }
                throw $e;
            }
        }
        return ($route['handler'])($request, $context, ...($route['params'] ?? []));
    }

    private function webPage(Request $request): ?Response
    {
        $path = Router::normalise($request->path);
        if ($path === '/') {
            return $this->landing->home($request->cookie(AuthController::COOKIE));
        }
        if ($path === '/server-check') {
            // tools/server_check.php (shipped with the app) answers it; it refuses unless SERVER_CHECK_KEY is set and matches
            return (require dirname(__DIR__) . '/tools/server_check.php')($this->config, $request, fn (): \PDO => ($this->pdoFactory)());
        }
        if ($path === '/soon') {
            return $this->landing->soon();
        }
        if (isset(PolicyPages::paths()[$path])) {
            return $this->policyPages->show($request, PolicyPages::paths()[$path]);
        }
        if (str_starts_with($path, '/collection/')) {
            return $this->catalogPages->newArrivals($request, substr($path, 12));
        }
        // the catalogue pages and the sign-in pages (same paths as the web app); the bag, checkout and account pages; the orders page is still the coming page
        switch ($path) {
            case '/new-arrivals':
                return $this->catalogPages->newArrivals($request);
            case '/wishlist':
                return $this->catalogPages->wishlist($request);
            case '/search':
                return $this->catalogPages->search($request);
            case '/product':
                return $this->catalogPages->product($request);
            case '/login':
                return $this->authPages->login($request);
            case '/signup':
                return $this->authPages->signup($request);
            case '/forgot-password':
                return $this->authPages->forgot($request);
            case '/verify-email':
                return $this->authPages->verify($request);
            case '/bag':
                return $this->shopPages->bag($request);
            case '/checkout':
                return $this->shopPages->checkout($request);
            case '/account':
                return $this->shopPages->account($request);
            case '/account/orders':
            case '/account/addresses':
            case '/account/notifications':
            case '/account/payments':
            case '/account/reviews':
            case '/account/help':
                return $this->shopPages->account($request, substr($path, 9));
        }
        // not lower-cased: file names are case-sensitive
        if (str_starts_with($request->path, '/assets/')) {
            return $this->staticFiles->serve($request->path);
        }
        return null;
    }

    private function routes(): void
    {
        $auth = fn (): AuthController => $this->authController();
        $address = fn (): AddressController => $this->addressController();
        $consent = fn (): ConsentController => new ConsentController(new ConsentRepo($this->pdo()));
        $numbers = fn (): NumberController => new NumberController(new NumberVerification($this->pdo(), $this->mobileOtpService(), $this->clock));
        $r = $this->router;
        // mobile codes are off until SMS_ENABLED=true (the SMS template is approved): the same 503 on every mobile route
        $sms = fn (\Closure $handler): \Closure => function (Request $q, Context $c) use ($handler): Response {
            if (!$this->config->smsEnabled) {
                throw ApiException::unavailable('Mobile sign-in coming soon');
            }
            return $handler($q, $c);
        };
        $r->add('GET', '/health', static fn (Request $q, Context $c): Response => Response::json(['ok' => true]));
        $r->add('POST', '/auth/signup', fn (Request $q, Context $c): Response => $auth()->signup($q, $c), 'signup');
        $r->add('POST', '/auth/login', fn (Request $q, Context $c): Response => $auth()->login($q, $c), 'login');
        $r->add('POST', '/auth/google', fn (Request $q, Context $c): Response => $auth()->google($q, $c), 'google');
        // PHP-only additions (docs/API_CONTRACT.md, "PHP-only additions")
        $r->add('POST', '/auth/otp/request', $sms(fn (Request $q, Context $c): Response => $auth()->otpRequest($q, $c)), 'otp_ip');
        $r->add('POST', '/auth/otp/verify', $sms(fn (Request $q, Context $c): Response => $auth()->otpVerify($q, $c)), 'otp_verify');
        $r->add('POST', '/auth/otp/signup/request', $sms(fn (Request $q, Context $c): Response => $auth()->otpRequest($q, $c, 'signup')), 'otp_ip');
        $r->add('POST', '/auth/otp/signup/verify', $sms(fn (Request $q, Context $c): Response => $auth()->otpVerify($q, $c, 'signup')), 'otp_verify');
        $r->add('POST', '/auth/logout', fn (Request $q, Context $c): Response => $auth()->logout($q, $c));
        $r->add('GET', '/auth/me', fn (Request $q, Context $c): Response => $auth()->me($q, $c));
        // email verification: {token} is the old link (no sign-in needed), {code} is the 6-digit code of the signed-in customer; resend sends a code
        $r->add('POST', '/auth/email/verify', fn (Request $q, Context $c): Response => $auth()->verifyEmail($q, $c), 'verify');
        $r->add('POST', '/auth/email/resend', fn (Request $q, Context $c): Response => $auth()->resend($q, $c), 'verify', true);
        // adding a mobile number or an email to the signed-in account, and the account's name
        $r->add('POST', '/auth/mobile/request', $sms(fn (Request $q, Context $c): Response => $auth()->mobileRequest($q, $c)), 'otp_ip', true);
        $r->add('POST', '/auth/mobile/verify', $sms(fn (Request $q, Context $c): Response => $auth()->mobileVerify($q, $c)), 'otp_verify', true);
        $r->add('POST', '/auth/email/link/request', fn (Request $q, Context $c): Response => $auth()->emailLinkRequest($q, $c), 'otp_ip', true);
        $r->add('POST', '/auth/email/link/verify', fn (Request $q, Context $c): Response => $auth()->emailLinkVerify($q, $c), 'otp_verify', true);
        $r->add('POST', '/auth/profile/name', fn (Request $q, Context $c): Response => $auth()->profileName($q, $c), null, true);
        $r->add('POST', '/auth/password/forgot', fn (Request $q, Context $c): Response => $auth()->forgot($q, $c), 'reset');
        $r->add('POST', '/auth/password/reset', fn (Request $q, Context $c): Response => $auth()->reset($q, $c), 'reset');
        $r->add('GET', '/catalog/products', fn (Request $q, Context $c): Response => $this->catalog->products($q, $c), 'catalog');

        // Address book (docs/API_CONTRACT.md section 2.4)
        $r->add('GET', '/addresses', fn (Request $q, Context $c): Response => $address()->list($q, $c), null, true);
        $r->add('POST', '/addresses', fn (Request $q, Context $c): Response => $address()->create($q, $c), null, true);
        $r->add('PUT', '/addresses/:id', fn (Request $q, Context $c, string $id): Response => $address()->update($q, $c, $id), null, true);
        $r->add('DELETE', '/addresses/:id', fn (Request $q, Context $c, string $id): Response => $address()->delete($q, $c, $id), null, true);
        $r->add('POST', '/addresses/:id/default', fn (Request $q, Context $c, string $id): Response => $address()->setDefault($q, $c, $id), null, true);

        // PHP-only: marketing consent per channel, and a code to a delivery number (docs/API_CONTRACT.md 11.7)
        $r->add('GET', '/consent', fn (Request $q, Context $c): Response => $consent()->show($q, $c), null, true);
        $r->add('PUT', '/consent', fn (Request $q, Context $c): Response => $consent()->update($q, $c), null, true);
        $r->add('POST', '/numbers/otp/request', $sms(fn (Request $q, Context $c): Response => $numbers()->request($q, $c)), 'otp_ip', true);
        $r->add('POST', '/numbers/otp/verify', $sms(fn (Request $q, Context $c): Response => $numbers()->verify($q, $c)), 'otp_verify', true);

        // Online payment with Razorpay (section 2.6). There is no cash-on-delivery route: COD is off for launch.
        $r->add('GET', '/payments/config', fn (Request $q, Context $c): Response => $this->payments->config($q, $c));
        $r->add('POST', '/payments/razorpay/order', fn (Request $q, Context $c): Response => $this->payments->start($q, $c), 'payment_order', true);
        $r->add('POST', '/payments/razorpay/verify', fn (Request $q, Context $c): Response => $this->payments->verify($q, $c), 'payment_verify', true);
    }

    private function pdo(): \PDO
    {
        return $this->pdo ??= ($this->pdoFactory)();
    }

    private function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->pdo(),
            new CustomerRepo($this->pdo()),
            new SessionRepo($this->pdo()),
            new TokenRepo($this->pdo()),
            $this->config->sessionDays,
            $this->clock,
            $this->logger,
            $this->eventMail(),
        );
    }

    /** The account and order emails, each sent once per event (claims live in shop_mail_events). */
    private function eventMail(): EventMail
    {
        return $this->eventMail ??= new EventMail($this->mailer, EventMail::claimInDb($this->pdo()), EventMail::releaseInDb($this->pdo()), $this->config->checkoutEnabled || $this->config->paymentsEnabled, $this->clock, $this->logger);
    }

    private function authController(): AuthController
    {
        return $this->authController ??= new AuthController(
            $this->auth(),
            $this->config->isProduction,
            fn (): OtpService => $this->mobileOtpService(),
            fn (): AccountService => $this->accountService(),
            $this->limiter,
            $this->logger,
            $this->google ??= $this->config->googleClientId === null ? null : new GoogleVerifier($this->config->googleClientId, GoogleVerifier::certsFrom($this->config->catalogCacheDir . '/google_certs.json', $this->clock), $this->clock, $this->logger),
        );
    }

    /** Mobile sign-in needs the gateway and the pepper; without them the two routes answer 503 before the database is reached. */
    private function mobileOtpService(): OtpService
    {
        if ($this->sms === null || $this->config->otpPepper() === null) {
            throw ApiException::unavailable('Mobile sign-in is not set up yet. Please sign in with your email.');
        }
        return $this->otpService();
    }

    /** The code routes of the signed-in customer and the password reset need the pepper; each channel also needs its sender (checked when a code is sent). */
    private function accountService(): AccountService
    {
        if ($this->config->otpPepper() === null) {
            throw ApiException::unavailable('Codes are not set up yet. Please try again later.');
        }
        return $this->accountService ??= new AccountService(
            new CustomerRepo($this->pdo()),
            new SessionRepo($this->pdo()),
            new TokenRepo($this->pdo()),
            $this->otpService(),
            $this->clock,
            $this->logger,
            $this->config->smsEnabled,
            $this->eventMail(),
        );
    }

    private function otpService(): OtpService
    {
        return $this->otpService ??= new OtpService(
            $this->pdo(),
            new OtpRepo($this->pdo()),
            new CustomerRepo($this->pdo()),
            $this->auth(),
            $this->sms,
            $this->limiter,
            (string) $this->config->otpPepper(),
            $this->clock,
            $this->logger,
            $this->mailer,
        );
    }

    public function addressRepo(): AddressRepo
    {
        return $this->addressRepo ??= new AddressRepo($this->pdo());
    }

    private function paymentService(): PaymentService
    {
        if ($this->razorpay === null || $this->config->razorpayKeyId === null || $this->config->razorpayKeySecret() === null) {
            throw new ApiException(503, 'Online payment is not open yet. Please try again later.', null, null, 'payments-disabled');
        }
        return $this->paymentService ??= new PaymentService(
            new OrderRepo($this->pdo(), $this->clock),
            $this->erp,
            $this->razorpay,
            $this->addressRepo(),
            $this->config->razorpayKeyId,
            (string) $this->config->razorpayKeySecret(),
            $this->clock,
            $this->logger,
            null,
            fn (StoredOrder $o) => $this->confirmationMail($o),
        );
    }

    private function confirmationMail(StoredOrder $o): void
    {
        $customer = (new CustomerRepo($this->pdo()))->findById($o->customerId);
        $email = (string) ($customer['email'] ?? '');
        $this->eventMail()->order('orderConfirmed', $email, [
            'orderNo' => $o->orderNo, 'name' => $o->shipTo['name'] !== '' ? $o->shipTo['name'] : (string) ($customer['name'] ?? ''),
            'items' => array_map(static fn (array $i): array => [$i['name'], $i['quantity'], $i['unitPricePaise']], $o->items),
            'itemsTotalPaise' => $o->itemsTotalPaise, 'taxPaise' => $o->taxPaise, 'totalPaise' => $o->totalPaise, 'address' => $o->shipTo['name'] . ', ' . $o->addressText(),
        ]);
    }

    private function addressController(): AddressController
    {
        return $this->addressController ??= new AddressController($this->addressRepo());
    }

    /** The client's x-request-id (cut to 64 characters) when it is plain printable ASCII, otherwise a new UUID. */
    private static function requestId(Request $request): string
    {
        $given = $request->header('x-request-id');
        if ($given !== null && preg_match('/^[\x20-\x7e]*$/D', $given) === 1) {
            return substr($given, 0, 64);
        }
        return Text::uuid();
    }
}

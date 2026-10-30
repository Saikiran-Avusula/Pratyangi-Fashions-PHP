<?php
declare(strict_types=1);

namespace Pratyangi;

/**
 * Settings, read once and checked at start-up. The messages name settings, never values.
 *
 * Where the values come from: a PHP file that returns an array of NAME => value. Its path is the environment variable PF_CONFIG,
 * otherwise config.local.php one level above the web root (the project folder). Tests build a Config straight from an array.
 * An empty string counts as unset.
 */
final class Config
{
    public readonly string $appEnv;
    public readonly bool $isProduction;
    public readonly string $dbHost;
    public readonly int $dbPort;
    public readonly string $dbName;
    public readonly string $dbUser;
    private readonly string $dbPass;
    /** @var list<string> */
    public readonly array $webOrigins;
    public readonly string $webUrl;
    public readonly int $sessionDays;
    public readonly int $trustProxy;
    public readonly string $logLevel;
    public readonly string $logDir;

    public readonly string $mailMode;
    public readonly ?string $smtpHost;
    public readonly int $smtpPort;
    public readonly ?string $smtpUser;
    private readonly ?string $smtpPass;
    public readonly string $smtpSecure;
    public readonly string $mailFrom;
    public readonly string $shopName;
    // the shop's company details (SHOP_*): every page footer, the policy pages and the emails read them from here; an empty one is simply not shown
    public readonly string $shopLegalName;
    public readonly string $shopAddress;
    public readonly string $shopGstin;
    public readonly string $shopPhone;
    public readonly string $shopSupportEmail;
    public readonly string $shopHours;
    /** @var list<string> the rotating messages of the top bar (SHOP_ANNOUNCEMENTS, pipe-separated) */
    public readonly array $shopAnnouncements;
    /** Company identity number (SHOP_CIN), WhatsApp number as digits with the country code (SHOP_WHATSAPP), and the Grievance Officer (SHOP_GRIEVANCE_*); empty = not shown. */
    public readonly string $shopCin;
    /** Shops & Establishments registration number (SHOP_SE_REG), shown on /contact only; empty = hidden. */
    public readonly string $shopSeReg;
    public readonly string $shopWhatsapp;
    public readonly string $shopGrievanceName;
    public readonly string $shopGrievanceEmail;
    public readonly string $shopGrievancePhone;
    /** STAGING=true: every answer carries X-Robots-Tag: noindex. SERVER_CHECK_KEY: the secret of /server-check (empty = that page refuses). */
    public readonly bool $staging;
    private readonly string $serverCheckKey;
    /** MAINTENANCE=true: every page answers 503 "We'll be right back", except for the addresses in MAINTENANCE_ALLOW_IP (comma-separated). */
    public readonly bool $maintenance;
    /** @var list<string> */
    public readonly array $maintenanceAllowIp;
    /** Orders above this many rupees ship free (SHOP_FREE_SHIPPING_ABOVE); null = the shop says nothing about it. */
    public readonly ?int $shopFreeShippingAbove;
    /** @var array<string,string> facebook, youtube, instagram, whatsapp => https address */
    public readonly array $shopSocial;
    /** The public Google OAuth client id (GOOGLE_CLIENT_ID); null = no Google button and POST /auth/google answers 503. */
    public readonly ?string $googleClientId;
    public readonly string $mailLogPath;

    public readonly string $erpMode;
    public readonly ?string $erpBaseUrl;
    private readonly ?string $erpApiKey;
    public readonly ?string $erpWarehouse;
    public readonly string $erpPriceRule;
    public readonly string $erpTaxMode;
    public readonly int $erpTimeoutMs;
    public readonly ?string $erpCaBundle;
    public readonly string $erpMockFile;
    public readonly int $catalogCacheSeconds;
    public readonly string $catalogCacheDir;
    private readonly ?string $erpOrderApiKey;

    // online orders and Razorpay (stage B1). Both switches default to off.
    /** SMS_ENABLED: while false (until the SMS template is approved) everything runs on email only. */
    public readonly bool $smsEnabled;
    public readonly string $orderOtp;
    public readonly bool $checkoutEnabled;
    /** DEMO_UI=true shows the mock preview screens (app/Demo/DemoData.php: checkout steps 2-3, payment methods, membership, reviews). Refused in production. */
    public readonly bool $demoUi;
    public readonly bool $paymentsEnabled;
    public readonly ?string $razorpayKeyId;
    private readonly ?string $razorpayKeySecret;
    public readonly string $razorpayBaseUrl;
    public readonly int $razorpayTimeoutMs;

    // mobile OTP sign-in (PHP-only addition)
    public readonly string $smsMode;
    public readonly ?string $smsApiUrl;
    public readonly ?string $smsUsername;
    private readonly ?string $smsApiKey;
    public readonly ?string $smsSenderId;
    public readonly ?string $smsOtpTemplateId;
    public readonly string $smsOtpTemplateText;
    public readonly ?string $smsHintTemplateId;
    public readonly string $smsHintTemplateText;
    public readonly string $smsLogPath;
    private readonly ?string $otpPepper;

    /** @param array<string,mixed> $values NAME => value */
    public function __construct(array $values)
    {
        $v = self::clean($values);
        // the names used in the stage C brief are accepted too; the original name wins when both are set
        foreach (['SMS_API_KEY' => 'SMS_APIKEY', 'SMS_TEMPLATE_ID' => 'SMS_OTP_TEMPLATE_ID', 'MAIL_HOST' => 'SMTP_HOST', 'MAIL_PORT' => 'SMTP_PORT', 'MAIL_USER' => 'SMTP_USER', 'MAIL_PASS' => 'SMTP_PASS'] as $alias => $name) {
            if (isset($v[$alias]) && !isset($v[$name])) {
                $v[$name] = $v[$alias];
            }
        }
        $problems = [];
        $root = dirname(__DIR__);

        $env = $v['APP_ENV'] ?? 'production';
        if (!in_array($env, ['production', 'development', 'test'], true)) {
            $problems[] = 'APP_ENV: must be production, development or test';
            $env = 'production';
        }
        $this->appEnv = $env;
        $this->isProduction = $env === 'production';

        $this->dbHost = (string) ($v['DB_HOST'] ?? '127.0.0.1');
        $this->dbPort = self::int($v, 'DB_PORT', 3306, 1, 65535, $problems);
        $this->dbName = self::required($v, 'DB_NAME', $problems);
        $this->dbUser = self::required($v, 'DB_USER', $problems);
        $this->dbPass = (string) ($v['DB_PASS'] ?? '');

        // production has no default: CORS, the CSRF origin check and the emailed links all depend on it
        if ($this->isProduction && !isset($v['WEB_ORIGIN'])) {
            $problems[] = 'WEB_ORIGIN: required in production (the website address, for example https://shop.example.com)';
        }
        $origins = [];
        $originProblem = $this->isProduction && !isset($v['WEB_ORIGIN']);
        foreach (explode(',', (string) ($v['WEB_ORIGIN'] ?? 'http://localhost:3000')) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') {
                continue;
            }
            $parts = parse_url($origin);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === ''
                || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                $problems[] = 'WEB_ORIGIN: every address must be just http(s)://host[:port], with no path, query or login';
                $originProblem = true;
                continue;
            }
            $origins[] = $origin;
        }
        if ($origins === [] && !$originProblem) {
            $problems[] = 'WEB_ORIGIN: needs at least one address';
        }
        $this->webOrigins = $origins;
        $this->webUrl = $origins[0] ?? 'http://localhost:3000';
        $this->sessionDays = self::int($v, 'SESSION_DAYS', 30, 1, 3650, $problems);
        $this->trustProxy = self::int($v, 'TRUST_PROXY', 0, 0, 20, $problems);
        $level = (string) ($v['LOG_LEVEL'] ?? ($env === 'test' ? 'silent' : 'info'));
        if (!in_array($level, ['error', 'warn', 'info', 'debug', 'silent'], true)) {
            $problems[] = 'LOG_LEVEL: must be error, warn, info, debug or silent';
            $level = 'info';
        }
        $this->logLevel = $level;
        $this->logDir = (string) ($v['LOG_DIR'] ?? $root . '/storage/logs');
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        // mail
        $mailMode = (string) ($v['MAIL_MODE'] ?? ($this->isProduction ? 'smtp' : 'log'));
        if (!in_array($mailMode, ['smtp', 'log'], true)) {
            $problems[] = 'MAIL_MODE: must be smtp or log';
            $mailMode = 'smtp';
        }
        if ($mailMode === 'log' && $this->isProduction) {
            $problems[] = 'MAIL_MODE: "log" writes verification links and reset codes to a file, so it needs APP_ENV=development or APP_ENV=test';
        }
        $this->mailMode = $mailMode;
        $this->smtpHost = isset($v['SMTP_HOST']) ? (string) $v['SMTP_HOST'] : null;
        $secure = (string) ($v['SMTP_SECURE'] ?? '');
        if ($secure !== '' && !in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $problems[] = 'SMTP_SECURE: must be tls (STARTTLS), ssl or none';
            $secure = '';
        }
        $this->smtpPort = self::int($v, 'SMTP_PORT', $secure === 'tls' ? 587 : 465, 1, 65535, $problems);
        $this->smtpSecure = $secure !== '' ? $secure : ($this->smtpPort === 465 ? 'ssl' : 'tls');
        $this->smtpUser = isset($v['SMTP_USER']) ? (string) $v['SMTP_USER'] : null;
        $this->smtpPass = isset($v['SMTP_PASS']) ? (string) $v['SMTP_PASS'] : null;
        $this->mailFrom = (string) ($v['MAIL_FROM'] ?? 'Pratyangi Fashions <no-reply@pratyangifashions.com>');
        $shop = (string) ($v['SHOP_NAME'] ?? 'Pratyangi Fashions');
        if (mb_strlen($shop) > 80) {
            $problems[] = 'SHOP_NAME: at most 80 characters';
        }
        $this->shopName = $shop;
        // stored as plain text and escaped once, where it is printed: an entity typed into the settings (Sahiti&apos;s) is turned back into its character here
        $plain = static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->shopLegalName = $plain((string) ($v['SHOP_LEGAL_NAME'] ?? $shop));
        $this->shopAddress = $plain((string) ($v['SHOP_ADDRESS'] ?? ''));
        $this->shopGstin = strtoupper((string) ($v['SHOP_GSTIN'] ?? ''));
        $this->shopPhone = $plain((string) ($v['SHOP_PHONE'] ?? ''));
        $this->shopSupportEmail = (string) ($v['SHOP_SUPPORT_EMAIL'] ?? '');
        $this->shopHours = $plain((string) ($v['SHOP_HOURS'] ?? ''));
        $this->shopAnnouncements = array_slice(array_values(array_filter(array_map(static fn (string $m): string => $plain(trim($m)), explode('|', (string) ($v['SHOP_ANNOUNCEMENTS'] ?? 'Authentic Handlooms|Handwoven by master artisans|Help on WhatsApp'))), static fn (string $m): bool => $m !== '')), 0, 6);
        foreach (['SHOP_LEGAL_NAME' => 120, 'SHOP_ADDRESS' => 300, 'SHOP_PHONE' => 40, 'SHOP_HOURS' => 120] as $name => $max) {
            if (mb_strlen((string) ($v[$name] ?? '')) > $max) {
                $problems[] = $name . ': at most ' . $max . ' characters';
            }
        }
        if ($this->shopGstin !== '' && preg_match('/^[0-9A-Z]{15}$/D', $this->shopGstin) !== 1) {
            $problems[] = 'SHOP_GSTIN: must be 15 letters and digits';
        }
        if ($this->shopSupportEmail !== '' && filter_var($this->shopSupportEmail, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'SHOP_SUPPORT_EMAIL: must be an email address';
        }
        $this->shopCin = strtoupper((string) ($v['SHOP_CIN'] ?? ''));
        $this->shopSeReg = $plain((string) ($v['SHOP_SE_REG'] ?? ''));
        if (strlen($this->shopSeReg) > 60) {
            $problems[] = 'SHOP_SE_REG: at most 60 characters';
        }
        $this->shopWhatsapp = (string) ($v['SHOP_WHATSAPP'] ?? '');
        $this->shopGrievanceName = $plain((string) ($v['SHOP_GRIEVANCE_NAME'] ?? ''));
        $this->shopGrievanceEmail = (string) ($v['SHOP_GRIEVANCE_EMAIL'] ?? '');
        $this->shopGrievancePhone = $plain((string) ($v['SHOP_GRIEVANCE_PHONE'] ?? ''));
        if ($this->shopCin !== '' && preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}$/D', $this->shopCin) !== 1) {
            $problems[] = 'SHOP_CIN: must be 21 characters, for example U12345TG2020PTC123456';
        }
        if ($this->shopWhatsapp !== '' && preg_match('/^[1-9]\d{7,14}$/D', $this->shopWhatsapp) !== 1) {
            $problems[] = 'SHOP_WHATSAPP: digits only with the country code, for example 919876543210';
        }
        if ($this->shopGrievanceEmail !== '' && filter_var($this->shopGrievanceEmail, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'SHOP_GRIEVANCE_EMAIL: must be an email address';
        }
        foreach (['SHOP_GRIEVANCE_NAME' => 80, 'SHOP_GRIEVANCE_PHONE' => 40] as $name => $max) {
            if (mb_strlen((string) ($v[$name] ?? '')) > $max) {
                $problems[] = $name . ': at most ' . $max . ' characters';
            }
        }
        $this->staging = self::bool($v, 'STAGING', $problems);
        $this->maintenance = self::bool($v, 'MAINTENANCE', $problems);
        $this->maintenanceAllowIp = array_values(array_filter(array_map('trim', explode(',', (string) ($v['MAINTENANCE_ALLOW_IP'] ?? '')))));
        foreach ($this->maintenanceAllowIp as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $problems[] = 'MAINTENANCE_ALLOW_IP: a comma-separated list of IP addresses';
                break;
            }
        }
        $this->serverCheckKey = (string) ($v['SERVER_CHECK_KEY'] ?? '');
        if ($this->serverCheckKey !== '' && strlen($this->serverCheckKey) < 16) {
            $problems[] = 'SERVER_CHECK_KEY: at least 16 characters, or empty';
        }
        $freeAbove = (string) ($v['SHOP_FREE_SHIPPING_ABOVE'] ?? '');
        if ($freeAbove !== '' && (preg_match('/^[1-9]\d{0,7}$/D', $freeAbove) !== 1)) {
            $problems[] = 'SHOP_FREE_SHIPPING_ABOVE: must be a whole number of rupees (1 to 99999999), or empty';
            $freeAbove = '';
        }
        $this->shopFreeShippingAbove = $freeAbove === '' ? null : (int) $freeAbove;
        $social = [];
        foreach (['facebook', 'youtube', 'instagram', 'whatsapp'] as $network) {
            $url = (string) ($v['SHOP_SOCIAL_' . strtoupper($network)] ?? '');
            if ($url === '') {
                continue;
            }
            if (preg_match('#^https://[^\s<>"' . "'" . ']+$#D', $url) !== 1) {
                $problems[] = 'SHOP_SOCIAL_' . strtoupper($network) . ': must be an https address';
                continue;
            }
            $social[$network] = $url;
        }
        $this->shopSocial = $social;
        $google = trim((string) ($v['GOOGLE_CLIENT_ID'] ?? ''));
        if ($google !== '' && preg_match('/^[A-Za-z0-9._-]{10,200}$/D', $google) !== 1) {
            $problems[] = 'GOOGLE_CLIENT_ID: must look like 123-abc.apps.googleusercontent.com, or be empty';
            $google = '';
        }
        $this->googleClientId = $google === '' ? null : $google;
        $this->mailLogPath = (string) ($v['MAIL_LOG_PATH'] ?? $this->logDir . '/mail.log');

        // ERP
        $erpMode = (string) ($v['ERP_MODE'] ?? 'off');
        if (!in_array($erpMode, ['off', 'mock', 'http'], true)) {
            $problems[] = 'ERP_MODE: must be off, mock or http';
            $erpMode = 'off';
        }
        if ($erpMode === 'mock' && $this->isProduction) {
            $problems[] = 'ERP_MODE: "mock" serves invented products, so it needs APP_ENV=development or APP_ENV=test';
        }
        $this->erpMode = $erpMode;
        $baseUrl = isset($v['ERP_BASE_URL']) ? (string) $v['ERP_BASE_URL'] : null;
        // ERP_CATALOG_KEY opens api/catalog_*.php; ERP_API_KEY is the older name of the same key
        $apiKey = isset($v['ERP_CATALOG_KEY']) ? (string) $v['ERP_CATALOG_KEY'] : (isset($v['ERP_API_KEY']) ? (string) $v['ERP_API_KEY'] : null);
        if ($erpMode === 'http') {
            if ($baseUrl === null) {
                $problems[] = 'ERP_BASE_URL: required when ERP_MODE=http';
            } else {
                $parts = parse_url($baseUrl);
                $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
                if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
                    $problems[] = 'ERP_BASE_URL: must be a full http(s) web address';
                } elseif ($this->isProduction && $scheme !== 'https') {
                    $problems[] = 'ERP_BASE_URL: must be an https address in production, because the ERP key travels with every request';
                } elseif (isset($parts['user']) || isset($parts['pass'])) {
                    $problems[] = 'ERP_BASE_URL: must not contain a user name or password';
                }
            }
            if ($apiKey === null) {
                $problems[] = 'ERP_CATALOG_KEY: required when ERP_MODE=http';
            } elseif (strlen($apiKey) < 32) {
                $problems[] = 'ERP_CATALOG_KEY: must be at least 32 characters';
            }
        }
        $this->erpBaseUrl = $baseUrl;
        $this->erpApiKey = $apiKey;
        $warehouse = isset($v['ERP_WAREHOUSE']) ? trim((string) $v['ERP_WAREHOUSE']) : null;
        if ($warehouse !== null && ($warehouse === '' || mb_strlen($warehouse) > 120)) {
            $problems[] = 'ERP_WAREHOUSE: 1 to 120 characters';
            $warehouse = null;
        }
        $this->erpWarehouse = $warehouse;
        $rule = (string) ($v['ERP_PRICE_RULE'] ?? 'offer-else-retail');
        if (!in_array($rule, ['offer-else-retail', 'retail-only'], true)) {
            $problems[] = 'ERP_PRICE_RULE: must be offer-else-retail or retail-only';
            $rule = 'offer-else-retail';
        }
        $this->erpPriceRule = $rule;
        $tax = (string) ($v['ERP_TAX_MODE'] ?? 'exclusive');
        if (!in_array($tax, ['exclusive', 'none'], true)) {
            $problems[] = 'ERP_TAX_MODE: must be exclusive or none';
            $tax = 'exclusive';
        }
        $this->erpTaxMode = $tax;
        $this->erpTimeoutMs = self::int($v, 'ERP_TIMEOUT_MS', 8000, 500, 9000, $problems);
        $bundle = isset($v['ERP_CA_BUNDLE']) ? (string) $v['ERP_CA_BUNDLE'] : null;
        if ($bundle !== null && !is_file($bundle)) {
            $problems[] = 'ERP_CA_BUNDLE: the file does not exist';
            $bundle = null;
        }
        $this->erpCaBundle = $bundle;
        $this->erpMockFile = (string) ($v['ERP_MOCK_FILE'] ?? $root . '/tests/fixtures/mock_erp.json');
        $this->catalogCacheSeconds = self::int($v, 'CATALOG_CACHE_SECONDS', 60, 0, 3600, $problems);
        $this->catalogCacheDir = (string) ($v['CATALOG_CACHE_DIR'] ?? $root . '/cache/catalog');

        // ERP_ORDER_KEY opens api/order_create.php only. It never falls back to the catalogue key: each key opens its own endpoints.
        $orderKey = isset($v['ERP_ORDER_KEY']) ? (string) $v['ERP_ORDER_KEY'] : (isset($v['ERP_ORDER_API_KEY']) ? (string) $v['ERP_ORDER_API_KEY'] : null);
        if ($orderKey !== null && strlen($orderKey) < 32) {
            $problems[] = 'ERP_ORDER_KEY: must be at least 32 characters';
            $orderKey = null;
        }
        $this->erpOrderApiKey = $orderKey;

        // Razorpay: test keys only outside production. A live key needs APP_ENV=production AND RAZORPAY_ALLOW_LIVE=true.
        $this->smsEnabled = self::bool($v, 'SMS_ENABLED', $problems);
        // when an order needs a code sent to its delivery number (only while SMS_ENABLED=true): cod = every cash-on-delivery order, always = every order, new_number = a number not verified yet, off = never
        $orderOtp = strtolower(trim((string) ($v['ORDER_OTP'] ?? 'cod')));
        if (!in_array($orderOtp, ['cod', 'always', 'new_number', 'off'], true)) {
            $problems[] = 'ORDER_OTP: must be cod, always, new_number or off';
            $orderOtp = 'cod';
        }
        $this->orderOtp = $orderOtp;
        $this->checkoutEnabled = self::bool($v, 'CHECKOUT_ENABLED', $problems);
        $this->paymentsEnabled = self::bool($v, 'PAYMENTS_ENABLED', $problems);
        $this->demoUi = self::bool($v, 'DEMO_UI', $problems);
        if ($this->demoUi && $this->isProduction) {
            $problems[] = 'DEMO_UI: must be false (or unset) when APP_ENV=production, the preview screens show made-up data';
        }
        if ($this->paymentsEnabled && $erpMode === 'http' && $orderKey === null && !self::hasProblem($problems, 'ERP_ORDER_KEY')) {
            $problems[] = 'ERP_ORDER_KEY: required when PAYMENTS_ENABLED=true and ERP_MODE=http';
        }
        $keyId = isset($v['RAZORPAY_KEY_ID']) ? (string) $v['RAZORPAY_KEY_ID'] : null;
        $keySecret = isset($v['RAZORPAY_KEY_SECRET']) ? (string) $v['RAZORPAY_KEY_SECRET'] : null;
        if ($keyId !== null && preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,40}$/D', $keyId) !== 1) {
            $problems[] = 'RAZORPAY_KEY_ID: must look like rzp_test_... or rzp_live_...';
            $keyId = null;
        }
        if ($keyId !== null && str_starts_with($keyId, 'rzp_live_') && !($this->isProduction && self::bool($v, 'RAZORPAY_ALLOW_LIVE', $problems))) {
            $problems[] = 'RAZORPAY_KEY_ID: a live key (rzp_live_) is refused unless APP_ENV=production and RAZORPAY_ALLOW_LIVE=true';
            $keyId = null;
        }
        if ($this->paymentsEnabled && ($keyId === null || $keySecret === null) && !self::hasProblem($problems, 'RAZORPAY_KEY_ID')) {
            $problems[] = 'RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET: both required when PAYMENTS_ENABLED=true';
        }
        $this->razorpayKeyId = $keyId;
        $this->razorpayKeySecret = $keySecret;
        $rzpBase = rtrim((string) ($v['RAZORPAY_API_BASE_URL'] ?? 'https://api.razorpay.com'), '/');
        $rzpHost = strtolower((string) parse_url($rzpBase, PHP_URL_HOST));
        $rzpLocal = !$this->isProduction && parse_url($rzpBase, PHP_URL_SCHEME) === 'http' && in_array($rzpHost, ['127.0.0.1', 'localhost'], true);
        if ($rzpBase !== 'https://api.razorpay.com' && !$rzpLocal) {
            $problems[] = 'RAZORPAY_API_BASE_URL: must be https://api.razorpay.com (a local http address is allowed outside production, for a fake)';
            $rzpBase = 'https://api.razorpay.com';
        }
        $this->razorpayBaseUrl = $rzpBase;
        $this->razorpayTimeoutMs = self::int($v, 'RAZORPAY_TIMEOUT_MS', 8000, 500, 9000, $problems);

        // mobile OTP: the pepper keys the stored code hashes; log mode writes live codes to a file, so it is development/test only
        $pepper = isset($v['OTP_PEPPER']) ? (string) $v['OTP_PEPPER'] : null;
        if ($pepper !== null && strlen($pepper) < 32) {
            $problems[] = 'OTP_PEPPER: must be at least 32 characters (a long random secret)';
            $pepper = null;
        }
        if ($pepper === null && $this->isProduction && !self::hasProblem($problems, 'OTP_PEPPER')) {
            $problems[] = 'OTP_PEPPER: required in production';
        }
        $this->otpPepper = $pepper;
        $smsMode = (string) ($v['SMS_MODE'] ?? ($this->isProduction && $this->smsEnabled ? 'http' : 'log'));
        if (!in_array($smsMode, ['log', 'http'], true)) {
            $problems[] = 'SMS_MODE: must be log or http';
            $smsMode = 'log';
        }
        if ($smsMode === 'log' && $this->isProduction && $this->smsEnabled) {
            $problems[] = 'SMS_MODE: "log" writes one-time codes to a file, so it needs APP_ENV=development or APP_ENV=test';
        }
        $this->smsMode = $smsMode;
        $smsUrl = isset($v['SMS_API_URL']) ? (string) $v['SMS_API_URL'] : null;
        $this->smsUsername = isset($v['SMS_USERNAME']) ? (string) $v['SMS_USERNAME'] : null;
        $this->smsApiKey = isset($v['SMS_APIKEY']) ? (string) $v['SMS_APIKEY'] : null;
        $this->smsSenderId = isset($v['SMS_SENDER_ID']) ? (string) $v['SMS_SENDER_ID'] : null;
        $this->smsOtpTemplateId = isset($v['SMS_OTP_TEMPLATE_ID']) ? (string) $v['SMS_OTP_TEMPLATE_ID'] : null;
        $templateText = (string) ($v['SMS_OTP_TEMPLATE_TEXT'] ?? 'Your Pratyangi Fashions verification code is {#var#}. It is valid for 5 minutes.');
        if (substr_count($templateText, '{#var#}') !== 1 || mb_strlen($templateText) > 300) {
            $problems[] = 'SMS_OTP_TEMPLATE_TEXT: must contain {#var#} exactly once (it is replaced by the code) and be at most 300 characters';
            $templateText = 'Your verification code is {#var#}.';
        }
        $this->smsOtpTemplateText = $templateText;
        $this->smsHintTemplateId = isset($v['SMS_HINT_TEMPLATE_ID']) ? (string) $v['SMS_HINT_TEMPLATE_ID'] : null;
        $hintText = (string) ($v['SMS_HINT_TEMPLATE_TEXT'] ?? 'Your Pratyangi Fashions account email is {#var#}');
        if (substr_count($hintText, '{#var#}') !== 1 || mb_strlen($hintText) > 300) {
            $problems[] = 'SMS_HINT_TEMPLATE_TEXT: must contain {#var#} exactly once (it is replaced by the masked email) and be at most 300 characters';
            $hintText = 'Your account email is {#var#}';
        }
        $this->smsHintTemplateText = $hintText;
        if ($smsMode === 'http' && $this->smsEnabled) {
            foreach (['SMS_API_URL', 'SMS_USERNAME', 'SMS_APIKEY', 'SMS_SENDER_ID', 'SMS_OTP_TEMPLATE_ID'] as $name) {
                if (!isset($v[$name])) {
                    $problems[] = $name . ': required when SMS_MODE=http';
                }
            }
            if ($smsUrl !== null) {
                $parts = parse_url($smsUrl);
                $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
                $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
                // https only. A plain http address is allowed for a gateway on this machine, outside production (the tests' fake gateway).
                $local = !$this->isProduction && $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true);
                if (!is_array($parts) || $host === '' || ($scheme !== 'https' && !$local)) {
                    $problems[] = 'SMS_API_URL: must be an https web address';
                } elseif (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                    $problems[] = 'SMS_API_URL: must not contain a user name, password or fragment';
                }
            }
        }
        $this->smsApiUrl = $smsUrl;
        $this->smsLogPath = (string) ($v['SMS_LOG_PATH'] ?? $this->logDir . '/sms.log');

        if ($problems !== []) {
            throw new ConfigException("Invalid settings:\n  " . implode("\n  ", $problems));
        }
    }

    /** Loads the settings file named by PF_CONFIG, or config.local.php in the project folder. */
    public static function load(): self
    {
        $env = getenv('PF_CONFIG');
        $path = ($env !== false && $env !== '') ? $env : dirname(__DIR__) . '/config.local.php';
        if (!is_file($path)) {
            throw new ConfigException('Settings file not found. Copy config.example.php to config.local.php (above the web root) or set PF_CONFIG.');
        }
        $values = (static function (string $file): mixed {
            return require $file;
        })($path);
        if (!is_array($values)) {
            throw new ConfigException('The settings file must return an array of NAME => value.');
        }
        return new self($values);
    }

    public function dbPassword(): string
    {
        return $this->dbPass;
    }

    public function smtpPassword(): ?string
    {
        return $this->smtpPass;
    }

    public function erpApiKey(): ?string
    {
        return $this->erpApiKey;
    }

    /** The key for api/order_create.php (ERP_ORDER_KEY). Null = not set; there is no fallback to the catalogue key. */
    public function erpOrderApiKey(): ?string
    {
        return $this->erpOrderApiKey;
    }

    public function razorpayKeySecret(): ?string
    {
        return $this->razorpayKeySecret;
    }

    /** "test" or "live" from the key id prefix; null without a usable key. */
    public function razorpayMode(): ?string
    {
        return $this->razorpayKeyId === null ? null : (str_starts_with($this->razorpayKeyId, 'rzp_live_') ? 'live' : 'test');
    }

    public function smsApiKey(): ?string
    {
        return $this->smsApiKey;
    }

    /** The secret that keys the stored one-time code hashes; null = mobile sign-in is not set up. */
    public function otpPepper(): ?string
    {
        return $this->otpPepper;
    }

    /** @param list<string> $problems */
    private static function hasProblem(array $problems, string $name): bool
    {
        foreach ($problems as $problem) {
            if (str_starts_with($problem, $name . ':')) {
                return true;
            }
        }
        return false;
    }

    /** Keeps secrets out of var_dump and logs. */
    public function __debugInfo(): array
    {
        return ['appEnv' => $this->appEnv, 'erpMode' => $this->erpMode, 'mailMode' => $this->mailMode];
    }

    /**
     * Empty strings count as unset; scalars become strings (environment-style), so "30" and 30 are the same.
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value !== '') {
                $out[(string) $name] = $value;
            }
        }
        return $out;
    }

    /** @param array<string,string> $v @param list<string> $problems */
    private static function int(array $v, string $name, int $default, int $min, int $max, array &$problems): int
    {
        if (!isset($v[$name])) {
            return $default;
        }
        if (preg_match('/^\d{1,9}$/D', $v[$name]) !== 1 || (int) $v[$name] < $min || (int) $v[$name] > $max) {
            $problems[] = $name . ': must be a whole number from ' . $min . ' to ' . $max;
            return $default;
        }
        return (int) $v[$name];
    }

    /** @param array<string,string> $v @param list<string> $problems */
    /** True when SERVER_CHECK_KEY is set and $given is that key (compared in constant time). */
    public function serverCheckKeyMatches(string $given): bool
    {
        return $this->serverCheckKey !== '' && hash_equals($this->serverCheckKey, $given);
    }

    private static function bool(array $v, string $name, array &$problems): bool
    {
        if (!isset($v[$name])) {
            return false;
        }
        if (!in_array($v[$name], ['true', 'false'], true)) {
            $problems[] = $name . ': must be true or false';
            return false;
        }
        return $v[$name] === 'true';
    }

    /** @param array<string,string> $v @param list<string> $problems */
    private static function required(array $v, string $name, array &$problems): string
    {
        if (!isset($v[$name])) {
            $problems[] = $name . ': required';
            return '';
        }
        return $v[$name];
    }
}

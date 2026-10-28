<?php
declare(strict_types=1);

/**
 * Settings template. Copy to config.local.php ABOVE the web root (the project folder), or point the PF_CONFIG
 * environment variable at your own file. config.local.php is git-ignored: never commit real values, never deploy this
 * file's placeholders as real ones.
 *
 * Every value below is a placeholder. An empty string counts as "not set".
 */
return [
    // production | development | test. Missing = production (Secure cookie, no mock ERP, no log mailer).
    'APP_ENV' => 'production',

    // MySQL 8
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'REPLACE_ME',
    'DB_USER' => 'REPLACE_ME',
    'DB_PASS' => 'REPLACE_ME',

    // REQUIRED in production (start-up fails without it); development/test default to http://localhost:3000.
    // The website's address(es), comma separated, each just http(s)://host[:port]. Only these may call the API with cookies and make changes. The first is used in emailed links.
    'WEB_ORIGIN' => 'https://shop.example.com',
    'SESSION_DAYS' => '30',
    // Number of reverse proxies in front of PHP (1 behind most hosts), so rate limits see the visitor. 0 = none.
    'TRUST_PROXY' => '0',
    // error | warn | info | debug | silent
    'LOG_LEVEL' => 'info',
    // Directory for channel logs (app, error, auth, erp, orders, payment, mail, sms, cron). Default: storage/logs (created if missing).
    'LOG_DIR' => '',

    // Mail. MAIL_MODE=smtp sends through SMTP_HOST (needs app/Lib/src/*.php); with no SMTP_HOST there is no mailer
    // (reset/resend answer 503, signup reports verificationEmailSent=false). MAIL_MODE=log writes mail to MAIL_LOG_PATH
    // (default storage/logs/mail.log) and is refused when APP_ENV=production.
    // MAIL_MODE defaults to log outside production and smtp in production. Port 465 means SSL (SMTPS), 587 means STARTTLS.
    // MAIL_HOST / MAIL_PORT / MAIL_USER / MAIL_PASS are accepted as other names for SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS.
    // SMS_API_KEY and SMS_TEMPLATE_ID are accepted as other names for SMS_APIKEY and SMS_OTP_TEMPLATE_ID.
    'MAIL_MODE' => 'smtp',
    'SMTP_HOST' => '',
    'SMTP_PORT' => '587',
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
    // tls (STARTTLS, usual for port 587) | ssl (port 465) | none
    'SMTP_SECURE' => 'tls',
    'MAIL_FROM' => 'Pratyangi Fashions <no-reply@shop.example.com>',
    'SHOP_NAME' => 'Pratyangi Fashions',
    'MAIL_LOG_PATH' => '',

    // ERP. off = catalogue answers 503. http = the real ERP. mock = local fixture file, only with APP_ENV development or test.
    'ERP_MODE' => 'off',
    'ERP_BASE_URL' => '',
    // Two keys, from the ERP team: the catalogue key opens api/catalog_*.php, the order key opens api/order_create.php only. Each 32+ characters.
    // ERP_API_KEY is the older name of ERP_CATALOG_KEY and still works. The order key has no fall back to the catalogue key.
    'ERP_CATALOG_KEY' => '',
    // Needed when PAYMENTS_ENABLED=true and ERP_MODE=http.
    'ERP_ORDER_KEY' => '',
    'ERP_WAREHOUSE' => '',
    // offer-else-retail | retail-only
    'ERP_PRICE_RULE' => 'offer-else-retail',
    // exclusive | none
    'ERP_TAX_MODE' => 'exclusive',
    // 500 to 9000
    'ERP_TIMEOUT_MS' => '8000',
    // Optional: path of a CA certificate bundle for the ERP's https certificate.
    'ERP_CA_BUNDLE' => '',
    // Optional, ERP_MODE=mock only: fixture file (default tests/fixtures/mock_erp.json).
    'ERP_MOCK_FILE' => '',
    // SMS_ENABLED: false (default) = email only. Hides the Mobile OTP tabs and Add mobile, and the mobile routes answer 503 "Mobile sign-in coming soon"; first order needs a verified EMAIL.
    // Set true once the SMS template is approved: mobile sign-in, Add mobile, reset by mobile and the verified-mobile rule for a first order switch on.
    'SMS_ENABLED' => 'false',
    // Mobile OTP sign-in (PHP-only addition; apply sql/003_mobile_otp.sql first).
    // OTP_PEPPER: a long random secret (32+ characters) that keys the stored code hashes. REQUIRED in production; without it the two OTP routes answer 503.
    'OTP_PEPPER' => '',
    // log | http. log writes each code to SMS_LOG_PATH (default storage/logs/sms.log) and is refused in production. Default: log outside production, http in production.
    'SMS_MODE' => 'http',
    // http mode only, all required: the provider's https address (never taken from a request), account name, key, DLT sender id and template id.
    'SMS_API_URL' => '',
    'SMS_USERNAME' => '',
    'SMS_APIKEY' => '',
    'SMS_SENDER_ID' => '',
    'SMS_OTP_TEMPLATE_ID' => '',
    // The approved DLT template text; {#var#} is replaced by the code.
    'SMS_OTP_TEMPLATE_TEXT' => 'Your Pratyangi Fashions verification code is {#var#}. It is valid for 5 minutes.',
    'SMS_LOG_PATH' => '',
    // Online orders (stage B1; apply sql/005_orders.sql first). Both switches are OFF unless set to true.
    // PAYMENTS_ENABLED opens the payment routes; CHECKOUT_ENABLED lets customers start an order. Cash on delivery does not exist yet.
    'PAYMENTS_ENABLED' => 'false',
    'CHECKOUT_ENABLED' => 'false',
    // Razorpay TEST keys only (rzp_test_...). A live key (rzp_live_) stops start-up unless APP_ENV=production and RAZORPAY_ALLOW_LIVE=true.
    'RAZORPAY_KEY_ID' => '',
    'RAZORPAY_KEY_SECRET' => '',
    'RAZORPAY_ALLOW_LIVE' => 'false',
    // Leave empty for the real address (https://api.razorpay.com). 500 to 9000.
    'RAZORPAY_API_BASE_URL' => '',
    'RAZORPAY_TIMEOUT_MS' => '8000',
    // The shop's company details. They are printed in the footer of every page, on /contact and the policy pages, and at the bottom of the emails.
    // An empty one is simply not shown. SHOP_LEGAL_NAME empty = SHOP_NAME is used (for the copyright line too).
    'SHOP_LEGAL_NAME' => '',
    // Several lines are allowed (a new line becomes a line break).
    'SHOP_ADDRESS' => '',
    // 15 letters and digits.
    'SHOP_GSTIN' => '',
    'SHOP_PHONE' => '',
    'SHOP_SUPPORT_EMAIL' => '',
    // For example: Mon-Sat 10:00-18:00 IST
    'SHOP_HOURS' => '',
    // Orders above this many rupees ship free: the top bar of every page says so. Empty = the line is not shown. Whole rupees, for example 1999.
    'SHOP_FREE_SHIPPING_ABOVE' => '',
    // Full https addresses of the shop's pages. Only the ones set get an icon in the footer.
    'SHOP_SOCIAL_FACEBOOK' => '',
    'SHOP_SOCIAL_YOUTUBE' => '',
    'SHOP_SOCIAL_INSTAGRAM' => '',
    'SHOP_SOCIAL_WHATSAPP' => '',
    // 0 to 3600
    'CATALOG_CACHE_SECONDS' => '60',
    // Must be writable by PHP and NOT inside the web root. Empty = cache/catalog in the project folder.
    'CATALOG_CACHE_DIR' => '',
];

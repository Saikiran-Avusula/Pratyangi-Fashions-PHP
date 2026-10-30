<?php
declare(strict_types=1);

/**
 * STAGING settings for https://test.pratyangi.com (BigRock cPanel, PHP 8.3, MySQL 8, Apache).
 *
 * How to use: copy this file to  /home/<your-cpanel-user>/pratyangi-staging/config.local.php  (the folder ABOVE public/, never inside it),
 * then replace every REPLACE_ME. Placeholders only: this file holds no real secret, and the real config.local.php must never be committed,
 * e-mailed or put inside public/. Every value is a string. See docs/DEPLOY_STAGING.md.
 */
return [
    // ---- the site -------------------------------------------------------------------------------------------------------------------
    // production = Secure cookie, https only, no mock ERP, no log mailer. A test site still runs as production so it behaves like the real one.
    'APP_ENV' => 'production',
    // true = every page carries X-Robots-Tag: noindex (Google stays away from the test site).
    'STAGING' => 'true',
    // true during a live deploy: every page shows "We'll be right back" (503). MAINTENANCE_ALLOW_IP = your own IPs (comma-separated) that still see the shop.
    'MAINTENANCE' => 'false',
    'MAINTENANCE_ALLOW_IP' => '',
    // The exact address of the site: https, no slash at the end.
    'WEB_ORIGIN' => 'https://test.pratyangi.com',
    // Secret for https://test.pratyangi.com/server-check?key=<this value>. At least 16 random characters. Empty = that page is off.
    'SERVER_CHECK_KEY' => 'REPLACE_ME_with_20_or_more_random_letters_and_digits',
    'SESSION_DAYS' => '30',
    // cPanel (shared hosting) answers Apache directly: leave 0.
    'TRUST_PROXY' => '0',
    'LOG_LEVEL' => 'info',
    // Empty = storage/logs in the project folder (it must be writable). Never inside public/.
    'LOG_DIR' => '',

    // ---- database (cPanel > MySQL Databases: the names carry your cpanel user as a prefix, e.g. cpuser_pratyangi) ----------------------
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'REPLACE_ME',
    'DB_USER' => 'REPLACE_ME',
    'DB_PASS' => 'REPLACE_ME',

    // ---- one-time codes -------------------------------------------------------------------------------------------------------------
    // A NEW long random secret for this site (at least 32 characters; 48 is better). Do not reuse the one from your computer or from production.
    'OTP_PEPPER' => 'REPLACE_ME_with_a_new_random_secret_of_48_characters',
    // Codes go by e-mail only while the SMS template is not approved.
    'SMS_ENABLED' => 'false',
    // When an order needs a code sent to its delivery number (only used when SMS_ENABLED is true): cod, always, new_number or off.
    'ORDER_OTP' => 'cod',
    // Only used when SMS_ENABLED is true.
    'SMS_MODE' => 'http',
    'SMS_API_URL' => '',
    'SMS_USERNAME' => '',
    'SMS_APIKEY' => '',
    'SMS_SENDER_ID' => '',
    'SMS_OTP_TEMPLATE_ID' => '',
    'SMS_OTP_TEMPLATE_TEXT' => 'Your Pratyangi Fashions verification code is {#var#}. It is valid for 5 minutes.',
    'SMS_LOG_PATH' => '',
    // "Forgot email or mobile?" texts a masked email hint (only with SMS_ENABLED true). Empty id = no hint SMS is sent (the page still answers the same).
    'SMS_HINT_TEMPLATE_ID' => '',
    'SMS_HINT_TEMPLATE_TEXT' => 'Your Pratyangi Fashions account email is {#var#}',

    // ---- e-mail (cPanel > Email Accounts: create no-reply@pratyangi.com, then use its "Connect Devices" settings) ----------------------
    'MAIL_MODE' => 'smtp',
    'SMTP_HOST' => 'REPLACE_ME',
    'SMTP_PORT' => '587',
    'SMTP_USER' => 'REPLACE_ME',
    'SMTP_PASS' => 'REPLACE_ME',
    // tls (port 587) or ssl (port 465)
    'SMTP_SECURE' => 'tls',
    'MAIL_FROM' => 'Pratyangi Fashions <no-reply@pratyangi.com>',
    'MAIL_LOG_PATH' => '',

    // ---- ERP --------------------------------------------------------------------------------------------------------------------------
    // http = the test ERP below. If the test ERP is not ready yet, set ERP_MODE to off (the catalogue then answers "try again later").
    'ERP_MODE' => 'http',
    'ERP_BASE_URL' => 'https://test-erp.pratyangi.com',
    'ERP_CATALOG_KEY' => 'REPLACE_ME_paste_the_catalogue_key_here_32plus_chars', // at least 32 characters
    'ERP_ORDER_KEY' => 'REPLACE_ME_paste_the_order_key_here_32plus_characters', // at least 32 characters
    'ERP_WAREHOUSE' => '',
    'ERP_PRICE_RULE' => 'offer-else-retail',
    'ERP_TAX_MODE' => 'exclusive',
    'ERP_TIMEOUT_MS' => '8000',
    'ERP_CA_BUNDLE' => '',
    'ERP_MOCK_FILE' => '',

    // ---- shopping and payment: OFF on the first test round -----------------------------------------------------------------------------
    'PAYMENTS_ENABLED' => 'false',
    'CHECKOUT_ENABLED' => 'false',
    'DEMO_UI' => 'false', // never 'true' in production (the app refuses to start); 'true' on a preview site shows the made-up preview screens
    'RAZORPAY_KEY_ID' => '',
    'RAZORPAY_KEY_SECRET' => '',
    'RAZORPAY_ALLOW_LIVE' => 'false',
    'RAZORPAY_API_BASE_URL' => '',
    'RAZORPAY_TIMEOUT_MS' => '8000',

    // ---- Google sign-in: the public client id (not a secret). In Google Cloud Console add https://test.pratyangi.com as an authorised JavaScript origin.
    'GOOGLE_CLIENT_ID' => '',

    // ---- the company (printed in the footer, on /contact, on the policy pages and in the e-mails) ----------------------------------------
    'SHOP_NAME' => 'Pratyangi Fashions',
    'SHOP_LEGAL_NAME' => 'Vistaraverse E-Cart Private Limited',
    'SHOP_ADDRESS' => '#102, Sahiti Ramkuteer, Jai Hind Gandhi Road, VIP Hills, Silicon Valley, Madhapur, Hyderabad, Telangana 500081',
    'SHOP_CIN' => 'U46901AP2026PTC126193',
    'SHOP_SE_REG' => 'SEA/RAN/ALO/BN/1425883/2026',
    'SHOP_GSTIN' => '36AAMCV3017H1Z9',
    'SHOP_PHONE' => '+91 99892 33328',
    // Digits only, with the country code: makes https://wa.me/919989233328
    'SHOP_WHATSAPP' => '919989233328',
    'SHOP_SUPPORT_EMAIL' => 'info@pratyangi.com',
    // Grievance Officer on /contact. The name is empty for now (the block still shows the e-mail).
    'SHOP_GRIEVANCE_NAME' => '',
    'SHOP_GRIEVANCE_EMAIL' => 'grievance@pratyangi.com',
    'SHOP_GRIEVANCE_PHONE' => '',
    'SHOP_HOURS' => '9:00 AM - 5:00 PM, Monday to Saturday',
    'SHOP_ANNOUNCEMENTS' => 'Authentic Handlooms|Handwoven by master artisans|Help on WhatsApp',
    'SHOP_FREE_SHIPPING_ABOVE' => '',
    'SHOP_SOCIAL_FACEBOOK' => '',
    'SHOP_SOCIAL_YOUTUBE' => '',
    'SHOP_SOCIAL_INSTAGRAM' => '',
    'SHOP_SOCIAL_WHATSAPP' => '',

    // ---- catalogue cache --------------------------------------------------------------------------------------------------------------
    'CATALOG_CACHE_SECONDS' => '60',
    // Empty = cache/catalog in the project folder (it must be writable).
    'CATALOG_CACHE_DIR' => '',
];

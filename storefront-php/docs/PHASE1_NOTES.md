# Phase 1 notes: answers from the Node source (read-only)

Read from `Pratyangiwebsite-main/apps/api` (`src/config.ts`, `src/app.ts`, `src/catalog/service.ts`, `src/erp/http.ts`, `src/erp/mock.ts`, `src/validation.ts`, `src/auth/*`, `package.json`) and from the installed `node_modules` of `express-rate-limit`, `helmet`, `cors`, `express`. No `.env` file was opened.

## 1. ERP_PRICE_RULE and ERP_TAX_MODE
- `ERP_PRICE_RULE`: `offer-else-retail` (default) or `retail-only`. `offer-else-retail` = a positive `offer_price`, otherwise `retail_price`. `retail-only` = always `retail_price`.
- `ERP_TAX_MODE`: `exclusive` (default) or `none`. `exclusive` = the product's `tax_rate` is added on top of the price at checkout and a row whose tax rate cannot be read is skipped. `none` = `taxPercent` is 0 and `tax_rate` is ignored.
- Anything else is a start-up error in Node (zod enum). PHP does the same.
- Other ranges worth keeping: `ERP_TIMEOUT_MS` 500 to 9000 (default 8000), `CATALOG_CACHE_SECONDS` 0 to 3600 (default 60), `ERP_API_KEY` at least 32 characters, `SESSION_DAYS` positive integer, `TRUST_PROXY` integer 0 or more.

## 2. Rate-limit headers
- Package: `express-rate-limit` 8.7.1, every limiter uses `standardHeaders: true` and `legacyHeaders: false`. In v8 `true` means **draft-6**.
- Draft-6 sends four separate headers on every response that passes through the limiter (not one combined `RateLimit` header):
  - `RateLimit-Policy: <limit>;w=<window seconds>` (for example `10;w=3600`)
  - `RateLimit-Limit: <limit>`
  - `RateLimit-Remaining: <limit minus hits, never below 0>`
  - `RateLimit-Reset: <whole seconds until the window ends>`
- On a refused request (`429`) it also sends `Retry-After: <same reset seconds>`.
- No `X-RateLimit-*` headers. The PHP port sends exactly these.
- Node's window starts at the first hit and lasts the window length. The PHP table uses clock-aligned windows (needed for one atomic `INSERT ... ON DUPLICATE KEY UPDATE`), so the reset value is "seconds to the end of the aligned window".
- Node creates one limiter object per route, so the contract's "shared bucket" for verify+resend and forgot+reset is not really shared in Node (each route has its own counter). The contract text and the plan say shared, so PHP shares them. IPv6 clients are limited per /56 (express-rate-limit default); PHP does the same.
- Order inside a route in Node: CSRF check, then session lookup, then (for resend only) the sign-in check, then the limiter, then validation. A `401` therefore comes before a `429` on resend only.

## 3. Trailing slash and path matching
- Express 5 defaults are used (`strict routing` off, `case sensitive routing` off). So `/api/v1/auth/me/` (one trailing slash) matches, and the path matching ignores letter case. `/api/v1/auth/me//` does not match. A path that matches no route (or the wrong method on a known path) is the plain `404 {"message":"That page does not exist."}`, never `405`.
- `GET /health` at the root and under `/api/v1` both work; `HEAD` is answered for every `GET` route.
- The CSRF check runs for every unsafe method under `/api/v1`, including unknown paths, so a bad Origin on an unknown path is `403`, not `404`. A request outside `/api/v1` other than `GET /health` is `404` without a CSRF check.
- JSON body parsing runs before routing and before CSRF (Node `express.json` limit `20kb`, strict mode: only an object or array is accepted, anything else is `400`). It only reads bodies whose `Content-Type` is `application/json`. Other content types leave the body empty (counts as `{}`).
- CORS and the helmet headers run first, so preflight (`OPTIONS`, any path) and every error response carry them.

## 4. Stock
- Stock is fetched **at every catalogue refresh**, never per request: one pass of `catalog_products` (pages of 200) then `catalog_stock` (batches of 100 SKUs, with `warehouse` when `ERP_WAREHOUSE` is set), only for the SKUs that survived the price filter. Both land in one cached list.
- A SKU missing from the stock answer counts as 0 pieces. Only SKUs that were asked about are accepted from the answer.
- `availableQuantity = min(max(floor(pieces), 0), 10)`, `inStock = pieces > 0` (computed from the real count, before the cap).
- Cache: whole list kept for `CATALOG_CACHE_SECONDS`; browsers get `Cache-Control: public, max-age=<whole seconds left>`; `no-store` when under a second is left, when the copy is stale, or when the setting is 0. After an ERP failure the last copy is served for up to `cache + 600` s, a failure starts a 5 s cool-down, the whole refresh is cut off after 10 s, a hung refresh blocks new ones for at most 60 s. `ERP_MODE=off` answers `503 "The catalogue is not available yet. Please try again later."` and starts no cool-down.
- A product is dropped when its selling price is 0 or above Rs 10,00,000 (100,000,000 paise); an MRP below the price, missing or above that bound is replaced by the price. Sort is name then SKU with `localeCompare(..., "en")`.

## 5. Other facts the port relies on
- Product row (ERP): all 13 keys must be present as string or null; `sku` 1 to 60 chars (trimmed), `name` non-empty. Money regex `^\d{1,10}(\.\d{1,2})?$`; tax regex `^\d{1,3}(\.\d{1,2})?$` and at most 100. Duplicate SKUs: first wins, the rest are skipped and counted.
- The login password is **trimmed** by Node's validator (zod `.trim()` output is used), the signup password is not. PHP copies this; it only matters for a password that starts or ends with a space.
- Express `res.cookie` writes `name=value; Path=/; Expires=<GMT>; HttpOnly; [Secure; ]SameSite=Lax` with no `Max-Age`. PHP builds the header itself to match (PHP's `setcookie()` would add `Max-Age`).
- Emails: verification subject `Confirm your email for Pratyangi Fashions`, reset subject `Your Pratyangi Fashions password reset code`; texts are copied in `app/Mail/MailTemplates.php`.
- Node responses also carry a weak `ETag` and answer `304` to `If-None-Match`; PHP does not (listed as a difference in the contract note).

## 6. SMS provider request format (for the PHP-only mobile OTP, `app/Sms/HttpSmsGateway.php`)
Learned by reading only the SMS client class of the ADC project (`app/SmsService.php`; no config, env or credential file was opened, and nothing was copied from it but the shape of the request).
- One `GET` to the provider's API address with the query `username`, `apikey`, `senderid`, `mobile`, `message` and `templateid`. `mobile` is `91` + the 10 digits (no `+`). `message` is the approved DLT template text with the code put in the `{#var#}` / `{otp}` place, URL-encoded. `templateid` is the DLT template id of that text.
- The provider answers `200` even for a refusal. A JSON body with `Reports` (single quotes are possible, so they are swapped for double quotes before decoding) means the message was queued; a JSON `Error`/`error`/`ERROR` is a refusal; an empty body is a failure; plain text containing `invalid`, `not matched` or `reject` (and none of `ok`, `success`, `sent`, `reports`) is a refusal. The ADC client treats exactly these as failures; the PHP gateway copies the rules (not the code).
- The API address, account name, key, sender id and template id are not in this project: they come from the settings `SMS_API_URL`, `SMS_USERNAME`, `SMS_APIKEY`, `SMS_SENDER_ID`, `SMS_OTP_TEMPLATE_ID` (and the text from `SMS_OTP_TEMPLATE_TEXT`). The key travels in the query string, so the PHP code never logs the URL, the key, the number or the code, and its failure messages are fixed texts.
- The DLT template text and id must be the ones registered for this shop; the ADC project's are for a different sender and are not reused.

## 7. Logging and Channels
- Channel-based logging under `LOG_DIR` (default: `storage/logs`, created if missing).
- Channels: `app`, `error`, `auth`, `erp`, `orders`, `payment`, `mail`, `sms`, `cron`. One log file per channel (`<channel>.log`).
- `MAIL_LOG_PATH` and SMS log path default under `LOG_DIR` (`storage/logs/mail.log` and `storage/logs/sms.log`).
- Line format: `ISO time | level | channel | request id | message | JSON context`. Single line per entry, newlines in messages are sanitised.
- Rotation at 5 MB to `name.log.1` (keeps one old file).
- Redaction rules: Never logs passwords, tokens, API keys, cookies, or full mobile numbers (`+91******XXXX`). OTPs and email codes appear only in `mail.log` and `sms.log`, and only when `APP_ENV` is development or test.
- Every request generates a request ID, returned via `X-Request-Id` response header.
- Uncaught exceptions and 5xx responses are logged to `error.log` with the request ID and stack trace.
- ERP call results (status code, duration, failure kind) are logged to `erp.log`.
- Auth outcomes (sign-in, OTP, reset, link) are logged to `auth.log`.

## 8. Clearing the OTP limits while testing
`php tools/reset_otp_limits.php` deletes every row of `shop_verification_codes` and the `otp_*` rows of `shop_rate_limits` (so the 5 codes per mobile per day, 10 per IP per hour and 20 checks per 15 minutes start again). It refuses unless `APP_ENV` is `development` or `test`, `DB_NAME` ends in `_test` and `DB_PORT` is 3399, and prints only the two counts. The production limits in `app/Http/RateLimiter.php` are unchanged.

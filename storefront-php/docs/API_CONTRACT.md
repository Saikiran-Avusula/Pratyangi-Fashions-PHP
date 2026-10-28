# Storefront API contract (derived from the Node/Express API)

Source (read-only): `Pratyangiwebsite-main/apps/api/src` (routes in `app.ts`), the web client `apps/web/src/lib`, and the tests in `apps/api/test`. No `.env` file was opened; env names come from `config.ts`. This document only describes behaviour. It contains no code.

**Legend.** `LAUNCH` = the web app calls it today in the checkout, account or catalog flow and the shop cannot open without it. `LATER` = built and tested, but off by default (switch-controlled) or not called until a later phase. `(web)` = the web client calls it (file named); `(tests)` = covered by a test.

Money in JSON is **rupees** (decimals, e.g. `26772.9`). Inside the server and DB it is **whole paise**. Requests never carry prices.

---

## 1. Global rules

### 1.1 Base path and versioning
- Everything is under `/api/v1`. `GET /health` is also served at the root (for load balancers) and at `/api/v1/health`.
- Unknown path: `404 {"message":"That page does not exist."}`.

### 1.2 Request and response format
- JSON only. Request body limit `20kb` (webhook: `100kb`, raw bytes). Malformed JSON: `400 {"message":"The request could not be read. Please try again."}`.
- Observation (as coded, not tested): an oversized normal body is not special-cased by the error handler, so it would come back as the generic `500`. A port should answer `413`.
- Every response carries `X-Request-Id` (echoes the client's `x-request-id`, cut to 64 chars, else a new UUID). `helmet` default security headers are on; `X-Powered-By` is off.
- Logs never contain the `Cookie`, `Authorization`, `Set-Cookie` or `x-razorpay-signature` headers, nor request bodies.

### 1.3 Error body shape (all errors)
```json
{ "message": "Human text for the customer", "field": "email", "errors": { "email": "..." }, "code": "out-of-stock", "skus": ["SAR-1"], "retrySafe": true }
```
Only `message` is always present. `field` and `errors` appear on validation and some account errors. `code`, `skus`, `retrySafe` appear on checkout and payment errors.
- Validation failure: `422`, `message` = the first field's message, `field` = first field name, `errors` = one message per field (first failing rule per field). A missing body counts as `{}`.
- Generic server failure: `500 {"message":"Something went wrong on our side. Please try again in a moment."}`.
- Rate limit: `429 {"message":"Too many attempts. Please wait a few minutes and try again."}` (the webhook's 429 says `"Too many requests."`). Standard `RateLimit-*` headers are sent; legacy `X-RateLimit-*` are not.

### 1.4 Cookie
- Name `pf_session`. Value is a random 256-bit token (base64url). Only its SHA-256 is stored in `shop_sessions`.
- Flags: `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure` only when `NODE_ENV=production`, `Expires` = now + `SESSION_DAYS` (default 30). No `Domain` attribute (host-only cookie).
- Set by signup, login, google. Cleared by logout (same flags). A password reset deletes all of the customer's sessions. Signing in with Google over an unverified password account also deletes that account's sessions and its password.
- The session is looked up on every `/api/v1` request; an unknown or expired token is treated as signed out (never an error by itself). Protected routes answer `401 {"message":"Please sign in to continue."}`.

### 1.5 CSRF
- On `POST/PUT/PATCH/DELETE` under `/api/v1` the `Origin` header must exactly equal one of the `WEB_ORIGIN` values (trailing slash ignored). Otherwise `403 {"message":"This request did not come from the Pratyangi Fashions website."}`.
- A missing `Origin` is refused. This check runs before the session lookup, so it comes before `401`.
- The Razorpay webhook is mounted outside `/api/v1`'s router (before JSON parsing) and is exempt (Razorpay sends no Origin). It is authenticated by its HMAC signature instead.
- No CSRF token. Protection = Origin allow-list + `SameSite=Lax` cookie.

### 1.6 CORS
- `Access-Control-Allow-Origin` is restricted to the `WEB_ORIGIN` list (comma separated), `Access-Control-Allow-Credentials: true`. Defaults of the `cors` package otherwise (preflight answered for the usual methods and the requested headers).
- The browser must send `credentials: "include"` on every account, address, checkout, order and payment call. The catalog call sends **no** cookies (public read, no credentialed CORS needed).
- Custom request header the web adds: `Idempotency-Key` (checkout and Razorpay order start).

### 1.7 Rate limits (per IP, fixed window, in memory)
`TRUST_PROXY` must be set to the number of proxies in production, otherwise all visitors share one bucket.

| Key | Applies to | Limit | Window |
|---|---|---|---|
| `signup` | POST /auth/signup | 10 | 60 min |
| `login` | POST /auth/login | 10 | 15 min |
| `google` | POST /auth/google | 20 | 15 min |
| `verify` | POST /auth/email/verify and /auth/email/resend (shared bucket) | 20 | 15 min |
| `reset` | POST /auth/password/forgot and /auth/password/reset (shared bucket) | 5 | 15 min |
| `catalog` | GET /catalog/products | 120 | 1 min |
| `checkout` | POST /checkout | 10 | 15 min |
| `payment` | POST /payments/razorpay/order | 10 | 15 min |
| `payment` | POST /payments/razorpay/verify | 30 | 15 min |
| `webhook` | POST /payments/razorpay/webhook | 300 | 1 min |

No limit on `/auth/me`, `/auth/logout`, `/addresses*`, `/orders*`, `/payments/config`, `/health`. Password reset also has its own guard: max 5 wrong codes per code, code valid 10 min.

### 1.8 Idempotency (summary; details per route)
- `POST /checkout` and `POST /payments/razorpay/order` require header `Idempotency-Key` matching `^[A-Za-z0-9_-]{16,64}$` (the web sends a UUID; one key per checkout attempt, kept in `sessionStorage` until the order is placed or refused for good, replaced when the bag or address changes). Missing/invalid: `422 {"message":"This checkout attempt could not be identified. Please reload the page and try again."}`.
- The key is scoped to the customer: DB unique `(customer_id, idempotency_key)`.
- Same key + same bag again returns the same order (`200` instead of `201`), never a second order and never re-priced.
- Same key + different bag (compared as SKU to total quantity, repeated SKUs merged) or a key first used with the other payment method: `409 code:"key-reused"`.
- Our order number `PF-` + 12 chars from `23456789ABCDEFGHJKMNPQRSTUVWXYZ` is made once per order, is the ERP `order_no` **and** `external_order_id`, and makes ERP retries safe. A number clash is retried up to 3 times.
- `verify` and the webhook are idempotent by state (see 4.x). Webhook is idempotent by event id.

---

## 2. Endpoints

All paths are relative to `/api/v1`. "Auth" = needs the `pf_session` cookie. Every unsafe method also needs the CSRF Origin (1.5).

### 2.1 Health

#### GET /health (also `/health` at root) , LAUNCH (deploy check)
Auth: none. `200 {"ok":true}`.

### 2.2 Accounts

User object (`PublicCustomer`) used everywhere:
```json
{ "id": "1", "name": "Test Buyer 1", "email": "buyer1@example.com", "provider": "password", "emailVerified": false, "createdAt": "2026-10-23T09:00:00.000Z", "picture": "https://..." }
```
`provider` is `"password"` or `"google"` (google = account with no password hash). `picture` only when set. `id` is a string.

Validation rules (identical server and browser): email trimmed, lower-cased, max 254, shape `x@y.zz`; name 2 to 80 chars; new password 8 to 128 chars with a letter and a digit; login password just non-empty. Messages: `"Enter your email address."`, `"Enter a valid email address, like name@example.com."`, `"Password must be at least 8 characters."`, `"Password needs at least one letter."`, `"Password needs at least one number."`.

#### POST /auth/signup , LAUNCH (web: `auth/apiAuth.ts`, tests: auth.test.ts)
Auth: none. Limit `signup`.
Request: `{"name":"Test Buyer 1","email":"buyer1@example.com","password":"Saree2026"}`
Response `201`, sets cookie:
```json
{ "user": { "id": "1", "name": "Test Buyer 1", "email": "buyer1@example.com", "provider": "password", "emailVerified": false, "createdAt": "..." } } (PHP: no `verificationEmailSent`, see section 11.3)
```
PHP: sign-up sends no email code, so the Node field `verificationEmailSent` is not returned. The code is asked only for forgot password, change email (`/auth/email/link/*`) and before the first order (`POST /payments/razorpay/order` answers 403 `email-required` until the email is verified; the account page has Send code).
Errors: `422` field errors (e.g. `{"errors":{"password":"Password needs at least one letter."}}`); `409` `{"field":"email","message":"An account with this email already exists. Please sign in instead."}`, or, if that email belongs to a Google-only account, `"This email is already registered through Google. Please use Continue with Google."`; `403` CSRF; `429`.
Rules: email is unique (DB unique key), so five simultaneous signups yield exactly one `201` and four `409`.

#### POST /auth/login , LAUNCH (web, tests)
Auth: none. Limit `login`. Request `{"email","password"}`. Response `200` + cookie `{"user":{...}}`.
Errors: `401 {"message":"The email or password is incorrect."}` is **identical** for unknown email and wrong password (a decoy hash check equalises timing); `401` `"This account signs in with Google. Please use Continue with Google, or choose Forgot password to add a password."` for a Google-only account; `422`; `403`; `429`.

#### POST /auth/google , LATER (web calls it only when `NEXT_PUBLIC_GOOGLE_CLIENT_ID` is set; tests use a fake verifier)
Auth: none. Limit `google`. Request `{"credential":"<Google ID token>"}`. Response `200` + cookie `{"user":{...}}`.
Rules: token verified server-side for signature, expiry, issuer and audience = `GOOGLE_CLIENT_ID`. Match order: Google subject, then verified email (so one email is never two accounts). An existing unverified password account is taken over: its password hash is deleted and its sessions end.
Errors: `503 "Google sign-in is not set up on the server yet."` (no client id); `401 "Google sign-in could not be verified. Please try again."`; `401 "Your Google email address is not verified, so we cannot sign you in with it."`; `409 "This Google account is already linked. Please try again."` (race); `422`; `429`.

#### POST /auth/logout , LAUNCH (web, tests)
Auth: none needed (works with or without a cookie). No limit. Deletes the session row and clears the cookie. `204`, empty body. A replay of the old cookie afterwards gives `401` on protected routes.

#### GET /auth/me , LAUNCH (web: `current()` on every page load)
Auth: cookie. `200 {"user":{...}}` or `401 {"message":"Please sign in to continue."}`.

#### POST /auth/email/verify , LAUNCH (web: `verify-email/VerifyEmailView.tsx`)
Auth: none (the link token is the proof). Limit `verify`. Request `{"token":"<20 to 200 chars>"}`. Link in email: `{webUrl}/verify-email?token=...`, token valid 24 hours, single use (all of the customer's tokens are removed once used).
Response `200 {"customer":{...user object, "emailVerified": true}}` (note: key is `customer`, not `user`).
Errors: `400 {"field":"token","message":"This verification link is invalid, has expired or was already used. If your email is not verified yet, sign in and request a new link."}`; `422` for a malformed token (`"This verification link is incomplete."`).

#### POST /auth/email/resend , LAUNCH (web: `EmailVerifyNotice.tsx`)
Auth: cookie. Limit `verify`. Request `{}`.
Response `200 {"message":"We have sent a new verification link to your email."}` or, if already verified, `{"message":"Your email is already verified."}`. Replaces any earlier link.
Errors: `401`; `503 "Email verification is not set up on the server yet, so we cannot send a link right now."` (no mailer).

#### POST /auth/password/forgot , LAUNCH (web)
Auth: none. Limit `reset`. Request `{"email"}`. Response `200 {"message":"If an account matches, we have emailed a 6-digit code."}` **whether or not the email has an account** (no enumeration). Code is 6 digits, hashed, valid 10 minutes, one active code per customer.
Errors: `503 "Password reset by email is not set up on the server yet."` (no mailer); `422`; `429`.

#### POST /auth/password/reset , LAUNCH (web)
Auth: none. Limit `reset`. Request `{"email","code":"123456","password":"NewSaree2027"}`.
Response `204`. Effects: new password set, email marked verified if it was not, code deleted, **all sessions of that customer deleted**.
Errors: `400 {"field":"code","message":"That code is not correct or has expired. Please request a new one."}` (unknown email or no live code, same text); `400 "That code is not correct. Please check it and try again."` (counts an attempt); after 5 wrong attempts the code is deleted and `400 "Too many wrong codes. Please request a new one."`; `422` (`"The code is 6 digits."`, password rules).

### 2.3 Catalog

#### GET /catalog/products , LAUNCH (web: `catalogApi.ts`, loops pages of 100)
Auth: none (no cookie sent). Limit `catalog`.
Query: `category` (max 60, case-insensitive exact), `q` (max 80, case-insensitive substring of name/sku/category/fabric/colour), `limit` 1 to 100 (default 24), `cursor` (digits, max 6; it is an offset). Empty values are ignored.
Response `200`:
```json
{ "products": [ { "sku": "MOCK-SAR-001", "name": "Kanjivaram Silk Saree", "category": "Sarees", "fabric": "Silk", "color": "Maroon", "size": null, "price": 11499, "mrp": 14999, "taxPercent": 5, "imagePath": "uploads/products/mock-sar-001.jpg", "inStock": true, "availableQuantity": 10 } ], "total": 5, "nextCursor": null }
```
Exactly these 12 product keys, nothing else (tests assert it). Sorted by name then sku. `price` = positive offer price else retail price (config `ERP_PRICE_RULE`), tax **not** included; `mrp` never below `price`; `taxPercent` is added on top at checkout (`ERP_TAX_MODE`); `availableQuantity` is capped at 10; `imagePath` is only returned when it is a picture file under `uploads/products/`, else `null` (the web currently shows a placeholder image for all). Inactive and unpriced items (price <= 0 or above Rs 10,00,000) are hidden. One SKU = one entry (no grouping).
Headers: `Cache-Control: public, max-age=N` where N is the seconds left of the in-memory cache (`CATALOG_CACHE_SECONDS`, default 60); `no-store` for stale data or when the cache is 0.
Errors: `503 {"message":"The catalogue is not available yet. Please try again later."}` (ERP off/not configured), `503 "The catalogue is temporarily unavailable. Please try again in a moment."` (ERP failed and no copy younger than cache + 10 min), `422` (`"The page size cannot be more than 100."`, `"That page marker is not valid."`), `429`.
Server behaviour: one ERP refresh at a time; stale copy served for up to 10 min when the ERP fails; 5 s cool-down after a failure; 10 s timeout; a hung call blocks new calls for at most 60 s.

### 2.4 Address book (all routes need the cookie; every query is scoped to the signed-in customer)

Address object returned (DTO): 
```json
{ "id": "5", "type": "Home", "label": "Home", "isDefault": true, "name": "Priya Rao", "phone": "+91 9876543210", "alternatePhone": "+91 9123456789", "house": "12-3-45", "street": "Road No. 5, Banjara Hills", "city": "Hyderabad", "state": "Telangana", "pincode": "500034" }
```
`alternatePhone` is omitted when empty. `label` falls back to `type` when no custom label was stored.

Save body (create and update): 
```json
{ "type": "Home", "label": "", "name": "Priya Rao", "phone": "98765 43210", "alternatePhone": "", "house": "12-3-45", "street": "Road No. 5, Banjara Hills", "city": "Hyderabad", "state": "Telangana", "pincode": "500034", "isDefault": false }
```
Rules: `type` is `Home|Office|Other`; `label` max 30; `name` 2 to 80; `phone` is normalised (spaces, dashes, `+91`/`91`/`0` prefix removed) and must be 10 digits starting 6 to 9; `alternatePhone` optional same rule; `house` 1 to 120; `street` 1 to 160; `city` 2 to 60; `state` must be in the fixed list of 36 states/UTs; `pincode` `^[1-9]\d{5}$`; `isDefault` optional boolean. Max **10** addresses per customer. The first address is always default. Each mutation locks the customer row (`SELECT ... FOR UPDATE`) so races cannot break the limit or create two defaults.

| Route | Status | Notes |
|---|---|---|
| GET /addresses , LAUNCH | `200 {"addresses":[...]}` | default first, then id ascending |
| POST /addresses , LAUNCH | `201 {"address":{...}}` | `422` + `errors` map; `422 "You can save up to 10 addresses. Please delete one to add another."` |
| PUT /addresses/:id , LAUNCH | `200 {"address":{...}}` | `isDefault:true` moves the default; `false` does not clear it |
| DELETE /addresses/:id , LAUNCH | `204` | if the default is deleted, the newest remaining address becomes default |
| POST /addresses/:id/default , LAUNCH | `200 {"addresses":[...]}` | whole list returned |

Errors for all: `401`; `403` (CSRF on unsafe); `404 {"message":"We could not find that address. It may have been deleted."}` for a non-numeric id (`^\d{1,9}$`), a missing id, or another customer's id.

### 2.5 Checkout (cash on delivery) and orders

Order DTO (`toOrderDto`), returned by checkout, order detail and the payment routes:
```json
{ "orderNo": "PF-7K3M9QXT42AB", "status": "placed", "paymentMethod": "COD", "paid": false, "paidAt": null,
  "itemsTotal": 25498, "tax": 1274.9, "total": 26772.9,
  "items": [ { "sku": "SAR-1", "name": "Silk Saree", "quantity": 2, "unitPrice": 11499.5, "lineTotal": 22999 }, { "sku": "SAR-2", "name": "Cotton Saree", "quantity": 1, "unitPrice": 2499, "lineTotal": 2499 } ],
  "shipTo": { "name": "Priya Rao", "phone": "9876543210", "address": "12-3-45, Road No. 5, Hyderabad, Telangana - 500034" },
  "createdAt": "2026-10-23T09:00:00.000Z", "placedAt": "2026-10-23T09:00:01.000Z" }
```
`status` values: `placed`, `confirming` (saved, ERP has not confirmed), `failed`, `awaiting-payment`, `paid-confirming`, `paid-unfulfilled`. No ERP ids, attempts, failure codes, customer id or idempotency key ever appear (tests assert this). `shipTo.address` = `house, street, city, state - pincode`.

#### POST /checkout , LAUNCH (web: `orders.ts placeOrder`; tests: checkout.test.ts)
Auth: cookie. Limit `checkout`. Headers: `Content-Type: application/json`, `Idempotency-Key`.
Request:
```json
{ "addressId": "1", "paymentMethod": "COD", "lines": [ { "sku": "SAR-1", "quantity": 2 }, { "sku": "SAR-2", "quantity": 1 } ] }
```
`addressId` number or numeric string (1 to 999,999,999) and must be this customer's saved address; `paymentMethod` must be the literal `"COD"` (`"Only cash on delivery is available right now."`); `lines` 1 to 20 entries, `quantity` 1 to 10 each (merged SKUs may not exceed 10 per SKU). Extra fields such as `price`, `total` are ignored (a test sends `price:1` and the order is still priced from the ERP).
Response `201` new order, `200` replay of the same key. `Cache-Control: no-store`. Body `{"order":{...DTO...}}`.
Flow: price every line from the ERP now (offer-else-retail, plus tax per line: `floor((unit x qty x rateBp + 5000) / 10000)` paise, line by line, summed) to save the order as `PLACING` with copied address to ask the ERP (3.1) to record `PLACED`, `FAILED` or `UNCONFIRMED`. Max order total 2,000,000,000 paise.
Examples from tests: 2 x 11499.50 + 1 x 2499.00 at 5% gives `itemsTotal 25498`, `tax 1274.9`, `total 26772.9`.

Replays by status of the stored order: `PLACED` returns it (ERP not called); `PLACING`/`UNCONFIRMED` re-sent to the ERP with the same order number and stored prices; `FAILED` returns the same refusal again (a new key is needed).

Errors (body always has `message`; checkout problems add `code`):
| Status | `code` | When | Extra |
|---|---|---|---|
| 503 | `checkout-disabled` | `CHECKOUT_ENABLED` is not `true` (nothing validated or saved) | |
| 401 | | no cookie | |
| 403 | | bad Origin | |
| 422 | | bad/missing Idempotency-Key; bad body (with `errors`); `invalid` order errors (address not found `"Choose a delivery address from your saved addresses."`, empty bag, too large `"This order is too large to place online. Please contact the shop."`, ERP refused for another reason `"The order could not be placed. Please check the details and try again."`) | `code:"invalid"` on order errors |
| 409 | `unknown-sku` | an item is no longer sold | `skus` |
| 409 | `price-unavailable` | an item has no usable price | `skus` |
| 409 | `out-of-stock` | ERP refused for stock | `skus` |
| 409 | `key-reused` | same key, different bag or other payment method | |
| 503 | `erp-unavailable` | ERP could not be asked / reached (nothing ordered) | `retrySafe:true` |
| 503 | `outcome-unknown` | request sent but answer lost; order saved as `UNCONFIRMED` | `retrySafe:true` |
| 429 | | rate limit | |
Examples from tests: out of stock `409 {"code":"out-of-stock","skus":["SAR-1"],"message":"Sorry, some items are no longer available in the quantity you asked for."}`; ERP down `503 {"code":"erp-unavailable","retrySafe":true}` then the same key later gives `200` with `status:"placed"` (completed, not duplicated).

#### GET /orders/:orderNo , LAUNCH (web: `fetchOrder`, `order/OrderView.tsx`)
Auth: cookie. `:orderNo` must match `^PF-[A-Z0-9]{12}$` (so lower case or `../../etc/passwd` is `404`). Response `200 {"order":{...DTO...}}`, `Cache-Control: no-store`. `404 {"message":"We could not find that order."}` for no such order **or someone else's order**. `401` without cookie. A not-yet-confirmed order reads `status:"confirming"`.

#### GET /orders , LAUNCH (web: `fetchOrderList`, `RealOrdersList.tsx`)
Auth: cookie. Query: `limit` 1 to 20 (default 10), `cursor` (opaque, from `nextCursor`). Newest first, keyset paging on `(created_at DESC, id DESC)`. Cursor = base64url of `ISO-time_id`, strictly validated; a cursor made from another customer's order reveals nothing (the customer id always comes from the session). `FAILED` and `PAYMENT_PENDING` orders are not listed. `Cache-Control: no-store`.
Response `200`:
```json
{ "orders": [ { "orderNo": "PF-7K3M9QXT42AB", "customerStatus": "shipped", "total": 1050, "itemCount": 3, "itemNames": ["Silk Saree", "Cotton Saree"], "createdAt": "2026-10-23T09:00:00.000Z", "courier": "Delhivery", "awb": "AWB123" } ], "nextCursor": null }
```
`courier` and `awb` are omitted when empty (a test asserts the exact key set for a bare order). Empty: `{"orders":[],"nextCursor":null}`. `customerStatus` is one of `confirming, placed, payment_received_confirming, payment_received_not_confirmed, shipped, delivered, cancelled, returned`; mapping: `PLACING/UNCONFIRMED` to `confirming`; `PAID_PLACING` to `payment_received_confirming`; `PAID_NOT_FULFILLED` to `payment_received_not_confirmed`; `PLACED` with ERP word `CANCELLED` to `cancelled`, `RETURN_IN_TRANSIT/RETURNED` to `returned`, `DELIVERED` to `delivered`, `DISPATCHED` to `shipped`, anything else to `placed`. (Without the status sync (section 5) every placed order stays `placed`.)
Errors: `422` (`"The page size cannot be more than 20."`, `"That page marker is not valid."`), `401`.

### 2.6 Online payment with Razorpay

All `/payments/*` responses carry `Cache-Control: no-store`, errors included.

#### GET /payments/config , LAUNCH (web: `fetchPaymentConfig`, called by checkout; must exist so the page falls back to COD)
Auth: none. `200 {"enabled":false,"mode":null}` when off; `{"enabled":true,"mode":"test"}` or `"live"` (taken from the key id prefix) when on. The key id is **not** returned here. On any failure the web treats payments as unavailable and shows COD only.

#### POST /payments/razorpay/order , LAUNCH (build phase 3 or 4) (needs `PAYMENTS_ENABLED=true`; web: `startOnlineOrder`; tests: payment-routes/payment-start/payment-retry)
Auth: cookie. Limit `payment` (10/15 min). Headers: `Idempotency-Key` (required). Request: `{"addressId":"1","lines":[{"sku":"SAR-1","quantity":1}]}` (same rules as checkout, no `paymentMethod`).
Response `201` new, `200` replay: 
```json
{ "order": { "orderNo": "PF-TEST0000001", "status": "awaiting-payment", "paymentMethod": "RAZORPAY", "paid": false, "paidAt": null, "total": 26772.9, "...": "..." },
  "razorpay": { "keyId": "rzp_test_FakeKeyId123", "orderId": "order_AbC123xYz789Q", "amountPaise": 2677290, "currency": "INR" } }
```
`razorpay` is `null` when the order is already past `PAYMENT_PENDING` (replay of a paid order). Nothing reaches the ERP and no stock is reserved at this step. A soft stock pre-check (ERP stock call) can answer `409 out-of-stock`; if the stock call fails it is skipped.
Rules: the same key returns the same local order and the same Razorpay order. The saved Razorpay order is handed out again only if: made with the current key (last 6 chars of key id stored as `razorpay_key_tag`), Razorpay still shows it `created`/`attempted` with our amount and `INR`, it is under 30 minutes old, and the freshly priced bag still equals the stored total; otherwise a new Razorpay order is swapped in on the same local order (one conditional update). A Razorpay order already `paid` is never replaced: `409 code:"payment-received"`.
Errors: `503 code:"payments-disabled"` (off); `422` key/body; `409` `key-reused`, `out-of-stock`, `unknown-sku`, `price-unavailable`; `409 payment-received` (`"We have received your payment and are confirming your order. Please do not pay again."`); `503 provider-unavailable` with `retrySafe:true` (`"We could not start the payment right now. Nothing has been charged. Please try again in a moment."`); `503` `erp-unavailable`; `401`, `403`, `429`.

#### POST /payments/razorpay/verify , LAUNCH (build phase 3 or 4) (web: `verifyPayment`/`finishPayment`)
Auth: cookie. Limit `payment` (30/15 min). Body is **strict** (any extra key is `422`):
```json
{ "razorpay_order_id": "order_AbC123xYz789Q", "razorpay_payment_id": "pay_AbC123xYz789Q", "razorpay_signature": "<64 lowercase hex>" }
```
Formats: `^order_[A-Za-z0-9]{10,30}$`, `^pay_[A-Za-z0-9]{10,30}$`, `^[a-f0-9]{64}$`.
Flow: find the order by Razorpay order id **for this customer** (else `404`). If already paid (`PAID_PLACING/PLACED/PAID_NOT_FULFILLED`), no Razorpay call; the ERP leg is retried if unfinished. Otherwise check HMAC-SHA256(`order_id|payment_id`, key secret) in constant time, then fetch the payment from Razorpay and compare order id, amount and `INR`; only status `captured` counts. A single conditional update moves `PAYMENT_PENDING` to `PAID_PLACING` (one winner); only the winner calls the ERP (3.1, `payment_status: "PAID"`).
Response: `200 {"order":{...},"outcome":"placed"}`; `outcome` is `placed | confirming | not-fulfilled | pending`. `pending` (Razorpay `authorized`, not captured; this server never captures) is `202`. Examples: `{"outcome":"placed","order":{"status":"placed","paymentMethod":"RAZORPAY","paid":true}}`; `{"outcome":"not-fulfilled","order":{"status":"paid-unfulfilled","paid":true}}`; `{"outcome":"pending","order":{"status":"awaiting-payment"}}`.
Errors: `400 code:"payment-signature"` (`"We could not verify this payment. Please try again."`); `409 code:"payment-mismatch"` (message contains the order number, e.g. `"We could not confirm this payment. If money was deducted, contact us with order number PF-TEST0000001."`); `404` unknown or foreign order; `503 provider-unavailable retrySafe:true` (Razorpay unreachable: keep the three values and repeat verify, never pay again); `503 payments-disabled`; `422`; `401`; `403`; `429`.

#### POST /payments/razorpay/webhook , LAUNCH (build phase 3 or 4) (Razorpay server to server; tests: payment-webhook.pure.test.ts)
Mounted at `/api/v1/payments/razorpay/webhook` **before** the JSON parser. When payments are off: `404`.
Auth: none, no cookie, no Origin. Headers required: `x-razorpay-signature` = hex HMAC-SHA256 of the **raw request bytes** with `RAZORPAY_WEBHOOK_SECRET`; `x-razorpay-event-id` matching `^[A-Za-z0-9_.-]{1,64}$`. Content type must be `application/json`. Limit 300/min. Body max 100kb (`413`).
Order of checks: rate limit, raw body, signature (before any DB access), event id, JSON parse.
Errors: `400 {"message":"The request could not be verified."}` (bad/missing signature or wrong content type); `400 "The request could not be read."` (bad event id); `413`; `5xx` for transient failures (DB down) so Razorpay redelivers.
Success is always `200 {"ok":true,"outcome":"<word>"}` with outcomes: `duplicate`, `failed`, `ignored`, `unreadable`, `mismatch`, `pending`, `confirming`, `placed`, `not-fulfilled`. A payment that cannot be accepted (mismatch) is also `200` so Razorpay does not retry forever. The ERP leg runs **after** the response is sent.
Events handled: `payment.captured` and `order.paid` settle the order (same checks as verify, no second Razorpay call); `payment.failed` is recorded only; anything else is recorded as `ignored`. Event example (fields read; the rest is ignored):
```json
{ "event": "payment.captured", "payload": { "payment": { "entity": { "id": "pay_AbC123xYz789Q", "order_id": "order_AbC123xYz789Q", "amount": 2677290, "currency": "INR", "status": "captured" } } } }
```
Idempotency by `shop_payment_events.event_id` (unique): a processed event is answered `duplicate` with no work; one recorded but never finished (ERP was down; unknown order; unreadable body) is processed again when the same id arrives.

---

## 3. Outbound calls

### 3.1 ERP (server to server only; browser never calls it). `ERP_MODE=off|mock|http`
`http` mode: base URL `ERP_BASE_URL` (https required in production), `Authorization: Bearer <key>`, redirects refused, timeout `ERP_TIMEOUT_MS` (default 8000), response cap 8,000,000 chars, `accept: application/json`. The ERP is PHP. Money arrives as exact strings (`"1299.50"`) and is converted to paise with no floats; tax rate `"18.00"` to 1800 bp.

| Call | Key | Used for | Phase |
|---|---|---|---|
| `GET api/catalog_products.php?limit=200&after_id=<n>` | `ERP_CATALOG_KEY` | product list, follows `next_cursor` (max 200 pages; cursor must advance) | LAUNCH |
| `POST api/catalog_stock.php` `{"skus":[...],"warehouse":"..."}` | `ERP_CATALOG_KEY` | available pieces, batches of 100 | LAUNCH |
| `POST api/order_create.php` | `ERP_ORDER_KEY` (only this endpoint; no fall back to the catalogue key) | create order + reserve stock | LAUNCH (COD) |
| `GET api/order_status.php?external_order_id=PF-...` | `ERP_STATUS_API_KEY` (never defaulted; must differ from both) | one order's state | LATER |
| `GET api/order_status.php?since=<ISO +00:00>&limit=100&cursor=` | `ERP_STATUS_API_KEY` | feed of changes | LATER |

Product row from the ERP: `sku, name, category, fabric, pattern_name, design_model, color, size, mrp, retail_price, offer_price, tax_rate, image_path` (strings or null). `wholesale_price`, dealer price and cost are never read. A row with an unreadable price (or unreadable tax when tax is on) is skipped and counted, not fatal. Envelope: `{"ok":true,"products":[...],"next_cursor":"123"|null}`; stock: `{"ok":true,"stock":[{"sku":"X","available_pieces":3}]}`.

`POST api/order_create.php` payload:
```json
{ "source": "WEBSITE", "order_no": "PF-7K3M9QXT42AB", "external_order_id": "PF-7K3M9QXT42AB", "customer_name": "Priya Rao", "customer_mobile": "9876543210",
  "shipping_address": "12-3-45, Road No. 5, Hyderabad, Telangana - 500034",
  "items": [ { "sku": "SAR-1", "quantity": 2 } ], "order_amount": "26772.90", "payment_status": "COD", "notes": "Website order", "warehouse": "Main" }
```
`payment_status` is `COD` (checkout), `PAID` (verified Razorpay payment) or `PENDING` (supported by the order service, unused by routes). `warehouse` only when `ERP_WAREHOUSE` is set (the ERP requires it when several warehouses are enabled). Local limits before sending: order_no `^[A-Za-z0-9._-]{1,60}$`, 1 to 50 items, quantity 1 to 100.
Answers: `201 {"ok":true,"order_id":12,"order_no":"...","status":"ALLOCATED"}` = created; `200 {"ok":true,"duplicate":true,"order":{"id":12,"order_no":"...","status":"..."}}` = already existed (success). `422/400 {"error":"..."}` is classified by text only (the wording never reaches customers): "insufficient sellable stock" = `out-of-stock`, "unknown sku" = `unknown-sku`, "product is missing/inactive" = `product-unavailable`, "reserved by another order" = `retryable`, "multiple fulfilment warehouses / unknown or disabled fulfilment" = `config`, else `rejected`. `401/403`, `402` (licence), `503` mean key refused/disabled; `429/5xx` unavailable. For order create any failure after the request is sent is "outcome unknown".

Status row: `external_order_id (^PF-[A-Z0-9]{12}$), order_no, status, payment_status, courier, awb, package_weight ("0.450"), dispatched_at, updated_at`; times **must** carry an offset or the row is skipped. A single-order `404` is believed only when it has the ERP's own `{"ok":false,"error":"..."}` envelope.
ERP status words: `NEW, ALLOCATED, PICKING, PICKED, PACKING, PACKED, DISPATCHED, DELIVERED, RETURN_IN_TRANSIT, RETURNED, CANCELLED` (unknown words are ignored, kept raw otherwise). Rules: ERP clock (`updated_at`) decides which report is newer; `CANCELLED`/`RETURNED` are final.

Mapping of ERP refusals to local state: COD: out-of-stock / unknown-sku / other rejection becomes `FAILED` (final); `erp-unavailable` leaves `PLACING` (safe to resend); lost answer becomes `UNCONFIRMED` (resend with same number). Paid: a stock/SKU/inactive refusal, or any `422`, becomes `PAID_NOT_FULFILLED` (manual refund, logged `payment.refund_needed`); `401`/`402`/`503`/missing order key stay `PAID_PLACING` and are logged `payment.erp_config_error`; any other refusal or outage stays `PAID_PLACING` and is retried (never failed). See 11.6.

### 3.2 Razorpay (server to server; plain `fetch`, no SDK)
Base `RAZORPAY_API_BASE_URL` (exactly `https://api.razorpay.com` in production), `Authorization: Basic base64(keyId:keySecret)`, redirects refused, timeout `RAZORPAY_TIMEOUT_MS`.
| Call | Body / use | Phase |
|---|---|---|
| `POST v1/orders` | `{"amount":2677290,"currency":"INR","receipt":"PF-...","notes":{"order_no":"PF-..."}}`; answer's amount and currency must match or it is refused. Extra capture setting `RAZORPAY_ORDER_EXTRA` is intentionally empty (shop owner must confirm Razorpay auto-capture; without it payments stay `authorized` and orders stay `awaiting-payment`) | LAUNCH (build phase 3 or 4) |
| `GET v1/orders/{order_id}` | reuse check: status `created`/`attempted`/`paid` | LAUNCH (build phase 3 or 4) |
| `GET v1/payments/{payment_id}` | amount, currency, order_id, status `created/authorized/captured/refunded/failed` | LAUNCH (build phase 3 or 4) |
Signature checks (both constant-time hex HMAC-SHA256): checkout = `order_id|payment_id` with key secret; webhook = raw body with webhook secret. Live keys (`rzp_live_`) are refused unless `NODE_ENV=production` and `RAZORPAY_ALLOW_LIVE=true`. Web side (`razorpay.ts`) loads Razorpay's checkout script in the browser; card and UPI data never touch this site.

### 3.3 Email (SMTP via `SMTP_URL`) , LAUNCH for verify/reset; order confirmation email: LAUNCH (build phase 3 or 4)
- Verification link email, password-reset code email, "order confirmed" email (HTML + text receipt, subject built by `receipt.ts`).
- No `SMTP_URL`: development prints to the console; production has **no mailer**, so reset/resend routes answer `503` and signup reports `verificationEmailSent:false`.
- Order confirmation: sent at most once per order, when it becomes `PLACED` by any path (COD, verify, webhook, recovery, sync). One conditional update on `confirmation_email_sent_at` claims it; a failed send is not re-claimed (a missing email is preferred over a duplicate). Never awaited and never fails the order. Recipient comes from the customer record.

### 3.4 Google
`google-auth-library` `verifyIdToken` with `GOOGLE_CLIENT_ID` audience. Public client id only, no secret. LATER.

---

## 4. Order state machine (`shop_orders.status`)
`PLACING` (saved, ERP not answered) to `PLACED` | `FAILED` | `UNCONFIRMED` (answer lost; resend same number).
Online: `PAYMENT_PENDING` (never expires, never deleted) to `PAID_PLACING` (single conditional update) to `PLACED` | `PAID_NOT_FULFILLED`.
Money columns are paise: `items_total_paise`, `tax_paise` (default 0), `total_paise` (= items + tax; delivery charges and coupons are **not** part of the total yet). Delivery details are copied onto the order, so editing or deleting an address never changes a placed order.

---

## 5. Background jobs (run inside the API process; order status sync: LATER, off by default; payment recovery: LAUNCH (build phase 3 or 4))

| Job | Switch | Interval | What it does |
|---|---|---|---|
| Order status sync (`orders/sync.ts`) | `ORDER_SYNC_ENABLED=true` and `ERP_MODE=http` and `ERP_STATUS_API_KEY` | `ORDER_SYNC_INTERVAL_SECONDS` (15 to 3600, default 60); after a failure the wait doubles to a 15 min cap with jitter | Three passes under DB leases (`shop_sync_state`, 120 s lease, so several API processes never duplicate work): **settle** (orders stuck in `PLACING`/`UNCONFIRMED` older than 2 min: ask the ERP first; found becomes `PLACED`; not found and `PLACING` becomes `FAILED`; not found and `UNCONFIRMED` under 60 min is re-sent with the same number, 60 min or older becomes `FAILED`; max 50, a lookup failure changes nothing), **poll** (ERP change feed since a saved high-water mark with a 2 min overlap, `ORDER_SYNC_PAGE_SIZE` 1 to 100, mark never moves back or past now), **reconcile** (up to 50 `PLACED` orders asked individually, never-checked or unchecked for 6 h; `CANCELLED`/`RETURNED` skipped). Copies `erp_status, courier, awb, shipped_at, delivered_at, cancelled_at` etc. Three bad answers in a row end a run. |
| Payment recovery (`payments/recovery.ts`) , LAUNCH (build phase 3 or 4) | runs whenever `PAYMENTS_ENABLED=true` | every 60 s | Finishes the ERP leg of `PAID_PLACING` orders paid at least 2 min ago, 25 per pass; same order number so the ERP returns the existing order; an ERP outage ends the pass; an order stuck over 1 hour is logged as an error every pass; never failed or expired. |
| Session cleanup | | | `purgeExpired()` exists but nothing in `server.ts` schedules it (a cleanup needs to be added in the port). |
Both jobs stop first on `SIGINT`/`SIGTERM` (5 s wait), then the HTTP server closes and the DB disconnects. At start-up the server does `customer.count()` and exits if the DB is unreachable or unmigrated.

---

## 6. Environment variable names (names only)

Server (`apps/api`): `NODE_ENV`, `PORT`, `LOG_LEVEL`, `DATABASE_URL`, `WEB_ORIGIN`, `SESSION_DAYS`, `GOOGLE_CLIENT_ID`, `SMTP_URL`, `MAIL_FROM`, `SHOP_NAME`, `TRUST_PROXY`, `ERP_MODE`, `ERP_BASE_URL`, `ERP_API_KEY`, `ERP_ORDER_API_KEY`, `ERP_STATUS_API_KEY`, `ERP_WAREHOUSE`, `ERP_PRICE_RULE`, `ERP_TAX_MODE`, `ERP_TIMEOUT_MS`, `CATALOG_CACHE_SECONDS`, `CHECKOUT_ENABLED`, `ORDER_SYNC_ENABLED`, `ORDER_SYNC_INTERVAL_SECONDS`, `ORDER_SYNC_PAGE_SIZE`, `PAYMENTS_ENABLED`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `RAZORPAY_ALLOW_LIVE`, `RAZORPAY_API_BASE_URL`, `RAZORPAY_TIMEOUT_MS`.
Web (build time): `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_AUTH_DEMO`, `NEXT_PUBLIC_GOOGLE_CLIENT_ID`.
Tests: `.env.test` (DB name must end `_test`).

| Group | Needed for launch | Later |
|---|---|---|
| Core | `NODE_ENV`, `PORT`, `DATABASE_URL`, `WEB_ORIGIN`, `SESSION_DAYS`, `TRUST_PROXY`, `LOG_LEVEL` | |
| Mail | `SMTP_URL`, `MAIL_FROM`, `SHOP_NAME` | |
| ERP | `ERP_MODE=http`, `ERP_BASE_URL`, `ERP_CATALOG_KEY` (older name `ERP_API_KEY`), `ERP_ORDER_KEY` (older name `ERP_ORDER_API_KEY`), `ERP_WAREHOUSE`, `ERP_PRICE_RULE`, `ERP_TAX_MODE`, `ERP_TIMEOUT_MS`, `CATALOG_CACHE_SECONDS` | `ERP_STATUS_API_KEY` |
| Switches | `CHECKOUT_ENABLED`, `PAYMENTS_ENABLED` (build phase 3 or 4) | `ORDER_SYNC_ENABLED`, `ORDER_SYNC_INTERVAL_SECONDS`, `ORDER_SYNC_PAGE_SIZE` |
| Google | | `GOOGLE_CLIENT_ID`, `NEXT_PUBLIC_GOOGLE_CLIENT_ID` |
| Razorpay | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `RAZORPAY_ALLOW_LIVE`, `RAZORPAY_API_BASE_URL`, `RAZORPAY_TIMEOUT_MS` (build phase 3 or 4) | |
| Web | `NEXT_PUBLIC_API_URL` | `NEXT_PUBLIC_AUTH_DEMO` is demo/test only |

Start-up validation rules to keep: `ERP_MODE=mock` only if `NODE_ENV` is explicitly `development` or `test`, and createApp refuses a mock ERP in production; `ERP_MODE=http` needs `ERP_BASE_URL` (https in production) and `ERP_API_KEY` (32+ chars); `ERP_STATUS_API_KEY` (32+) must differ from the other two; `ORDER_SYNC_ENABLED` needs `ERP_MODE=http` + status key; `PAYMENTS_ENABLED` needs the three Razorpay values (key id `^rzp_(test|live)_[A-Za-z0-9]{6,}$`, key secret 16+, webhook secret 8+, the two secrets different), `CHECKOUT_ENABLED=true` and `ERP_MODE` not `off`. `WEB_ORIGIN`'s first value is used in emailed links. Boolean switches are on only when exactly `"true"`. Empty strings count as unset.

---

## 7. Database tables (MySQL 8, all prefixed `shop_`, no product or stock tables: the ERP owns those)

| Table | Purpose | Key columns / constraints | Phase |
|---|---|---|---|
| `shop_customers` | accounts | `id`, `name` (80), `email` (254, unique `uq_shop_customers_email`, stored lower-case), `password_hash` (null for Google-only), `email_verified_at`, `picture_url` (500), `created_at`, `updated_at` | LAUNCH |
| `shop_customer_identities` | linked sign-in methods | `customer_id` (cascade), `provider` (20), `provider_user_id` (191), unique `(provider, provider_user_id)` | LATER (Google) |
| `shop_sessions` | server sessions | `customer_id` (cascade), `token_hash` char(64) unique, `expires_at` (indexed), `user_agent` (255) | LAUNCH |
| `shop_customer_addresses` | address book | `customer_id` (cascade), `type` (10, default Home), `label` (30), `recipient_name` (80), `phone` char(10), `alternate_phone` char(10) null, `house` (120), `street` (160), `city` (60), `state` (60), `pincode` char(6), `is_default` | LAUNCH |
| `shop_password_resets` | reset codes | `customer_id`, `code_hash` char(64), `attempts` default 0, `expires_at` | LAUNCH |
| `shop_email_verifications` | verify links | `customer_id`, `token_hash` char(64) unique, `expires_at` | LAUNCH |
| `shop_orders` | the website's record of each order | `order_no` (40, unique), `customer_id` (restrict), `idempotency_key` (64), unique `(customer_id, idempotency_key)`, `status` enum (PLACING, UNCONFIRMED, PLACED, FAILED, PAYMENT_PENDING, PAID_PLACING, PAID_NOT_FULFILLED), `payment_method` (10, default COD), `items_total_paise`, `tax_paise`, `total_paise`, `ship_name/phone/house/street/city/state/pincode` (copied), `erp_order_id`, `erp_status`, `failure_code` (20), `failed_skus` (JSON), `attempts`, `placed_at`, `confirmation_email_sent_at`; indexes on `(customer_id, created_at)`, `status`, `(status, created_at)`, `(status, status_checked_at)` | LAUNCH |
| `shop_orders` sync columns | filled by the status sync | `erp_order_no` (40), `erp_payment_status` (20), `courier` (80), `awb` (80), `erp_updated_at`, `shipped_at`, `delivered_at`, `cancelled_at`, `status_changed_at`, `status_checked_at` | LATER (columns can exist from day one, empty) |
| `shop_orders` payment columns | Razorpay | `payment_provider` (20), `razorpay_order_id` (40, unique), `payment_ref` (40, **unique**: one payment can never settle two orders), `paid_amount_paise`, `paid_at`, `razorpay_key_tag` (12), `razorpay_order_at` | LAUNCH (build phase 3 or 4) |
| `shop_order_items` | order lines | `order_id` (cascade), `sku` (60), `name` (180), `quantity`, `unit_price_paise` (name and price as the customer saw them) | LAUNCH |
| `shop_sync_state` | job leases + high-water mark | `name` (PK, 40), `high_water`, `lease_owner` (64), `lease_until` | LATER |
| `shop_payment_events` | webhook de-duplication | `event_id` (64, unique), `event` (40), `razorpay_order_id`, `razorpay_payment_id`, `outcome` (20), `received_at`, `processed_at` null until finished; no bodies stored | LAUNCH (build phase 3 or 4) |

Migrations in order: `20261017000000_init`, `20261022000000_shop_orders`, `20261023000000_order_status_sync`, `20261024000000_razorpay_payments`, `20261025000000_order_confirmation_email`, `20261026000000_razorpay_order_key_tag`. Concurrency idioms to reproduce: row lock on the customer for address changes; unique keys decide races (signup email, order key, payment ref); conditional `UPDATE ... WHERE status = ...` for every order state move.

---

## 8. Web calls actually made (`apps/web/src/lib`)

| File | Calls |
|---|---|
| `auth/apiAuth.ts` | `/auth/login`, `/auth/signup`, `/auth/google`, `/auth/password/forgot`, `/auth/password/reset`, `/auth/email/verify`, `/auth/email/resend`, `/auth/logout`, `/auth/me` |
| `addressBook.ts` | `GET/POST /addresses`, `PUT/DELETE /addresses/:id`, `POST /addresses/:id/default` |
| `catalogApi.ts` | `GET /catalog/products?limit=100[&cursor=]` (no credentials, 10 s timeout, up to 20 pages; any failure surfaces an error, never a partial list) |
| `orders.ts` | `POST /checkout` (30 s timeout, `Idempotency-Key`), `GET /orders/:orderNo`, `GET /orders[?cursor=]` |
| `payments.ts` | `GET /payments/config`, `POST /payments/razorpay/order`, `POST /payments/razorpay/verify` |
Client behaviour a port must keep compatible: on `401` the web says "session ended" (checkout) or treats `/auth/me` failure as signed out; `204` is accepted for logout and reset; error `code` falls back to `not-found` (404), `too-many` (429), else `error`; network loss on a POST is treated as retry-safe with the same key; `fetchOrderList` tolerates a missing `orders` array. Pending Razorpay values are kept in `sessionStorage` (`pratyangi-pending-verify`) and the checkout key under `pratyangi-checkout-attempt`.

## 9. Launch scope summary
- LAUNCH: health; signup, login, logout, me; email verify and resend; password forgot and reset; catalog list; addresses (5 routes); `POST /checkout` (COD only, behind `CHECKOUT_ENABLED`); `GET /orders`, `GET /orders/:orderNo`; `GET /payments/config` (returns `enabled:false`); ERP calls `catalog_products`, `catalog_stock`, `order_create`; `POST /payments/razorpay/order`, `/verify` and `/webhook`, the Razorpay calls and the payment recovery job (build phase 3 or 4); SMTP mail (verify, reset, order confirmation); tables for customers, sessions, addresses, resets, verifications, orders (+ order items), plus the payment columns and `shop_payment_events` (build phase 3 or 4); CSRF, CORS, cookie, rate limits and idempotency exactly as above.
- LATER: Google sign-in; ERP status sync (settle, poll, reconcile) and its columns and `shop_sync_state`; ERP status key.
- Known gaps to decide in the port: oversized-body status (`413` vs coded `500`), nothing schedules expired-session cleanup, delivery charges and coupons are not in the total, product grouping and image URLs are undecided (web shows a placeholder image), price and tax rules (`ERP_PRICE_RULE`, `ERP_TAX_MODE`) are placeholders until the shop confirms, Razorpay auto-capture setting is unconfirmed.

## 10. Deliberate differences from Node (PHP port only)
These are intended and are not parity bugs.
- **Oversized request bodies.** A JSON body over 20 KB (20,480 bytes) gets `413 {"message":"That request is too large."}`. Node would answer a coded `500` (see 1.2). The webhook's own `413` (100 KB) is unchanged.
- **Rate-limit store.** Limits are kept in the MySQL table `shop_rate_limits` (`bucket_key`, `window_start`, `hits`; see `sql/002_rate_limits.sql`), not in process memory, because PHP has no shared memory between requests. Windows are clock-aligned fixed windows (Node's start at the first hit). The `RateLimit-*` header set is the same (draft-6: `RateLimit-Policy`, `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`, plus `Retry-After` on 429). The verify+resend and forgot+reset buckets really are shared.
- **Mock ERP.** `ERP_MODE=mock` reads a local JSON fixture (`tests/fixtures/mock_erp.json`, or `ERP_MOCK_FILE`). It is accepted only when `APP_ENV` is `development` or `test`; the config loader refuses it in production. `MAIL_MODE=log` (mail written to a local file) is refused in production in the same way.
- **Settings.** PHP reads a settings file (`config.local.php`, path from `PF_CONFIG`) instead of environment variables. `APP_ENV` replaces `NODE_ENV` and defaults to `production` when missing (safer than Node's default of development).
- **Catalogue cache.** The cache is a file in `CATALOG_CACHE_DIR` instead of process memory; the semantics (TTL, 10 minute stale grace, 5 s cool-down, 60 s refresh lock, 10 s refresh limit) are the same.
- **ETag.** Node adds a weak `ETag` and may answer `304`; PHP sends no `ETag`.
- **Request id.** A client `x-request-id` is echoed only if it is printable ASCII (cut to 64 chars); otherwise a new UUID is used.

## 11. PHP-only additions
Nothing in this section exists in the Node API. It changes no Node route, key or status: an email account looks exactly as before. Added on top of section 1's rules (same `pf_session` cookie and flags, same CSRF Origin check, same CORS, same error body shape).

### 11.1 Mobile one-time-code sign-in
> Changed by 11.3: shop_verification_codes replaces shop_otp_codes, the name step and the typed email are gone, and the code hash covers channel and purpose.

Migration `sql/003_mobile_otp.sql` (apply once, after review): `shop_customers.email` becomes nullable, `shop_customers.mobile VARCHAR(16) NULL` (unique, stored as `+91XXXXXXXXXX`), `shop_customers.contact_email VARCHAR(254) NULL` (plain, unverified, not unique, never looked up), and the table `shop_otp_codes` (`id`, `mobile`, `code_hash`, `expires_at`, `attempts`, `consumed_at`, `created_at`, `ip_hash`).

**Mobile number.** 10 digits starting 6-9, with an optional `+91` or `91` in front and spaces anywhere; anything else (other digits, dashes, brackets, other countries) is refused. Stored and returned as `+91XXXXXXXXXX`.

**`POST /api/v1/auth/otp/request`** body `{ "mobile": "98765 43210" }`, no sign-in needed. Bucket `otp_ip`: 10 per client address per hour (`10;w=3600`).
- `200 { "message": "If this number can receive SMS, a 6-digit code is on its way.", "resendAfter": 45, "expiresIn": 300 }`. **The answer is the same whether or not the number has an account**: the request path never looks at `shop_customers`.
- `422` bad number: `{ message: "Enter a valid 10-digit mobile number.", field: "mobile", errors: { mobile } }`.
- `429` resend cooldown: less than 45 s since the last code for that number: `{ message: "Please wait N seconds before asking for another code." }` with `Retry-After: N`. Nothing is sent. The same for known and unknown numbers.
- `429` more than 5 codes for that number in the UTC day (the count is kept under a keyed hash of the number in `shop_rate_limits`): `{ message: "Too many codes have been requested for this number today. Please try again tomorrow." }`; or more than 10 requests from the address in the hour: the usual `Too many attempts...` text.
- `503` the SMS gateway failed: `{ message: "Could not send code, try again." }`. The new code is deleted (the customer's earlier code still works) and no account is created or changed. `503 { message: "Mobile sign-in is not set up yet. Please sign in with your email." }` when `OTP_PEPPER` is missing.

**`POST /api/v1/auth/otp/verify`** body `{ "mobile", "code", "name"?, "email"? }`. Bucket `otp_verify`: 20 per client address per 15 minutes.
- Every failure is `400 { "message": "Invalid or expired code" }`, nothing more, no cookie: unknown number, wrong code, expired, already used, five wrong tries used up, and a malformed number, code or body. A bad body is refused before the settings are looked at.
- A correct code for a number **with an account**: `200 { user }` and the `pf_session` cookie (the existing session code). The code is used up.
- A correct code for a number **without an account** and no `name`: `200 { "nameRequired": true, "message": "Tell us your name to finish creating your account." }`, no cookie, the code stays usable for the second call. With `name` (2 to 80 characters, same rule as signup): the account is created with no email and no password, the code is used up, and the answer is `201 { user }` with the cookie. A bad `name` or `email` is a `422` with the field and the code stays usable.
- `user` is the usual object plus `mobile` (only on an account that has one). For an account made this way `email` is `""`, `emailVerified` is `false` and `provider` is `"mobile"`.

**The code.** 6 digits from `random_int`. Only an HMAC-SHA256 of `mobile|code` keyed with the `OTP_PEPPER` setting is stored, compared with `hash_equals`. Valid 5 minutes, single use. A new request ends the older codes of that number. At most 5 wrong tries per code (the 5th wrong try locks the code, even for the right digits). A code is never written to the app log; the number only masked (`+91******3210`). In `SMS_MODE=log` the code and the masked number go to `storage/sms.log` (git-ignored).

**An email typed during OTP signup** goes to `contact_email` only. It is never marked verified, never used to sign in, to reset a password, or to match, block or merge with an email account (all of those read `email`, which stays empty). A visitor can still sign up by email with the same address afterwards; that is a separate account. A mobile-only account that asks for an email verification link gets `400 { message: "This account has no email address to confirm." }`.

**SMS gateway.** `SMS_MODE=log` (development and test only) or `SMS_MODE=http`. The http mode sends one `GET` to `SMS_API_URL` (https only; from the settings, never from a request; redirects are not followed) with the query `username`, `apikey`, `senderid`, `mobile` (`91` + 10 digits), `message` (`SMS_OTP_TEMPLATE_TEXT` with `{#var#}` replaced by the code) and `templateid`. A `2xx` answer counts as sent when its JSON has `Reports`; a JSON `Error`, an empty body or a non-2xx status is a failure. Settings: `SMS_MODE`, `SMS_API_URL`, `SMS_USERNAME`, `SMS_APIKEY`, `SMS_SENDER_ID`, `SMS_OTP_TEMPLATE_ID`, `SMS_OTP_TEMPLATE_TEXT`, `SMS_LOG_PATH`, `OTP_PEPPER` (32+ characters). In production the config loader refuses `SMS_MODE=log` and a missing or short `OTP_PEPPER`, and `SMS_MODE=http` needs the five http settings (names only are shown in the error).

**Rate-limit store.** The daily per-number window means rows are now kept for two days (was two hours) before the 1 % clean-up deletes them.

### 11.2 After a sign-in
The sign-in and sign-up pages go to the landing page `/` after any successful sign-in (email, mobile code, sign-up, and after email verification the button is "Continue shopping" to `/`), never to `/account` by default. `?next=` is followed only when it is exactly `/checkout` (a sign-in started from checkout); any other value (`/account`, `//evil`, a full URL) is ignored and the answer is `/`. The result is always a single-`/` same-origin path (`app/Web/SafeNext.php`). No name, email or address is asked at sign-in; addresses are asked only in `/checkout`, where a customer with no saved address sees the add-address form first. The server prints the answer into the page (`data-pf-next`); `auth.js` repeats the checks. Node's default after sign-in is `/account`.

Known trade-off (11.1): the 5-per-day count per number is spent by anyone who asks, so a third party can use up a victim's daily codes; this follows the agreed limit. An SMS answer the gateway does not recognise as "sent" (no `Reports`, no ok/success/sent/queued/submitted) counts as a failure.

### 11.3 Account flow with 6-digit codes (supersedes the name step and the typed email of 11.1, and the Node email-link and reset-code details where stated)
Migration `sql/003b_verification_codes.sql` (apply after 003; safe to run twice): table `shop_verification_codes` (`id`, `channel` email|mobile, `purpose` login|email_verify|mobile_verify|reset, `target`, `customer_id`, `code_hash`, `expires_at`, `attempts`, `consumed_at`, `created_at`, `ip_hash`) and `shop_customers.mobile_verified_at`. `shop_otp_codes` (003) is no longer written to. `contact_email` is no longer written.

**One code engine.** Every code (login, email verify, mobile verify, reset) uses the rules of 11.1: 6 digits from `random_int`; stored as HMAC-SHA256 of `channel|purpose|target|code` keyed with `OTP_PEPPER`; valid 5 minutes; single use; 5 wrong tries lock the code; a new request for the same channel, purpose and target ends the older code; 45 s resend cooldown (`429` + `Retry-After`); per target per day 5 (`otp_mobile`, new `otp_email` with the text "...for this address today..."); per address per hour `otp_ip` on request routes, `otp_verify` / `verify` on verify routes. Because the hash and every lookup cover channel, purpose and target, **a code works only for the purpose, channel and target it was made for**. Every failure to use a code is `400 { message: "Invalid or expired code" }` (with `field: "code"` where the route names one). Codes are never logged; a failed send logs the exception class, channel, purpose and a masked number.

**Email sign-up.** `POST /auth/signup` answers `201 { user }`, signed in, and sends NO email. The 6-digit code (purpose `email_verify`) is sent only when asked: `POST /auth/email/resend` (account page Send code), `/auth/email/link/request`, forgot password. An address already registered with a password: `409 { message: "An account with this email already exists.", field: "email" }`.
- `POST /auth/email/verify` `{ code }` (signed in, bucket `verify`): confirms the signed-in customer's own address, `200 { customer }`. `{ token }` (no sign-in) still works for links sent before; no new link is made.
- `POST /auth/email/resend` (signed in): sends a new code, `200 { message: "We have sent a new 6-digit code to your email.", resendAfter, expiresIn }`; `{ message: "Your email is already verified." }` when verified; `400` for an account without an email.

**Email sign-in.** No verification step at sign-in; an unverified customer signs in normally and sees "Verify your email" with Send code on `/account`. Unknown email: `404 { message: "We could not find an account with this email.", field: "email", code: "account_not_found" }` (the page links to Create account). Wrong password: `401 { message: "Incorrect password.", field: "password" }`. Limits: per client address `login` (10 / 15 min) and, new, per email address `login_email` (10 / 15 min, counted for every address, known or not, without replacing the route's RateLimit-* headers). There is no other lockout. Mobile account with a linked email but no password: `401` "This account has no password yet...".

**Mobile sign-in and sign-up, one flow.** `POST /auth/otp/request {mobile}` and `POST /auth/otp/verify {mobile, code}` as in 11.1, but a correct code for an unknown number **creates the account at once** (`201`, name `""`, no email, no password, `mobile_verified_at` set); there is no `nameRequired` answer and `name` / `email` in the body are ignored. An existing number answers `200`. `user.name` may be `""`; the header then shows the avatar (a person icon) and "Hello" with no name.

**Linking** (all need sign-in; a number or address is stored on the account only after its code was entered; one account per number or address; the account must lack one):
- `POST /auth/mobile/request {mobile}` (`otp_ip`), `POST /auth/mobile/verify {mobile, code}` (`otp_verify`) -> `200 { user }` with `mobile` and `mobileVerified: true`.
- `POST /auth/email/link/request {email}`, `POST /auth/email/link/verify {email, code}` -> `200 { user }`, the address is stored verified.
- A number or address owned by another account: `409` "This mobile number is already linked to another account." / "This email is already linked to another account." (checked at request and again at verify). The account already has one: `409`.
- `POST /auth/profile/name {name}` (signed in) sets the name (same rules as sign-up).
- `user` has `mobileVerified` (PHP-only) when it has `mobile`; `provider` is `"mobile"` for any account without a password that has a mobile number.

**Forgot password.** `POST /auth/password/forgot` `{ email }` or `{ channel: "mobile", mobile }` (bucket `reset`) and `POST /auth/password/reset` `{ email | mobile (+ channel), code, password }`. The code goes **only to the verified email or verified mobile stored on an account**; an unverified or typed address or number gets nothing. A mobile number whose account has neither a password nor an email also gets nothing (such accounts use Sign in with OTP; the page says so). The answer is identical for every target: `200 { message }` ("...emailed a 6-digit code." / "...sent a 6-digit code by SMS."), same headers, same `429` cooldown (`code_cooldown`, one request per target per 45 s, counted for every target) and same daily count (reset requests count on their own). A failing mailer or gateway also answers `200`. `503` only when the channel has no sender at all. Reset: sets the new password, **deletes every session of that account** and ends the account's other open reset codes; orders, addresses, mobile and email are untouched. The Node reset-code table `shop_password_resets` is no longer used. Behaviour change from Node: an unverified email can no longer reset a password.

**After success** the sign-in, sign-up, verification and reset pages go to `/` unless the sign-in began at `/checkout` (`?next=/checkout`, 11.2).

### 11.4 Stage B1: online orders only (differences from sections 2.5 and 2.6)
Cash on delivery is off for launch, so **there is no `POST /checkout`** in the PHP API (a request to it is the plain 404). Everything else in 2.6 is as written; what changed on purpose:
- **Routes built:** `GET /payments/config`, `POST /payments/razorpay/order`, `POST /payments/razorpay/verify`. Not built yet: the webhook, the recovery job, `GET /orders`, `GET /orders/:orderNo`, the confirmation email.
- **`CHECKOUT_ENABLED`** (default `false`) gates `POST /payments/razorpay/order`: `503 {"code":"checkout-disabled","message":"Online ordering is not open yet. Please check back soon."}` before anything is validated, saved or sent. `PAYMENTS_ENABLED` (default `false`) is checked first (`503 payments-disabled`, message `"Online payment is not open yet. Please try again later."`; Node's text mentions cash on delivery). `verify` does not look at `CHECKOUT_ENABLED`, so a payment already made can always be finished.
- **Verified mobile (PHP-only):** the order route answers `403 {"code":"mobile-required","message":"Please verify your mobile number before placing an order."}` unless the signed-in customer has a mobile number confirmed by a code. It comes after the key and body checks and before anything is saved.
- **Settings:** `CHECKOUT_ENABLED`, `PAYMENTS_ENABLED`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_API_BASE_URL` (exactly `https://api.razorpay.com`; a local `http://127.0.0.1` address is allowed outside production, for a fake), `RAZORPAY_TIMEOUT_MS` (500 to 9000), `RAZORPAY_ALLOW_LIVE`, `ERP_ORDER_KEY` (no fall back; see 11.6). A live key (`rzp_live_`) stops start-up unless `APP_ENV=production` **and** `RAZORPAY_ALLOW_LIVE=true`; the message never shows the key. `PAYMENTS_ENABLED=true` needs both key settings.
- **Order states in the database** are the online ones of section 4: `PAYMENT_PENDING`, `PAID_PLACING`, `PLACED`, `PAID_NOT_FULFILLED` (`PLACING`, `FAILED`, `UNCONFIRMED` are for the cash-on-delivery path and are never written). In the shop's words: paid and sold out at the ERP = `PAID_NOT_FULFILLED` (`paid-unfulfilled`, refund by hand, logged `payment.refund_needed`); ERP timeout, outage or any non-stock refusal = `PAID_PLACING` (`paid-confirming`, retried with the same order number). Only stock, unknown-SKU and product-inactive refusals are final.
- **Tables** (`sql/005_orders.sql`): `shop_orders` (unique `(customer_id, idempotency_key)`, unique `payment_ref`, unique current `razorpay_order_id`), `shop_order_items`, and `shop_razorpay_orders`, the history of **every** Razorpay order id issued for an order. `verify` finds the order through the history, so a payment made on a Razorpay order that was replaced later (after 30 minutes, or a key change) still matches its order.
- **Rate limits:** buckets `payment_order` (10 per 15 minutes) and `payment_verify` (30 per 15 minutes), one counter each (as in Node, where each route has its own).
- **Validation messages** for nested `lines` problems use the zod 4 default wording as best known (`Too small: expected number to be >=1`, ...); UNVERIFIED against a running Node API.
- Every `/payments/*` answer, errors included, carries `Cache-Control: no-store`.

### 11.5 Stage C3: email only until the SMS template is approved (`SMS_ENABLED`)
`SMS_ENABLED` (default `false`) switches every mobile feature. **While false:** the Mobile OTP tab and form are not printed on `/login` and `/signup` (the divider reads "or sign in with email"; no `auth-otp.js`), the forgot-password page has no mobile tab, `/account` shows a disabled "Add mobile number - Coming soon", and every mobile route answers `503 {"message":"Mobile sign-in coming soon"}` before any validation, limit or database work: `POST /auth/otp/request`, `/auth/otp/verify`, `/auth/otp/signup/request`, `/auth/otp/signup/verify`, `/auth/mobile/request`, `/auth/mobile/verify`, and `/auth/password/forgot` and `/auth/password/reset` with `channel:"mobile"`. Email sign-up, sign-in, verification codes, forgot password and reset are unchanged. Production then needs no `SMS_*` settings (only `OTP_PEPPER` for the email codes). **While true:** everything as in 11.1 to 11.4, except:
- **One channel per code.** A code goes only to the channel asked for (an email code to the mailbox, a mobile code to the phone), never both.
- **Password reset.** The email code is used when the account has a verified email. A mobile reset code is sent only when `SMS_ENABLED=true` **and** the account has no verified email (it still needs a verified mobile and a password or contact email); for any other account the answer is the same 200 and nothing is sent.
- **First order (`POST /payments/razorpay/order`).** `SMS_ENABLED=true`: a verified mobile (`403 mobile-required`). `SMS_ENABLED=false`: a verified email (`403 {"code":"email-required","message":"Please verify your email address before placing an order."}`).

### 11.6 What the ERP team confirmed (checked against the ERP client)
- **Two keys, each opens only its own endpoints:** `ERP_CATALOG_KEY` for `api/catalog_products.php` and `api/catalog_stock.php`, `ERP_ORDER_KEY` for `api/order_create.php`. `ERP_API_KEY` stays as another name for the catalogue key; the order key never falls back to it. With `PAYMENTS_ENABLED=true` and `ERP_MODE=http` start-up needs `ERP_ORDER_KEY`.
- **Catalogue (already as the ERP said):** at most 200 products a page, paged with `after_id`; stock at most 100 SKUs a call; `offer_price` null or 0 is no offer (the retail price is used); the ERP price excludes tax and `tax_rate` is added on top.
- **Pictures:** the picture address is the ERP base address + "/" + `image_path`. The catalogue API keeps returning `imagePath` as it is (only `uploads/products/...` picture files pass). Cards, the product page and the bag and checkout lines load the picture from the ERP (`ProductImage`), and only that one address is added to the page's `img-src`. A product without a picture, a failed picture or `ERP_MODE` mock/off shows the placeholder.
- **Orders:** `order_no` and `external_order_id` are always both sent (both are our order number `PF-...`); `payment_status` is `PAID` (the only one the shop sends: there is no cash-on-delivery route) or `COD`. `200` with `duplicate:true` is the same order: success. **`422` is final** (out of stock or unknown SKU, whatever the wording): the paid order becomes `PAID_NOT_FULFILLED` (refund by hand). **`401`, `402` and `503` are configuration errors**: the order stays paid and "confirming", is logged as `payment.erp_config_error`, is tried again later and is never worded as sold out; so is a missing `ERP_ORDER_KEY`. **`500`**, a timeout or a lost answer: outcome unknown, `PAID_PLACING` ("confirming"), retried with the same order number. A `400` is our own bad request: not final.
- **Never before payment:** an order is sent to the ERP only after the payment is verified as captured (a stock reservation at the ERP never expires). Starting an order, a wrong signature, a mismatched or merely authorized payment send nothing.
- **No status API yet:** My Orders will show the shop's own status only (every placed order stays `placed`; section 5, order status sync, is later).

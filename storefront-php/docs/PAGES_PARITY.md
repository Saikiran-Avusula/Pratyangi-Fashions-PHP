# Pages parity: Node web pages vs the PHP storefront

Sources read (read-only): `Pratyangiwebsite-main/apps/web/src` (`app/*`, `components/*`, `lib/*`) and the built site in `flow-test/web-out` (class names with their compiled CSS-module prefixes, page titles).
Status words: **planned** (not built yet, row filled in for a later step), **built**, **soon** (the PHP route exists and shows the "coming" page), **not possible**.
Nothing here has been seen side by side with the Node site by a person. The sign-in pages were driven in headless Chrome against a local PHP server with the API replaced by a fake `fetch` (see "How it was checked").

## Page table

| Page | Node file | PHP route | Status |
|---|---|---|---|
| Home | `app/page.tsx` | `/` | built (stage 1, see `UI_PARITY.md`) |
| Coming soon (PHP only) | none | `/soon` | built |
| Sign in | `app/login/page.tsx`, `LoginView.tsx` | `/login` | built |
| Create account | `app/signup/page.tsx`, `SignupView.tsx` | `/signup` | built |
| Forgot / reset password (one page: email, then the 6-digit code and new password) | `app/forgot-password/page.tsx`, `ForgotView.tsx` | `/forgot-password` | built |
| Separate "reset password" page | none (Node has no `/reset-password`; the reset mail carries a code, not a link) | none (404, as in Node) | not applicable |
| Verify email (the emailed link) | `app/verify-email/page.tsx`, `VerifyEmailView.tsx` | `/verify-email?token=...` | built |
| New Arrivals listing; the Shop menu and search links are the same page with `?fabric=` / `?occasion=` | `app/new-arrivals/page.tsx`, `NewArrivalsView.tsx` | `/new-arrivals` (+ `?fabric=`) | built (2B; differences in "What the PHP catalogue pages do differently") |
| Product | `app/product/page.tsx`, `ProductView.tsx` | `/product?sku=...` | built (2B; built here instead of 2C because the cards and menus link to it) |
| Bag | `app/bag/page.tsx`, `BagView.tsx` | `/bag` | built (2C) |
| Checkout | `app/checkout/page.tsx`, `CheckoutView.tsx`, `components/checkout/*` | `/checkout` | built (2C, step 1 only: delivery and ordering disabled until phase 2) |
| Order and receipt | `app/order/page.tsx`, `OrderView.tsx`, `OrderReceipt.tsx` | `/order?no=...` (`&receipt=1`) | planned (later; needs orders API) |
| Account overview | `app/account/page.tsx`, `components/account/AccountOverview.tsx` (frame: `AccountShell.tsx`) | `/account` | built (2C; orders, addresses and the other rows go to `/soon`) |
| My orders | `app/account/orders/page.tsx`, `OrdersView.tsx`, `RealOrdersList.tsx` | `/account/orders` | soon |
| Wishlist, addresses, payment methods, membership, reviews, notifications, help (rows of the account sidebar) | `AccountShell.tsx` (`navItems`; no `href` = disabled rows in Node too) | none | not possible (not pages in Node either) |
| Google sign-in | `components/auth/GoogleButton.tsx` | button on `/login`, `/signup` | not possible for now: the button is drawn as Node draws it with no Google client id and shows Node's own "not switched on yet" notice. The Google script would break `script-src 'self'`, and the API has no `/auth/google` yet (later phase). |

Links from the header, menu and footer (all of them):

| Place | Link | PHP today |
|---|---|---|
| Header brand | `/` | `/` |
| Header Shop menu (WOMEN: Half Sarees, Uppada, Banarasi, Kanchipuram Patti, Dharmavaram, Soft Silk, Bridal Sarees, Gadwal; MEN: Men's Ethnic Wear; JEWELLERY: Jewellery, Accessories) | `catalogLink()`: `/new-arrivals`, `/new-arrivals?fabric=<name>`, `/new-arrivals?occasion=Wedding` (Half Sarees, Gadwal, Men's, Jewellery, Accessories are plain `/new-arrivals`) | same links, built in 2B (`CatalogLinks::catalogLink`, `+` for a space like URLSearchParams); the Shop menu has no `/soon` link left |
| Header search results (New Arrivals; the 7 fabrics; Bridal Sarees and Wedding Collection `?occasion=Wedding`; Festive Collections `?occasion=Festive`; Best Sellers `/#arrivals`) | `catalogLink()` and `/#arrivals` | same links, built in 2B |
| Header bag | `/bag` | `/bag`, with the live count |
| Header account (signed in) | `/account` | `/account` (coming page) |
| Header account (signed out) | `/login` | `/login` |
| Header hamburger | opens the Shop menu (no page) | same |
| Footer | no page links: column entries are plain text; the four social icons are `<a>` only when `siteConfig` has an address (none yet) | same |
| Home page links | category cards and "See all" are `#arrivals` in Node too; product cards `/product?sku=` | same: cards link to `/product?sku=<encodeURIComponent(sku)>` (built in 2B) |
| Product card photo and title (home, New Arrivals) | `productHref(sku)` = `/product?sku=<encodeURIComponent(sku)>` | same |
| "Back to New Arrivals" (product page) | `/new-arrivals` | same |
| "View bag" (shown after Add to bag) | `/bag` | `/bag` (one constant: `CatalogLinks::BAG`) |

## Class names, wording and message sources, per page

The class prefixes are the CSS-module hashes in the compiled CSS that is already copied to `public/assets/css/3wh3hbs4o_ca8.css`.

| Page | Root and main classes | Wording and validation source |
|---|---|---|
| Sign-in pages (login, signup, forgot, verify) | `<main class="neurva_page">` > header, `<section class="neurva_stage">` (`neurva_brand` aside with `neurva_brandPhoto`, `neurva_brandShade`, `neurva_brandContent`; `neurva_card`), footer. Card parts: `neurva_title`, `neurva_lead`, `neurva_google`, `neurva_divider`, `neurva_form`, `neurva_field`, `neurva_labelRow`, `neurva_required`, `neurva_control` (+ `neurva_invalid`), `neurva_icon`, `neurva_messageSlot`, `neurva_reveal`, `neurva_error`, `neurva_hint`, `neurva_alert`, `neurva_notice`, `neurva_submit`, `neurva_spinner`, `neurva_switch`, `neurva_inlineLink`, `neurva_strength` / `neurva_bars` / `neurva_barOn` / `neurva_strengthLabel` / `neurva_rules` / `neurva_ruleMet`, `neurva_check` / `neurva_box` / `neurva_checkInvalid` / `neurva_strong`, `neurva_otp` / `neurva_otpInput` / `neurva_otpBox` / `neurva_otpBoxActive` / `neurva_otpInvalid`, `neurva_resend`, `neurva_badge`, `neurva_success` | Frame: `components/auth/AuthShell.tsx`. Fields: `components/auth/Fields.tsx`. Flow and the "Welcome" block: `components/auth/useAuthFlow.tsx`. Checks and their messages: `lib/auth/validation.ts` (validateEmail, validateIdentifier, validateName, validatePassword, validateConfirm, validateCode, passwordStrength, safeNext, mobile notice). Server messages come from the API (`apps/api/src/validation.ts`, `auth/service.ts`) and are shown as sent. Google button: `components/auth/GoogleButton.tsx`. CSS: `components/auth/Auth.module.css`. |
| Login | titles: `Sign In \| Pratyangi Fashions` | `LoginView.tsx` |
| Signup | `Create Account \| Pratyangi Fashions` | `SignupView.tsx` |
| Forgot password | `Reset Password \| Pratyangi Fashions` | `ForgotView.tsx` |
| Verify email | `Verify Email \| Pratyangi Fashions` | `VerifyEmailView.tsx` |
| New Arrivals | `_4HCqHa_page`, `_4HCqHa_catalog`, `_4HCqHa_listing`, `_4HCqHa_intro`, `_4HCqHa_introTitle`; loading skeletons `GSLLNW_*` (`CatalogState`); product cards `JEjkwq_*`; price slider `ZxDzjq_*` (the sheet `chunks/0xslsry9_slir.css`, copied to `public/assets/css/` in 2B; the product page does not load it) | `NewArrivalsView.tsx`, `new-arrivals.module.css`, `components/ui/CatalogState`, `ProductCard`, `PriceRange`, `lib/catalog.ts`; title `New Arrivals \| Pratyangi Fashions` |
| Product | `udK_nG_page` (also `udK_nG_back`, `_chip`, `_chipCurrent`, `_chipSoldOut`, `_info`, `_options`, `_add`, `_note`) | `ProductView.tsx`, `product.module.css`, `lib/variants.ts`; title `Product \| Pratyangi Fashions`; SKU from `?sku=` |
| Bag | `BpPmcW_page`, `_announcement`, `_bag`, `_intro`, `_titleRow`, `_continue`, `_layout`, `_items`, `_panels`, `_summary`, `_breakdown`, `_total`, `_status` | `BagView.tsx`, `bag.module.css`, `lib/bag.ts`, `lib/pricing.ts`; title `Your Bag \| Pratyangi Fashions` |
| Checkout | `FVHMJa_page`, `_announcement`, `_workspace`, `_ornament`, `_top`, `_heading`, `_steps`, `_stepActive`, `_layout`, `_main`, `_card` | `CheckoutView.tsx`, `checkout.module.css`, `components/checkout/AddressDialog.tsx`, `CardDialog.tsx`, `lib/checkout.ts`, `lib/addressBook.ts`, `lib/payments.ts`, `lib/razorpay.ts`; title `Checkout \| Pratyangi Fashions` |
| Order | `oYM1ta_page`, `_order`, `_orderNo`, `_items`, `_itemName`, `_facts`, `_links`, `_linkButton`, `_note`; receipt `receipt.module.css` | `OrderView.tsx`, `OrderReceipt.tsx`, `order.module.css`, `lib/orders.ts`; title `Your Order \| Pratyangi Fashions` |
| Account | `pnvDaq_page`, `_announcement`, `_main`, `_headingRow`, `_heading`, `_layout`, `_sidebar`, `_nav*`, `_logout`, `_content` | `AccountShell.tsx`, `AccountOverview.tsx`, `AccountShell.module.css`, `AccountPages.module.css`; titles `My Account \| Pratyangi Fashions`, `My Orders \| Pratyangi Fashions` |

## How the Node web talks to the API (needed by 2B, 2C and later)

**Base URL.** `NEXT_PUBLIC_API_URL` (trailing slash removed). Every call goes to `<that address>/api/v1/...` (`lib/auth/index.ts`: `apiRoot`). With no address the site is in "off" mode (forms say accounts are not connected) or "demo" mode (in-browser accounts, only when `NEXT_PUBLIC_AUTH_DEMO=true`). The PHP pages use same-origin relative URLs `/api/v1/...` instead, so there is no "off" or "demo" mode and the "not connected" notice never shows.

**Auth calls** (`lib/auth/apiAuth.ts`, function `call`):
- `fetch(url, { method, credentials: "include", headers: body ? { "Content-Type": "application/json" } : undefined, body: body ? JSON.stringify(body) : undefined })`. No other headers (no `Accept`, no CSRF token; the API checks `Origin`). No timeout.
- Network failure: `AuthError("We could not reach the server. Check your connection and try again.")`.
- `204`: no body. Otherwise the JSON is read (`{}` when unreadable). Not `ok`: `AuthError(data.message ?? "Something went wrong. Please try again.", data.field, data.errors)`. `describeError` turns it into a form message plus per-input messages (`errors`, plus `field` -> `errors[field] ?? message`). A message that belongs to an input is shown at that input; anything else at the top of the form (`neurva_alert`, `role="alert"`).
- Endpoints: `POST /auth/login {email,password}` -> `{user}`; `POST /auth/signup {name,email,password}` -> `{user}`; `POST /auth/password/forgot {email}`; `POST /auth/password/reset {email,code,password}`; `POST /auth/email/verify {token}` -> `{customer}`; `POST /auth/email/resend {}` -> `{message}`; `POST /auth/logout` (no body); `GET /auth/me` -> `{user}` (errors mean signed out). The email is sent trimmed and lower-cased; the name trimmed.

**Redirects.**
- After login or signup: the card shows "Welcome back, <first name>!" / "Welcome, <first name>!" (`useAuthFlow.finish`), then after 1200 ms `router.push(safeNext(?next))`. `safeNext`: a value that starts with `/` and not `//`, else `/account`. The pages never redirect a visitor who is already signed in. `?next=/checkout` (exactly) changes the lead text and is kept on the links between the sign-in pages.
- Reset password: no redirect; the card becomes "Password updated" with a "Sign In" link to `/login` (+ `?next=/checkout` when given).
- Verify email: no redirect; the card shows the result; the button goes to `/account` when signed in, `/login` otherwise; a failed link offers "Send me a new link" (signed in) or a link to `/login?next=/account`.
- Logout (account sidebar): `POST /auth/logout`, then `router.push("/")`.
- Elsewhere: account pages signed out -> `router.replace("/login?next=<path>")`; bag "checkout" link -> `/login?next=/checkout` when signed out; checkout signed out -> `router.replace("/login?next=/checkout")`; order page signed out -> link `/login?next=<encoded /order?no=...>`.

**Catalogue** (`lib/catalogApi.ts`, `lib/useCatalog.ts`): `GET <apiRoot>/catalog/products?limit=100[&cursor=<encoded>]`, followed page by page (at most 20 pages), a plain public read: `fetch(url, { signal })`, **no cookies**, 10 s timeout. One shared request is reused for 30 s in the browser. Product fields used: `sku, name, category, fabric, color, size, price, mrp, imagePath, inStock, availableQuantity, taxPercent`. Mapping: `id = sku`, `priceInr = price` (rupees), `compareAtInr = mrp`, subtitle = `color · size`, image is always `/images/reference/product-1.webp` for now. Errors: unreachable -> "We could not reach the store. Check your connection and try again."; 503 -> "The catalogue is not available right now. Please try again in a moment."; 429 -> "Too many requests. Please wait a minute and try again."; other -> "We could not load the catalogue. Please try again."; unreadable -> "The store sent an answer we could not read. Please try again."; more than 2000 products -> "The catalogue is too large to load in one go. Please try again later." Product page link: `/product?sku=<encodeURIComponent(sku)>`.

**Bag** (`lib/bag.ts`):
- `localStorage` key **`pratyangi-demo-bag`** (the word "demo" is in the real key). Change event: a `window` event named `pratyangi-bag-change` (plus the browser `storage` event for other tabs).
- JSON shape: an array of `{ "id": "<sku>", "quantity": <integer 1..10> }`. An old-style entry that is just a string is read as `{id, quantity: 1}`. Quantities are rounded and clamped to 1..10 when read and written; adding an existing id raises its quantity (max 10). A missing key is an empty bag; text that is not a JSON array of such entries leaves the bag empty with the message "The saved demo bag could not be read, so it started empty."; storage errors give "Browser storage is unavailable; bag changes will not persist."
- For the real store a saved line is kept whatever the catalogue says (bag and checkout mark lines the catalogue no longer has). The header count is the sum of quantities.

**Addresses, orders, payments** are only needed from 2C on (`lib/addressBook.ts`, `lib/checkout.ts`, `lib/orders.ts`, `lib/payments.ts`); they will be inventoried when those steps start.

## What the PHP sign-in pages do differently from Node (on purpose or unavoidable)

- Server-rendered: the markup is in the PHP page (Node renders the same markup in React). `?next=/checkout` is read on the server (Node reads it after load), so the lead text is right from the first paint.
- Element ids are fixed (`auth-identifier`, `auth-password`, ...) instead of React's generated ones.
- `safeNext` also refuses a backslash or a control character (`/\host` is another site in a browser). Node accepts it. Stricter, never looser.
- After login/signup the header is not updated in place during the 1.2 s welcome (Node updates its in-memory session); the next page load shows the signed-in header.
- The verify page decides "signed in" on the server from the `pf_session` cookie; Node asks `GET /auth/me` after load. A page without a token shows "Open the link from your email" directly (no script needed); with a token it starts as "Checking your link..." as Node does.
- Cache: a page requested with a `pf_session` cookie gets `Cache-Control: private, no-store` and `Vary: Cookie`; the verify page with a token is `no-store`.
- The Google button only shows Node's "not switched on yet" notice (see the table).
- There is no header dropdown: Node has none either (the avatar is a link to `/account`). Sign out lives where Node has it, the "Logout" button of the account sidebar; until the account pages exist, `/account` shows the coming page with that button when signed in.
- A server error that names only the email in the reset step ("Set a new password") has no input to show it on, in Node and in PHP (same code path); not changed.

## What the PHP catalogue pages do differently from Node (2B)

Both pages are the "real store" (API) variant of the Node pages: no demo products, no demo colour swatches, no occasion filter.
- **Where the filtering runs.** Node loads the whole catalogue in the browser and filters it in React. PHP prints every product as a card (`data-price`, `data-category`, `data-fabric`, `data-color`, `data-instock`) and `public/assets/listing.js` shows, hides and orders them with the same rules (category, price range, fabrics, colours, "In Stock", sort Featured / price, 9 a page, back to page 1 on any change). The `?fabric=` link is applied on the server (box ticked, only those cards visible on page 1, "N Products" right) and `listing.js` reads that state. With JavaScript off the first page of the server-filtered cards shows, but the filters, sort and page buttons do nothing.
- **Unknown filters.** As in Node, an unknown `?fabric=` value is ignored (200, everything listed) and `?occasion=` is accepted and ignored (the ERP has no occasion, so Node's Bridal / Wedding / Festive links also show everything). Query text is never printed.
- **Catalogue over 2000 products or unreadable** shows the friendly "We could not load products right now. Please try again in a moment." box with a "Try again" button (reloads the page; the reason is never shown). Node shows a message per reason (unreachable, 503, 429, too large); PHP has one message because the visitor cannot act on the difference. Status stays 200 with `Cache-Control: no-store`. An empty catalogue shows Node's "No products are available right now. Please check back soon."
- **Product page, not found.** Node's static page says "We could not find this product. It may no longer be sold." with status 200; PHP shows the same box with **404** (missing, empty or unknown `sku`, exact case-sensitive match, the first `sku` wins like `URLSearchParams.get`). When the catalogue cannot be read the page is the error box above (200), never "not found".
- **Product page, title.** Node's title is the fixed "Product | Pratyangi Fashions" (a static export cannot know the product); PHP keeps that exact title.
- **No quantity picker.** Node's product page has none either: Add to bag adds one, repeats raise the quantity (max 10). A sold-out product has a disabled "Sold out" button and the note "This option is sold out."
- **Add to bag.** `public/assets/bag.js` reproduces `lib/bag.ts` (key `pratyangi-demo-bag`, `{id, quantity 1..10}`, strings read as quantity 1, rounding and clamping, event `pratyangi-bag-change`, the two Node messages). Difference: if the browser refuses to store the bag, Node still shows "Added to your bag."; PHP shows "Browser storage is unavailable; bag changes will not persist." instead. The cards' "Add to cart" (home and New Arrivals) use the same helper; New Arrivals shows "Added to your bag." under the toolbar as Node does.
- **Bag icon and count.** Not part of 2B: the icon still goes to `/soon` and shows no count (step 2C), and "View bag" goes to `/soon` until the bag page exists.
- **Images.** Every card and the product photo use the placeholder `product-1.webp`, as Node does until the ERP image decision; the ERP `imagePath` is never used.
- **Sorting names and fabrics** uses ICU (`Collator('en')`) when the intl extension exists, otherwise `Collation`'s approximation (same as the catalogue API), so the order of the fabric and colour lists can differ from Node's `localeCompare` for unusual characters. UNVERIFIED for non-ASCII values.
- **Filters start folded on a phone** (width up to 700 px, no query in the address) by script after load, as Node does by effect; before the script runs they are open.
- **Slider.** Two native range inputs on one track, like Node's `PriceRange`; thumb and track styling come from the copied sheet. Checked in headless Chrome only (see below), not on real phones or other browsers.
- **Menu / search links** use the exact `catalogLink()` addresses of Node; Half Sarees, Gadwal, Men's Ethnic Wear, Jewellery and Accessories are plain `/new-arrivals` in Node too.
- **Added for safety:** hostile product data (names, SKU, category, fabric, colour) and any query parameter are escaped with `View::e`; `StaticFiles` serves exactly `site.js`, `auth.js`, `bag.js`, `listing.js`, `product.js` as JavaScript.

## How it was checked

- `php tests/auth_pages_test.php` (no database): routes, markup ids and classes, one deferred `site.js` plus one `auth.js` and no inline script, hostile query values never printed, hostile first name escaped, signed-in/out header, `StaticFiles`, mail link paths.
- `php tests/catalog_pages_test.php` (no database, mock ERP): both routes, markup, filters from `?fabric=`, pagination markup, sold-out and low-stock, other options, 404 cases, hostile data and query values, unavailable and empty catalogue, card links, every header/footer link resolves, per-visitor cache headers, images and sheets exist, `StaticFiles`.
- 2B in headless Chrome against a short-lived local `php -S` (the real app, mock ERP with 21 products, stopped afterwards), driven over the DevTools protocol: pagination, sort, fabric, colour, in-stock, price slider and presets, category, show more, fold, "Add to cart" from a card, the `?fabric=` link, phone fold, product Add to bag (quantity 1, 2, capped at 10, unreadable saved bag, old string entries, sold-out button), the not-found page, the home card Add to cart; no script errors. Screenshots looked right at 1400 px and 420 px wide; not compared side by side with Node by a person.
- Headless Chrome against a short-lived local `php -S` (stopped afterwards) with `fetch` replaced by a fake (sign-in pages): validation messages, field errors (422, 409), 429, network failure, unreadable answer, the welcome and the redirect, `?next`, strength meter, show/hide password, code box, resend countdown, the "Change" step, verify outcomes, the Shop menu from `site.js` on a sign-in page. Not run: the Logout button click (needs a signed-in page, which needs the database).

## Bag, checkout and account (2C): differences from Node

- **Bag lines are drawn in the browser** by `bag-page.js` (Node does the same in React) from the saved bag (`pratyangi-demo-bag`) and `GET /api/v1/catalog/products` (no cookies, 10 s timeout, 20 pages max, shared 30 s). Line states (ok, limited, soldOut, gone, checking, unverified), tax per line in whole paise rounded half up, "Total (not final)" and the Node messages are the same. The server prints only the frame and "You May Also Like" (first four in-stock products); Node shows a skeleton while loading, PHP prints the cards at once. If the catalogue cannot be read the cards are replaced by the retry box.
- **Header count** (`site.js`) is the sum of quantities, updated on this tab (`pratyangi-bag-change`) and other tabs (`storage`); the server prints 0. An unreadable bag counts as 0.
- **Checkout** is the real-store step 1 only. Signed out: server redirect (302) to `/login?next=/checkout` (Node redirects in the browser). Add New Address, the address list and Proceed to Pay are disabled; the note is "Delivery and ordering come in the next build phase". No payment step, no review step, no orders API. Node shows "Loading your saved addresses…" there.
- **Account** is the signed-in overview only. Signed out: 302 to `/login?next=/account`. Added over Node: the email address (and mobile when a `mobile`/`phone` value exists; the table has none today) in the profile line. Node's disabled sidebar rows are links to `/soon`; My Orders and View All Orders also go to `/soon` (`/account/orders` stays the coming page). "Saved Addresses" shows "–" (no addresses API yet). The button keeps Node's wording "Logout", not "Sign out". The email-verify notice and its resend button work as in Node.
- Extra markup: a few unclassed hook `div`s (`data-pf="bag-notices"`, `total-note`) for the script.
- Icons the bag needs were copied into `public/assets/images/figma/` (arrow-left, badge-percent, checkout-arrow, chevron-up, heritage-lotus, lock-keyhole, trash, wishlist-heart).

### Side-by-side checklist (2C), tick when seen in both browsers
1. /bag empty: title "Your Bag (0 items)", lead "Your bag is empty — discover something beautiful.", empty box with "Shop New Arrivals", disabled Proceed to Checkout.
2. Add a product from a card and from the product page: header count goes up, also in a second tab.
3. /bag with lines: photo, name (link), colour · size, − / + (limits at stock and 10), Remove, price, struck MRP, "N% OFF".
4. Totals: Subtotal, "Tax (added on top)", "Shipping: Confirmed by the shop", Total, "Delivery charges are not included."
5. A SKU the catalogue lacks: "Item <sku>", "This item is no longer available.", total shows "(not final)", checkout disabled.
6. Signed out, Proceed to Checkout goes to /login?next=/checkout; signed in goes to /checkout.
7. /checkout signed out lands on sign in; signed in shows steps 1-2-3, address card, Delivery card, order summary with Qty select and trash.
8. Checkout: Add New Address and Proceed to Pay disabled, note visible.
9. /account signed out lands on sign in; signed in: Hello <first name>, member since, stats, Recent Orders empty state, Logout works.
10. Sidebar rows and View All Orders open /soon.

## Mobile OTP sign-in and the redirect after sign-in (PHP-only, step OTP)
Not in the Node web. Contract: `docs/API_CONTRACT.md` section 11.

### Differences from the Node sign-in pages (on purpose)
- **Email | Mobile OTP switch** on `/login` and `/signup` (markup `AuthHtml::modeTabs()`, the buttons use the new `.pf_mode` rules in `site.css`; everything else uses the compiled `neurva_*` classes: `neurva_prefix` for the fixed `+91`, `neurva_otp` six boxes, `neurva_resend`, `neurva_inlineLink`, `neurva_submit`). The divider above it reads "or sign in with" / "or sign up with" (Node: "... with email"). Email is the tab shown first, so the Node page looks the same until the visitor switches.
- `/login` Mobile OTP tab: `+91` + 10 digits, **Send OTP**, the code (six boxes), **Resend code in Ns** (45 s, or the `Retry-After` of a 429), **Change number**, and a **Full name** field that appears only when the server answers `nameRequired`. `/signup` Mobile OTP tab: Full name, mobile number, the terms box, **Send OTP**, then the code; the button then reads "Verify & Create Account".
- The Email tab's "Email or mobile number" box now answers a typed mobile number with "To sign in with a mobile number, use the Mobile OTP tab." (was "Mobile sign-in isn't available yet").
- `auth-otp.js` is the third deferred script on `/login` and `/signup` only; it uses `window.pfAuth`, which `auth.js` publishes. No inline script.
- **Redirect after sign-in**: the landing page `/` (Node: `/account`), unless `?next=` is a safe same-site path (`app/Web/SafeNext.php`). The server prints the answer as `data-pf-next` on `<main data-pf-auth>`; `auth.js` follows it after the 1.2 s welcome. The verify-email page's button for a signed-in visitor is "Continue shopping" to `/` (Node: "Go to my account" to `/account`).
- The account page shows the stored mobile number next to the email; for an account without an email there is no email chip and no "confirm your email" notice.

### Checked
- `php tests/otp_logic_test.php`, `tests/auth_pages_test.php` (markup, scripts, `data-pf-next`), `tests/catalog_pages_test.php` (the new script is served as JavaScript): no database.
- `php tests/db_otp_test.php` (MySQL `pratyangi_php_test` on 127.0.0.1:3399 only): see the run record in `.claude/snapshots/otp/CHANGES.md`.

### Steps for the headless Chrome check on the running 8100 server (settings need `OTP_PEPPER`; `SMS_MODE` defaults to `log` outside production; `sql/003_mobile_otp.sql` applied to that server's database)
1. `/login`: Email tab is shown, the Mobile OTP tab is hidden; click "Mobile OTP": the `+91` box, Send OTP; click "Email": back. Arrow keys move between the tabs.
2. Email sign-in with an existing email account: the welcome shows, then the browser lands on `/` (not `/account`).
3. Mobile OTP: enter a 10-digit number, Send OTP, read the code from `storage/sms.log` (log mode; the file is git-ignored and not opened by Claude's tools in this project's rules, so the user or a test reads it), enter it: a new number asks for the name, then lands on `/`; a number with an account lands on `/`. Resend is disabled with a countdown for 45 s.
4. `/login?next=/checkout` and `?next=/account` land on that page after sign-in; `?next=//evil.example`, `?next=https://evil.example`, `?next=/\evil.example` land on `/`.
5. Signed in with that real session: `/checkout` and `/account` render (account shows the stored `+91...` number and no email notice for a mobile-only account), the header shows the avatar and first name, Logout works.

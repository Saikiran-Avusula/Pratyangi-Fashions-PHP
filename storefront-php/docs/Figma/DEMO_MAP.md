# DEMO_UI map (UI6): every mock spot and what replaces it

`DEMO_UI=true` (default `false`; the app refuses to start with `APP_ENV=production` + `DEMO_UI=true`) turns on the preview screens. With it off, none of them exist: the paths below are 404, the phone account menu and the sidebar have no Membership / My Reviews rows, no "Preview" pill, no card dialog.
All made-up data lives in `app/Demo/DemoData.php` (money in integer paise, tax rate as the ERP gives it). Nothing is written to the database or sent to the ERP, Razorpay or any mailbox. The pages are `app/Web/DemoPages.php` + `app/Views/demo/*`, styled by `public/assets/css/demo.css`, behaviour in `public/assets/demo.js` (toasts, radio choice, dialog, stars, tabs; no network calls).

| Screen (Figma) | Path | Mock source | Real source that replaces it |
|---|---|---|---|
| 6 Checkout Address | `/checkout/address` | `DemoData::addresses()`, `deliveryOptions()`, `lines()`, `summary()` | `GET /api/v1/addresses`; the shop's shipping rules; the priced bag. "+ Add New Address" opens the real address sheet (that part is the real feature and saves through the real API if used). |
| 7 Checkout Payment | `/checkout/payment` | `DemoData::paymentOptions()` (COD off through `codEnabled()`) | Razorpay methods for the order; a later COD setting |
| 8 Order Review | `/checkout/review?pay=` | `addresses()[0]`, `lines()`, `summary()`, coupon `WELCOME10` | the checkout API's priced order (subtotal, discount, GST at each line's ERP `tax_rate`, shipping, total) |
| Place Order | `/checkout/demo-confirmation` | none (text only) | the real order confirmation (`POST /checkout`, still off: `CHECKOUT_ENABLED=false`) |
| 9 Payment Methods | `/account/payments` | `DemoData::savedPayments()` (masked) | saved Razorpay tokens (brand + last 4 only; the shop never holds a card number) |
| 10 Add card dialog | on `/account/payments` | none; every field disabled | Razorpay's own card window (nothing is typed in our page) |
| 11 Membership | `/account/membership` | `DemoData::membership()` | a membership record per customer (later feature) |
| 12 Reviews | `/account/reviews` | `DemoData::reviews()`, `pendingReviews()` | reviews table (later feature) |
| 13 Edit Review | `/account/reviews/edit?id=` | the same review rows; Update Review only shows a toast | `PUT` on a review (later feature) |
| Account menu rows | `/account` (phone) and the sidebar | row list in `app/Views/shop/account.php` guarded by `$demoUi` | the rows stay, guarded by the real feature flags instead |

Price rule on every order screen: Subtotal, Discount, GST (rate and amount), Shipping, Total. The words "Inclusive of all taxes" never appear (checked by `tests/demo_ui_test.php`).

Left out on purpose even in the preview: brand logos for UPI apps / cards (no licensed art here, plain text chips instead), the "Exclusive Offers" block of the membership page and its fabric photos (no data; tier cards use flat colours), photo upload in the review form (a tile that shows a toast).

## Sizes (UI10) — mock until the ERP has a variant field

Unlike the screens above, the size picker is live in every mode (not behind `DEMO_UI`), because the mock lives in one place that the real data will replace:

| What | Mock source | Real source that replaces it |
|---|---|---|
| Which products have sizes, which sizes, which are sold out | `app/Catalog/Variants::sizes()` (only products whose size is S–XXL, in stock, not a saree; sizes S M L XL XXL; one size sold out, picked from the SKU's CRC32) | the ERP variant group (`design_model` / `pattern`, or a new variant field): one row per size with its own SKU and stock |
| What the page sends to the browser | `data-sizes` (product page) / `data-qv-sizes` (cards, Quick View) = `"S:1,M:1,L:0,…"` via `Variants::attr()` | same attribute, filled from the ERP rows |
| The bag line | id = variant SKU `"<sku>~<size>"` (`site.js` sizePicker; `bag-page.js` reads the base SKU before `~`) | the size's own ERP SKU; checkout must send that SKU (checkout does not send bag lines yet) |
| Size guide table | DRAFT inches in `site.js` `sizeGuide()` | the shop's measurement chart |

Not covered yet: the wishlist's "Move to bag" adds the plain SKU (no size picker there).

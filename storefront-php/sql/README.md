# SQL

Status: **not yet run anywhere.** Nothing in this folder has been applied to any database.

Apply in this order, to a fresh MySQL 8 database (utf8mb4), after review:

1. `001_phase1_accounts.sql` - `shop_customers`, `shop_sessions`, `shop_password_resets`, `shop_email_verifications`.
2. `002_rate_limits.sql` - `shop_rate_limits` (PHP-only request counters).

Later phases add numbered files after these (addresses, identities, orders, payments). A fresh database is used: nothing here is meant to be applied on top of the Node API's database.

Notes
- All times are UTC. The PHP connection runs `SET time_zone = '+00:00'`.
- Names of tables, columns, indexes and foreign keys follow the Node API's Prisma migration `20261017000000_init`.
- Tests use a throwaway database whose name ends in `_test` on a port other than 3306 (see `tests/lib/DbGuard.php`).

## Added later (PHP-only)
3. `003_mobile_otp.sql` - mobile one-time-code sign-in: `shop_customers.email` nullable, `mobile` (unique) and `contact_email` columns, and `shop_otp_codes`. Apply **once** (the ALTER is not repeatable). So far applied only to the throwaway `*_test` database by the tests; see `docs/API_CONTRACT.md` section 11.
4. `004_addresses.sql` - `shop_customer_addresses`.
5. `005_orders.sql` - `shop_orders`, `shop_order_items`, `shop_razorpay_orders` (online orders, stage B1; see `docs/API_CONTRACT.md` 11.4). Safe to run twice (`IF NOT EXISTS`); applied so far only to the throwaway `*_test` database.
6. `006_google_identities.sql` - Google sign-in identities.
7. `007_mail_events.sql` - `shop_mail_events`: one row per email event already sent (welcome, order emails), so each is sent once.
8. `008_consent_numbers.sql` - `shop_consent_log` (marketing consent per channel, with time, page and IP) and `shop_verified_numbers` (delivery numbers proved with a code).

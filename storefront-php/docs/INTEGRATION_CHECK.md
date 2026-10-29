# Integration check (read-only), 2026-10-29

Scope: `PHP_dev\integration\ERP Storefront-Pratyangi` (A) and `PHP_dev\integration\Pratyangi central licence` (B). Nothing was changed. Not opened: `.env`, `config.php`, `config.secret.php`, `keys/*.pem`, `error_log`, storage PDFs. File names only for those.
Not checked: I did not unzip or run anything, and did not read `database.sql` or the 5.9 MB `app (2).zip` beyond its file list.

## 1. What the folders are

**A: ERP source tree (git repo), the OLD ERP.** Staff UI (`public/*.php`), `app/`, `sql/` (`database.sql` + migrations v1_5 to v1_8 purchase module), `docker/`, `Dockerfile`, `render.yaml`, `DEPLOY_RENDER.md`, `BIGROCK_GO_LIVE_GUIDE_TELUGU.txt`, `LOCAL_LICENSE_DEVELOPMENT.md`, `ERP_STOREFRONT_INTEGRATION.md` (an audit of this old ERP), `app (2).zip` (the same tree, "app (3)" inside), a nested `Central License\app (4)` copy of B, `license/public_key.pem`, `ERP_UPLOAD_ROOT\config.php`, `.env`, `config.php`.
It is **not** a staging package. No version number is visible. Newest file dates are 2026-10-29 (copy dates); the purchase module (migration v1_8) is the latest feature.

**B: Central licence server (git repo).** `public/` admin UI (login, dashboard, customers, `license_new.php`, `license_view.php`, `setup_admin.php`, `events.php`), `public/api/v1/{ping,activate,validate}.php`, `sql/license_server.sql`, `keys/` (private.pem, public.pem, README.txt), `app/bootstrap.php`, Docker/render files, `LIVE_DEPLOYMENT_CHECK.txt` (cPanel steps), `app (4).zip`. It is a licence server.

## 2. ERP (A) against what our storefront expects

| Item | Result |
|---|---|
| `public/api/catalog_products.php` | **MISSING** |
| `public/api/catalog_stock.php` | **MISSING** |
| shared `catalog_api.php` (anywhere) | **MISSING** |
| `public/api/order_create.php` | Present, but it is the old version (see mismatches) |
| Other `public/api` files | `product_search`, `product_stock`, `product_lookup`, `scan_find`, `notification_count`: staff-session only, unusable by us |
| `order_status.php` (LATER phase) | Missing (not needed yet) |
| STAGING_SETUP folder, `CPANEL_CHECKLIST.md`, `erp-staging.zip`, `STAGING_FRESH_DATABASE.sql`, `env.template.txt`, PURCHASE_UPDATE | **All missing** in A, B and the zips. Only `sql/database.sql` and the migrations exist. |

So A is the ERP **before** the storefront integration work. The catalogue API our `ErpClient` calls (`app/Erp/ErpClient.php:50,83`; contract section 3.1) does not exist in it. `ERP_STOREFRONT_INTEGRATION.md` section 0 says the same ("There is no catalogue endpoint").

**Keys.** A has one key only: config `api.order_ingest_key` (`app/bootstrap.php:255`, read from `config.php`, not from `.env` names I could see). Needs 32+ chars, otherwise 503. No catalogue key exists. We expect `ERP_CATALOG_KEY` and `ERP_ORDER_KEY`, two separate keys.

**Mismatches in `order_create.php` against what we send/read (contract 3.1):**
1. Catalogue endpoints missing (above). The storefront cannot list products or stock against this ERP.
2. Offer price, `tax_rate`, `image_path`, stock per location, the online-fulfilment flag and `after_id` paging: no endpoint serves them. The columns exist in `products` (`retail_price`, `offer_price`, `tax_rate`, `product_image`, `pattern_name`, `design_model`, `category_id`), per the audit doc.
3. Auth check order: `order_create.php` answers **503 before** checking the bearer (disabled if the key is under 32 chars). We treat 401/403/402/503 as "key refused/disabled", so that is fine.
4. 422 returns raw exception text. Our classifier works on text: "insufficient sellable stock", "unknown sku", "reserved by another order", "multiple fulfilment warehouses", "unknown or disabled fulfilment". Source here gives "Unknown SKU: x", "Unknown or disabled fulfilment location/warehouse." and "Multiple fulfilment warehouses are enabled…". The `online_fulfillment.php` stock and "reserved by another order" wording was not verified. The "product is missing/inactive" wording is not produced here (inactive gives "Unknown SKU"), so it is classified `unknown-sku`, which is still a final refusal. No code change needed.
5. `payment_status`: accepts `PAID/COD/PENDING/REFUNDED`. We send `COD`/`PAID`/`PENDING`. OK.
6. `external_order_id` + `source`: duplicate answer `200 {ok,duplicate:true,order:{id,order_no,status}}`. Matches our parser. We send `external_order_id` = `order_no` and `source=WEBSITE`. OK.
7. `order_amount` is stored as sent, not validated. Fine. The contract sends a string like `"26772.90"`; the ERP casts it, which should be fine, but I did not test it.
8. `warehouse` and `location_code` are supported. OK.
9. No `402` licence response in `order_create.php` itself. The licence gate is elsewhere (`license_client.php`). Unverified whether API calls are gated.

## 3. Licence (B)

- **Endpoint:** `POST {LICENSE_SERVER}/api/v1/activate.php` with `license_key`, `installation_id`, `domain`, `app_version`. Also `validate.php` and `ping.php`. The ERP calls it from `app/license_client.php:160,189`.
- **Key check:** the key is hashed and looked up in `licenses.license_key_hash`. Refused if the customer is disabled, the status is SUSPENDED/REVOKED, or the start date is in the future. A wrong key gives 403. Failed attempts are rate-limited (429).
- **Domain binding: yes.** Each installation row stores the first domain. A different domain returns 409 until the installation is reset in the Licence Control Center. There is also a `max_installations` limit (409 when full).
- **Response:** a payload signed with the private key in B (`keys/private.pem`). The ERP verifies it with `license/public_key.pem`, checks the installation id and the domain, and refuses if the signature or domain differs.
- **test-erp.pratyangi.com needs its own licence.** It is a new domain and a new installation. Either (a) create a new licence in B (`license_new.php`, set max installations and the customer), or (b) raise `max_installations` on an existing licence. If it reuses the production key on a new installation, the domain binding is per installation, so it works only while `max_installations` allows it. A separate test licence is cleaner and does not touch the live count. The test ERP must carry the **same `public_key.pem`** as the licence server that signs for it.
- **Who creates it:** you, in the licence admin UI at the licence server (`license_new.php`), and the key is shown once there. Then paste it into the test ERP's licence activation page (`license_activate.php`). `LOCAL_LICENSE_DEVELOPMENT.md` explains a local dev mode, not read in full.
- **Storefront needs nothing from B.** Correct: the storefront never calls the licence server and never holds a licence key. The only link is that an unlicensed ERP may answer `402`, which the storefront handles as a config error.

## 4. Setup checklist for test-erp.pratyangi.com (cPanel)

**Blocker first: A has no catalogue API.** Do not set up a test ERP from A and expect the storefront to work. Steps 1 and 2 must come first.

1. **Get the right ERP build.** Ask whoever built the ERP for the version with `public/api/catalog_products.php`, `catalog_stock.php`, `app/catalog_api.php` and the staging pack (`CPANEL_CHECKLIST.md`, `erp-staging.zip`, `STAGING_FRESH_DATABASE.sql`, `env.template.txt`, PURCHASE_UPDATE). **MISSING in these folders.**
2. In cPanel > Domains, create the subdomain `test-erp.pratyangi.com`. Point its document root at the folder where the ERP's root `index.php`/`.htaccess` live (check the delivered pack; A has a root `index.php` and `.htaccess`, B says do not move only `public/`). Turn on a free SSL (AutoSSL).
3. cPanel > Select PHP Version: PHP 8.2 or newer, extensions `pdo_mysql`, `openssl`, `mbstring` (as B's `LIVE_DEPLOYMENT_CHECK.txt` says; confirm for the ERP).
4. cPanel > MySQL Databases: create a **new empty** test database and user, add the user with ALL PRIVILEGES. Write down the exact full names (cPanel adds a prefix).
5. phpMyAdmin: select that database and import the fresh schema. Intended file: `STAGING_FRESH_DATABASE.sql` (**MISSING**). Fallback from A: `sql/database.sql`, then `migration_v1_5_location_shelf.sql`, `v1_6_transfer_workflow`, `v1_7_count_exceptions_import`, `v1_8_purchase_module`, in that order. Do not import any live data or backup.
6. Upload and extract the ERP files (`erp-staging.zip`, **MISSING**) into the document root.
7. Create the ERP config from `env.template.txt` (**MISSING**; A's own is `config.php` + `ERP_UPLOAD_ROOT\config.php`, do not copy their values): test database name/user/password, and two new random keys of 40+ chars (catalogue key, order key) for the storefront. Use brand-new keys, never the production ones.
8. In the licence server admin (B): create a **test licence** with `license_new.php`, copy the key once. Make sure the test ERP's licence server URL (`license_server`) and `license/public_key.pem` match B.
9. Open the test ERP, go to `license_activate.php`, enter the test key. Check `license_status.php` shows active, and `preflight.php` passes.
10. Create the first staff user, a warehouse/location with online fulfilment enabled, and a few test products with prices and stock (fake data).
11. Check from outside: `https://test-erp.pratyangi.com/api/catalog_products.php` with a wrong key must answer 401 (JSON). With the order key on `order_create.php`, a `GET` answers 405.
12. Give us the base URL and the two keys through a private channel (never in chat or in files in this repo), then we point a `_test` storefront at it with `ERP_MODE=http`.

## 5. Other notes
- `B\public\error_log` (6.4 KB) and `B\keys\private.pem` are inside a folder that looks like it is going to be uploaded. Do not deploy `error_log`, `keys/`, `.git`, `*.zip`, `.env`, `config.secret.php` to the server.
- A contains `.env`, `config.php` and `ERP_UPLOAD_ROOT\config.php` with unknown (possibly real) values. Treat as secrets.
- A holds supplier purchase-bill PDFs inside `app (2).zip` (`storage/purchase_bills`). Business documents, do not publish.

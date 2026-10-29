# Staging deploy: https://test.pratyangi.com (BigRock cPanel)

For a test copy of the shop only. Payments, checkout and SMS are switched OFF in the settings template. Nothing here touches the real site.
`<user>` below is your cPanel user name (shown at the top right of cPanel and in File Manager as `/home/<user>`).

What you need before you start: the file `dist/pratyangi-staging.zip` (build it on your computer with `powershell -File tools\build_deploy.ps1`), the
settings template `config.staging.example.php` (it is also inside the zip), and your cPanel login.

Where things go on the server:

| What | Where |
|---|---|
| the shop (the zip, extracted) | `/home/<user>/pratyangi-staging/` |
| the web folder (document root of test.pratyangi.com) | `/home/<user>/pratyangi-staging/public` |
| the settings file (secrets; outside the web folder) | `/home/<user>/pratyangi-staging/config.local.php` |
| folders PHP writes to | `/home/<user>/pratyangi-staging/storage` and `.../cache` |

## First time

1. **Check the PHP version.** cPanel > **MultiPHP Manager**. Tick `test.pratyangi.com` after step 2 and choose **PHP 8.3** (8.1 or 8.2 also work). Click Apply.
2. **Create the subdomain and its document root.** cPanel > **Domains** (older cPanel: **Subdomains**) > **Create A New Domain**. Domain: `test.pratyangi.com`.
   Untick "Share document root". Document Root: `pratyangi-staging/public` (this is relative to `/home/<user>/`; the folder is created for you). Save.
   Then go back to MultiPHP Manager (step 1) and choose PHP 8.3 for it.
3. **Create the database and its user.** cPanel > **MySQL Databases**.
   - Create New Database: for example `pratyangi` (cPanel makes it `<user>_pratyangi`).
   - MySQL Users > Add New User: a name such as `shop` and a strong password. Write the password down; you need it in step 8.
   - Add User To Database: pick both, tick **ALL PRIVILEGES**, save.
4. **Upload the shop.** cPanel > **File Manager** > open `/home/<user>/pratyangi-staging/` (it exists since step 2) > **Upload** `pratyangi-staging.zip`.
   Back in File Manager, select the zip > **Extract** > extract into `/home/<user>/pratyangi-staging/`. You should now see `app`, `public`, `sql`, `tools`, `storage`, `cache`.
   Delete the zip afterwards. Make sure you did NOT extract into `public`.
5. **Make the two folders writable.** File Manager > right-click `storage` > **Change Permissions** > `755` (cPanel hosting already runs PHP as you, so 755 is enough;
   use 775 only if /server-check later says a folder is not writable). Do the same for `cache`.
6. **Create the tables.** cPanel > **phpMyAdmin** > click your database (`<user>_pratyangi`) on the left > **Import** > choose
   `sql/ALL_IN_ORDER.sql` (download it from the File Manager, or use the copy on your computer) > **Import**. Do this once. You should see 12 tables starting with `shop_`.
7. **Make the settings file.** File Manager > `/home/<user>/pratyangi-staging/` > open `config.staging.example.php` > copy its content > **+ File**, name it
   exactly `config.local.php` (in the same folder, NOT in `public`) > edit it and paste. Save.
8. **Fill the REPLACE_ME values** in `config.local.php`:
   - `DB_NAME`, `DB_USER`, `DB_PASS`: from step 3 (`DB_HOST` stays `localhost`).
   - `OTP_PEPPER`: a NEW random secret, at least 32 characters (use 48 letters and digits; a password manager can make one). Do not reuse one from your computer.
   - `SERVER_CHECK_KEY`: another random value, at least 16 characters. You will put it in the check address in step 12.
   - `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`: cPanel > **Email Accounts** > create `no-reply@pratyangi.com` > **Connect Devices** shows the outgoing server and port.
     Keep `SMTP_PORT` 587 with `SMTP_SECURE` tls, or 465 with ssl.
   - `ERP_BASE_URL`, `ERP_CATALOG_KEY`, `ERP_ORDER_KEY`: the test ERP. If the test ERP is not ready, change `ERP_MODE` to `off` (the shop pages still open; the catalogue says "try again later").
   - `GOOGLE_CLIENT_ID`: optional. Leave empty and the Google button is hidden.
   - Leave `STAGING` true, `APP_ENV` production, `PAYMENTS_ENABLED`, `CHECKOUT_ENABLED` and `SMS_ENABLED` false.
   Never send this file by e-mail or chat, and never copy it into `public`.
9. **Turn on https.** cPanel > **SSL/TLS Status** > tick `test.pratyangi.com` > **Run AutoSSL**. Wait a few minutes until it shows a green padlock.
   (The shop redirects http to https by itself once the certificate exists.)
10. **Lock the site with a password** so only you can see it. cPanel > **Directory Privacy** > open `pratyangi-staging` > `public` > tick **Password protect this directory**,
    give it a name, Save > Add a user and a strong password > Save. Use these only for the browser pop-up.
11. **Scheduled jobs.** This build has no scheduled job yet (the payment recovery job comes with online payments, which are off here). Leave **Cron Jobs** empty.
    When that job is added, this guide will carry the exact line.
12. **Run the server check.** Open `https://test.pratyangi.com/server-check?key=<your SERVER_CHECK_KEY>` (log in with the Directory Privacy user first).
    Every row must say `ok`. A row that says FAIL tells you what to fix: database (check `DB_*`), writable storage/cache (step 5), erp reachable (ERP address or `ERP_MODE=off`).
    The page shows no passwords. With a wrong or empty key it answers "Not found".

## Test URLs

Open each; all must load without an error page.

- `https://test.pratyangi.com/` home
- `/new-arrivals`, `/collection/sarees` (a name that exists in the catalogue), `/search?q=saree`
- `/login`, `/signup`, `/forgot-password` (try a real e-mail code: the mail must arrive and show the company footer)
- `/contact`, `/privacy`, `/terms`, `/refund-policy`, `/shipping-policy`
- `/api/v1/health`
- View the page source: it must contain `noindex` (the `X-Robots-Tag: noindex` header is sent on every page).

## Update later

1. On your computer: build a new `pratyangi-staging.zip`.
2. cPanel > **phpMyAdmin** > Export your database (Quick, SQL) and keep the file. If the update brings a new numbered SQL file, import only that file.
3. File Manager: select `app`, `public`, `sql`, `tools` > **Compress** to `backup-<date>.zip` (keep it). Do not include `config.local.php`.
4. Upload the new zip into `pratyangi-staging/` > Extract (overwrite when asked) > delete the zip. `config.local.php`, `storage` and `cache` are not in the zip, so they stay.
5. Extracting replaces `public/.htaccess`, which also holds the Directory Privacy lines: open `https://test.pratyangi.com/` and, if it no longer asks for a password, repeat step 10.
6. Run the server check again and open two or three test URLs.

## Roll back

1. File Manager: delete the new `app`, `public`, `sql`, `tools` folders (not `config.local.php`, `storage` or `cache`).
2. Upload `backup-<date>.zip` > Extract into `pratyangi-staging/`.
3. If the update changed the database, phpMyAdmin > your database > **Operations** > drop all tables (tick all > With selected: Drop), then **Import** the SQL export you saved in "Update later" step 2.
4. Repeat Directory Privacy (step 10) if the password prompt is gone, then run the server check.

## If something goes wrong

- A white page or "The site cannot be reached right now": open File Manager > `pratyangi-staging/storage/logs` > the newest log file names the problem (setting names only, never values).
- "Not found" for every page: the document root (step 2) is not `pratyangi-staging/public`.
- Pages open but have no styles: the extraction went one folder too deep (the `public` folder must hold `index.php` and `assets`).
- E-mail does not arrive: check `SMTP_*`, that `no-reply@pratyangi.com` exists, and the spam folder.

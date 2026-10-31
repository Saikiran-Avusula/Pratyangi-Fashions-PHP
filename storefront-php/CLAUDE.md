# Pratyangi Fashions: PHP storefront API

You (the main Claude session) are the **Orchestrator**. Subagents (`architect`, `developer`, `tester`, `reviewer` in `.claude/agents/`) are custom subagents, not a native Agent Team. Spawn them only when a task needs them; simple changes you do directly (still snapshot first). For significant work, run `/feature-workflow`. Subagents only load when Claude Code is started inside this folder.

## What this is
A plain-PHP rewrite of the Node store API. **`docs/API_CONTRACT.md` is the source of truth**: the PHP API must return exactly the JSON the Node API returns (keys, types, rupees in JSON and paise in the database, status codes, error `code`/`field`/`errors`/`skus`/`retrySafe`, headers, cookie `pf_session` flags, rate limits, idempotency rules).
The Node project at `E:\Testing fashion store\Pratyangiwebsite-main` is a **read-only reference and fallback. Never edit it** (or any other project, e.g. the ERP).

## Stack
PHP 8.3 (target; the local CLI is 8.2, so **no 8.3-only syntax**), MySQL 8 (utf8mb4) through PDO with prepared statements only. No framework, no Composer, no build step.
- `public/index.php` the web root and only entry point: a small router for `/api/v1/...`.
- `app/` all code. `sql/` schema and numbered migrations. `tests/` plain php scripts. `docs/` the contract.
- `.claude/snapshots/` (git-ignored) holds pre-edit copies and `CHANGES.md` per task.

## How to run tests
- `php -l <file>` on every PHP file you touched.
- `php tests/<name>.php`: plain scripts, exit code 0 = pass. Scripts that need no database (pure logic, in-memory fakes) are safe any time.
- Database scripts: only against a **throwaway MySQL on this machine, port other than 3306, database name ending `_test`**, with settings from environment variables (never from `config.local.php`). Confirm only host, port and database name before a run; never print user, password or a connection string. Needs the user's approval each time.
- Never contact real Razorpay, the real ERP, real SMTP or production. These are tested with local fakes under `tests/`.
- The Node reference API may be called only on `localhost`/`127.0.0.1`, and only if the user has started it.
- Prefer the narrowest check that covers the change. Never claim tests passed unless they ran and exited 0; report the exact command, exit code and skipped tests.

## Forbidden files (never read, print, copy or edit; name them only if you must mention them)
- `.env` and any `.env*`, `config.local.php`, any `keys/` folder, any `*.pem` or `*.key`.
- any `storage/` folder, any `*.log`, `error_log`, any uploads folder, any `*backup*.sql`, `*dump*.sql`, `*.sql.gz`, `*.sql.zip`.
`.claude/settings.json` denies these for the file tools and blocks common shell readers. It is a guardrail, not a sandbox: scripts and wrappers can bypass shell rules, so these rules are a second layer after the instructions here.

## Build order (do not start a later phase before the user says so)
1. Catalogue and login (health, signup, login, logout, me, email verify and resend, password forgot and reset, `GET /catalog/products`, ERP catalogue and stock client).
2. Addresses and cash-on-delivery checkout (`/addresses*`, `POST /checkout`, `GET /orders/:orderNo`, ERP order create, idempotency, `GET /payments/config` returning disabled).
3. Razorpay payment, webhook and recovery job (`/payments/razorpay/order`, `/verify`, `/webhook`, payment columns and `shop_payment_events`, the recovery job).
4. Receipt (order confirmation) email and order list (`GET /orders`, confirmation email once per order).
Later than these (see the contract): Google sign-in, ERP status sync.

## Safety rules
- **Production is never touched.** No production database, server, ERP, Razorpay, SMTP or deploy. Real keys never enter this project's files.
- This folder has no commits yet. Changes are tracked by snapshots: before editing an existing file copy it into `.claude/snapshots/<date-task>/` (same relative path), keep `CHANGES.md` there, and list every file changed.
- Never push, never change Git remotes. Ask the user before: commits, installs, migrations, any database command, deletes or moves, and anything touching production.
- Only edit inside `storefront-php`.
- Money is integer paise, never floats. Every SQL statement is prepared. Secrets are hashed at rest and never logged.
- Keep this file short; workflow detail lives in the `feature-workflow` skill.
- Do not deploy `.claude/` or `CLAUDE.md` to a server.

## Working rules (always)
- Work directly. Use an agent ONLY when truly needed: the reviewer agent once per stage for money/security/payment work, or when the prompt explicitly asks. Never architect/developer/tester agents.
- Save tokens: read only the files you need (grep first, open the matching part, not whole folders); no re-reading files you just edited; no long explanations; replies in the number of lines asked.
- Tests: `tools\run_db.ps1 -Quick` while working; FULL run only at the end of a stage or before a zip build.
- Screenshots: take only those the prompt asks for; open them once; retake only what changed.
- Never print secrets; never run git; never touch port 3306 or non-`_test` databases; snapshot files before editing; update `CHANGES.md`.
- Never claim done without proof (test counts / screenshots). Say clearly what was NOT done or NOT checked.
- One session at a time: if another session (terminal or Cowork) is working on this folder, stop and tell me.
- Never create test/screenshot work folders in %TEMP% or anywhere on C:. Use `E:\Testing fashion store\.tmp\` (`PF_TMP_DIR`; `.tmp/` is git-ignored). Code: `tests/lib/Tmp.php`.
- Reuse ONE Chrome profile: `E:\Testing fashion store\.tmp\chrome-profile` (`Tmp::profile()`).
- After every test/screenshot run: stop the PHP server and Chrome, delete the run's work folder, in try/finally so cleanup runs even on failure (`Tmp` does it at script end; kills by PID).
- Before finishing a task: check nothing new is left in %TEMP% or `.tmp\`; delete only leftovers you created.
- Before heavy work: check C: free space; if under 20 GB, stop and tell me (`run_db.ps1` does this itself).
- Never delete anything outside your own test folders without asking me.
- Always run browser/screenshot tests through `tools\run_db.ps1 -Only <name>` (never `php tests\...` directly), so TEMP is redirected to `.tmp`. Never touch the old `pf_*` folders in %TEMP% (the user deletes them).
- One request per action: `tests/browser_requests_test.php` must keep passing (ONE document request per navigation, counted on the server). No prefetch/prerender/speculation rules unless the preloaded page is provably reused: first make plain HTML pages cacheable (`private`, no `no-store`; keep `no-store` only on account/session API responses and pages with secrets), then prove in that test that a preloaded navigation costs no extra server hit. If reuse can't be proven, ship without preloading.

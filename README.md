# WhatsUP Campaign Manager

A multi-tenant WhatsApp campaign operations starter built with PHP 8.2, PDO, MySQL/MariaDB, semantic server-rendered HTML, CSS and vanilla JavaScript. It manages opted-in contacts and suppression, ordered templates, quota-backed campaigns, a persistent queue worker, signed provider callbacks, admin tools, and a local delivery simulator.

> **Important:** The built-in simulator is for local development only. It does not connect to WhatsApp. A webhook HTTP 2xx means the configured delivery adapter accepted a request, not that WhatsApp delivered it. Use a compliant provider, enforce current WhatsApp Business Platform template and messaging-window requirements, and obtain appropriate consent before production use.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `intl`, `mbstring`, `fileinfo`, `curl`, `openssl`, and `json` extensions.
- MySQL 8+ or MariaDB 10.4+ with InnoDB enabled.
- Apache with `mod_rewrite` and `.htaccess` overrides enabled (or equivalent front-controller configuration).
- XAMPP for the included local path, or a comparable PHP/Apache/MySQL setup.
- CLI PHP matching the Apache PHP build for migration, admin bootstrap, tests, and worker commands.

## Fresh local installation (XAMPP)

1. Place this project at `D:\xampp\htdocs\WhatsUP` (or your Apache document root).
2. Create an empty database, for example:
   ```sql
   CREATE DATABASE whatsup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'whatsup_app'@'localhost' IDENTIFIED BY 'replace-with-a-long-random-password';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON whatsup.* TO 'whatsup_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
   For local XAMPP only, the existing `.env` uses `root` with no password. This is not suitable for production.
3. Copy `.env.example` to `.env`, set the DB DSN/user/password, and generate independent random values for `APP_KEY`, `ACCESS_CODE_PEPPER`, `WEBHOOK_SECRET`, and `CALLBACK_SECRET`. Exclude `.env` from source control and web access. Keep `APP_ENV=local` only for local development. Set `SESSION_SECURE=true` behind HTTPS.
4. Ensure writable private storage exists (included directories are created in this checkout): `storage/`, `storage/media/`, and `storage/logs/`. The Apache/PHP identity needs access. Keep storage outside the public document root where possible. Root `.htaccess` denies direct access to storage in this Apache layout.
5. Apply migrations (safe to re-run; each sorted SQL file is recorded in `schema_migrations`):
   ```powershell
   D:\xampp\php\php.exe scripts\migrate.php
   ```
6. Create the initial administrator (interactive prompt avoids putting a password in shell history):
   ```powershell
   D:\xampp\php\php.exe scripts\create-admin.php
   ```
   Or provide name/email/password as CLI arguments; do not use that form on shared process hosts because arguments may be visible to local users.
7. Optionally load fictional sample contacts, group and template:
   ```powershell
   D:\xampp\php\php.exe scripts\seed.php
   ```
   Seed data is synthetic; the demo operator password is deliberately random and inaccessible. Register a normal operator account or use your admin account.
8. Open `http://localhost/WhatsUP/login`.

Registration creates an operator workspace. Admin privileges must be granted by the initial administrator. Password-reset links are written to `storage/logs/reset-links.log` only in `APP_ENV=local`; configure SMTP or another secure delivery channel before exposing account recovery in production.

## Local simulated end-to-end delivery

The checked-in `.env` is configured for local simulation. Confirm these settings:

```dotenv
APP_ENV=local
APP_URL=http://localhost/WhatsUP
WEBHOOK_URL=http://localhost/WhatsUP/simulator/webhook
WEBHOOK_ALLOW_HTTP_LOCAL=true
SIMULATOR_ENABLED=true
```

The simulator route refuses requests unless `APP_ENV=local`, the explicit enable flag is on, and `APP_URL` points at localhost. It validates the worker's timestamp + raw-body HMAC signature with `WEBHOOK_SECRET`, then emits a locally generated `delivered` callback signed with `CALLBACK_SECRET`. It never contacts WhatsApp. Do not enable it on a public or production deployment.

1. Register/sign in, create contacts with `Opted in` and a consent source (unknown consent is not eligible), and create a text or mixed-media template. Then create a campaign: review the projected part usage, enter an unused access code assigned to your account, and confirm. Confirmation atomically rechecks ownership, consent, audience, quota and the access code before queueing.
2. An administrator creates/issues an access code from Admin and assigns it to the operator account. Codes are one-use per assigned account and expire at midnight in the issuing administrator's configured local timezone (stored in UTC).
3. Start the persistent PHP CLI worker in a separate terminal:
   ```powershell
   D:\xampp\php\php.exe scripts\worker.php
   ```
   One polling pass is available using `--once`. Stop normally with Ctrl+C. The worker must not be exposed as a web route. MariaDB 10.4 compatibility means job claims serialize under row locks (safe, lower parallel throughput).
4. Refresh campaign status to see provider accepted/delivered state. The simulator callback updates it to delivered. To run a disposable complete local verification (creates and removes its own synthetic account/contact/template/campaign and uses only the local simulator), run `D:\xampp\php\php.exe scripts\test-local-e2e.php`. For another local webhook adapter, send JSON to `callbacks/provider` with `X-Webhook-Timestamp` and `X-Webhook-Signature: sha256=<hex>`; the signature is `HMAC_SHA256(timestamp + "." + exact_raw_body, CALLBACK_SECRET)`. Include unique `event_id`, numeric `job_id`, and status `accepted`, `delivered`, or `failed`. Callbacks are timestamp-bounded and event-ID deduplicated.

Worker payload contract: JSON includes `job_id`, stable `idempotency_key`, `campaign_id`, `recipient_id`, `user_id`, `to` (E.164), `sequence`, and `part` (`type`, `text`, `caption`, and private media reference/MIME metadata). The client sends `X-Webhook-Timestamp`, `X-Webhook-Signature` using `WEBHOOK_SECRET`, and `Idempotency-Key`. Return a 2xx JSON response with optional `message_id` to record provider acceptance; callback is still needed to confirm delivery. Adapters should fetch/upload media through an appropriately authenticated private mechanism rather than assuming a local storage reference is remotely accessible.

## Navigation and dashboard

Authenticated screens use a persistent left sidebar on desktop with role-aware links to Overview, Contacts, Groups, Templates, Campaigns, Settings, and (for administrators only) the Admin console. The active screen is highlighted; workspace identity, daily message-part limit, and sign-out are shown at the bottom. At tablet and phone widths, the sidebar becomes an accessible slide-in menu with a backdrop and Escape/close controls. The dashboard adds quick-action links and campaign summary cards. Login, registration, and password-recovery screens remain a separate compact layout.

## Application behavior and policy

- Every user-owned record is scoped to its account. Admin operations verify active administrator status and are audited.
- E.164 normalization uses `intl` when available and `DEFAULT_REGION` (default `GH`). Unknown consent is never sent. Opt-out is explicit; worker rechecks active account and consent immediately before dispatch and releases still-pending quota reservations when suppressed.
- Groups organize membership only; they do not grant consent. Selected contacts are deduplicated across individual/group targeting and snapshotted for a campaign.
- Templates have ordered text/image/audio/document parts; media uploads are type-checked, size-limited and stored privately. Templates are snapshotted into campaign parts.
- Quota is counted as one recipient × one ordered part. Reservations are serialized by a user/day bucket. Pending cancellation and permanent failure release once; acceptance consumes a reservation; retry reuses the same job/idempotency key and re-reserves quota if previously released.
- The campaign worker sends each recipient's parts sequentially. Provider acceptance is not the same as delivery. Signed callbacks are authoritative for delivered/failed status.
- Reset, callback and webhook signing secrets must be different, random values and rotated if exposed. Only HTTPS webhook destinations are allowed outside explicit localhost development mode.

## Tests

Tests only connect to the explicitly isolated `whatsup_test` database; they refuse any other DB name. Create an empty test database/user if needed, then run:

```powershell
D:\xampp\php\php.exe tests\run.php
```

The 41-check integration suite covers auth/admin roles, tenant isolation, E.164 and consent/opt-out, group ownership, mixed-media ordering, campaign snapshots, per-part quota/idempotence, actual concurrent quota contention using two child PHP processes, cancellation/retry, and signed callback replay protection. `tests/campaign-services.php` is an earlier narrower smoke test; prefer `tests/run.php` because old runs of the smoke test may leave fixtures behind. Both scripts target only `whatsup_test` when `DB_DSN` is overridden. The separate local simulator test at `scripts/test-local-e2e.php` is intentionally scoped to the configured `whatsup` application database, but creates a random disposable account and deletes all its dependent records afterward.

## Operations and production checklist

- Use a dedicated least-privileged DB account; protect DB network access and use unique production secrets. Never deploy the checked-in local `.env` values.
- Terminate TLS at Apache/reverse proxy, set `SESSION_SECURE=true`, disable `APP_DEBUG`, turn off `SIMULATOR_ENABLED`, and remove localhost HTTP exceptions. Store secrets in a secret manager/environment rather than committed files.
- Configure real provider webhook and callback authentication, callback delivery retries, message/template policy, opt-out keyword processing, provider-specific media transport, monitoring, and SMTP before sending real messages. The basic SMTP variables are placeholders; the app does not implement SMTP delivery yet.
- Run the worker under a process supervisor or scheduled service account; keep CLI logs private, monitor stale claims and queue failures, and maintain capacity. Use HTTPS and stable idempotency on the provider adapter.
- Back up the database and private storage together; test restoration regularly. Retain consent/audit data according to law and organizational policy. Remove old rate-limit/import/reset records according to retention policy.
- Configure web server denial for `.env`, scripts, tests, database, and storage; verify after each host migration. The supplied `.htaccess` rules assume Apache `AllowOverride` is active.

## Troubleshooting

- **HTTP 404/500:** ensure Apache `mod_rewrite` and overrides are enabled, then check Apache/PHP error logs. Do not expose debug errors publicly.
- **DB connection failure:** check `.env` DSN, DB permissions, and that PDO MySQL is enabled in both CLI and Apache PHP.
- **Worker has no work:** ensure campaign has eligible opted-in recipients, an assigned unexpired access code was used, campaign is within quota, and `available_at` is due. Provider messaging-window restrictions are adapter/provider policy; the app does not impose a local 08:00–20:00 sending window.
- **Webhook retries:** verify endpoint TLS/local simulator flags, network reachability, HMAC secret agreement, and 2xx behavior. Retry uses the same idempotency key.
- **Upload rejected:** verify `fileinfo`, PHP upload limits, allowed MIME type, maximum size and private storage permissions.

## Migrations

Add future schema changes as new, ordered files under `database/migrations/` (for example `002_add_feature.sql`) and use `scripts/migrate.php`. MySQL DDL may implicitly commit, so each migration is applied statement-by-statement and recorded after success; keep migrations re-runnable or repair partial DDL before retrying a failed migration. Do not edit already-applied migration files in deployed environments.

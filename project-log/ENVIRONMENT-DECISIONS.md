# Environment & technical decisions

[← Back to INDEX](INDEX.md)

## Local environment (found 2026-11-19)

| Tool | Version | Spec requirement | OK? |
|---|---|---|---|
| OS | Windows 11 Pro (XAMPP) | Linux VPS for production | ✅ (dev only) |
| PHP | 8.2.12 | 8.3+ | ⚠️ see D1 |
| Database | MariaDB 10.4.32 (XAMPP's "MySQL") | MySQL | ✅ compatible (see D2) |
| Composer | 2.10.2 | — | ✅ |
| Node / npm | 24.18.0 / 11.16.0 | — | ✅ |
| PHP extensions | pdo_mysql, pdo_sqlite, openssl, mbstring, curl, fileinfo, zip, gd ✓ — intl ✗ | — | ✅ |

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | Use **Laravel 12** (newest version that runs on PHP 8.2). Code is written to also run on PHP 8.3 / 8.4. | Local PHP is 8.2.12. Laravel 13 needs PHP 8.3. Production VPS should run PHP 8.3+ as the spec asks; upgrading to Laravel 13 later is a normal minor upgrade. |
| D2 | MariaDB locally, MySQL 8 recommended on the VPS. Schema avoids MySQL-only / MariaDB-only features. | XAMPP ships MariaDB. Both work with Laravel's `mysql`/`mariadb` drivers. |
| D3 | Automated tests use SQLite in-memory; Meta API is always mocked with `Http::fake()`. | Fast, isolated tests; no real messages sent during tests. |
| D4 | Breeze (Blade + Alpine + Tailwind) for authentication; public registration disabled. | Matches the spec stack; this is an admin tool, so only admins create users. |
| D5 | Roles & permissions via `spatie/laravel-permission`; account-level access via a `user_whatsapp_account` pivot. | Proven package; account pivot gives per-number access control (spec §9). |
| D6 | Secrets (access token, app secret, verify token) stored with Laravel `encrypted` casts (AES-256 with `APP_KEY`). | Encryption at rest (spec §3, §12). Losing `APP_KEY` makes saved tokens unreadable — back it up. |
| D7 | Webhook URL uses a random public **UUID** for `{account}`, not the numeric DB id. | Numeric ids are guessable; UUID avoids account enumeration. |
| D8 | Queue driver `database` locally; Redis optional on the VPS. Reverb for WebSockets. | Works on XAMPP without extra services. |
| D9 | Default Graph API version **v26.0** (configurable per account and via `WHATSAPP_GRAPH_VERSION`). | Latest version listed in Meta's changelog (released 2026-07-29). |
| D10 | Every Graph call sends `appsecret_proof`. | Confirms the App Secret belongs to the token's app; required if "Require App Secret" is enabled. Can be turned off with `WHATSAPP_APPSECRET_PROOF=false`. |
| D11 | Sending is **queued** (message saved as `pending` first). | Retries on rate limits / network errors without losing messages; UI updates through events. Needs a running queue worker. |
| D12 | One Meta app → one callback URL: events are routed by `phone_number_id`, only between accounts with the same App Secret. | Meta allows only one webhook URL per app; same-secret rule keeps different apps isolated. |
| D13 | Customer-sent documents are always downloaded (never rendered) with a `sandbox` CSP. | Prevents a customer-sent HTML/SVG from running script in the dashboard. |
| D14 | Laravel Reverb installed with `--prefer-source`. | Windows antivirus blocked Composer's zip download on this PC; on Linux use the normal install. |
| D15 | `composer run dev` runs server + queue + Reverb + Vite (no `pail`). | `pail` needs `pcntl`, which Windows PHP does not have. |
| D16 | Optional `DemoDataSeeder` (clearly labelled demo number, fake token). | Lets you see the UI without Meta credentials; blocked in production. |

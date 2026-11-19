# Phase 7 — Multiple accounts, security, testing & deployment

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done (code, tests, docs) — VPS deployment itself is for you to run |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §9 Multiple accounts, §12 Security, §14 Testing, §15 Installation & deployment |

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 7.1 | Account switcher; isolation audit | ✅ | Global switcher (session, always re-checked) + chat switcher; `AccountIsolationTest` (9 tests, 40+ assertions) |
| 7.2 | Policies / scopes on every account query | ✅ | `accessibleAccounts()`, `WhatsAppAccountPolicy`, `ConversationPolicy`, `ContactPolicy`, private channels; foreign IDs give 403/404 |
| 7.3 | Security headers, HTTPS-only production | ✅ | nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS (prod), `URL::forceScheme('https')`, secure cookies via `.env` |
| 7.4 | Log redaction | ✅ | `App\Logging\RedactSecrets` Monolog tap (EAA… tokens, Bearer, appsecret_proof, signatures, secret keys); ActivityLogger redaction |
| 7.5 | Rate limiting | ✅ | Login 5 tries (Breeze), webhook 1200/min/number, sending 60/min/user, API checks 10/min, import/export 10/min, search 60/min |
| 7.6 | Full test suite | ✅ | **158 passed, 613 assertions** |
| 7.7 | `.env.example` | ✅ | All variables documented with production values |
| 7.8 | `docs/LOCAL-INSTALLATION.md` | ✅ | |
| 7.9 | `docs/VPS-DEPLOYMENT.md` | ✅ | Ubuntu 24.04, PHP 8.3, Nginx (+ Reverb proxy), Certbot, Supervisor, cron, update steps, backups |
| 7.10 | `docs/META-SETUP.md` | ✅ | App, WABA, phone, System User token, App Secret, webhook fields, go-live, error table |
| 7.11 | Final review; reports updated | ✅ | This log |

### Extra
- Scheduled jobs (`routes/console.php`): hourly `whatsapp:check-accounts` (detects expired tokens), daily prune of processed webhook payloads (30 days) and old failed jobs.
- `whatsapp:retry-webhooks` command to re-process failed webhook events.
- `PageSmokeTest` renders every page for each role.

## Files created / changed
`tests/Feature/AccountIsolationTest.php`, `SecurityTest.php`, `PageSmokeTest.php`, `app/Logging/RedactSecrets.php`, `config/logging.php`, `app/Models/WebhookEvent.php` (MassPrunable), `routes/console.php`, `.env.example`, `composer.json`, `README.md`, `docs/LOCAL-INSTALLATION.md`, `docs/VPS-DEPLOYMENT.md`, `docs/META-SETUP.md`.

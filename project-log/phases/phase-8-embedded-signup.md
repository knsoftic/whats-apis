# Phase 8 — WhatsApp Embedded Signup (Meta Tech Provider)

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code done + tested with mocked Meta API · ⏳ live test needs your Tech Provider app on https://whats-apis.com |
| **Started / finished** | 2026-11-20 |
| **Requested by user** | "Connect with Meta" (Embedded Signup) **and** manual entry, both available |

## What was built

| # | Task | Status | Notes |
|---|---|---|---|
| 8.1 | Meta Tech Provider settings page (Super Admin) | ✅ | App ID, App Secret (masked), Configuration ID, verify token, Graph version; encrypted `settings` table; "Test App ID + App Secret" |
| 8.2 | Embedded Signup page "Connect with Meta" | ✅ | Facebook JS SDK, `FB.login` with `config_id`, `response_type: code`, `extras: {setup: {}}` (ES **v4**); listens to `WA_EMBEDDED_SIGNUP` messages (FINISH, FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING, CANCEL, ERROR) |
| 8.3 | Server-side onboarding | ✅ | Code → business token (`/oauth/access_token`), WABA check via `/debug_token`, `POST /{waba}/subscribed_apps`, `POST /{phone}/register` (6-digit PIN; skipped for coexistence), save account, API check, activity log |
| 8.4 | Shared platform webhook | ✅ | `GET/POST /api/webhooks/whatsapp`, signed with platform App Secret, routed by phone_number_id / WABA id, only to accounts of the same app; unknown numbers acknowledged (no retries) |
| 8.5 | Manual entry kept | ✅ | "Add manually" + per-number webhook unchanged |
| 8.6 | Account page | ✅ | "Embedded Signup" badge, registration status, retry registration with PIN, platform-webhook info |
| 8.7 | Tests | ✅ | `EmbeddedSignupTest` (10), `PlatformWebhookTest` (7), `MetaSettingsTest` (5) |
| 8.8 | Docs | ✅ | `docs/META-SETUP.md` Option A (Embedded Signup) / Option B (manual) |

## Files
`database/migrations/2026_11_20_100000_add_embedded_signup_support.php`, `app/Models/Setting.php`, `app/Support/MetaPlatform.php`, `app/Services/WhatsApp/EmbeddedSignupService.php`, `app/Exceptions/EmbeddedSignupException.php`, `app/Http/Controllers/EmbeddedSignupController.php`, `app/Http/Controllers/MetaSettingsController.php`, `WhatsAppWebhookController.php` (platform endpoints), `WhatsAppCloudApiService::registerPhoneNumber()`, `routes/api.php`, `routes/whatsapp.php`, `resources/js/embedded-signup.js`, `resources/views/accounts/connect.blade.php`, `resources/views/settings/meta.blade.php`, accounts index/show, sidebar, `app/Support/Permissions.php` (`settings.manage`).

## How to go live (whats-apis.com)
1. Re-run the installer on the server (pulls code, runs the new migration, rebuilds assets).
2. Sidebar → **Meta Tech Provider** → enter App ID, App Secret, Configuration ID, verify token → Save → Test.
3. Meta app → WhatsApp → Configuration → webhook `https://whats-apis.com/api/webhooks/whatsapp` + verify token → Verify and save; subscribe `messages`, `account_update`.
4. Facebook Login for Business → allowed domain + redirect URI `https://whats-apis.com`.
5. WhatsApp Accounts → **Connect with Meta** → complete the popup with a real number.

## Test results
`php artisan test` → **180 passed (704 assertions)**.

## Notes / limits
- Meta docs (read 2026-11-20) show `FB.init` version v27.0 in examples; this app uses the Graph version from settings (default v26.0, still supported). Change it on the settings page if needed.
- Business token lifetime is not stated in Meta's docs; the hourly `whatsapp:check-accounts` flags a token that stops working.

# Phase 1 — Laravel installation, authentication, database & dashboard

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested |
| **Started** | 2026-11-19 10:50 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §1 Stack, §2 Auth & dashboard, §11 Database, §13 UI/UX |

## Goal
A working Laravel app with secure admin login, roles & permissions, user management, activity logs, the complete database schema, and a branded dashboard with live statistics.

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 1.1 | Install Laravel (latest version compatible with local PHP) | ✅ | Laravel **12.73.0** (PHP 8.2 local). See ENVIRONMENT-DECISIONS D1 |
| 1.2 | Configure `.env` and MySQL database | ✅ | DB `kn_whatsapp` on XAMPP MariaDB 10.4, utf8mb4 |
| 1.3 | Authentication (Breeze — Blade + Alpine + Tailwind): login, logout, password reset, profile | ✅ | Breeze 2.4.2, restyled with KN Softic branding |
| 1.4 | Disable public registration (only admins create users) | ✅ | `/register` returns 404; controller + view removed |
| 1.5 | Secure session settings | ✅ | DB sessions, `SESSION_ENCRYPT=true`, regenerate on login, inactive users blocked + logged out mid-session |
| 1.6 | Roles & permissions (Super Admin / Admin / Agent) | ✅ | spatie/laravel-permission 6.25, 13 permissions in `app/Support/Permissions.php` |
| 1.7 | User management (list, create, edit, deactivate, assign roles) | ✅ | Also assigns WhatsApp numbers; protects last Super Admin; no self-delete/demote |
| 1.8 | Core migrations for every table in spec §11 | ✅ | All 8 migrations run on MariaDB without errors |
| 1.9 | Eloquent models + relationships | ✅ | 11 models |
| 1.10 | Factories + seeders | ✅ | Seeder creates roles + first Super Admin from `.env` |
| 1.11 | Activity log | ✅ | Login, logout, failed login, password reset, profile & user changes; secrets redacted |
| 1.12 | Dashboard statistic cards | ✅ | Numbers (operational / total), contacts, sent, received, failed, unread |
| 1.13 | Recent conversations widget | ✅ | + 7-day sent/received chart + number health list |
| 1.14 | Branded layout: sidebar, top bar, dark/light mode, responsive | ✅ | Mobile drawer sidebar, theme saved in browser, account switcher |
| 1.15 | Toast notifications, empty states, loading indicators | ✅ | Blade components `x-toasts`, `x-empty-state`, spinners on submit |
| 1.16 | Tests | ✅ | 36 tests pass (see TESTING.md) |

## Database tables created
`users` (+ is_active, last_login_at/ip), `password_reset_tokens`, `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `whatsapp_accounts`, `user_whatsapp_account`, `contacts`, `tags`, `contact_tag`, `contact_notes`, `conversations`, `messages`, `message_attachments`, `message_templates`, `webhook_events`, `activity_logs`.

Key constraints: `whatsapp_accounts.phone_number_id` unique · `whatsapp_accounts.uuid` unique · `contacts (whatsapp_account_id, wa_id)` unique · `conversations (whatsapp_account_id, contact_id)` unique · `messages (whatsapp_account_id, wa_message_id)` unique (duplicate guard) · `webhook_events (whatsapp_account_id, payload_hash)` unique · `message_templates (whatsapp_account_id, name, language)` unique.

## Files created / changed
| Area | Files |
|---|---|
| Config | `config/whatsapp.php` (new), `config/app.php` (name, timezone, seed admin), `config/permission.php`, `.env`, `tailwind.config.js` |
| Bootstrap | `bootstrap/app.php` (api routes, middleware, permission aliases, secret-safe exceptions) |
| Migrations | `database/migrations/0001_01_01_000000_create_users_table.php` (edited), `2026_11_19_061653_create_permission_tables.php`, `2026_11_19_100000_create_whatsapp_accounts_table.php`, `2026_11_19_100100_create_contacts_table.php`, `2026_11_19_100200_create_conversations_and_messages_tables.php`, `2026_11_19_100300_create_templates_webhooks_activity_tables.php` |
| Models | `app/Models/User.php`, `WhatsAppAccount.php`, `Contact.php`, `Conversation.php`, `Message.php`, `MessageAttachment.php`, `MessageTemplate.php`, `WebhookEvent.php`, `ActivityLog.php`, `Tag.php`, `ContactNote.php` |
| Support / services | `app/Support/Permissions.php`, `app/Support/CurrentAccount.php`, `app/Services/ActivityLogger.php` |
| Middleware / listeners | `app/Http/Middleware/EnsureUserIsActive.php`, `SecurityHeaders.php`, `app/Listeners/LogAuthenticationActivity.php` |
| Controllers | `DashboardController.php`, `UserController.php`, `ActivityLogController.php`, `CurrentAccountController.php`, `ProfileController.php` (self-delete removed), `Http/Requests/UserRequest.php`, `Http/Requests/Auth/LoginRequest.php` (inactive users blocked) |
| Routes | `routes/web.php`, `routes/auth.php` (registration removed), `routes/whatsapp.php`, `routes/api.php` |
| Views | `layouts/app.blade.php`, `layouts/sidebar.blade.php`, `layouts/guest.blade.php`, `layouts/partials/account-switcher.blade.php`, `dashboard.blade.php`, `users/index|form.blade.php`, `activity-logs/index.blade.php`, `auth/login.blade.php`, `profile/edit.blade.php`, components `icon, badge, button, card, stat-card, empty-state, page-header, toasts, application-logo` |
| Frontend | `resources/js/app.js` (http helper, toasts, theme toggle, copy), `resources/css/app.css` |
| Factories / seeders | `UserFactory` (role states), `WhatsAppAccountFactory`, `ContactFactory`, `ConversationFactory`, `MessageFactory`, `RolesAndPermissionsSeeder`, `DatabaseSeeder` |
| Tests | `tests/TestCase.php` (no Vite, no real HTTP), `tests/Feature/AuthorizationTest.php`, `tests/Feature/UserManagementTest.php`, Breeze auth tests (registration + self-delete tests removed) |

## Commands run
```bash
composer create-project laravel/laravel _tmp_laravel     # then moved into project root
composer install                                          # retry after Windows "Permission denied" download errors
composer require spatie/laravel-permission
composer require laravel/breeze --dev
php artisan breeze:install blade --dark
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
mysql -u root -e "CREATE DATABASE kn_whatsapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate --seed
npm run build
php artisan test
```

## How to test this phase
1. `php artisan serve` → open http://127.0.0.1:8000
2. Log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.
3. Check dashboard cards, dark/light toggle (moon/sun icon), mobile view (sidebar becomes a drawer).
4. Users → Add user (Agent) → log in as that user in a private window → Users page must give **403**.
5. Activity Logs → login entries visible.

## Test results
`php artisan test` → **36 passed (101 assertions)**.

## Issues found & fixes
| Issue | Fix |
|---|---|
| Composer "Permission denied" writing zip files (Windows antivirus lock) | Re-ran `composer install` with `COMPOSER_MAX_PARALLEL_HTTP=4` → succeeded |
| Breeze `--phpunit` flag doesn't exist in v2.4 | Ran without it (PHPUnit is the default) |
| `npm install` inside Breeze took > 10 min on this machine | Waited in background; finished OK |

# Work log (chronological)

[← Back to INDEX](INDEX.md)

Newest entries at the bottom. Each entry: date-time · phase · what was done · result.

---

### 2026-11-19 10:41 · Setup
- Checked project folder `C:\xampp\htdocs\whts api mi` → empty (fresh start).
- Checked environment: PHP 8.2.12, Composer 2.10.2, Node 24.18.0, npm 11.16.0, MariaDB 10.4.32, Git 2.55.
- MySQL (MariaDB) was not running → started `mysqld` from XAMPP.
- **Result:** environment usable. PHP is 8.2 (spec wants 8.3+) → decision recorded in [ENVIRONMENT-DECISIONS.md](ENVIRONMENT-DECISIONS.md).

### 2026-11-19 10:45 · Project log
- Created this project log: INDEX, 7 phase reports, STATUS, TESTING, ENVIRONMENT-DECISIONS, REMAINING, WORK-LOG.
- **Result:** ✅

### 2026-11-19 10:50 – 11:55 · Phase 1 — Foundation
- Laravel 12.73 installed (temp folder → moved to root; Composer "Permission denied" retried).
- spatie/laravel-permission 6.25, Breeze 2.4.2 (Blade + dark). Registration and self-delete removed.
- All migrations (24 tables) written and run on MariaDB; 11 models; factories; seeders (roles + first Super Admin from `.env`).
- Branded layout (sidebar, top bar, dark mode, account switcher, toasts), dashboard, users, activity logs.
- **Result:** ✅ 36 tests pass. UI checked in browser.

### 2026-11-19 12:00 – 12:25 · Phase 2 — WhatsApp accounts
- Confirmed latest Graph API = **v26.0** (Meta changelog, released 2026-07-29) → default.
- `WhatsAppCloudApiService`, `WhatsAppApiException` (friendly messages for Meta codes), credential validation (phone → WABA → subscribed apps, appsecret_proof).
- Accounts CRUD with encrypted secrets, masked tokens, three separate statuses, test/subscribe/toggle actions.
- **Result:** ✅ 58 tests pass.

### 2026-11-19 12:25 – 12:50 · Phase 3 — Webhooks
- Webhook controller (GET verify / POST signed), `ProcessWebhookEvent` job, `WebhookProcessor`, `IncomingMessageParser`, `DownloadMessageMedia` job, broadcast events.
- Design fix: one Meta app has one callback URL → route events by `phone_number_id` within the same App Secret.
- Bug found: message factory state read an unresolved attribute → fixed.
- **Result:** ✅ 88 tests pass.

### 2026-11-19 12:50 – 13:15 · Phase 4 — Sending
- `MessageSender`, `SendWhatsAppMessage` (retries/backoff, idempotent), `SendReadReceipt`, `TemplateMessageBuilder`, `OutgoingMediaValidator`, `MessageController`.
- Fixes: MIME now detected from file bytes (finfo); retry endpoint returns fresh status.
- **Result:** ✅ 112 tests pass.

### 2026-11-19 13:15 – 13:50 · Phase 5 — Real-time chat
- `install:broadcasting --reverb`; Reverb zip blocked by antivirus → installed via `--prefer-source`; duplicate `.env` key fixed; `reverb:install`.
- Laravel Echo + pusher-js; `ChatController`, `MediaController`, private channel auth, Alpine `chat.js`, chat Blade view.
- Optional `DemoDataSeeder` loaded locally for UI testing.
- Browser checks: chat list, chat window, ticks, dark mode, template modal ✅.
- **Live test:** Reverb + queue worker started → "Live updates connected" → signed webhook sent → message appeared instantly ✅.
- **Result:** ✅ 13 chat tests pass.

### 2026-11-19 13:50 – 14:10 · Phase 6 — Contacts & templates
- Contacts CRUD, tags, notes, consent, CSV import/export (Excel-safe), history; `ContactPolicy`.
- Template sync (paginated) / list / preview / send page.
- **Result:** ✅ 13 new tests pass.

### 2026-11-19 14:10 – 14:35 · Phase 7 — Isolation, security, docs
- `AccountIsolationTest`, `SecurityTest`, `PageSmokeTest`.
- `RedactSecrets` log tap, webhook payload pruning, hourly credential check, retry-webhooks command.
- `composer run dev` made Windows-friendly (server + queue + reverb + vite); Reverb constraint pinned `^1.14`.
- `.env.example`, `README.md`, `docs/LOCAL-INSTALLATION.md`, `docs/VPS-DEPLOYMENT.md`, `docs/META-SETUP.md`.
- **Result:** ✅ **158 tests, 613 assertions, 0 failures.** All project-log reports updated.

### 2026-11-19 · Production install guide (aaPanel)
- Domain **whats-apis.com**, aaPanel directory **/www/wwwroot/whats-apis.com**.
- Wrote a step-by-step Hindi guide as a Claude Doc: [whats-apis.com — aaPanel Installation Guide](https://claude.ai/artifact/Wdxy1F22e2iGPv5zKHosrP).
- Covers aaPanel software, PHP 8.3 extensions and disabled functions (`putenv`, `proc_open`, `pcntl_*`), website + DB + SSL, code upload, `.env` for production, Nginx running dir / rewrite / Reverb proxy, Supervisor daemons, cron, Meta webhook, checklist, troubleshooting, updates and backups.
- **Result:** ✅ Guide ready. The server install itself has not been done yet (the user runs it).

### 2026-11-19 · Git + one-command installer
- `deploy/install-aapanel.sh`: one command that installs **and** updates on aaPanel. It does 12 steps: checks, re-enables PHP functions, Composer/Node, clone/pull, composer, `.env`, migrate/seed, npm build, permissions, Nginx (/public, rewrite, Reverb proxy), Supervisor (queue + Reverb), cron, then a health check.
- Syntax checked (`bash -n`). The Nginx-insert and php.ini edits were dry-tested on sample files. **Not yet run on the real server.**
- Local git repo initialised (`main`); `.env`, `vendor`, `node_modules`, `public/build` and `.claude` are excluded. Initial commit `51cc0fd`.
- GitHub repo `knsoftic/whats-apis` (public, as requested): waiting for it to be created on github.com before pushing.

### 2026-11-20 · Pushed to GitHub
- User created https://github.com/knsoftic/whats-apis (public). Pushed `main` (commits `51cc0fd`, `c3bc910` + this log update).
- Checked: installer raw URL returns HTTP 200 with LF line endings; `.env` is not in the repo (404).
- **Next (user):** run the one command on the aaPanel server, then make the repo private.

### 2026-11-20 · First server run (aaPanel, PHP 8.4)
- Server has PHP 8.4 → installer now auto-detects the newest aaPanel PHP (commit `090d7d3`); all 91 packages allow 8.4.
- Run 1: steps 1–10 ✅ (incl. Nginx updated + reloaded). Step 11 ❌ "Failed at line 286": `find_sup_conf` returned non-zero when no supervisord.conf existed (`set -e` bug in the script).
- Fixed (commit `c677e7e`) + added `php artisan admin:reset-password {email}`. Re-run needed from step 1 (script is idempotent).

### 2026-11-20 · Phase 8 — Embedded Signup (Tech Provider)
- Read Meta's current Embedded Signup docs (v4; v2/v3 retired 15 Oct 2026).
- Built: Meta Tech Provider settings (encrypted), "Connect with Meta" page (FB JS SDK + ES v4), server onboarding (code exchange, debug_token WABA check, subscribed_apps, /register with PIN, coexistence), shared platform webhook `/api/webhooks/whatsapp`, account page registration retry. Manual entry unchanged.
- Bug found by a test: second `Http::fake` did not override the first (test-only) → used a response sequence.
- **Result:** ✅ 180 tests pass (704 assertions). UI checked in browser (accounts page, settings page). Live Embedded Signup not tested — needs the user's Tech Provider app on https.

### 2026-11-20 · Legal pages
- Public `/privacy-policy` (incl. `#data-deletion` instructions for Meta) and `/terms-conditions`, branded layout, light/dark; linked from login page and sidebar.
- Company, contact email, address, jurisdiction and effective date come from `LEGAL_*` env values (`config/legal.php`).
- Content is a solid template written for a WhatsApp Cloud API SaaS. It is not legal advice and should be reviewed for the business.
- **Result:** ✅ 183 tests pass. Pages checked in browser.

### 2026-11-20 · Installer fix: false "mbstring missing"
- Server run stopped at step 1: "PHP extension 'mbstring' missing" although the same check passed earlier.
- Cause: `php -m | grep -q` under `set -o pipefail`. `grep -q` exits on the first match, `php -m` can get SIGPIPE (exit 141) → random false failure.
- Fix (commit `9b1cf58`): capture output first, then grep (also for the Redis and port checks).

### 2026-11-20 · Phase 9 — Registration, Plans, Bot Flows
- Public registration (Customer role + default plan, terms acceptance, ON/OFF switch).
- Plans: Embedded/manual permission, max numbers, daily message limit, max contacts, bots on/off + max flows, trial/expiry; enforced in one place (`PlanGate`); number ownership (`owner_id`) and customer isolation.
- Bot flows: keyword / welcome / fallback / menu-only triggers; text, menu (buttons/list → other flows), image, document, handoff steps; pause after agent reply; chat pause/resume.
- **Result:** ✅ 206 tests pass (830 assertions). Plans page and bot builder checked in browser.

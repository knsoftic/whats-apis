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

### 2026-11-20 · Phase 10 — Settings center + PKPay
- Read https://pkpay.site/docs (single page: signed requests, Raast QR, PKR only, signed callbacks, status endpoint, IP allowlist).
- Settings tabs: General (name, logo, registration), Appearance (brand colour via CSS variables, default theme, toggle), Payments (currency, self-purchase, PKPay creds, manual), Email SMTP (runtime), Legal, Meta.
- Self-purchase: Billing page → PKPay checkout → verified callback → plan activation (idempotent, renewal-aware); manual payments approved on the Payments page; `payments:sync` scheduler.
- Fixes during work: test helper named `callback()` clashed with PHPUnit's final method; `@js` inside a Blade component attribute is not compiled (colour swatch ticks).
- **Result:** ✅ 223 tests pass (934 assertions). Settings pages checked in browser.

### 2026-11-20 · Phase 11 — Landing page + contact inbox
- `/` is now a public landing page (was a redirect to `/dashboard`). The header always shows Login and Register; logged-in users see Dashboard instead.
- Every section (hero image/video/YouTube, numbers, features, steps, video, pricing from plans, testimonials, FAQ, contact, CTA, footer, SEO) is editable on **Settings → Landing page**, with on/off switches, uploads and a media library. The server cleans all input.
- The contact form saves to **Contact Messages** (honeypot, rate limit, optional e-mail alert, unread badge in the sidebar).
- Fixes during work: dynamic Tailwind grid classes changed to fixed class names; Laravel reports fake `.mp4` uploads as `application/mp4` (Symfony map), so that MIME type is now accepted too.
- **Result:** ✅ 238 tests pass (1045 assertions). Checked in the browser: desktop, mobile, dark mode, editor save, contact form → inbox.

### 2026-11-20 · Payments — Super Admin status change
- Request: on /payments, a button so the Super Admin (only) can change the status of an expired payment by hand.
- Added a **Change status** popup (any status, optional note, "activate plan" box), `PaymentService::changeStatus()`, route `payments.status` with a Super Admin role check, a history note and an activity log entry.
- Safety: a plan is never activated twice for one payment (also covers a later PKPay "paid"); pending payments set by the admin are not auto-expired; the PKPay mismatch note no longer overwrites the history.
- Fix during work: CSS had to be rebuilt for the new popup width class.
- **Result:** ✅ 245 tests pass (1082 assertions). Checked in the browser (expired → paid → plan activated). Local test data removed.

### 2026-11-20 · Phase 12 — Visual bot flow builder (n8n-style)
- Request: Bot Flows like n8n — drag-and-drop nodes connected on a canvas.
- Built a canvas builder (palette drag & drop, wires from output dots, one output per menu option, pan/zoom/fit, settings panel, red nodes on errors), a graph data model (`bot_flows.graph`, `BotGraph`), and an engine that follows the connections and waits at menus.
- Older step flows keep working and open in the canvas.
- Issues found while browser-testing and fixed: the settings panel covered the nodes (now a side column on wide screens, and the selected node is panned into view); stale connection errors are cleared after re-wiring; palette buttons now have labels for screen readers.
- **Result:** ✅ 252 tests pass (1139 assertions). Browser-tested with real mouse drag, connect, move, save, delete-connection error and dark mode. Local test data removed.

### 2026-11-20 · Bot builder — Question + Condition nodes
- Question node: asks, waits for the reply (24 h), validates (any/number/email/phone) with retries, saves to a variable (+ contact name/email); outputs Answer / No valid answer.
- Condition node: rules (text/answer, business hours, contact tag, new contact) with one output each + Otherwise.
- `{{variables}}` work in messages. Bot memory is stored in `conversations.bot_state`; an agent reply, a handoff, a menu tap or the timeout clears the wait.
- Browser-tested with a real mouse (built and saved Trigger → Message → Question → Condition → 3 paths). The saved JSON is now a test fixture and runs end to end.
- **Result:** ✅ 261 tests pass (1219 assertions). Local test flow removed.

### 2026-11-20 · Fix: Callback URL missing on manual add-account form
- User report: https://whats-apis.com/whatsapp-accounts/create (manual) did not show the Callback URL.
- Cause: the URL uses the account UUID, which only existed after saving.
- Fix: the UUID is generated when the form opens and kept on save; a new Webhook card shows the Callback URL and Verify token with Copy buttons and the setup steps.
- **Result:** ✅ 262 tests pass. Checked in the browser.

### 2026-11-20 · Live webhook verify failed — diagnosis + form guidance
- Meta: “callback URL or verify token couldn't be validated”. The live probe gave 404 for that account id: the account was never saved (the URL came from an unsaved or failed add form). The server and routes are fine (the shared webhook returns 403 as expected).
- Added the form notice and the link to an already-added number. Tests pass.

### 2026-11-20 · Chrome “Dangerous site” (Google Safe Browsing) on whats-apis.com
- Checked the live home page: only the app's own scripts, no iframes, no foreign forms or redirects, so no sign of a hack. The likely cause is a phishing false positive from brand look-alike signals: a domain with “whats”, the site name “Whats Apis”, a green chat-bubble “WA” logo, “Official WhatsApp” wording and a login form.
- App changes:
  - the default logo is now a neutral monogram (no speech bubble);
  - public text no longer says “Official WhatsApp” (it says “Built on the WhatsApp Business Platform”);
  - login and register pages show an ownership + anti-phishing note (“independent service … never ask for your WhatsApp code or Facebook password”).
- Still needed from the user: rebrand in Settings (name/logo/colour), saved landing texts, then **Google Search Console → Security issues → Request review**.
- **Result:** ✅ 262 tests pass.

### 2026-11-20 · Webhook verify still failing (second URL)
- The live probe of the new Callback URL (`704acee9-…`) gave 404 again: no saved account with that id. The live site runs the latest PHP code (new landing defaults are served), so it is not stale code.
- Likely cause: a fresh “Add account” form for a number that is already saved, so the save is blocked and its URL never exists.
- Added `php artisan whatsapp:webhooks`, which prints the exact Callback URL + verify token of every saved account, and a log warning when Meta calls an unknown account id.
- **Result:** ✅ tests pass.

### 2026-11-22 · Phase 13 — Login as user, suspend / block, locked customer numbers
- Users page: “Login as” (Super Admin → any non–Super Admin, amber return bar, audit with impersonated_by, password/profile changes blocked), Suspend / Block with a reason + Re-activate, status filter.
- Suspended users only see a notice; blocked users are signed out and can't sign in; their numbers stop sending and bots pause.
- Customers can no longer delete a connected number or change its WABA / Phone Number ID; a full plan hides Add/Connect (“2 / 2 used”). Only staff can delete numbers.
- Browser-checked; fixed overflowing table actions and an invalid `@elseunless` directive found during the check.
- **Result:** ✅ 269 tests pass (1326 assertions). Local test customer removed.

### 2026-11-24 · Phase 14 — Leads sheet, WhatsApp Manager, template builder
- Leads: the first message of each new number is saved (profile name, number, message) and old chats are backfilled; page with filters and CSV export for campaigns.
- WhatsApp Manager per number: health (quality, limit tier, name status, can-send issues), business profile (incl. photo via resumable upload), two-step PIN, blocked customers (block/unblock from chat and contact), Meta charges (local estimate × rates + Meta pricing analytics).
- Template builder: create (text/media header, variables with examples, footer, buttons, OTP) + delete, rule checks, live preview.
- Fixes during work: CSV phone kept without a quote mark; `CurrentAccount` cache keyed by user.
- **Result:** ✅ 277 tests pass (1411 assertions). Browser-checked locally; live Meta behaviour still to confirm on the server.

### 2026-11-24 · Voice notes in chat
- Mic button in the chat composer: record with timer, cancel, send. Sent as a WhatsApp voice note (OGG/Opus).
- Chrome / Android / iPhone recordings are converted with ffmpeg on the server; the installer installs ffmpeg. Firefox OGG is sent as it is.
- Fix during work: MediaRecorder / MediaStream kept outside Alpine's reactive state (proxies break native methods).
- **Result:** ✅ 281 tests pass. Real microphone recording not testable in the in-app browser — check in Chrome on the live site.

### 2026-11-24 · Fix: microphone blocked without a browser popup
- Live report: “Allow microphone access…” appeared and the browser never asked.
- Cause: our security header `Permissions-Policy: microphone=()` disabled the microphone for the whole site, so the browser refused without a prompt.
- Fix: `microphone=(self)` (camera and location stay off). Clearer errors: the site still blocks it (needs the update) / blocked in the browser (🔒 → Site settings → Microphone → Allow) / no mic / mic busy.
- Verified locally: page reports microphone allowed, header test added. ✅ Tests pass.

### 2026-11-24 · Fix: voice note failed — “Media file scrutiny … mediaEngineStatus: 0”
- Live: the voice note was uploaded but Meta failed it (error 131053).
- Meta's audio docs: voice messages must be **OGG + Opus + mono**, sent with `"voice": true`. Files over 512 KB show a download icon instead of play.
- Cause: some recordings reached Meta as stereo. Firefox OGG was passed through unchanged, and the ffmpeg fallback only copied the stream.
- Fix: with ffmpeg, **every** recording is re-encoded to mono Opus 48 kHz, 24 kbit/s, no metadata. The fallback is ffmpeg's built-in Opus encoder; there is no more stream copy. Without ffmpeg, only an OGG that is already mono is accepted.
- Audio is now sent with `voice: true`. **Retry** on an older failed voice note re-encodes the stored file and uploads it again.
- **Result:** ✅ 283 tests pass (VoiceNoteTest 6).

### 2026-11-24 · Fix: long voice notes failing
- Live report: short voice notes arrive, longer ones fail.
- Meta: voice files over 512 KB lose the play icon. In practice the bigger files were refused (131053), and Meta's docs set no duration limit.
- Fix 1: **adaptive bitrate.** Encode at 24 kbit/s; if the file is over 480 KB, re-encode at a lower bitrate (minimum 8 kbit/s), so a 5-minute note stays under 512 KB.
- Fix 2: **automatic fallback.** If Meta still refuses a voice note (131053), the same recording is resent **once** as plain audio (AAC .m4a, without the voice flag), so it still reaches the customer. There is no loop.
- **Result:** ✅ 285 tests pass (VoiceNoteTest 8). Not yet confirmed against Meta live.

### 2026-11-24 · 24h follow-up message
- WhatsApp Manager → **24h follow-up** tab (per number). Settings:
  - on/off switch;
  - send after 20 / 21 / 22 / **23** / 23.5 hours from the customer's last message;
  - the message, with {{contact_name}}, {{business_name}}, {{phone}};
  - skip closed chats.
  - The tab also has a live preview, counts (sent in 7 days / due now) and the last follow-ups.
- `whatsapp:send-followups` runs every 5 minutes (scheduler). It sends **once per customer message**, only while at least 10 minutes of the window remain. It skips opted-out / blocked contacts, disabled numbers and suspended owners, and respects plan limits. In chat it shows as “⏰ Auto follow-up”.
- Checked locally: turned it on in the browser and saved; a real command run on a demo chat 23 h 05 m old queued the follow-up with the contact's name. Demo data restored afterwards.
- **Result:** ✅ 290 tests pass (FollowUpTest 5).

### 2026-11-24 · Fix: chats updated only after reload + sidebar unread counters
- Live report: new chats and messages appeared only after a page reload; the sidebar showed no unread numbers.
- Probe of the live site: the WebSocket opens, then the server on the Reverb port answers `4001 Application does not exist`. The Reverb process there does not know the app key in `.env` and the build. Either it runs with an old config, or another program holds port 8080.
- Chat page fixes:
  - "Live" now means socket connected **and** private channel subscribed. A refused channel (`.error`) also counts as offline.
  - Offline: auto-refresh every **5 s** (was 10 s). Live: a safety-net refresh every **30 s**, plus an instant refresh when the tab gets focus or the connection drops.
  - Silent background refresh (no spinner or error toast). Out-of-order responses are ignored. A chat marked read is no longer shown unread again by a stale response.
  - Hint text: "Live updates connected" / "Auto-refresh every 5 s (realtime offline)".
- New **inbox counters** (`App\Support\ChatCounts`, one grouped query):
  - unread chats, unread messages, **waiting for reply** (open chat, customer wrote last), open, closed, all;
  - given per number and in total, across every number the user can open.
- Sidebar "Chats":
  - a green badge with the unread chats count;
  - a 2×2 mini panel: Unread msgs / Waiting reply / Open chats / All chats, each linking to the inbox with that filter;
  - the browser tab title shows "(N)";
  - refresh every 15 s, instantly from the chat page, and on tab focus. Endpoint: `GET /chat/unread-summary`.
- Chat filter tabs: All · Unread · **Waiting** (new filter) · Open · Closed, each with its count. The number switcher shows "— N unread" for every number. `/chat?filter=…` preselects a tab.
- New command: `php artisan whatsapp:realtime-check`. It checks:
  - the broadcast driver;
  - Reverb keys in .env, in config/reverb.php and in the **built JS**;
  - whether the port is listening;
  - whether the process on the port **accepts this app** (if not, it shows which process holds the port);
  - an end-to-end publish through Nginx.
  - It prints the exact fix for each problem.
- Installer:
  - keeps the Reverb port saved in .env;
  - if the port is held by **another program**, moves Reverb to a free port (8081–8099) and updates .env, the Nginx `proxy_pass` and Supervisor;
  - runs `reverb:restart` + Supervisor restart, so old Reverb processes reload the config;
  - runs `whatsapp:realtime-check` at the end.
- Checked locally in the in-app browser:
  - live connected → a new incoming message showed instantly in the list, tab counts, sidebar badge and title "(1)";
  - Reverb stopped → hint switched to auto-refresh, and the next message appeared within 5 s;
  - an open chat received a new message, which was marked read and the counters dropped to 0;
  - `realtime-check` passed with Reverb running and caught a wrong app id.
  - Test messages deleted afterwards.
- **Result:** ✅ 295 tests pass (ChatCountsTest 5).

### 2026-11-24 · Bot Flows: "Any message" trigger + "Message type" condition
- Request: a flow must start whenever a customer sends **anything**: any time, first message or not, keyword match or not, and voice notes, pictures and text alike.
- New trigger **"Any message (every message, any type)"**. It runs for text, voice note/audio, picture, video, document, sticker, location, contact card and button replies.
- Order of choice:
  1. a tapped bot menu button, or the answer to a pending Question, continues its own flow;
  2. **Any message** flows (highest priority first);
  3. Keyword;
  4. Welcome;
  5. Fallback.
  - So keyword, welcome and fallback flows don't answer while an Any-message flow does.
- **Cooldown** on an Any-message flow means it answers at most once per X minutes per chat. In between, the normal keyword / welcome / fallback flows answer.
- New Condition rule **"Message type"** (is / is not), with these values: text, voice/audio, picture, video, document, sticker, location, contact card, button reply, any media. Example: voice → "Thanks for the voice note", picture → "Nice picture", else → text reply.
- Builder: a new radio option with a help box explaining the behaviour; the rule editor has the type picker and the rule label on the node; violet badge in the flow list.
- Bots still pause in a chat for 60 min after an agent replies manually (unchanged), and still respect the plan's bot limits.
- Checked in the in-app browser: picked the trigger (chip + help shown), added a Condition → Message type rule ("1. Message is voice note / audio" + Otherwise). Nothing saved.
- **Result:** ✅ 300 tests pass (BotAnyMessageTest 5: all 7 message types, per-type replies, question/menu continuity, cooldown hand-over, builder save/validation).

### 2026-11-24 · Manual Meta setup re-checked against Meta's current docs (+ register button for manual numbers)
- Request: full links and steps in Roman Urdu for creating a fresh Meta app and attaching WhatsApp manually. If the method has changed, update the app and the docs too.
- Read Meta's official docs, which moved in 2026 to `developers.facebook.com/documentation/business-messaging/whatsapp/…`. Changes found:
  - WhatsApp menus now sit under **Use cases → Connect with customers through WhatsApp → Customize** (Quickstart / API Setup / Configuration);
  - the WABA is also called "Messaging account ID";
  - registration is **API-only** (`POST /{phone-id}/register`, at most 10 tries per 72 h);
  - the 6-digit PIN is deprecated for eligible numbers (Sept–Oct 2026), but a valid 6 digits must still be sent;
  - examples now use Graph **v27.0**;
  - no App Review is needed for your own WABA;
  - publishing is done from the left menu **Publish**.
- App fix: manually added numbers that Meta reports as not on the Cloud API (`platform_type` ≠ `CLOUD_API`) now show the **Register number** card (badge "Manual"), with PIN help. The API check adds a warning "not registered on the Cloud API yet". Before this fix, the card appeared only for Embedded Signup numbers.
- Help texts updated in these places:
  - the account form (new menu paths, a note on v27.0);
  - the account page webhook card;
  - the WhatsApp Manager PIN tab (PIN deprecation note).
  - The Graph default stays v26.0, which is still supported.
- `docs/META-SETUP.md` Option B was rewritten as 13 steps with direct links (business portfolio, create app, WABA + number, IDs, App Secret, System User token, add in app, register, webhook, subscribe, publish, payment + test). Troubleshooting rows added.
- The aaPanel install guide (Claude Doc) Step 7.2–7.3 was replaced with the same 12 steps in **Roman Urdu** (7.2–7.13).
- **Result:** ✅ 304 tests pass (ManualRegistrationTest 4).

### 2026-11-27 · Auto Replies (separate from Bot Flows) + fix: a banned WABA showing on other numbers
**Request 1 — Auto Replies inside our software.** Read our own chat box: is this the customer's first message, or did they write before? Keep separate rules. Allow triggering even while a chat is ongoing. Always reply / auto reply / quick action buttons, **not linked to Bot Flows**.
- New sidebar item **Auto Replies** (`/auto-replies`), with its own table, model, engine (`AutoReplyEngine`) and job (`RunAutoReplies`). Button ids are `ar:{rule}:{button}`.
  - Bot Flows ignore `ar:` taps, and Auto Replies ignore `bf:` taps. Nothing else is shared.
  - The Bot Flows pause does not apply to Auto Replies.
- Triggers, all read from our chat history:
  - **First message** (the customer never wrote to this number);
  - **Returning customer** (wrote before; optionally only after N quiet hours);
  - **Keyword**;
  - **Every message**.
- Options:
  - message types (voice, picture …);
  - time: any / during hours / **outside hours (away message)**;
  - **"Also while an agent is chatting"** (off by default = quiet when an agent replied in the last 30 min);
  - cooldown and priority. For each customer message, one reply comes from the highest-priority matching rule.
- **Quick action buttons**: up to 3 per reply. A tap sends that button's own answer at once.
- Form: presets (Welcome new customers / Welcome back / Away message / Always reply), a live WhatsApp preview, and placeholders `{{contact_name}}`, `{{business_name}}`, `{{phone}}`. In chat these messages show as "⚡ Auto reply · <rule>".
- Plan: follows the plan's automation switch (allow_bot_flows), plus message limits.

**Request 2 — one WABA banned, another healthy WABA also showed disabled.** Two causes found and fixed:
1. **Webhook mix-up.** One Meta app has one callback URL, so the events of all WABAs/numbers come to one account's URL.
   - Before: template-status and quality events were stored on the **account that owned the URL**, not on the WABA/number they were about. Meta's `account_update` (ban / restriction) was ignored.
   - Now: the WABA is routed by `entry.id` and the number by `display_phone_number`, only within the same App Secret. Unknown WABAs or numbers are ignored and never pinned on the receiver.
   - `account_update` is now handled: DISABLED_UPDATE (DISABLE / SCHEDULE_FOR_DISABLE / REINSTATE), ACCOUNT_VIOLATION, ACCOUNT_RESTRICTION, ACCOUNT_DELETED.
   - The result shows a red **"Disabled by Meta"** badge and banner **only on that WABA's numbers**. The banner says the other WABAs are not affected.
   - Our own switch is now labelled **"Disabled here"**, so it is not confused with a Meta ban.
2. **Embedded Signup guessed the WABA.** If the Meta popup did not report which WABA was picked, the code took the **first** shared WABA, which can be the old banned one. That re-connected the banned number instead of the new one.
   - Now it uses the only shared WABA that is not connected here yet, otherwise it asks to run the signup again. It also prefers a phone number that is not connected yet.
   - The popup wait went from 3 s to 6 s.
- Checked in the in-app browser: created an auto reply from the preset, switched to Keyword + outside hours (fields and preview updated), saved, and it showed in the list. The local test rule was deleted afterwards.
- **Result:** ✅ 319 tests pass (AutoReplyTest 8, WabaIsolationTest 5, EmbeddedSignupTest +2).

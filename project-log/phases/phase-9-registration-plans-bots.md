# Phase 9 — Registration, Plans & Bot Flows

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (206 passing) · ⏳ live bot test with a real number |
| **Date** | 2026-11-20 |
| **Requested by user** | Public user registration; plans controlling Embedded/manual, daily message limit etc.; bot flow feature |

## 1. Registration
- `/register` (public, rate-limited): name, business name, email, phone, password, **Terms & Privacy acceptance**.
- New user → role **Customer** + the **default plan** (trial days → `plan_expires_at`). Activity logged.
- Super Admin can switch registration **ON/OFF** (Plans page). Login page shows "Create an account" when ON.

## 2. Plans
| Setting | Effect |
|---|---|
| Connect with Meta (Embedded Signup) | Hides/blocks the "Connect with Meta" flow |
| Add numbers manually | Hides/blocks the manual form |
| Max WhatsApp numbers | Counted per owner (`whatsapp_accounts.owner_id`) |
| Daily outgoing message limit | All outgoing (agent, template, bot) across the owner's numbers; resets at midnight; incoming never blocked |
| Max contacts | Manual add + CSV import (import stops adding new ones at the limit) |
| Bot flows on/off + max flows | Bots don't reply / can't be created when off |
| Price, currency, billing, trial days, active, default | Shown on Plans page; one default plan |

- Staff (Super Admin / Admin) and **platform-owned numbers** (no owner) are unlimited.
- Customers see **only their own numbers** (owner is attached automatically when they add or connect a number; existing pivot isolation).
- Super Admin assigns plan + expiry on the Users page and can set a number's owner on the account edit page.
- Taking over a number already connected by someone else is blocked.
- Customer dashboard shows a **Your plan** usage card (messages today, numbers, contacts, bots, expiry).

## 3. Bot flows
- Triggers: **Keyword** (contains / exact / starts with, priority), **Welcome** (first message), **Fallback** (with per-contact cooldown), **Menu only**.
- Steps (max 8): Text · **Menu** (1–3 options = reply buttons, 4–10 = list message; each option opens another flow) · Image (HTTPS link) · Document (HTTPS link) · **Handoff to agent** (pauses bot in that chat for 24 h).
- Engine runs in a queued job after an incoming message; steps are sent in order; bot messages show "🤖 Bot · flow" in chat and count towards the daily limit.
- Bot pauses in a chat for 60 min after an agent replies; chat menu has **Pause bot / Resume bot**.
- Builder validates WhatsApp limits (button title 20, list row 24, description 72, body 1024, footer 60).

## Files (main)
Migration `2026_11_20_200000_add_plans_registration_and_bot_flows.php`; models `Plan`, `BotFlow`, `BotFlowRun`; `App\Services\PlanGate`, `App\Exceptions\PlanLimitException`; `App\Services\WhatsApp\BotEngine`, `App\Jobs\RunBotFlow`; controllers `Auth\RegisteredUserController`, `PlanController`, `BotFlowController`; views `auth/register`, `plans/*`, `bots/*`; `resources/js/bot-builder.js`; seeder `PlanSeeder` (Free / Starter / Business); changes in MessageSender, SendWhatsAppMessage (interactive + media links), WebhookProcessor, accounts/users/dashboard/chat views, Permissions (Customer role, plans.manage, bots.manage).

## Tests
`RegistrationTest` (4), `PlanTest` (11), `BotFlowTest` (8) + updated `AuthorizationTest`, `LegalPagesTest` → **206 passed (830 assertions)**.

## Issues found & fixes
| Issue | Fix |
|---|---|
| Test fake returned the same `wamid` for every send → unique index error | Fake now returns a new wamid each time (like Meta) |
| Daily-limit test crossed the 24h window after "next day" | Test simulates the customer writing again |

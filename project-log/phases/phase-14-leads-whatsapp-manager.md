# Phase 14 — Leads sheet, WhatsApp Manager, template builder

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (277 passing) · browser-checked locally · ⏳ live Meta calls need a real connected number |
| **Date** | 2026-11-24 |
| **Requested by user** | Save every new lead's first message (profile name + number) in a sheet, exportable for marketing. Also: template creation and sync, number health, Meta charges estimate, business profile, block/unblock customers, two-step PIN. |

## Leads (sidebar → Leads)
- When a **new number sends its first message**, one row is saved: WhatsApp **profile name, number, first message, time, which of your numbers**. Later messages don't add rows.
- People who had already messaged before this update were **imported automatically** by the migration.
- The page has search (name / number / message), number and date filters, and counts (filtered / last 7 days / today).
- **Export to Excel (CSV)** uses the same filters. Columns: name, phone, whatsapp_number (digits only, ready for campaign tools), first_message, date, account. The file is UTF-8 so Urdu and emoji work, and it is protected against spreadsheet formulas.
- Leads stay even if the chat or contact is deleted. Customers see only their own numbers' leads; export needs the import/export permission.

## WhatsApp Manager (WhatsApp Accounts → number → “WhatsApp Manager”)
| Tab | What |
|---|---|
| **Number health** | Quality (High / Medium / Low + advice), messaging limit (250 / 1K / 10K / 100K / unlimited), display-name status, official-account tick, Meta's “can send” status with problems + fixes, verification, platform, throughput, WABA review / currency. “Refresh from Meta”; optional fields never break the page. |
| **Business profile** | About, description, address, email, category, 2 websites, **profile photo** (resumable upload; uses the Meta App ID, which is auto-detected or entered once). |
| **Two-step PIN** | Change the 6-digit PIN (confirmed; obvious PINs refused). Sent to Meta only, never stored. |
| **Blocked customers** | List + Unblock. Block / Unblock from the chat menu (⋯) or the contact page. Meta's refusal reason is shown (e.g. only people who messaged in the last 24 h). |
| **Meta charges** | Per month: templates sent by category (marketing / utility / authentication) × your rates = estimate. Replies are free and failed messages are not charged. “Get Meta's own figures” reads Meta's pricing analytics. Rates and currency are editable per number. |

## Templates
- **New template**: number, name (auto-formatted), language, category (Marketing / Utility / Authentication).
  - Header: text with one variable, or image / video / PDF sample.
  - Body with {{1}}…{{n}}; example fields appear automatically.
  - Footer.
  - Buttons: quick replies, up to 2 links (with {{1}} at the end) and 1 call button.
  - **Live WhatsApp preview** while you type.
- **Authentication (OTP)** uses Meta's fixed format: security line, expiry, Copy-code button.
- Meta's rules are checked before sending: no variable at the start/end, variables numbered in order, examples required, button limits, link variable only at the end.
- The template is submitted to Meta, saved locally as PENDING, and its status updates automatically via webhook. **Sync from Meta** (existing) brings templates made elsewhere.
- **Delete** removes it at Meta and locally (Meta blocks re-using the name for 30 days).

## Other changes
- Template messages now store their category (for the charges estimate).
- `CurrentAccount` caches the number list per user (correct after “Login as”).
- New tables/columns: `leads`, `whatsapp_accounts.meta_app_id`, `contacts.blocked_at` (migrations `2026_11_24_100000`, `2026_11_24_200000`).

## Tests
`WhatsAppManagerTest` (8) covers:
- lead captured once, page, search, CSV (BOM, formula-safe, phone intact), agent access;
- health incl. a failing optional field;
- profile read/save with photo upload handle; PIN rules + API call;
- block/unblock + Meta refusal;
- template payload (header, body examples, footer, URL example, quick reply) + delete; template rule errors + OTP format;
- charges estimate + Meta figures.

**277 passed (1411 assertions).**

## Browser check (local)
Leads page with the 3 imported demo leads, Manager tabs (Health, Charges), and the template builder live preview. Real Meta responses (health, profile, PIN, block, template review, pricing) can only be confirmed on the server with a connected number.

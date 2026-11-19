# Phase 6 — Media messages, contacts & templates

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested (mocked Meta API) |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §6 Media, §7 Message templates, §8 Contacts |

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 6.1 | Media upload + send | ✅ | Real-byte MIME detection + extension check + per-type size limits (image 5 MB, video/audio 16 MB, document 100 MB); stored privately |
| 6.2 | Secure media download | ✅ | `/media/{id}` checks conversation access; only image/video/audio are shown inline; everything else is a forced download with a `sandbox` CSP and `nosniff` |
| 6.3 | Contacts list | ✅ | Search (name / number / email), filters: WhatsApp number, tag, consent; 25 per page |
| 6.4 | Add / edit; tags; notes | ✅ | Tags per account (comma-separated input); internal notes with author & time |
| 6.5 | Opt-in / consent | ✅ | Status, source, opted-in/out timestamps; STOP/START keywords from WhatsApp |
| 6.6 | CSV import | ✅ | Header row, `phone*,name,email,tags,opt_in`; creates/updates; row errors reported; max 5,000 rows / 5 MB; UTF-8 BOM handled |
| 6.7 | CSV export | ✅ | Uses current filters; Excel-safe (formula injection prevented); logged |
| 6.8 | Contact message history | ✅ | Paginated history on the contact page with status and download links |
| 6.9 | Template sync from Meta | ✅ | `GET /{waba-id}/message_templates`, all pages (cursor), deleted ones removed; status webhooks update automatically |
| 6.10 | Template list + preview | ✅ | Name, language, category, status badge, rejection reason, WhatsApp-style preview with highlighted variables |
| 6.11 | Send template with variables | ✅ | Header text/media (HTTPS link), body (positional or named), dynamic URL buttons; validated before calling Meta |
| 6.12 | Tests | ✅ | `ContactTest` (9), `TemplateSyncTest` (4) + media tests in Outgoing/Chat |

## Files created / changed
| Area | Files |
|---|---|
| Controllers | `app/Http/Controllers/ContactController.php`, `TemplateController.php` |
| Services / actions | `app/Services/ContactService.php`, `app/Actions/WhatsApp/SyncTemplates.php` |
| Request / policy | `app/Http/Requests/ContactRequest.php`, `app/Policies/ContactPolicy.php` |
| Views | `contacts/index|form|show|import.blade.php`, `templates/index|show.blade.php`, `templates/partials/preview.blade.php` |
| Routes | `routes/whatsapp.php` (contacts.*, templates.*) |
| Tests | `tests/Feature/ContactTest.php`, `tests/Feature/TemplateSyncTest.php` |

## How to test this phase
1. **Contacts → Import CSV** with the sample shown on the page → result card shows created / updated / skipped.
2. **Contacts → Export CSV** → open in Excel.
3. **Message Templates → Sync from Meta** (real WABA needed) → approved templates listed → **Send** → fill variables.
4. In a chat, attach a PDF / JPG → arrives on the phone; incoming media shows a preview/download.

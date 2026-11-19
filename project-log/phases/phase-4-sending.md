# Phase 4 — Outgoing message sending & message status handling

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested (mocked Meta API) |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §6 Message sending |

## Goal
A reusable `WhatsAppCloudApiService` sends every supported message type through `POST https://graph.facebook.com/{version}/{phone_number_id}/messages`, respects the 24-hour customer service window, stores the full result, and handles Meta errors and rate limits.

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 4.1 | `sendTextMessage()` (+ reply context) | ✅ | `context.message_id` for replies; `preview_url` auto when text has a link |
| 4.2 | `sendTemplateMessage()` | ✅ | Components built + validated by `TemplateMessageBuilder` |
| 4.3 | `sendImageMessage()`, `sendVideoMessage()`, `sendAudioMessage()`, `sendDocumentMessage()` | ✅ | File uploaded once to `/{phone-id}/media`, then sent by media id |
| 4.4 | `markMessageAsRead()` | ✅ | `SendReadReceipt` job when an agent opens a chat |
| 4.5 | `retrieveMedia()` + upload | ✅ | `retrieveMedia`, `downloadMediaTo` (streamed to disk), `uploadMedia` (multipart) |
| 4.6 | 24-hour window enforcement | ✅ | Free-form blocked outside window → HTTP 422 `window_closed`; templates always allowed (except opted-out contacts) |
| 4.7 | Store outgoing message fields | ✅ | wamid, sender, recipient, type, body/content, `api_response`, status, timestamps, account, user who sent |
| 4.8 | Queued send job with retry / backoff | ✅ | `SendWhatsAppMessage`: 6 tries, 10s→10min backoff; idempotent (never sends twice) |
| 4.9 | Error mapping | ✅ | Rate limit → retry later; 190/10/200 → fail + mark account "API failed"; 131047/131026/131030/1320xx → readable reason |
| 4.10 | Tests | ✅ | `OutgoingMessageTest` (14), `TemplateMessageTest` (10) |

## Files created / changed
| Area | Files |
|---|---|
| Services | `app/Services/WhatsApp/MessageSender.php`, `TemplateMessageBuilder.php`, `OutgoingMediaValidator.php` (+ send methods in `WhatsAppCloudApiService.php`) |
| Jobs | `app/Jobs/SendWhatsAppMessage.php`, `app/Jobs/SendReadReceipt.php` |
| Controller / policy | `app/Http/Controllers/Chat/MessageController.php`, `app/Policies/ConversationPolicy.php` |
| Exceptions | `app/Exceptions/MessagingRestrictionException.php` (rendered as 422 in `bootstrap/app.php`) |
| Routes | `routes/whatsapp.php` → `chat.messages.text|media|template|retry`, `chat.start` |
| Tests | `tests/Feature/OutgoingMessageTest.php`, `tests/Feature/TemplateMessageTest.php` |

## How to test this phase
1. Run `php artisan queue:work` (sending happens in the queue).
2. Open a conversation where the customer wrote in the last 24 h → type → Enter. Ticks: 🕒 pending → ✓ sent → ✓✓ delivered → blue ✓✓ read.
3. Open a conversation older than 24 h → the composer is replaced by "Send template".
4. Break the token on purpose → message shows **Failed** with the Meta reason and a **Retry** button; the account shows "API failed".

## Issues found & fixes
| Issue | Fix |
|---|---|
| Fake test uploads report MIME from the file name | Validator now sniffs real bytes with `finfo` (also safer in production) |
| Retry response showed stale "pending" | Controller re-reads the message after the job runs |

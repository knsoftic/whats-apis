# Phase 3 — Meta webhook verification & incoming message storage

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested (signed test payloads; live Meta delivery needs your HTTPS URL) |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §4 Webhook integration, §12 Security |

## Goal
Meta can verify the webhook (GET) and deliver signed events (POST). Every event is signature-checked against the raw body, stored once, processed in a queue, and turned into contacts, conversations, messages and status updates.

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 3.1 | Routes `GET/POST /api/webhooks/whatsapp/{account}` | ✅ | `{account}` = random UUID (numeric ids rejected) |
| 3.2 | GET verification | ✅ | `hub.mode=subscribe` + constant-time token compare; challenge returned as `text/plain`; non-alphanumeric challenge rejected (no reflected XSS) |
| 3.3 | POST `X-Hub-Signature-256` verification | ✅ | HMAC-SHA256 of the **raw** body with the App Secret, `hash_equals` |
| 3.4 | Store raw event in `webhook_events` | ✅ | Unique `(account, sha256(raw body))` — `createOrFirst` is race-safe |
| 3.5 | Respond 200 fast; queue processing | ✅ | `ProcessWebhookEvent` job (5 tries, backoff 5s→10min) |
| 3.6 | Parse text, image, video, document, audio, voice, sticker | ✅ | `IncomingMessageParser`; voice = audio with `voice:true`; filenames sanitised |
| 3.7 | Parse location, contacts, interactive, button, reaction | ✅ | + order, system, unsupported |
| 3.8 | Contact & conversation auto create/update; unread; service window | ✅ | Profile name updated; `last_inbound_at` opens 24h window; reactions don't count as unread |
| 3.9 | Status events sent / delivered / read / failed | ✅ | Never moves backwards; failed stores Meta error code + details |
| 3.10 | Duplicate message handling | ✅ | 3 layers: payload hash, wamid existence check, DB unique index |
| 3.11 | Media download via Meta media endpoint | ✅ | `DownloadMessageMedia` job → `GET /{media-id}` → authenticated download → **private** disk, SHA-256 verified |
| 3.12 | Rate limiting & safe logging | ✅ | 1200 req/min per account; logs never include tokens/secrets |
| 3.13 | Tests | ✅ | 31 webhook tests |

### Extra (found during design)
- **One Meta app = one callback URL.** If several numbers share one app, Meta sends all their events to the same URL. The processor routes each change by `metadata.phone_number_id` — **only** to an account with the same App Secret (same app), so data cannot leak between apps.
- `STOP` / `UNSUBSCRIBE` keywords → contact opted out; `START` → opted in.
- `message_template_status_update` webhooks update template status; `phone_number_quality_update` stored on the account.
- Broadcast events prepared for Phase 5: `MessageReceived`, `MessageStatusUpdated`, `MessageUpdated`, `ConversationRead` (private channel per account, dispatched after DB commit).

## Files created / changed
| Area | Files |
|---|---|
| Controller / routes | `app/Http/Controllers/WhatsAppWebhookController.php`, `routes/api.php` |
| Jobs | `app/Jobs/ProcessWebhookEvent.php`, `app/Jobs/DownloadMessageMedia.php` |
| Services | `app/Services/WhatsApp/WebhookProcessor.php`, `IncomingMessageParser.php` |
| Events | `app/Events/MessageReceived.php`, `MessageStatusUpdated.php`, `MessageUpdated.php`, `ConversationRead.php`, `Concerns/BroadcastsToAccount.php` |
| Resources | `app/Http/Resources/MessageResource.php`, `ConversationResource.php` |
| Tests | `tests/Concerns/SendsWebhooks.php`, `tests/Feature/Webhook/WebhookVerificationTest.php`, `WebhookSignatureTest.php`, `WebhookDuplicateTest.php`, `IncomingMessageProcessingTest.php` |

## How to test this phase
**Automated:** `php artisan test --filter=Webhook`

**Manual (needs public HTTPS):**
1. Run a tunnel, e.g. `cloudflared tunnel --url http://127.0.0.1:8000`, set `APP_URL` to the tunnel URL.
2. Run the queue worker: `php artisan queue:work`.
3. In Meta → WhatsApp → Configuration: paste Callback URL + Verify token → **Verify and save** → account page shows "Webhook verified".
4. Subscribe field **messages**, send a WhatsApp message to the number → it appears in the database / Chats.

## Test results
31 webhook tests pass (part of 88 total).

## Issues found & fixes
| Issue | Fix |
|---|---|
| `MessageFactory::outbound()` read `conversation_id` before it was resolved | Made the attribute a lazy closure |

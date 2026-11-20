# Testing report

[← Back to INDEX](INDEX.md)

**Last updated:** 2026-11-19 14:35
**Runner:** PHPUnit 11 via `php artisan test` · **Test DB:** SQLite in-memory · **Meta API:** always faked (`Http::fake()` + `Http::preventStrayRequests()`, so a test cannot reach graph.facebook.com)

## Latest result
```
Tests: 158 passed (613 assertions)   Duration: 19.4 s   Failed: 0
```

---

## 1. Required automated tests (spec §14)

| # | Area | Test file(s) | Tests | Status |
|---|---|---|---|---|
| T1 | Login and authorization | `Auth/*` (16), `AuthorizationTest` (11), `UserManagementTest` (5) | 32 | ✅ Pass |
| T2 | WhatsApp account creation | `WhatsAppAccountTest` | 13 | ✅ Pass |
| T3 | Credential encryption | `CredentialEncryptionTest` | 3 | ✅ Pass |
| T4 | API (credential) validation | `CredentialValidationTest` | 6 | ✅ Pass |
| T5 | Webhook verification (GET) | `Webhook/WebhookVerificationTest` | 6 | ✅ Pass |
| T6 | Invalid webhook signatures | `Webhook/WebhookSignatureTest` | 7 | ✅ Pass |
| T7 | Duplicate webhook handling | `Webhook/WebhookDuplicateTest` | 4 | ✅ Pass |
| T8 | Incoming message processing | `Webhook/IncomingMessageProcessingTest` | 13 | ✅ Pass |
| T9 | Outgoing message sending | `OutgoingMessageTest` | 14 | ✅ Pass |
| T10 | Template message sending | `TemplateMessageTest` (10), `TemplateSyncTest` (4) | 14 | ✅ Pass |
| T11 | Account data isolation | `AccountIsolationTest` | 9 | ✅ Pass |

## 2. Extra tests

| File | Tests | What it covers |
|---|---|---|
| `ChatTest` | 13 | Conversation list / search / filters, pagination, mark read + Meta read receipt, close/reopen, message search, templates endpoint, media access, forced download of customer documents, broadcast channel name & payload, **private channel authorization** |
| `ContactTest` | 9 | Add with tags + consent, duplicate rules, unassigned account blocked, search + history, notes, CSV import (create/update/skip/errors), bad header, permissions, export scope + formula-injection protection |
| `SecurityTest` | 8 | Log redaction, activity-log redaction, webhook rate limit (429), login rate limit, CSRF middleware active, no secrets in chat page HTML/JS, hourly account check command, pruning of old webhook payloads |
| `PageSmokeTest` | 3 | Every page renders for Super Admin; Agent menu limited; guest pages |
| `ProfileTest` | 3 | Profile edit (self-delete removed on purpose) |
| `Unit/ExampleTest` | 1 | Framework sanity |

## 3. Highlights of what is proven
- Webhook signature: missing, wrong secret, other account's secret, tampered body → **401**, nothing stored.
- Duplicate safety: same payload → stored once / queued once; same `wamid` in a different payload → one message; status repeated → no change; late "delivered" after "read" → stays "read".
- 24 h window: free-form blocked (422 `window_closed`), templates allowed; opted-out contacts can't receive business-initiated templates.
- Errors: rate limit (130429) → stays pending & retries; 131026 → failed with reason; 190 → failed + account marked "API failed".
- Encryption: DB columns never contain plaintext; `toArray()/toJson()` never include secrets; UI never shows the full token.
- Isolation: an Agent gets 403/404 on every chat, contact, template, media, search, switch and start endpoint of an unassigned number; dashboard numbers exclude it.

## 4. Test run history

| Date / time | Command | Passed | Failed | Notes |
|---|---|---|---|---|
| 2026-11-19 11:55 | `php artisan test` | 36 | 0 | End of Phase 1 |
| 2026-11-19 12:25 | `php artisan test` | 58 | 0 | End of Phase 2 |
| 2026-11-19 12:50 | `php artisan test` | 85 → 88 | 3 → 0 | Phase 3: factory bug found & fixed |
| 2026-11-19 13:15 | `php artisan test` | 110 → 112 | 2 → 0 | Phase 4: MIME sniffing + stale retry response fixed |
| 2026-11-19 13:40 | `--filter=ChatTest` | 13 | 0 | Phase 5 |
| 2026-11-19 14:05 | `--filter="ContactTest|TemplateSyncTest"` | 13 | 0 | Phase 6 |
| 2026-11-19 14:33 | `php artisan test` | **158** | **0** | Final (Phase 7) |

## 5. Manual / live checks

| # | Check | Status | Notes |
|---|---|---|---|
| M1 | Log in as Super Admin, dashboard renders | ✅ | In-app browser, 2026-11-19 |
| M2 | Chat UI: list, open chat, ticks, date separators, 24 h banner | ✅ | With demo data |
| M3 | Dark mode toggle | ✅ | |
| M4 | Template modal with live preview | ✅ | |
| M5 | **Real-time:** signed webhook → message appears without refresh (Reverb + queue running) | ✅ | Local, 200 EVENT_RECEIVED, appeared in ~2 s |
| M6 | Add a **real** WhatsApp account → "Test connection" green | ⏳ | Needs your Meta credentials |
| M7 | Meta "Verify and save" webhook → "Webhook verified" | ⏳ | Needs public HTTPS URL |
| M8 | Send from phone → appears in Chats; reply → arrives on phone; ticks change | ⏳ | Needs M6 + M7 |
| M9 | Send image / document both ways | ⏳ | Needs M6 + M7 |
| M10 | Sync real templates and send one | ⏳ | Needs approved templates in your WABA |
| M11 | Agent only sees assigned numbers (UI) | ✅ automated / ⏳ manual | Covered by `AccountIsolationTest` |
| M12 | Landing page: Login/Register header, editor save → page updates, contact form → inbox | ✅ | In-app browser, 2026-11-20 (desktop, 375 px mobile, dark) |
| M13 | Payments: Super Admin changes an expired payment to paid → plan activated, history note shown | ✅ | In-app browser, 2026-11-20 |
| M14 | Bot builder: drag nodes from palette, wire menu options, move node, save, delete a wire → red node + error, re-wire, dark mode | ✅ | In-app browser, 2026-11-20 (real mouse) |

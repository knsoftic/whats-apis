# Phase 2 — WhatsApp account connection & credential validation

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested (with mocked Meta API) |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §3 WhatsApp Accounts, §9 Multiple accounts, §12 Security |

## Goal
Admins can add, edit, remove, enable/disable multiple official WhatsApp Cloud API numbers. Credentials are encrypted, tokens are never shown in full, and the app checks real API access against Meta — with **separate** API-connection, webhook and app-subscription statuses (saving credentials is not treated as "connected").

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 2.1 | WhatsApp Accounts list page with status badges | ✅ | Card per number: API / webhook / subscription badges, unread count, last webhook time |
| 2.2 | Add account form (8 fields) with server-side validation | ✅ | Digits-only IDs, unique Phone Number ID, token/secret format, `vNN.N` version; "Generate" verify token |
| 2.3 | Edit account (blank secret field = keep current value) | ✅ | Changing token/IDs → automatic re-check; changing verify token/App Secret → webhook must re-verify |
| 2.4 | Delete account (with confirmation) | ✅ | Must type the exact account name; cascades contacts/conversations/messages |
| 2.5 | Encrypt access token, app secret, verify token at rest | ✅ | Laravel `encrypted` casts (AES-256, `APP_KEY`) + `$hidden` |
| 2.6 | Masked token display | ✅ | `EAAG••••••••••••WXYZ`; App Secret masked; only verify token can be revealed (logged) |
| 2.7 | `WhatsAppCloudApiService` base | ✅ | Bearer header only, `appsecret_proof`, timeouts, `WhatsAppApiException` with friendly messages per Meta error code |
| 2.8 | `validateCredentials()` + `getPhoneNumberDetails()` | ✅ | Phone details → phone belongs to WABA → subscribed apps |
| 2.9 | "Test connection" button + last checked time | ✅ | Rate-limited 10/min per user |
| 2.10 | Separate statuses | ✅ | `api_status`, `webhook_status`, `subscription_status` columns; "operational" only if enabled + API OK + webhook verified |
| 2.11 | Subscribe app to WABA | ✅ | `POST /{waba-id}/subscribed_apps` |
| 2.12 | Enable / disable; user ↔ account access | ✅ | Toggle button; access assignment on Users page; policy `WhatsAppAccountPolicy` |
| 2.13 | Tests | ✅ | 22 tests (see TESTING.md T2–T4) |

## Files created / changed
| Area | Files |
|---|---|
| Service | `app/Services/WhatsApp/WhatsAppCloudApiService.php`, `WhatsAppApiException.php`, `CredentialCheckResult.php` |
| Action | `app/Actions/WhatsApp/CheckAccountConnection.php` |
| Controller / request / policy | `app/Http/Controllers/WhatsAppAccountController.php`, `app/Http/Requests/WhatsAppAccountRequest.php`, `app/Policies/WhatsAppAccountPolicy.php`, `app/Http/Controllers/Controller.php` (AuthorizesRequests) |
| Routes | `routes/whatsapp.php` (accounts.* + test/subscribe/toggle/verify-token) |
| Views | `accounts/index.blade.php`, `accounts/form.blade.php`, `accounts/show.blade.php`, `accounts/partials/status-badges.blade.php` |
| Config | `config/whatsapp.php` (`appsecret_proof` option) |
| Tests | `tests/Concerns/FakesMeta.php`, `tests/Feature/WhatsAppAccountTest.php`, `CredentialEncryptionTest.php`, `CredentialValidationTest.php` |

## How to test this phase
1. WhatsApp Accounts → **Add WhatsApp account**, fill values from Meta (see `docs/META-SETUP.md` when written).
2. After saving, the page shows three separate cards: **API connection**, **Webhook verification**, **App subscribed to WABA**.
3. Wrong token → red "API failed" with the reason (e.g. "access token is invalid or expired (Meta error 190)").
4. Copy the **Callback URL** + **Verify token** from the details page into Meta.

## Test results
`php artisan test` → all Phase 2 tests pass (part of 88 total).

## Issues found & fixes
| Issue | Fix |
|---|---|
| Spec says "PHP 8.3+"; local is 8.2 | Code written for both; see ENVIRONMENT-DECISIONS D1 |
| Pasted tokens often contain spaces/newlines or "Bearer " | Form request strips them before validation |

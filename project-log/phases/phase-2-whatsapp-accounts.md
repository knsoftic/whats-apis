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

## Update 2026-11-20 — Callback URL on the manual “Add account” form
- Problem (reported on whats-apis.com): the manual form showed the verify token but **not the Callback URL**. The URL contains the account's id (UUID), which was only created when the account was saved.
- Fix: the form now creates the id up front and shows a **“4. Webhook — paste into Meta”** card. It has the Callback URL and the Verify token, each with a Copy button, plus 3 steps: Save → Meta “Verify and save” → subscribe to `messages`.
- Saving keeps exactly that id, so the URL copied into Meta keeps working. The id must be a unique UUID and cannot be changed when editing. The edit form shows the same Callback URL.
- Test: `WhatsAppAccountTest::test_callback_url_is_shown_before_saving_and_kept_after_saving` (the Meta GET verify works on the pre-shown URL). Checked in the browser: card, Generate token, Copy. **262 passed.**

## Update 2026-11-20 — “Callback URL or verify token couldn't be validated” (live)
- The user's Meta screen rejected `https://whats-apis.com/api/webhooks/whatsapp/faeb5556-…`.
- Probed the live URL with a deliberately wrong token. It returned **404**, and so did a random id, while the shared `/api/webhooks/whatsapp` URL returned Laravel's 403. So the server and app work, but **no account with that id is saved**. aaPanel's Nginx replaces Laravel 404s with its own “404 Not Found nginx” page.
- Likely cause: the URL was copied from a fresh “Add account” form that was never saved, or whose save failed because the number already exists. Each new form gets a new id.
- Product fixes:
  - an amber notice on the form: the URL works only after “Save & validate”; if the number is already added, copy its own URL from its account page;
  - after a “Phone Number ID already connected” error, a link to the existing account (only if the user can access it).
- Test extended (warning, duplicate link, unsaved URL → 404).

# Meta Developer Console setup (WhatsApp Business Cloud API)

There are **two ways** to connect a number:

| | Option A — Connect with Meta (Embedded Signup) | Option B — Add manually |
|---|---|---|
| Who | You are a Meta **Tech Provider**; customers onboard themselves | You own the Meta app and the number |
| Steps for the business | Click "Connect with Meta", log in with Facebook, pick/create WABA, verify number | Copy WABA ID, Phone Number ID, token, App Secret into the form |
| Token | Business token from Meta (automatic) | Permanent System User token |
| Webhook | One shared URL `https://YOUR-DOMAIN/api/webhooks/whatsapp`, set once | Per-number URL `…/api/webhooks/whatsapp/<uuid>` |

## Option A — Embedded Signup (Tech Provider), one-time setup
1. **Meta app (Business type)** with the **WhatsApp** and **Facebook Login for Business** products; Tech Provider onboarding / App Review done for `whatsapp_business_management` and `whatsapp_business_messaging`.
2. **Facebook Login for Business → Settings** → Yes for: Client OAuth login, Web OAuth login, Enforce HTTPS, Embedded Browser OAuth Login, Strict mode, **Login with the JavaScript SDK**. Add `https://YOUR-DOMAIN` to *Allowed domains for the JavaScript SDK* and *Valid OAuth Redirect URIs* (HTTPS only).
3. **Facebook Login for Business → Configurations** → create from the template *WhatsApp Embedded Signup Configuration…* (or custom with the **WhatsApp Embedded Signup** login variation). Copy the **Configuration ID**.
4. In this app: **Meta Tech Provider** (sidebar, Super Admin) → App ID, App Secret, Configuration ID, Verify token → Save → **Test App ID + App Secret**.
5. Meta app → **WhatsApp → Configuration → Webhook**: Callback URL `https://YOUR-DOMAIN/api/webhooks/whatsapp` + the same Verify token → **Verify and save**. Subscribe fields `messages`, `account_update` (+ optional `message_template_status_update`, `phone_number_quality_update`).

### Connecting a number (each customer)
**WhatsApp Accounts → Connect with Meta** → set a 6-digit PIN → **Connect WhatsApp with Facebook** → finish the Meta popup.
The app then (server-side): exchanges the 5-minute code for a business token, checks the shared WABA with `debug_token`, subscribes the app to the WABA webhooks, registers the number (`POST /{phone-id}/register` with the PIN — skipped for WhatsApp Business app "coexistence" numbers), saves the account and runs the API check. Registration can be retried from the account page. The business must add a **payment method** in WhatsApp Manager before sending templates.

Embedded Signup **v4** is used (v2/v3 were retired by Meta on 15 Oct 2026).

---

## Option B — Add manually

This part gets the 8 values the **WhatsApp Accounts → Add manually** form needs, and connects Meta's webhook to this app.
Only Meta's **official Cloud API** is used — no QR code, no WhatsApp Web, no unofficial library.

| Form field | Where it comes from |
|---|---|
| Account Name | Anything your team recognises, e.g. "Sales – India" |
| Meta Business Account ID (optional) | business.facebook.com → Settings → Business info |
| WhatsApp Business Account ID (WABA ID) | App Dashboard → WhatsApp → **API Setup** |
| Phone Number ID | App Dashboard → WhatsApp → **API Setup** (the ID under the number — not the number itself) |
| Access Token | Permanent **System User** token (step 4) |
| Webhook Verify Token | Any random string you choose (the form has a **Generate** button) |
| App Secret | App Dashboard → App settings → **Basic** → App secret → Show |
| Graph API version | `v26.0` (default; latest stable at the time of writing — July 2026 release) |

---

## 1. Business portfolio
1. Open <https://business.facebook.com> and create (or select) your business portfolio.
2. Complete **Business verification** (Security Centre) — needed for higher messaging limits and display-name approval.

## 2. Create the Meta app
1. <https://developers.facebook.com> → **My Apps → Create App**.
2. Use case: **Connect with customers through WhatsApp** (app type *Business*). Link it to your business portfolio.
3. In the app, add the **WhatsApp** product. Meta creates a WhatsApp Business Account (WABA) and a free **test number**.

## 3. Add your real phone number
1. App Dashboard → WhatsApp → **API Setup → Add phone number**.
2. Enter display name + category, verify the number by SMS / voice call.
3. Wait for display-name approval (WhatsApp Manager shows the status).
4. Copy the **Phone Number ID** and **WABA ID** shown on API Setup.

> A number that is active in the normal WhatsApp / WhatsApp Business **app** must be deleted from that app first, or migrated.

## 4. Permanent access token (System User)
The token on the API Setup page expires after ~24 hours — use it only for a quick test.

1. business.facebook.com → **Settings → Users → System users → Add** (role **Admin**).
2. **Assign assets** to the system user:
   - **Apps** → your app → *Full control*
   - **WhatsApp accounts** → your WABA → *Full control*
3. **Generate new token** → choose your app → expiry **Never** → permissions:
   - `whatsapp_business_messaging` (send / receive)
   - `whatsapp_business_management` (phone details, templates, webhook subscription)
4. Copy the token (starts with `EAA…`). Paste it into the form — after saving it is encrypted and only shown masked.

## 5. App Secret
App Dashboard → **App settings → Basic → App secret → Show**. It is used to:
- verify every webhook (`X-Hub-Signature-256`), and
- sign API calls with `appsecret_proof` (so a wrong secret is detected immediately by "Test connection").

## 6. Add the account in this app
1. Log in → **WhatsApp Accounts → Add WhatsApp account** → fill the form → **Save & validate**.
2. The details page shows three separate statuses:
   - **API connection** — token + IDs confirmed by Meta (phone details are fetched automatically)
   - **Webhook verification** — becomes green only after step 7
   - **App subscribed to WABA** — step 8

Saving credentials alone never marks a number as working.

## 7. Configure the webhook
Meta can only call a **public HTTPS** URL (your VPS domain, or a tunnel during local testing — see LOCAL-INSTALLATION.md).

1. On the account details page copy the **Callback URL** — it looks like
   `https://your-domain.com/api/webhooks/whatsapp/<uuid>` — and click **Reveal** to copy the **Verify token**.
2. App Dashboard → WhatsApp → **Configuration → Webhook → Edit**: paste both → **Verify and save**.
   The account page now shows **Webhook verified**.
3. **Webhook fields → Manage** → subscribe:
   - `messages` — **required** (incoming messages + sent/delivered/read/failed statuses)
   - optional: `message_template_status_update`, `phone_number_quality_update`

> **One app = one callback URL.** If several numbers belong to the same Meta app, use the callback URL of any of them.
> The app routes each event to the right number by `phone_number_id` — but only to numbers saved with the **same App Secret**, so data can never cross between different apps.

## 8. Subscribe the app to the WABA
Click **Subscribe app to WABA** on the account page (calls `POST /{waba-id}/subscribed_apps`). Without it Meta does not deliver webhooks for that WABA.

## 9. Go live
- App Dashboard → **Publish / App mode: Live** (needs a Privacy Policy URL). In development mode Meta only sends webhooks for test numbers and app roles.
- WhatsApp Manager → **Payment settings**: add a payment method for business-initiated (template) messages.
- **Test number only:** add up to 5 recipient numbers on API Setup ("To" list), otherwise sending fails with error 131030.

## 10. Templates
- Create templates in **WhatsApp Manager → Message templates**; wait for approval.
- In this app: **Message Templates → Sync from Meta**. Approved templates can then be sent from the chat (outside the 24-hour window) or from the template page.

## 11. Messaging rules enforced by this app
| Rule | Behaviour |
|---|---|
| 24-hour customer service window | Free-form text/media only within 24 h of the customer's last message; otherwise the UI asks for a template |
| Business-initiated messages | Only **APPROVED** templates; blocked for contacts marked *opted out* |
| STOP / UNSUBSCRIBE | Incoming keyword marks the contact opted out; START opts in again |
| Rate limits (130429, 80007, 131056 …) | Message stays *pending* and is retried with back-off |
| Token / permission errors (190, 10, 200…) | Message fails, account is flagged **API failed** with the reason |

## Troubleshooting
| Symptom | Fix |
|---|---|
| "Verify and save" fails in Meta | URL must be HTTPS and public; verify token must match exactly; check `storage/logs/laravel.log` |
| Webhook verified but no messages arrive | Subscribe the `messages` field; click *Subscribe app to WABA*; app must be **Live**; queue worker must run |
| API failed: error 190 | Token expired/revoked — generate a System User token (step 4) |
| API failed: "App Secret does not match" | Copy the App Secret of the **same** app that issued the token |
| Error 131047 | 24 h window closed — send a template |
| Error 131030 | Test number: add the recipient to the allowed list |

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

## Option B — Add manually (your own Meta app)

> Checked against Meta's current docs on **24 Nov 2026**:
> <https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started>.
> In 2026 Meta moved its docs to `developers.facebook.com/documentation/…`. The WhatsApp menus now live under
> **Use cases → Connect with customers through WhatsApp → Customize** (older apps still have a **WhatsApp** menu with the same pages).

This part gets the values the **WhatsApp Accounts → Add WhatsApp account** form needs, registers the number and connects Meta's webhook.
Only Meta's **official Cloud API** is used. There is no QR code, no WhatsApp Web and no unofficial library.

**You need:**
- a Facebook account;
- a phone number that can receive an SMS or call and is **not** active in the WhatsApp / WhatsApp Business app (delete the WhatsApp account on that phone first: *Settings → Account → Delete account*);
- this app running on **HTTPS** (e.g. `https://whats-apis.com`).

| Form field | Where it comes from |
|---|---|
| Account name | Anything your team recognises, e.g. "Sales – Lahore" |
| Meta Business Account ID (optional) | <https://business.facebook.com/latest/settings/business_info> → Business portfolio ID |
| WhatsApp Business Account ID (WABA ID) | App → **API Setup** (step 5) |
| Phone Number ID | App → **API Setup** (the ID under the number — **not** the phone number itself) |
| WhatsApp access token | Permanent **System User** token (step 7) |
| App Secret | App → **App settings → Basic → App secret → Show** (step 6) |
| Webhook verify token | Any random string (the form's **Generate** button) |
| Graph API version | `v26.0` (default, still supported). Meta's newest is `v27.0`. Change only if Meta asks. |

## 1. Business portfolio
1. Open <https://business.facebook.com> → create (or pick) your **business portfolio**.
2. Optional but recommended: **Business verification** → <https://business.facebook.com/latest/settings/security_center>. It unlocks higher messaging limits and helps the display name get approved.

## 2. Create the Meta app
1. <https://developers.facebook.com/apps> → **Create app**.
2. **App name** + **app contact email** → **Next**.
3. Use case: **Connect with customers through WhatsApp** → **Next**.
4. **Business portfolio**: select yours (or create one) → **Next**.
5. **Publishing requirements**: read → **Next** → **Create app** (Facebook may ask for your password).

You land on **Customize use case → Connect on WhatsApp**. The left menu shows: *Quickstart, API Setup, Configuration, Permissions and features …*.
You can reach the same pages later via **Use cases** (pencil icon) → **Connect with customers through WhatsApp → Customize**.

## 3. Connect a WhatsApp Business Account
1. **Quickstart → Start using the API** (or **API Setup**).
2. Pick an existing WhatsApp Business Account from the dropdown, or **Create a WhatsApp Business account**.
3. Meta also gives you a free **test number** and a **Generate access token** button. That token is **temporary** — use it only for a quick test, never in this app.

## 4. Add your real phone number
1. **API Setup → Add phone number**, or **WhatsApp Manager** (<https://business.facebook.com/latest/whatsapp_manager/>) → **Account tools → Phone numbers → Add phone number**.
2. Enter the **display name** (it must match your business), category and description.
3. Verify the number with the 6-digit code (SMS or voice call).
4. The display name goes to Meta review (WhatsApp Manager shows *In review / Approved*). You can already continue with the next steps.

> Adding and verifying a number does **not** register it on the Cloud API. Registration is done through the API (step 9 — this app has a button for it).

## 5. Copy the IDs
**API Setup** → choose your **real** number in the *From* dropdown → copy:
- **Phone number ID**
- **WhatsApp Business Account ID** (Meta now also calls it *Messaging account ID* — it is the same number).

## 6. App Secret
**App settings → Basic → App secret → Show** (asks for your password) → copy.

The app uses it to check every webhook (`X-Hub-Signature-256`) and to sign API calls (`appsecret_proof`). A wrong secret is caught right away by **Test connection**.

## 7. Permanent access token (System User)
1. <https://business.facebook.com/latest/settings/system_users> (Business settings → **Users → System users**) → **Add**.
2. Name, e.g. `kn-whatsapp-api` → role **Admin** → **Create system user**.
3. **Assign assets**:
   - **Apps** → your app → **Full control** (*Manage app*);
   - **WhatsApp accounts** → your WABA → **Full control** (*Manage WhatsApp Business accounts*) → **Assign assets**.
4. **Generate token** → select your app → token expiration **Never** → permissions:
   - `whatsapp_business_messaging` (send / receive);
   - `whatsapp_business_management` (number details, templates, webhooks);
   - `business_management` (optional — only if Meta's picker suggests it).
   - Then **Generate token**.
5. Copy the token (starts with `EAA…`). Meta shows it only once. In this app it is encrypted after saving and only shown masked.

> **App Review is not needed.** Meta: a direct developer who only uses its own business data does not need App Review or Advanced access.

## 8. Add the number in this app
1. Log in → **WhatsApp Accounts → Add WhatsApp account** → fill the form → **Save & validate**.
2. The account page shows separate statuses:
   - **API connection** — token + IDs confirmed by Meta (phone details fetched automatically);
   - **Webhook verification** — turns green after step 10;
   - **App subscribed to WABA** — step 11.

Saving credentials alone never marks a number as working.

## 9. Register the number on the Cloud API
If the account page shows **Cloud API registration: not registered** (Meta reports `platform_type` ≠ `CLOUD_API`):
1. Enter a **6-digit PIN** → **Register number** (calls `POST /{phone-number-id}/register`).
   - If the number already has a two-step PIN, enter **that** PIN.
   - Otherwise choose any 6 digits.
   - Since Sept–Oct 2026 Meta no longer uses the PIN for eligible numbers, so any 6 digits work then.
2. Meta allows **10 tries per 72 hours** (error 133016 after that).

## 10. Configure the webhook
Meta can only call a **public HTTPS** URL with a valid certificate (self-signed certificates do not work).

1. On the account page copy the **Callback URL**, e.g. `https://whats-apis.com/api/webhooks/whatsapp/<uuid>`, and click **Reveal** to copy the **Verify token**.
2. Meta app → **Use cases → Customize → Configuration** (older apps: **WhatsApp → Configuration**) → **Webhook**: paste both → **Verify and save**.
   The account page now shows **Webhook verified**.
3. **Webhook fields → Manage** → subscribe:
   - `messages` — **required** (incoming messages + sent / delivered / read / failed);
   - recommended: `message_template_status_update`, `phone_number_quality_update`, `account_update` (Meta bans / restrictions — shown only on that WABA's numbers).

> **One app = one callback URL.** If several numbers belong to the same Meta app, use the callback URL of any of them.
> The app routes each event to the right number by `phone_number_id`, but only to numbers saved with the **same App Secret**, so data can never cross between different apps.

## 11. Subscribe the app to the WABA
Click **Subscribe app to WABA** on the account page (`POST /{waba-id}/subscribed_apps`). Without it Meta sends no webhooks for that WABA.

## 12. Publish the app (Live)
1. **App settings → Basic**:
   - **App icon** (1024 × 1024) and **Category**;
   - **Privacy policy URL**: `https://YOUR-DOMAIN/privacy-policy`;
   - **Terms of Service URL**: `https://YOUR-DOMAIN/terms-conditions`;
   - **User data deletion → Data deletion instructions URL**: `https://YOUR-DOMAIN/privacy-policy#data-deletion`;
   - **Save changes**.
2. Left menu **Publish** → review → **Publish**.

While the app is unpublished (development mode), Meta does not send some webhooks.

## 13. Payment method and first test
- Add a payment method for business-initiated (template) messages: <https://business.facebook.com/latest/settings/whatsapp_account> → your account → payment settings (or WhatsApp Manager → **Overview → Add payment method**). Replies inside the 24-hour window do not need templates.
- Test:
  1. From your personal WhatsApp send "hi" to the business number. It appears in **Chats** within seconds.
  2. Reply from the chat; the ticks change.
  3. **Test number only:** add up to 5 recipient numbers on API Setup (*To* list), otherwise sending fails with error 131030.

## 14. Templates
- Create templates in **WhatsApp Manager → Message templates**; wait for approval.
- In this app: **Message Templates → Sync from Meta**. Approved templates can then be sent from the chat (outside the 24-hour window) or from the template page.

## 15. Messaging rules enforced by this app
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
| API failed: error 190 | Token expired/revoked — generate a System User token (step 7) |
| API failed: "App Secret does not match" | Copy the App Secret of the **same** app that issued the token |
| Error 131047 | 24 h window closed — send a template |
| Error 131030 | Test number: add the recipient to the allowed list |
| API warning "not registered on the Cloud API yet" | Step 9 — **Register number** on the account page |
| Error 133016 | More than 10 register tries in 72 hours — wait 72 h |
| "Verify and save" works but nothing in the menu | Newer apps: **Use cases → Customize → Configuration**; older apps: **WhatsApp → Configuration** |
| Number "already in use" when adding | Delete the WhatsApp account in the WhatsApp app on that phone first |

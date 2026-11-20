# Phase 11 — Public landing page (fully editable) + contact inbox

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (238 passing) · browser-checked (desktop, mobile, dark) |
| **Date** | 2026-11-20 |
| **Requested by user** | A landing page with Login and Register buttons at the top. Every text, contact detail, image and video must be editable from the admin panel. |

## Public page (`/`)
- Replaces the old `/` → `/dashboard` redirect. Logged-in users see a **Dashboard** button instead of Login/Register.
- Sticky header: logo + site name, section links (Features, How it works, Pricing, FAQ, Contact). **Login** and **Register** buttons always show, also on mobile. Register is hidden when registration is switched off. Dark/light toggle if allowed.
- Sections, each one switchable on/off:
  - Hero: badge, title, subtitle, two buttons. Right side shows an **image**, an **MP4/WebM video** (muted, looped) or a **YouTube** video. With no image set, a built-in inbox illustration shows instead.
  - Numbers strip.
  - Features (icon cards).
  - How it works (steps).
  - Video section (YouTube or an uploaded video).
  - **Pricing**: shows active plans from the Plans page automatically.
  - Testimonials.
  - FAQ (accordion).
  - **Contact**: email, phone, WhatsApp, address, plus a contact form.
  - Bottom CTA.
  - Footer with Privacy/Terms links.
- WhatsApp number set → floating WhatsApp chat button.
- SEO: page title, description, canonical, Open Graph/Twitter image.
- Uses the brand colour and theme from Settings → Appearance. It has its own small JS bundle (`landing.js`: Alpine only, no websockets).

## Admin editor (Settings → Landing page, sidebar "Landing Page")
- One collapsible card per section, each with a Shown/Hidden switch.
- Repeatable items: add, remove, move up/down; icon picker for features.
- Media picker on every image/video field: **Upload**, choose from the **Media library** (also lets you delete files), or paste an https:// link.
  - Images: PNG/JPG/WebP/GIF, up to 4 MB.
  - Videos: MP4/WebM, up to 50 MB, also limited by the server's `upload_max_filesize`.
  - Files are stored in `public/uploads/landing/`. That folder is git-ignored, so updates and the installer keep it.
- Shows an "Unsaved changes" note and warns before leaving with unsaved edits. Preview button. "Reset to default content".
- The server cleans every saved value (`LandingContent::sanitize`):
  - HTML tags are removed and lengths are limited.
  - Image/video links must be in `uploads/landing/…` or use http(s); `javascript:` links are dropped.
  - Icons must come from the allowed list; YouTube links must be valid.
  - If a link had to be dropped, the admin sees a warning toast.

## Contact form → "Contact Messages" (sidebar, with unread badge)
- `POST /contact`:
  - Limited to 5 requests per minute.
  - A hidden honeypot field catches bots: they get a fake success and nothing is stored.
  - Validation errors go back to `#contact`.
- Saved in the `contact_messages` table. An optional e-mail alert goes to the contact email (or the support email) when SMTP is set up; mail errors never break the form.
- Inbox: All / Unread, search, Reply (mailto), mark read/unread, mark all read, delete.

## Files
- `app/Support/LandingContent.php`, `LandingController`, `LandingEditorController`, `ContactMessageController`, `ContactMessage` model + migration `2026_11_20_400000`.
- `resources/views/landing.blade.php`, `landing/partials/inbox-mockup`, `settings/landing.blade.php`, `components/landing/{section,media-field,item-tools}`, `contact-messages/index`.
- `resources/js/landing.js` (new Vite entry), `resources/js/landing-editor.js`.

## Tests
`LandingTest` has 16 tests covering:
- Login/Register buttons; Register hidden when registration is off; Dashboard button when logged in.
- Pricing shows only active plans; only Super Admin can edit.
- Edits appear on the page; switched-off sections disappear.
- XSS and unsafe-link cleaning; invalid-link warning; reset.
- Image/video upload, SVG rejected, delete limited to the folder.
- Contact form storage, e-mail and inbox; validation and honeypot; form closed when hidden; YouTube parser.

`PageSmokeTest` was updated (`/` is now the landing page). **238 passed (1045 assertions).**

## Browser check (local)
- Desktop, mobile (375 px, menu open) and dark mode all render.
- In the editor: edited the title (`<b>` removed), changed an icon, reordered features, saved → the page updated.
- Contact form → success message → message in the inbox with a badge.
- Test data was removed afterwards.

## Needs from you (on the server)
1. Settings → Landing page: set your texts, upload a hero image or video, and fill in contact email, phone and WhatsApp.
2. For big videos, raise PHP `upload_max_filesize` and `post_max_size` (aaPanel → PHP 8.4 → Configuration), for example to 64M. Nginx `client_max_body_size` must also allow it.

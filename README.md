# KN Softic – WhatsApp Cloud API Manager

A Laravel platform for managing several **official WhatsApp Business Cloud API** numbers from one WhatsApp Web-style inbox.
Meta's Graph API only: no QR codes, no WhatsApp Web automation, no unofficial libraries.

## Features
- **Multiple WhatsApp numbers**, each with its own encrypted credentials, webhook URL, contacts, conversations and templates
- **Separate health statuses** per number: API connection, webhook verification, app subscription to the WABA
- **Webhooks**: Meta verification, `X-Hub-Signature-256` check on the raw body, duplicate-safe storage, queued processing
- **Inbox**: real-time updates over Laravel Reverb (with a polling fallback), unread counters, search, filters, replies, media, delivery and read ticks
- **Sending**: text, image, video, audio, document and templates; the 24-hour window is enforced, with retries and readable Meta error messages
- **Templates**: sync from Meta, preview, variable validation (header, body, URL buttons)
- **Contacts**: tags, internal notes, consent / opt-in tracking (STOP keyword), CSV import and export
- **Security**: roles (Super Admin / Admin / Agent), per-number access, encrypted secrets, masked tokens, audit log, log redaction, rate limits
- Dark and light mode, responsive layout

## Quick start (local)
```bash
composer install && npm install
cp .env.example .env && php artisan key:generate && php artisan reverb:install
php artisan migrate --seed
npm run build
composer run dev
```
Open http://127.0.0.1:8000 and log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.

## Documentation
| Guide | |
|---|---|
| [docs/LOCAL-INSTALLATION.md](docs/LOCAL-INSTALLATION.md) | Windows/XAMPP setup, running the queue and Reverb, local webhooks through a tunnel |
| [docs/META-SETUP.md](docs/META-SETUP.md) | Meta app, WABA, Phone Number ID, permanent token, App Secret, webhook fields |
| [docs/VPS-DEPLOYMENT.md](docs/VPS-DEPLOYMENT.md) | Ubuntu, Nginx, SSL, Supervisor (queue and Reverb), cron, updates, backups |
| [project-log/INDEX.md](project-log/INDEX.md) | Project log: phases, tasks, status, test report |

## Tests
```bash
php artisan test
```
All Meta API calls are faked in tests.

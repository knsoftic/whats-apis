# Local installation (Windows + XAMPP, also works on macOS / Linux)

## Requirements
| Tool | Version |
|---|---|
| PHP | 8.2+ (8.3 / 8.4 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `gd` (and `pdo_sqlite` for tests) |
| Composer | 2.x |
| Node.js / npm | 20+ |
| MySQL / MariaDB | MySQL 8 or MariaDB 10.4+ (XAMPP ships MariaDB) |

## 1. Install
```bash
cd "C:/xampp/htdocs/whts api mi"
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan reverb:install
```
`reverb:install` writes the `REVERB_*` keys into `.env`.

> **Windows tip:** if Composer prints *"Failed to open stream: Permission denied"* for `vendor/composer/tmp-*.zip`, antivirus is locking the download. Run the command again, or use `composer require <package> --prefer-source`.

## 2. Database
Start MySQL from the XAMPP Control Panel, then:
```bash
mysql -u root -e "CREATE DATABASE kn_whatsapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```
Edit `.env` (`DB_*`, and `ADMIN_EMAIL` / `ADMIN_PASSWORD` for the first login), then:
```bash
php artisan migrate --seed
```
The seeder creates the roles (Super Admin, Admin, Agent) and the first Super Admin. If `ADMIN_PASSWORD` is empty, a random password is printed once.

Optional sample data (one clearly labelled demo number with fake chats, cannot send real messages):
```bash
php artisan db:seed --class=DemoDataSeeder
```
Delete "Demo Account (sample data)" on the WhatsApp Accounts page to remove it again.

## 3. Run everything
```bash
composer run dev
```
This starts four processes together: web server (http://127.0.0.1:8000), queue worker, Reverb WebSocket server (port 8080) and Vite.

Or in separate terminals:
```bash
php artisan serve
```
```bash
php artisan queue:work
```
```bash
php artisan reverb:start
```
```bash
npm run dev
```

The **queue worker is required**: webhooks, sending, media downloads and real-time events all run through the queue. Without Reverb the chat still works (it refreshes every 10 seconds instead of instantly).

## 4. Receive real webhooks locally
Meta needs a public HTTPS URL. Use a tunnel, for example Cloudflare:
```bash
cloudflared tunnel --url http://127.0.0.1:8000
```
Put the printed `https://….trycloudflare.com` address in `APP_URL`, then:
```bash
php artisan config:clear
```
The account page now shows the tunnel-based Callback URL to paste into Meta (see META-SETUP.md).

## 5. Tests
```bash
php artisan test
```
Tests use an in-memory SQLite database and fake every Meta API call — nothing is sent to WhatsApp.

## 6. Useful commands
| Command | What it does |
|---|---|
| `php artisan whatsapp:check-accounts` | Re-checks the API credentials of all active numbers (also runs hourly) |
| `php artisan whatsapp:retry-webhooks` | Re-processes webhook events that failed in the last 24 h |
| `php artisan schedule:work` | Runs the scheduler locally (hourly checks, daily clean-up) |
| `php artisan queue:failed` | Lists failed jobs |

## Folder name with spaces
The project folder contains spaces. `php artisan serve` handles that fine. If you prefer XAMPP Apache, point a VirtualHost `DocumentRoot` at the `public` folder instead of browsing to `/whts api mi/public`.

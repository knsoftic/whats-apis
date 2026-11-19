# Production deployment (Ubuntu 24.04 VPS, Nginx, SSL, Supervisor)

Example values: domain `wa.example.com`, app path `/var/www/kn-whatsapp`, Linux user `deploy`.

## 1. Server packages
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server git unzip supervisor certbot python3-certbot-nginx
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

## 2. Database
```bash
sudo mysql -e "CREATE DATABASE kn_whatsapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kn_whatsapp'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON kn_whatsapp.* TO 'kn_whatsapp'@'localhost'; FLUSH PRIVILEGES;"
```

## 3. Code
```bash
sudo mkdir -p /var/www/kn-whatsapp && sudo chown deploy:www-data /var/www/kn-whatsapp
cd /var/www/kn-whatsapp
git clone <your-repository-url> .        # or upload the project files
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### Production `.env` (important lines)
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wa.example.com
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_DATABASE=kn_whatsapp
DB_USERNAME=kn_whatsapp
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis

BROADCAST_CONNECTION=reverb
REVERB_HOST=wa.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

MAIL_MAILER=smtp      # + MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD for password-reset emails
ADMIN_EMAIL=you@your-company.com
ADMIN_PASSWORD=       # empty → random password printed once by db:seed
```
Then:
```bash
php artisan reverb:install          # fills REVERB_APP_ID / KEY / SECRET
php artisan migrate --force
php artisan db:seed --force         # roles + first Super Admin (run once)
npm ci && npm run build             # rebuild whenever VITE_* / REVERB_* values change
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache
```

> **Back up `APP_KEY`.** All saved access tokens / App Secrets are encrypted with it. A new key = re-enter every credential.

## 4. Nginx
`/etc/nginx/sites-available/kn-whatsapp`
```nginx
server {
    listen 80;
    server_name wa.example.com;
    root /var/www/kn-whatsapp/public;
    index index.php;

    client_max_body_size 110M;          # WhatsApp documents up to 100 MB
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    # Laravel Reverb — WebSocket connections (/app/…) and its HTTP API (/apps/…)
    location ~ ^/(app|apps)/ {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/kn-whatsapp /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 5. SSL (required by Meta)
```bash
sudo certbot --nginx -d wa.example.com --redirect
```
In production the app forces `https://` URLs and sends HSTS.

## 6. Queue worker + Reverb with Supervisor
`/etc/supervisor/conf.d/kn-whatsapp.conf`
```ini
[program:kn-whatsapp-queue]
command=php /var/www/kn-whatsapp/artisan queue:work redis --sleep=1 --tries=3 --max-time=3600 --timeout=300
user=deploy
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=320
redirect_stderr=true
stdout_logfile=/var/www/kn-whatsapp/storage/logs/queue.log

[program:kn-whatsapp-reverb]
command=php /var/www/kn-whatsapp/artisan reverb:start --host=127.0.0.1 --port=8080
user=deploy
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/kn-whatsapp/storage/logs/reverb.log
minfds=10000
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

## 7. Scheduler (cron)
```bash
crontab -e -u deploy
```
```cron
* * * * * cd /var/www/kn-whatsapp && php artisan schedule:run >> /dev/null 2>&1
```
Runs hourly credential checks and daily clean-up of old webhook payloads and failed jobs.

## 8. Firewall
```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```
Port 8080 stays closed — Reverb is reached only through Nginx.

## 9. Updating to a new version
```bash
cd /var/www/kn-whatsapp
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
sudo supervisorctl restart kn-whatsapp-reverb
php artisan up
```

## 10. Backups
- Daily `mysqldump kn_whatsapp` (contacts, messages, encrypted credentials).
- `storage/app/private/whatsapp-media` (downloaded / sent media).
- `.env` (contains `APP_KEY`) — store it separately and securely.

## 11. After deployment
1. Log in, change the Super Admin password (Profile).
2. Add WhatsApp accounts and configure Meta webhooks with the `https://wa.example.com/...` Callback URL (META-SETUP.md).
3. Send a test message to your number — it should appear in Chats instantly.

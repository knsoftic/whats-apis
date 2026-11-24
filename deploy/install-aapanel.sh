#!/usr/bin/env bash
# =============================================================================
#  KN Softic – WhatsApp Cloud API Manager — one-command installer for aaPanel
#
#  Install AND update (safe to run again):
#    bash <(curl -fsSL https://raw.githubusercontent.com/knsoftic/whats-apis/main/deploy/install-aapanel.sh)
#
#  Optional settings (put them before "bash"):
#    DOMAIN=whats-apis.com  APP_DIR=/www/wwwroot/whats-apis.com  PHP=/www/server/php/84/bin/php
#    (PHP is auto-detected: newest of 8.5 / 8.4 / 8.3 / 8.2 installed by aaPanel)
#    DB_NAME=kn_whatsapp DB_USER=kn_whatsapp DB_PASS='...'  ADMIN_EMAIL=you@example.com
#    REPO=https://github.com/knsoftic/whats-apis.git  BRANCH=main  GH_TOKEN=... (private repo only)
#
#  What it does: checks PHP, fixes aaPanel disabled functions, installs Composer
#  and Node.js if missing, clones/updates the code, writes .env (first run),
#  composer install, migrate + seed, npm build, permissions, Nginx (run dir
#  /public, Laravel rewrite, Reverb WebSocket proxy), Supervisor (queue +
#  Reverb), cron scheduler, then a health check.
# =============================================================================
set -Eeuo pipefail

DOMAIN="${DOMAIN:-whats-apis.com}"
APP_DIR="${APP_DIR:-/www/wwwroot/${DOMAIN}}"
# Use the newest aaPanel PHP (8.5 → 8.2) unless PHP=/path/to/php is given.
if [[ -z "${PHP:-}" ]]; then
  for v in 85 84 83 82; do
    if [[ -x "/www/server/php/${v}/bin/php" ]]; then PHP="/www/server/php/${v}/bin/php"; break; fi
  done
  PHP="${PHP:-/www/server/php/84/bin/php}"
fi
REPO="${REPO:-https://github.com/knsoftic/whats-apis.git}"
BRANCH="${BRANCH:-main}"
WEB_USER="${WEB_USER:-www}"
if [[ -z "${REVERB_PORT_LOCAL:-}" && -f "${APP_DIR}/.env" ]]; then
  REVERB_PORT_LOCAL=$(grep -E '^REVERB_SERVER_PORT=[0-9]+' "${APP_DIR}/.env" | tail -1 | cut -d= -f2 || true)
fi
REVERB_PORT_LOCAL="${REVERB_PORT_LOCAL:-8080}"
NGINX_VHOST="/www/server/panel/vhost/nginx/${DOMAIN}.conf"
NGINX_REWRITE="/www/server/panel/vhost/rewrite/${DOMAIN}.conf"

G='\033[1;32m'; Y='\033[1;33m'; R='\033[1;31m'; B='\033[1;36m'; N='\033[0m'
step() { echo -e "\n${B}==> $*${N}"; }
ok()   { echo -e "${G}✔ $*${N}"; }
warn() { echo -e "${Y}! $*${N}"; }
die()  { echo -e "${R}✘ $*${N}"; exit 1; }
trap 'die "Failed at line $LINENO. Fix the problem above and run the same command again."' ERR

ask() { # ask VAR "Question" "default" [secret]
  local var="$1" q="$2" def="${3:-}" secret="${4:-}" ans=""
  if [[ -n "${!var:-}" ]]; then return; fi
  if [[ -r /dev/tty ]]; then
    if [[ -n "$secret" ]]; then read -r -s -p "$q: " ans < /dev/tty; echo; else read -r -p "$q [${def}]: " ans < /dev/tty; fi
  fi
  printf -v "$var" '%s' "${ans:-$def}"
}

set_env() { # set_env KEY VALUE  (adds or replaces a line in .env)
  local key="$1" val="$2" file="${APP_DIR}/.env"
  local esc; esc=$(printf '%s' "$val" | sed -e 's/[\/&|]/\\&/g')
  if grep -qE "^#?\s*${key}=" "$file"; then
    sed -i -E "s|^#?\s*${key}=.*|${key}=${esc}|" "$file"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}

artisan() { "$PHP" "${APP_DIR}/artisan" "$@"; }

# Who listens on a TCP port? (prints the command line, empty if free/unknown)
port_pid()   { (ss -ltnp "sport = :$1" 2>/dev/null || true) | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2 || true; }
port_busy()  { (ss -ltn "sport = :$1" 2>/dev/null || true) | tail -n +2 | grep -q . ; }
port_owner() { local p; p=$(port_pid "$1"); [[ -n "$p" && -r "/proc/$p/cmdline" ]] && tr '\0' ' ' < "/proc/$p/cmdline" || true; }
# Our own Reverb = started from this project (command line or working directory).
port_is_ours() {
  local p; p=$(port_pid "$1"); [[ -n "$p" ]] || return 1
  [[ "$(tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null)" == *"${APP_DIR}/artisan"* ]] && return 0
  [[ "$(readlink "/proc/$p/cwd" 2>/dev/null)" == "${APP_DIR}" ]]
}

# -----------------------------------------------------------------------------
step "1/12  Checking server"
[[ $EUID -eq 0 ]] || die "Run as root (sudo -i)."
[[ -x "$PHP" ]] || die "PHP not found at $PHP. Install PHP 8.4 (or 8.3) from aaPanel App Store (or set PHP=/path/to/php)."
PHP_VER=$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
"$PHP" -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' || die "PHP 8.2+ required (found $PHP_VER)."
ok "PHP $PHP_VER"
for ext in pdo_mysql mbstring openssl curl fileinfo tokenizer xml ctype json bcmath; do
  # Capture first: "php -m | grep -q" can hit SIGPIPE and fail randomly under pipefail.
  PHP_MODULES="${PHP_MODULES:-$("$PHP" -m)}"
  grep -qix "$ext" <<< "$PHP_MODULES" || die "PHP extension '$ext' missing → aaPanel → App Store → PHP-${PHP_VER} → Setting → Install extensions → $ext"
done
ok "PHP extensions OK"
command -v git >/dev/null || { apt-get update -y >/dev/null 2>&1 || true; apt-get install -y git >/dev/null 2>&1 || yum install -y git >/dev/null 2>&1; }
command -v git >/dev/null || die "git could not be installed."

# -----------------------------------------------------------------------------
step "2/12  Enabling PHP functions Laravel needs (aaPanel disables them)"
NEEDED="putenv proc_open proc_get_status pcntl_signal pcntl_alarm pcntl_async_signals pcntl_signal_dispatch"
PHP_ETC="$(dirname "$(dirname "$PHP")")/etc"
changed=0
for ini in "$PHP_ETC/php.ini" "$PHP_ETC/php-cli.ini"; do
  [[ -f "$ini" ]] || continue
  line=$(grep -E '^\s*disable_functions\s*=' "$ini" || true)
  [[ -n "$line" ]] || continue
  new="$line"
  for f in $NEEDED; do new=$(echo "$new" | sed -E "s/(=|,)\s*${f}\s*(,|$)/\1\2/g; s/,,+/,/g; s/=\s*,/=/; s/,\s*$//"); done
  if [[ "$new" != "$line" ]]; then
    cp -n "$ini" "${ini}.bak-kn" || true
    sed -i "s|^\s*disable_functions\s*=.*|${new//|/\\|}|" "$ini"
    changed=1
  fi
done
if [[ $changed -eq 1 ]]; then
  ( /etc/init.d/php-fpm-"${PHP_VER/./}" reload || systemctl reload php-fpm-"${PHP_VER/./}" ) >/dev/null 2>&1 || true
  ok "Removed $NEEDED from disable_functions (backup: php.ini.bak-kn), PHP-FPM reloaded"
else
  ok "Nothing to change"
fi
"$PHP" -r 'exit(function_exists("putenv") && function_exists("proc_open") ? 0 : 1);' || die "putenv/proc_open still disabled → remove them in aaPanel → PHP → Disabled functions"

# -----------------------------------------------------------------------------
step "3/12  Composer and Node.js"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer | "$PHP" -- --install-dir=/usr/local/bin --filename=composer >/dev/null
fi
COMPOSER="$(command -v composer)"
ok "Composer $("$PHP" "$COMPOSER" --version --no-ansi 2>/dev/null | awk '{print $3}')"
NODE_MAJOR=$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || echo 0)
if [[ "${NODE_MAJOR:-0}" -lt 20 ]]; then
  if command -v apt-get >/dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
    apt-get install -y nodejs >/dev/null
  else
    curl -fsSL https://rpm.nodesource.com/setup_22.x | bash - >/dev/null
    yum install -y nodejs >/dev/null
  fi
fi
ok "Node $(node -v), npm $(npm -v)"

# ffmpeg turns voice notes recorded in Chrome / Android / iPhone into WhatsApp voice notes (OGG/Opus).
if ! command -v ffmpeg >/dev/null; then
  if command -v apt-get >/dev/null; then
    (apt-get update -y && apt-get install -y ffmpeg) >/dev/null 2>&1 || true
  elif command -v dnf >/dev/null; then
    (dnf install -y epel-release && dnf install -y ffmpeg) >/dev/null 2>&1 || true
  else
    (yum install -y epel-release && yum install -y ffmpeg) >/dev/null 2>&1 || true
  fi
fi
if command -v ffmpeg >/dev/null; then
  ok "ffmpeg $(ffmpeg -version 2>/dev/null | head -n1 | awk '{print $3}') (voice notes)"
else
  warn "ffmpeg could not be installed automatically — voice notes from Chrome/Android/iPhone won't convert. Install it with your package manager."
fi

# -----------------------------------------------------------------------------
step "4/12  Getting the code from $REPO ($BRANCH)"
AUTH_REPO="$REPO"
if [[ -n "${GH_TOKEN:-}" ]]; then AUTH_REPO="${REPO/https:\/\//https://x-access-token:${GH_TOKEN}@}"; fi
mkdir -p "$APP_DIR"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" fetch --quiet "$AUTH_REPO" "$BRANCH"
  git -C "$APP_DIR" reset --quiet --hard FETCH_HEAD
  ok "Updated to $(git -C "$APP_DIR" rev-parse --short HEAD)"
  FIRST_INSTALL=0
else
  [[ -f "$APP_DIR/artisan" ]] && die "$APP_DIR already has a Laravel app without git. Move it away first."
  TMP=$(mktemp -d)
  git clone --quiet --branch "$BRANCH" "$AUTH_REPO" "$TMP/app"
  git -C "$TMP/app" remote set-url origin "$REPO"     # never keep a token on disk
  # aaPanel placeholder files
  chattr -i "$APP_DIR/.user.ini" 2>/dev/null || true
  rm -f "$APP_DIR/index.html" "$APP_DIR/404.html" "$APP_DIR/.htaccess"
  cp -a "$TMP/app/." "$APP_DIR/"
  rm -rf "$TMP"
  ok "Cloned $(git -C "$APP_DIR" rev-parse --short HEAD) into $APP_DIR"
  FIRST_INSTALL=1
fi
cd "$APP_DIR"

# -----------------------------------------------------------------------------
step "5/12  Composer packages"
COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
ok "vendor/ installed"

# -----------------------------------------------------------------------------
step "6/12  .env"
if [[ ! -f .env ]]; then
  cp .env.example .env
  ask DB_NAME "Database name" "kn_whatsapp"
  ask DB_USER "Database user" "kn_whatsapp"
  ask DB_PASS "Database password (aaPanel → Databases)" "" secret
  ask ADMIN_EMAIL "Super Admin email (first login)" "admin@${DOMAIN}"
  [[ -n "$DB_PASS" ]] || die "Database password is required."

  REDIS_PING="$(redis-cli ping 2>/dev/null || true)"
  if [[ "$REDIS_PING" == *PONG* ]] && grep -qix redis <<< "$("$PHP" -m)"; then QUEUE=redis; else QUEUE=database; fi

  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env APP_URL "https://${DOMAIN}"
  set_env APP_TIMEZONE "${APP_TIMEZONE:-Asia/Kolkata}"
  set_env LOG_STACK daily
  set_env LOG_LEVEL warning
  set_env DB_CONNECTION mysql
  set_env DB_HOST 127.0.0.1
  set_env DB_PORT 3306
  set_env DB_DATABASE "$DB_NAME"
  set_env DB_USERNAME "$DB_USER"
  set_env DB_PASSWORD "\"${DB_PASS}\""
  set_env SESSION_ENCRYPT true
  set_env SESSION_SECURE_COOKIE true
  set_env QUEUE_CONNECTION "$QUEUE"
  set_env CACHE_STORE "$QUEUE"
  set_env BROADCAST_CONNECTION reverb
  set_env REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
  set_env REVERB_APP_KEY "$(openssl rand -hex 10)"
  set_env REVERB_APP_SECRET "$(openssl rand -hex 16)"
  set_env REVERB_HOST "\"${DOMAIN}\""
  set_env REVERB_PORT 443
  set_env REVERB_SCHEME https
  set_env REVERB_SERVER_HOST 127.0.0.1
  set_env REVERB_SERVER_PORT "$REVERB_PORT_LOCAL"
  set_env ADMIN_EMAIL "$ADMIN_EMAIL"
  set_env ADMIN_PASSWORD ""
  artisan key:generate --force --no-interaction >/dev/null
  chmod 640 .env
  ok ".env created (queue/cache: $QUEUE). BACK UP .env — APP_KEY encrypts all saved WhatsApp tokens."
else
  ok ".env exists — kept as is"
fi

# Reverb needs its own local port. Another site's Reverb/Soketi on the same port answers
# "Application does not exist" and live chat updates silently stop → move to a free port.
if port_busy "$REVERB_PORT_LOCAL" && ! port_is_ours "$REVERB_PORT_LOCAL"; then
  OTHER="$(port_owner "$REVERB_PORT_LOCAL")"
  NEW_PORT=""
  for p in $(seq 8081 8099); do
    if ! port_busy "$p"; then NEW_PORT="$p"; break; fi
  done
  if [[ -n "$NEW_PORT" ]]; then
    warn "Port ${REVERB_PORT_LOCAL} is used by another program (${OTHER:-unknown}) — moving Reverb to ${NEW_PORT}"
    REVERB_PORT_LOCAL="$NEW_PORT"
  else
    warn "Port ${REVERB_PORT_LOCAL} is used by another program (${OTHER:-unknown}) and 8081-8099 are busy — set REVERB_PORT_LOCAL=<free port> and run again."
  fi
fi
set_env REVERB_SERVER_HOST 127.0.0.1
set_env REVERB_SERVER_PORT "$REVERB_PORT_LOCAL"

# -----------------------------------------------------------------------------
step "7/12  Database"
artisan config:clear --no-interaction >/dev/null
"$PHP" -r '
  $e = []; foreach (file(".env", FILE_IGNORE_NEW_LINES) as $l) { if (preg_match("/^(DB_[A-Z]+)=(.*)$/", $l, $m)) { $e[$m[1]] = trim($m[2], "\"'\''"); } }
  try { new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}", $e["DB_USERNAME"], $e["DB_PASSWORD"]); }
  catch (Throwable $t) { fwrite(STDERR, $t->getMessage()."\n"); exit(1); }' \
  || die "Cannot connect to MySQL. Check DB_DATABASE / DB_USERNAME / DB_PASSWORD in $APP_DIR/.env (aaPanel → Databases)."
artisan migrate --force --no-interaction
SEED_OUT=$(artisan db:seed --force --no-interaction 2>&1 || true)
echo "$SEED_OUT" | grep -E 'Super Admin|password|ERROR|Error' || true
ok "Database ready"

# -----------------------------------------------------------------------------
step "8/12  Frontend build (npm)"
npm ci --no-audit --no-fund --loglevel=error >/dev/null
npm run build --silent >/dev/null
ok "public/build ready"

# -----------------------------------------------------------------------------
step "9/12  Cache and permissions"
artisan optimize --no-interaction >/dev/null
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
chmod -R 775 storage bootstrap/cache
ok "Owner $WEB_USER, caches built"

# -----------------------------------------------------------------------------
step "10/12  Nginx (running dir /public, Laravel rewrite, Reverb proxy)"
if [[ -f "$NGINX_VHOST" ]]; then
  cp "$NGINX_VHOST" "${NGINX_VHOST}.bak-kn"
  sed -i -E "s#^(\s*root\s+)${APP_DIR}/?;#\1${APP_DIR}/public;#" "$NGINX_VHOST"
  if ! grep -q 'KN Softic: Laravel Reverb' "$NGINX_VHOST"; then
    BLOCK_FILE=$(mktemp)
    cat > "$BLOCK_FILE" <<EOF
    client_max_body_size 110m;
    # KN Softic: Laravel Reverb WebSocket proxy
    location ~ ^/(app|apps)/ {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header Scheme \$scheme;
        proxy_set_header SERVER_PORT \$server_port;
        proxy_set_header REMOTE_ADDR \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:${REVERB_PORT_LOCAL};
    }
EOF
    # Insert after the first "#SSL-END" (aaPanel marker); fall back to the first "index" line.
    MARK='#SSL-END'; grep -q "$MARK" "$NGINX_VHOST" || MARK='^[[:space:]]*index[[:space:]]'
    awk -v f="$BLOCK_FILE" -v m="$MARK" 'BEGIN{while((getline l<f)>0) b=b l "\n"} {print} !done && $0 ~ m {printf "%s", b; done=1}' \
      "$NGINX_VHOST" > "${NGINX_VHOST}.new" && cat "${NGINX_VHOST}.new" > "$NGINX_VHOST" && rm -f "${NGINX_VHOST}.new" "$BLOCK_FILE"
  fi
  # Point the WebSocket proxy at the current Reverb port (it may have moved).
  sed -i -E "/KN Softic: Laravel Reverb/,/^[[:space:]]*}/ s#proxy_pass http://127\.0\.0\.1:[0-9]+;#proxy_pass http://127.0.0.1:${REVERB_PORT_LOCAL};#" "$NGINX_VHOST"
  if [[ -f "$NGINX_REWRITE" ]] && ! grep -q 'try_files' "$NGINX_REWRITE"; then
    printf 'location / {\n    try_files $uri $uri/ /index.php$is_args$query_string;\n}\n' > "$NGINX_REWRITE"
  fi
  # aaPanel keeps open_basedir in .user.ini — allow the whole project, not only /public
  for ui in "$APP_DIR/.user.ini" "$APP_DIR/public/.user.ini"; do
    if [[ -f "$ui" ]] && grep -q 'open_basedir' "$ui"; then
      chattr -i "$ui" 2>/dev/null || true
      echo "open_basedir=${APP_DIR}/:/tmp/" > "$ui"
      chattr +i "$ui" 2>/dev/null || true
    fi
  done
  NGINX_BIN=$(command -v nginx || echo /www/server/nginx/sbin/nginx)
  if "$NGINX_BIN" -t >/dev/null 2>&1; then
    "$NGINX_BIN" -s reload
    ok "Nginx updated and reloaded (backup: ${NGINX_VHOST}.bak-kn)"
  else
    cp "${NGINX_VHOST}.bak-kn" "$NGINX_VHOST"
    warn "Nginx test failed — original config restored. Add the Reverb block by hand (guide Step 5.3)."
  fi
else
  warn "No aaPanel vhost at $NGINX_VHOST — create the website in aaPanel first, then run this again."
fi

# -----------------------------------------------------------------------------
step "11/12  Supervisor (queue worker + Reverb) and cron"
find_sup_conf() {
  SUP_CONF=""
  for c in /www/server/panel/plugin/supervisor/supervisord.conf /etc/supervisor/supervisord.conf /etc/supervisord.conf; do
    if [[ -f "$c" ]]; then SUP_CONF="$c"; return 0; fi
  done
  return 0   # "not found" is handled by the caller, not an error under set -e
}
find_sup_conf
if [[ -z "$SUP_CONF" ]] || ! command -v supervisorctl >/dev/null; then
  (apt-get install -y supervisor || yum install -y supervisor) >/dev/null 2>&1 || true
  systemctl enable --now supervisor >/dev/null 2>&1 || systemctl enable --now supervisord >/dev/null 2>&1 || true
  find_sup_conf
fi
[[ -n "$SUP_CONF" ]] || die "Supervisor not found — install 'Supervisor Manager' from aaPanel App Store and run again."
# Program files go wherever this supervisord.conf includes them from.
INCLUDE=$(awk '/^\[include\]/{f=1;next} /^\[/{f=0} f && /^files[[:space:]]*=/{sub(/^files[[:space:]]*=[[:space:]]*/,""); print $1; exit}' "$SUP_CONF")
[[ -n "$INCLUDE" ]] || { INCLUDE="$(dirname "$SUP_CONF")/conf.d/*.conf"; printf '\n[include]\nfiles = %s\n' "$INCLUDE" >> "$SUP_CONF"; }
[[ "$INCLUDE" == /* ]] || INCLUDE="$(dirname "$SUP_CONF")/$INCLUDE"
SUP_DIR=$(dirname "$INCLUDE"); EXT="${INCLUDE##*.}"; mkdir -p "$SUP_DIR"
SUPCTL=(supervisorctl -c "$SUP_CONF")
mkdir -p "$APP_DIR/storage/logs"
cat > "$SUP_DIR/kn-whatsapp-queue.$EXT" <<EOF
[program:kn-whatsapp-queue]
command=$PHP $APP_DIR/artisan queue:work --sleep=1 --tries=3 --max-time=3600 --timeout=300
directory=$APP_DIR
user=$WEB_USER
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=320
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/queue.log
EOF
cat > "$SUP_DIR/kn-whatsapp-reverb.$EXT" <<EOF
[program:kn-whatsapp-reverb]
command=$PHP $APP_DIR/artisan reverb:start --host=127.0.0.1 --port=$REVERB_PORT_LOCAL
directory=$APP_DIR
user=$WEB_USER
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/reverb.log
minfds=10000
EOF
chown "$WEB_USER":"$WEB_USER" "$APP_DIR"/storage/logs/*.log 2>/dev/null || true
"${SUPCTL[@]}" reread >/dev/null 2>&1 || true
"${SUPCTL[@]}" update >/dev/null 2>&1 || true
artisan reverb:restart --no-interaction >/dev/null 2>&1 || true   # old Reverb processes of this app exit within 5 s
"${SUPCTL[@]}" restart 'kn-whatsapp-queue:*' kn-whatsapp-reverb >/dev/null 2>&1 || true
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage" 2>/dev/null || true
"${SUPCTL[@]}" status 2>/dev/null | grep kn-whatsapp \
  || warn "Supervisor programs not running — config written to $SUP_DIR; restart Supervisor in aaPanel and check."
CRON_LINE="* * * * * su -s /bin/bash ${WEB_USER} -c \"cd ${APP_DIR} && ${PHP} artisan schedule:run >> /dev/null 2>&1\""
{ crontab -l 2>/dev/null | grep -vF "cd ${APP_DIR} && ${PHP} artisan schedule:run" || true; echo "$CRON_LINE"; } | crontab -
ok "Cron scheduler set (every minute)"
artisan queue:restart --no-interaction >/dev/null 2>&1 || true

# -----------------------------------------------------------------------------
step "12/12  Health check"
sleep 2
CODE=$(curl -sk -o /dev/null -w '%{http_code}' "https://${DOMAIN}/up" || echo 000)
if [[ "$CODE" == "200" ]]; then ok "https://${DOMAIN}/up → 200"; else warn "https://${DOMAIN}/up → $CODE (check SSL / DNS / Nginx; logs: $APP_DIR/storage/logs)"; fi
LISTENING="$( (ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) || true)"
if grep -q ":${REVERB_PORT_LOCAL} " <<< "$LISTENING"; then ok "Reverb listening on 127.0.0.1:${REVERB_PORT_LOCAL}"; else warn "Reverb is not listening yet — check Supervisor."; fi
sleep 4
echo "Live chat updates (Reverb):"
if artisan whatsapp:realtime-check --no-interaction; then
  ok "Live chat updates working"
else
  warn "Live updates have a problem (see above). The chat page still auto-refreshes every 5 s. Re-check any time: cd ${APP_DIR} && ${PHP} artisan whatsapp:realtime-check"
fi

echo -e "\n${G}=====================================================================${N}"
if [[ "$FIRST_INSTALL" == "1" ]]; then
  echo -e "${G} Installed!${N}  Open https://${DOMAIN}"
  echo -e " Login email: $(grep -E '^ADMIN_EMAIL=' .env | cut -d= -f2)"
  echo -e " Password: shown above under 'Generated password' (change it after login)."
  echo -e " Next: WhatsApp Accounts → Add → copy Callback URL into Meta (docs/META-SETUP.md)."
else
  echo -e "${G} Updated!${N}  https://${DOMAIN} is running commit $(git rev-parse --short HEAD)."
fi
echo -e "${G}=====================================================================${N}"

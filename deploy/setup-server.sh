#!/usr/bin/env bash
# One-time VPS bootstrap (Ubuntu 24.04). Run as root: bash setup-server.sh <domain-or-IP>
set -euo pipefail
SERVER_NAME="${1:-_}"
APP=/var/www/alphapulse
HERE="$(cd "$(dirname "$0")" && pwd)"
if [ -e "$APP/shared/database.sqlite" ]; then
  echo 'Existing legacy SQLite path: run remediate-permissions.sh first; refusing unsafe bootstrap.' >&2
  exit 1
fi

apt-get update
apt-get install -y ca-certificates curl unzip git rsync ufw nginx \
  python3 python3-venv python3-pip python3-dev build-essential sqlite3 certbot python3-certbot-nginx
# Distro PHP (Ubuntu 26.04 ships a recent PHP; no PPA needed)
apt-get install -y php-fpm php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip php-bcmath php-intl
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "PHP $PHPV installed (Laravel 13 needs >= 8.3)"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

id deploy &>/dev/null || adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy
mkdir -p /home/deploy/.ssh
cp -n /home/ubuntu/.ssh/authorized_keys /home/deploy/.ssh/ 2>/dev/null || true
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh

umask 027
mkdir -p $APP/releases $APP/shared/database $APP/shared/storage/app/public $APP/shared/storage/framework/cache \
  $APP/shared/storage/framework/sessions $APP/shared/storage/framework/views $APP/shared/storage/logs
touch "$APP/shared/database/database.sqlite"
# Deployment paths and secrets are read-only to service users.
chown deploy:www-data "$APP" "$APP/releases" "$APP/shared"
chmod 0750 "$APP" "$APP/releases" "$APP/shared"
if [ -f "$APP/shared/.env" ]; then
  chown deploy:www-data "$APP/shared/.env"
  chmod 0640 "$APP/shared/.env"
fi
# Only data directories grant service write access; SGID preserves the group.
chown -R deploy:www-data "$APP/shared/storage" "$APP/shared/database"
find "$APP/shared/storage" "$APP/shared/database" -type d -exec chmod 2770 {} +
find "$APP/shared/storage" "$APP/shared/database" -type f -exec chmod 0660 {} +

# Python engine venv (shared across releases)
sudo -u deploy python3 -m venv $APP/shared/venv
chown -R deploy:www-data "$APP/shared/venv"
chmod -R g+rX,g-w,o-rwx "$APP/shared/venv"

# Let the deploy user restart services without a password
echo "deploy ALL=(root) NOPASSWD: /bin/systemctl restart alphapulse-engine alphapulse-queue, /bin/systemctl reload php$PHPV-fpm, /bin/systemctl reload nginx" > /etc/sudoers.d/deploy
chmod 440 /etc/sudoers.d/deploy

# nginx, systemd units, Laravel scheduler cron
sed "s/__SERVER_NAME__/$SERVER_NAME/; s/__PHPV__/$PHPV/" "$HERE/nginx.conf" > /etc/nginx/sites-available/alphapulse
ln -sf /etc/nginx/sites-available/alphapulse /etc/nginx/sites-enabled/alphapulse
rm -f /etc/nginx/sites-enabled/default
cp "$HERE"/alphapulse-*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable alphapulse-engine alphapulse-queue
echo "* * * * * www-data cd $APP/current && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/alphapulse

ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
echo "OK. Next: create $APP/shared/.env (see deploy/README.md), then push to main."

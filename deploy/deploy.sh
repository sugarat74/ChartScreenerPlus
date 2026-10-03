#!/usr/bin/env bash
# Runs on the VPS as user "deploy". Usage: deploy.sh <release-id>
set -euo pipefail
APP=/var/www/alphapulse
REL=$APP/releases/$1
cd "$REL"

ln -sfn $APP/shared/.env .env
rm -rf storage
ln -sfn $APP/shared/storage storage
ln -sfn $APP/shared/database.sqlite database/database.sqlite

composer install --no-dev --optimize-autoloader --no-interaction
$APP/shared/venv/bin/pip install -q -r engine/requirements.txt
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

PREV=$(readlink -f $APP/current || true)
ln -sfn "$REL" $APP/current.new
mv -Tf $APP/current.new $APP/current
sudo systemctl restart alphapulse-engine alphapulse-queue
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo systemctl reload "php$PHPV-fpm"

# Health check; roll back if the engine does not answer
if ! curl -fsS --retry 15 --retry-delay 2 --retry-connrefused http://127.0.0.1:8090/docs -o /dev/null; then
  echo "Health check failed, rolling back"
  if [ -n "$PREV" ]; then
    ln -sfn "$PREV" $APP/current.new
    mv -Tf $APP/current.new $APP/current
    sudo systemctl restart alphapulse-engine alphapulse-queue
  fi
  exit 1
fi

# Keep the 5 most recent releases
ls -1dt $APP/releases/* | tail -n +6 | xargs -r rm -rf
echo "Deployed $1"

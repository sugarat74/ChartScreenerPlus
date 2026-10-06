#!/usr/bin/env bash
# Runs on the VPS as user "deploy". Usage: deploy.sh <release-id>
set -euo pipefail
APP=/var/www/alphapulse
[[ "${1:-}" =~ ^[a-f0-9]{10,40}$ ]] || { echo 'Invalid release id' >&2; exit 1; }
REL=$APP/releases/$1
[[ -d "$REL" && ! -L "$REL" && "$(realpath "$REL")" == "$REL" ]] || exit 1
# Driver from the shared .env (last assignment wins, like dotenv); no value is printed.
DB_DRIVER=$(sed -n 's/^[[:space:]]*DB_CONNECTION[[:space:]]*=[[:space:]]*["'\'']\{0,1\}\([a-z]*\).*/\1/p' "$APP/shared/.env" | tail -n 1)
case "$DB_DRIVER" in
  sqlite)
    [[ -f "$APP/shared/database/database.sqlite" ]] || {
      echo 'Run remediate-permissions.sh before deploying: private SQLite directory is missing.' >&2
      exit 1
    }
    ;;
  pgsql) ;;
  *) echo "Unsupported DB_CONNECTION in shared/.env: '${DB_DRIVER}'" >&2; exit 1 ;;
esac
umask 027
cd "$REL"

ln -sfn $APP/shared/.env .env
rm -rf storage
ln -sfn $APP/shared/storage storage
if [[ "$DB_DRIVER" == sqlite ]]; then
  ln -sfn $APP/shared/database/database.sqlite database/database.sqlite
fi

composer install --no-dev --optimize-autoloader --no-interaction
$APP/shared/venv/bin/pip install -q -r engine/requirements.txt
# On pgsql with DB_DIRECT_PORT, Laravel runs migrations on the direct
# PostgreSQL endpoint (5432); the cached runtime config uses PgBouncer (6432).
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
if [[ "$DB_DRIVER" == pgsql ]]; then
  # Before activation: the runtime (pooled) path must answer with the app role.
  # db:monitor queries the default connection itself (db:show would go direct).
  php artisan db:monitor --databases=pgsql --max=100000 > /dev/null || {
    echo 'Database unreachable through PgBouncer; release not activated.' >&2; exit 1;
  }
fi
bash "$REL/deploy/release-permissions.sh" "$REL"

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

#!/usr/bin/env bash
# Run once from the provider's root console on the existing Chartiko VPS.
# Does not deploy product code or run migrations/market ingestion.
set -euo pipefail
ALLOW_STALE_LEDGER=0
if [[ ${1:-} == --allow-stale-ledger ]]; then ALLOW_STALE_LEDGER=1; shift; fi
[[ $# == 0 ]] || { echo 'Usage: remediate-permissions.sh [--allow-stale-ledger]' >&2; exit 1; }
[[ $(id -u) == 0 ]] || { echo 'Run this script as root from the provider console.' >&2; exit 1; }
APP=/var/www/alphapulse
SHARED=$APP/shared
REL=$(readlink -f "$APP/current")
OLD_DB=$SHARED/database.sqlite
NEW_DB=$SHARED/database/database.sqlite
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM=php$PHPV-fpm
SYSTEMCTL=$(command -v systemctl)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/chartiko-security-$STAMP
[[ "$REL" =~ ^/var/www/alphapulse/releases/[a-f0-9]{10,40}$ && -d "$REL" ]] || exit 1
for directory in "$APP" "$APP/releases" "$SHARED" "$SHARED/storage" "$SHARED/venv"; do
  [[ -d "$directory" && ! -L "$directory" && $(realpath "$directory") == "$directory" ]] || exit 1
done
if [[ -e "$SHARED/database" ]]; then
  [[ -d "$SHARED/database" && ! -L "$SHARED/database" ]] || exit 1
fi
for tool in sqlite3 python3 runuser visudo; do command -v "$tool" >/dev/null; done
[[ -f "$SHARED/.env" && ! -L "$SHARED/.env" ]] || exit 1
[[ -f "$REL/bootstrap/cache/config.php" ]] || { echo 'Expected cached production config missing.' >&2; exit 1; }
# Reject an alternate database URL/path instead of silently relocating wrong data.
php -r '$c=require $argv[1]; $d=$c["database"]; if ($c["queue"]["default"]!=="database" || $d["default"]!=="sqlite" || !empty($d["connections"]["sqlite"]["url"]) || !in_array($d["connections"]["sqlite"]["database"],array_slice($argv,2),true)) exit(1);' \
  "$REL/bootstrap/cache/config.php" "$OLD_DB" "$NEW_DB"
if [[ -f "$OLD_DB" && ! -L "$OLD_DB" && ! -e "$NEW_DB" ]]; then
  DB=$OLD_DB
elif [[ -f "$NEW_DB" && ! -L "$NEW_DB" && ! -e "$OLD_DB" ]]; then
  DB=$NEW_DB
else
  echo 'Ambiguous database paths; stop and inspect without overwriting either file.' >&2
  exit 1
fi
assert_idle() {
  [[ $(sqlite3 "$DB" 'SELECT count(*) FROM jobs;') == 0 ]] || {
    echo 'Queue has pending/reserved jobs; wait for it to drain before retrying.' >&2; return 1;
  }
  if [[ $(sqlite3 "$DB" "SELECT count(*) FROM ingestion_runs WHERE status = 'running';") != 0 ]]; then
    [[ $ALLOW_STALE_LEDGER == 1 && $(sqlite3 "$DB" "SELECT count(*) FROM ingestion_runs WHERE status = 'running' AND (started_at IS NULL OR started_at > datetime('now','-6 hours'));") == 0 ]] || {
      echo 'A run is marked running. Only an explicitly acknowledged stale ledger may proceed.' >&2; return 1;
    }
    echo 'Acknowledged stale running ledger (>6h); queue empty. Ledger and business data remain unchanged.'
  fi
}
assert_idle
if pgrep -u www-data -f 'artisan (schedule:run|ingestion:|indicators:compute|signals:detect)' >/dev/null; then
  echo 'A scheduler/data command is active; wait before retrying.' >&2; exit 1
fi
install -d -m 0700 "$BACKUP"
cp -a "$SHARED/.env" "$BACKUP/env.before"
cp -a "$REL/bootstrap/cache/config.php" "$BACKUP/config.before.php"
find "$APP" -xdev -printf '%m %U %G %p\n' > "$BACKUP/permissions.before.txt"
if [[ -f /etc/sudoers.d/deploy ]]; then cp -a /etc/sudoers.d/deploy "$BACKUP/sudoers.before"; fi
systemctl is-active --quiet "$FPM" || { echo 'PHP-FPM is not active; inspect before remediation.' >&2; exit 1; }
systemctl is-active --quiet alphapulse-queue || { echo 'Worker is not active; inspect before remediation.' >&2; exit 1; }
[[ ! -f "$SHARED/storage/framework/maintenance.php" && ! -f "$SHARED/storage/framework/down" ]] || {
  echo 'Application is already in maintenance mode; inspect before remediation.' >&2; exit 1;
}
COHERENT=1
PAUSED=0
CRON_PAUSED=0
finish() {
  result=$?
  trap - EXIT
  if [[ $COHERENT == 1 && $PAUSED == 1 ]]; then
    runuser -u deploy -- sh -c 'cd "$1" && php artisan up' sh "$REL" || result=1
    systemctl start "$FPM" alphapulse-queue || result=1
  fi
  if [[ $COHERENT == 1 && $CRON_PAUSED == 1 ]]; then
    cp -a "$BACKUP/cron.before" /etc/cron.d/alphapulse || result=1
  fi
  if [[ $result != 0 ]]; then
    echo "Remediation failed. Private recovery evidence: $BACKUP" >&2
    if [[ $COHERENT == 0 ]]; then
      echo 'Services/cron remain paused. Align DB_DATABASE and config cache with the private database before starting them.' >&2
    fi
  fi
  exit "$result"
}
trap finish EXIT
if [[ -f /etc/cron.d/alphapulse ]]; then
  cp -a /etc/cron.d/alphapulse "$BACKUP/cron.before"
  rm -- /etc/cron.d/alphapulse
  CRON_PAUSED=1
fi
PAUSED=1
runuser -u deploy -- sh -c 'cd "$1" && php artisan down' sh "$REL"
systemctl stop alphapulse-queue "$FPM"
# Recheck after pausing to catch a run that started between the preflight and stop.
assert_idle
if pgrep -u www-data -f 'artisan (schedule:run|ingestion:|indicators:compute|signals:detect)' >/dev/null; then
  echo 'A data command raced the pause; inspect before retrying.' >&2; exit 1
fi
[[ $(sqlite3 "$DB" 'PRAGMA quick_check;') == ok ]] || { echo 'SQLite quick_check failed.' >&2; exit 1; }
sqlite3 "$DB" ".backup '$BACKUP/database.before.sqlite'"
chmod 0600 "$BACKUP/database.before.sqlite"
COHERENT=0
install -d -o deploy -g www-data -m 2770 "$SHARED/database"
if [[ "$DB" == "$OLD_DB" ]]; then
  mv -- "$OLD_DB" "$NEW_DB"
  for suffix in -wal -shm -journal; do
    if [[ -e "$OLD_DB$suffix" ]]; then mv -- "$OLD_DB$suffix" "$NEW_DB$suffix"; fi
  done
fi
python3 - "$SHARED/.env" "$NEW_DB" <<'PY'
import pathlib, re, sys
p = pathlib.Path(sys.argv[1])
lines = p.read_text().splitlines(keepends=True)
updated, found = [], False
for line in lines:
    if re.match(r'^\s*DB_DATABASE\s*=', line):
        if not found:
            updated.append('DB_DATABASE=' + sys.argv[2] + '\n')
            found = True
    else:
        updated.append(line)
if not found:
    updated.append('\nDB_DATABASE=' + sys.argv[2] + '\n')
p.write_text(''.join(updated))
PY
# Lock down deployment and shared parents without following their symlinks.
chown deploy:www-data "$APP" "$APP/releases" "$SHARED" "$SHARED/.env"
chmod 0750 "$APP" "$APP/releases" "$SHARED"
chmod 0640 "$SHARED/.env"
for release in "$APP"/releases/*; do
  [[ -d "$release" && ! -L "$release" && "$release" =~ /[a-f0-9]{10,40}$ ]] || exit 1
  if [[ -d "$release/database" ]]; then
    [[ -L "$release/database/database.sqlite" ]] || { echo 'Unexpected release-local database; inspect.' >&2; exit 1; }
    ln -sfn "$NEW_DB" "$release/database/database.sqlite"
  fi
  find "$release" -xdev -type d -exec chown deploy:www-data {} + -exec chmod 0750 {} +
  find "$release" -xdev -type f -exec chown deploy:www-data {} + -exec chmod u+rw,g+r,g-w,o-rwx,u-s,g-s {} +
  [[ ! -L "$release/bootstrap/cache" ]] || exit 1
  chmod 2770 "$release/bootstrap/cache"
  find "$release/bootstrap/cache" -maxdepth 1 -type f -exec chmod 0660 {} +
  # Retained releases must also point at the new DB when a later rollback occurs.
  if [[ -f "$release/bootstrap/cache/config.php" ]]; then
    php -r '$p=$argv[1]; $c=require $p; $d=&$c["database"]["connections"]["sqlite"]["database"]; if (!in_array($d,array_slice($argv,2),true)) exit(1); $d=$argv[3]; if (file_put_contents($p,"<?php return ".var_export($c,true).";\n")===false) exit(1);' \
      "$release/bootstrap/cache/config.php" "$OLD_DB" "$NEW_DB"
  fi
done
chown -R deploy:www-data "$SHARED/storage" "$SHARED/database" "$SHARED/venv"
find "$SHARED/storage" "$SHARED/database" -type d -exec chmod 2770 {} +
find "$SHARED/storage" "$SHARED/database" -type f -exec chmod 0660 {} +
find "$SHARED/venv" -type d -exec chmod 0750 {} +
find "$SHARED/venv" -type f -exec chmod u+rw,g+r,g-w,o-rwx,u-s,g-s {} +
runuser -u deploy -- sh -c 'cd "$1" && umask 027 && php artisan config:cache' sh "$REL"
chown deploy:www-data "$REL/bootstrap/cache/config.php"
chmod 0660 "$REL/bootstrap/cache/config.php"
php -r '$c=require $argv[1]; if ($c["database"]["connections"]["sqlite"]["database"]!==$argv[2]) exit(1);' "$REL/bootstrap/cache/config.php" "$NEW_DB"
COHERENT=1
# Replace the application-specific rule with exact commands; no wildcard/root shell.
printf 'deploy ALL=(root) NOPASSWD: %s restart alphapulse-engine alphapulse-queue, %s reload %s, %s reload nginx\n' \
  "$SYSTEMCTL" "$SYSTEMCTL" "$FPM" "$SYSTEMCTL" > "$BACKUP/sudoers.new"
chmod 0440 "$BACKUP/sudoers.new"
visudo -cf "$BACKUP/sudoers.new"
install -o root -g root -m 0440 "$BACKUP/sudoers.new" /etc/sudoers.d/deploy
visudo -c
SUDO_RULES=$(sudo -l -U deploy)
printf '%s\n' "$SUDO_RULES"
if grep -Eq 'NOPASSWD:.*(ALL|/\*)' <<< "$SUDO_RULES"; then
  echo 'A broad NOPASSWD rule remains in another sudoers source; inspect it from the root console.' >&2
  exit 1
fi
for path in "$APP" "$APP/releases" "$SHARED" "$REL" "$SHARED/.env" \
  "$REL/artisan" "$REL/engine/app/main.py" "$SHARED/venv"; do
  if runuser -u www-data -- test -w "$path"; then echo "FAIL: www-data can write $path" >&2; exit 1; fi
  echo "PASS: www-data cannot write $path"
done
for path in "$SHARED/storage/framework" "$SHARED/storage/logs" "$SHARED/database" "$REL/bootstrap/cache"; do
  runuser -u www-data -- sh -c 'p=$(mktemp "$1/.chartiko-permission-XXXXXX"); rm -- "$p"' sh "$path"
  echo "PASS: www-data can create/remove data files in $path"
done
runuser -u www-data -- python3 - "$NEW_DB" <<'PY'
import sqlite3, sys
c = sqlite3.connect(sys.argv[1])
c.execute('BEGIN IMMEDIATE')
c.rollback()
c.close()
print('PASS: SQLite writable transaction and rollback; no business data changed')
PY
stat -c '%a %U %G %n' "$APP" "$APP/releases" "$SHARED" "$SHARED/.env" "$SHARED/database" "$NEW_DB" "$SHARED/venv" "$REL/bootstrap/cache"
runuser -u deploy -- sh -c 'cd "$1" && php artisan up' sh "$REL"
systemctl start "$FPM" alphapulse-queue
systemctl is-active "$FPM" alphapulse-queue alphapulse-engine nginx
curl -fsS --max-time 10 http://127.0.0.1:8090/health
echo
PAUSED=0
if [[ $CRON_PAUSED == 1 ]]; then cp -a "$BACKUP/cron.before" /etc/cron.d/alphapulse; CRON_PAUSED=0; fi
echo "Permissions remediated. Private backup: $BACKUP"
echo 'Keep that root-only backup; never restore web write access to code, secrets or activation parents.'

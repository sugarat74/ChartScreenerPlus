#!/usr/bin/env bash
# One-time cut-over of production Chartiko from SQLite to PostgreSQL behind
# PgBouncer. Run from the provider's root console during a maintenance window
# (docs: deploy/README.md "Migración a PostgreSQL").
#
# Usage: migrate-to-postgresql.sh [--dry-run] [--allow-stale-ledger] <pgsql-env-file>
#   <pgsql-env-file>: root-only (0600) file with exactly these keys, no quotes:
#     DB_CONNECTION=pgsql  DB_HOST=127.0.0.1  DB_PORT=6432  DB_DIRECT_PORT=5432
#     DB_DATABASE=chartiko  DB_USERNAME=chartiko  DB_PASSWORD=<openssl rand -hex 32>
#
# --dry-run: preflight + schema migration of the (still unused) PostgreSQL
#   database + value validation of a private SQLite snapshot. The site, the
#   SQLite file, shared/.env, services and cron are not touched.
# Real run: pause site/cron/queue/engine -> refuse unless idle -> private SQLite
#   backup + integrity_check -> migrate + transactional copy (shared/.env still
#   SQLite, so a failure simply resumes on SQLite) -> switch shared/.env +
#   config cache -> resume -> smoke checks. A failure after the switch restores
#   the previous shared/.env and config automatically. The SQLite file is left
#   in place, untouched, as the rollback source.
set -euo pipefail
DRY_RUN=0
ALLOW_STALE_LEDGER=0
while [[ ${1:-} == --* ]]; do
  case $1 in
    --dry-run) DRY_RUN=1 ;;
    --allow-stale-ledger) ALLOW_STALE_LEDGER=1 ;;
    *) echo "Unknown option $1" >&2; exit 1 ;;
  esac
  shift
done
[[ $# == 1 ]] || { echo 'Usage: migrate-to-postgresql.sh [--dry-run] [--allow-stale-ledger] <pgsql-env-file>' >&2; exit 1; }
[[ $(id -u) == 0 ]] || { echo 'Run this script as root from the provider console.' >&2; exit 1; }
FRAGMENT=$1
APP=/var/www/alphapulse
SHARED=$APP/shared
REL=$(readlink -f "$APP/current")
DB=$SHARED/database/database.sqlite
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM=php$PHPV-fpm
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/chartiko-pgsql-cutover-$STAMP
[[ "$REL" =~ ^/var/www/alphapulse/releases/[a-f0-9]{10,40}$ && -d "$REL" ]] || exit 1
[[ -f "$SHARED/.env" && ! -L "$SHARED/.env" ]] || exit 1
[[ -f "$DB" && ! -L "$DB" ]] || { echo "SQLite source $DB missing." >&2; exit 1; }
[[ -f "$REL/bootstrap/cache/config.php" ]] || { echo 'Expected cached production config missing.' >&2; exit 1; }
for tool in sqlite3 python3 runuser curl ss; do command -v "$tool" >/dev/null; done
# SQLite is only ever opened as deploy so no root-owned journal files appear.
sq() { runuser -u deploy -- sqlite3 "$@"; }

# --- Read the PostgreSQL settings without evaluating them as shell code.
[[ -f "$FRAGMENT" && ! -L "$FRAGMENT" && $(stat -c '%u %a' "$FRAGMENT") == '0 600' ]] || {
  echo 'The env file must be a root-owned regular file with mode 0600.' >&2; exit 1;
}
declare -A PG=()
while IFS= read -r line || [[ -n "$line" ]]; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  [[ "$line" =~ ^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DIRECT_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=([A-Za-z0-9._~+/=-]+)$ ]] || {
    echo 'Unexpected line in the env file (allowed: DB_CONNECTION DB_HOST DB_PORT DB_DIRECT_PORT DB_DATABASE DB_USERNAME DB_PASSWORD, unquoted).' >&2; exit 1;
  }
  PG[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
done < "$FRAGMENT"
for key in DB_CONNECTION DB_HOST DB_PORT DB_DIRECT_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
  [[ -n ${PG[$key]:-} ]] || { echo "Missing $key in the env file." >&2; exit 1; }
done
[[ ${PG[DB_CONNECTION]} == pgsql && ${PG[DB_HOST]} == 127.0.0.1 && ${PG[DB_PORT]} == 6432 && ${PG[DB_DIRECT_PORT]} == 5432 ]] || {
  echo 'Expected DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_PORT=6432 (PgBouncer), DB_DIRECT_PORT=5432.' >&2; exit 1;
}
PG_ENV=()
for key in "${!PG[@]}"; do PG_ENV+=("$key=${PG[$key]}"); done

# Artisan as deploy against PostgreSQL while shared/.env still says SQLite:
# process env wins over .env and APP_CONFIG_CACHE points away from the cache.
artisan_pg() {
  runuser -u deploy -- env APP_CONFIG_CACHE="$BACKUP/no-config-cache.php" "${PG_ENV[@]}" \
    sh -c 'cd "$1" && shift && umask 027 && exec php artisan "$@"' sh "$REL" "$@"
}
artisan() { runuser -u deploy -- sh -c 'cd "$1" && shift && umask 027 && exec php artisan "$@"' sh "$REL" "$@"; }

# --- Preflight: current config is the SQLite one, PostgreSQL side is ready.
php -r '$c=require $argv[1]; $d=$c["database"]; if ($d["default"]!=="sqlite" || $d["connections"]["sqlite"]["database"]!==$argv[2] || $c["queue"]["default"]!=="database") exit(1);' \
  "$REL/bootstrap/cache/config.php" "$DB" || { echo 'Cached config is not the expected SQLite production config.' >&2; exit 1; }
php -m | grep -qx pdo_pgsql || { echo 'pdo_pgsql missing: apt-get install php-pgsql, then reload PHP-FPM.' >&2; exit 1; }
systemctl is-active --quiet postgresql pgbouncer "$FPM" alphapulse-queue alphapulse-engine || {
  echo 'postgresql, pgbouncer, PHP-FPM, queue and engine must all be active before the cut-over.' >&2; exit 1;
}
for port in 5432 6432; do
  LISTENERS=$(ss -Hltn "sport = :$port" | awk '{print $4}')
  [[ -n "$LISTENERS" ]] && ! grep -qvE "^(127\.0\.0\.1|\[::1\]):$port$" <<< "$LISTENERS" || {
    echo "Port $port must listen on loopback only: $LISTENERS" >&2; exit 1;
  }
done
# Both endpoints accept the application credentials (password via env, not argv).
for port in 6432 5432; do
  runuser -u deploy -- env PGPORT_CHECK="$port" "${PG_ENV[@]}" php -r '
    $p = new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("PGPORT_CHECK").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    $r = $p->query("select current_user, (select rolsuper from pg_roles where rolname = current_user)")->fetch(PDO::FETCH_NUM);
    if ($r[1]) { fwrite(STDERR, "Role is superuser; refusing.\n"); exit(1); }' || {
    echo "Cannot log in as ${PG[DB_USERNAME]} on 127.0.0.1:$port (or role is superuser)." >&2; exit 1;
  }
done

assert_idle() {
  [[ $(sq "$DB" 'SELECT count(*) FROM jobs;') == 0 ]] || {
    echo 'Queue has pending/reserved jobs; wait for it to drain before retrying.' >&2; return 1;
  }
  if [[ $(sq "$DB" "SELECT count(*) FROM ingestion_runs WHERE status = 'running';") != 0 ]]; then
    [[ $ALLOW_STALE_LEDGER == 1 && $(sq "$DB" "SELECT count(*) FROM ingestion_runs WHERE status = 'running' AND (started_at IS NULL OR started_at > datetime('now','-6 hours'));") == 0 ]] || {
      echo 'A run is marked running. Only an explicitly acknowledged stale ledger (>6h) may proceed.' >&2; return 1;
    }
    echo 'Acknowledged stale running ledger (>6h); it is copied unchanged.'
  fi
  if pgrep -u www-data -f 'artisan (schedule:run|ingestion:|indicators:compute|signals:detect)' >/dev/null; then
    echo 'A scheduler/data command is active; wait before retrying.' >&2; return 1
  fi
}
assert_idle
[[ ! -f "$SHARED/storage/framework/down" ]] || { echo 'Application already in maintenance mode; inspect first.' >&2; exit 1; }

install -d -m 0700 "$BACKUP"
# Private SQLite snapshot readable only by deploy for the copy/validation.
SNAPSHOT_DIR=$(runuser -u deploy -- mktemp -d /tmp/chartiko-cutover-XXXXXX)
SNAPSHOT=$SNAPSHOT_DIR/database.sqlite

if [[ $DRY_RUN == 1 ]]; then
  trap 'rm -rf -- "$SNAPSHOT_DIR"' EXIT
  sq "$DB" ".backup '$SNAPSHOT'"
  [[ $(sq "$SNAPSHOT" 'PRAGMA integrity_check;') == ok ]] || { echo 'SQLite integrity_check failed.' >&2; exit 1; }
  artisan_pg migrate --force
  artisan_pg db:copy-sqlite-to-pgsql --source="$SNAPSHOT" --dry-run
  echo 'Dry run passed: PostgreSQL schema migrated, SQLite data validated. Production untouched.'
  exit 0
fi

cp -a "$SHARED/.env" "$BACKUP/env.before"
cp -a "$REL/bootstrap/cache/config.php" "$BACKUP/config.before.php"
PAUSED=0
CRON_PAUSED=0
SWITCHED=0
resume() {
  if [[ $PAUSED == 1 ]]; then
    systemctl start alphapulse-engine alphapulse-queue || true
    artisan up || true
  fi
  if [[ $CRON_PAUSED == 1 ]]; then cp -a "$BACKUP/cron.before" /etc/cron.d/alphapulse || true; fi
}
finish() {
  result=$?
  trap - EXIT
  rm -rf -- "$SNAPSHOT_DIR"
  if [[ $result != 0 ]]; then
    if [[ $SWITCHED == 1 ]]; then
      echo 'Failure after the switch: restoring the SQLite shared/.env and config cache.' >&2
      cp -a "$BACKUP/env.before" "$SHARED/.env"
      cp -a "$BACKUP/config.before.php" "$REL/bootstrap/cache/config.php"
      systemctl reload "$FPM" || true
      systemctl restart alphapulse-queue || true
    fi
    resume
    echo "Cut-over failed; Chartiko runs on SQLite. Private evidence: $BACKUP" >&2
    echo 'PostgreSQL may hold schema or rows from this attempt; drop and recreate the database (postgresql-add-project.sh) before retrying.' >&2
  fi
  exit "$result"
}
trap finish EXIT

# --- Pause writers.
if [[ -f /etc/cron.d/alphapulse ]]; then
  cp -a /etc/cron.d/alphapulse "$BACKUP/cron.before"
  rm -- /etc/cron.d/alphapulse
  CRON_PAUSED=1
fi
PAUSED=1
artisan down --retry=60
systemctl stop alphapulse-queue alphapulse-engine
assert_idle

# --- Consistent SQLite snapshot (copy source) and private root-only backup.
sq "$DB" ".backup '$SNAPSHOT'"
[[ $(sq "$SNAPSHOT" 'PRAGMA integrity_check;') == ok ]] || { echo 'SQLite integrity_check failed.' >&2; exit 1; }
cp -- "$SNAPSHOT" "$BACKUP/database.before.sqlite"
chmod 0600 "$BACKUP/database.before.sqlite"
sq "$SNAPSHOT" "SELECT 'users', count(*) FROM users UNION ALL SELECT 'daily_bars', count(*) FROM daily_bars UNION ALL SELECT 'indicator_snapshots', count(*) FROM indicator_snapshots UNION ALL SELECT 'signals', count(*) FROM signals UNION ALL SELECT 'watchlist_items', count(*) FROM watchlist_items UNION ALL SELECT 'saved_screeners', count(*) FROM saved_screeners;" | tee "$BACKUP/counts.before.txt"

# --- Schema + transactional copy; shared/.env is still SQLite here.
artisan_pg migrate --force
artisan_pg db:copy-sqlite-to-pgsql --source="$SNAPSHOT" | tee "$BACKUP/copy.txt"

# --- Switch shared/.env to PostgreSQL behind PgBouncer.
python3 - "$SHARED/.env" "$FRAGMENT" <<'PY'
import pathlib, re, sys
env = pathlib.Path(sys.argv[1])
values = {}
for line in pathlib.Path(sys.argv[2]).read_text().splitlines():
    if line and not line.startswith('#'):
        key, value = line.split('=', 1)
        values[key] = value
out = []
for line in env.read_text().splitlines(keepends=True):
    match = re.match(r'^\s*(DB_[A-Z_]+)\s*=', line)
    if match:
        continue  # every DB_* line is replaced; the backup keeps the SQLite ones
    out.append(line)
if out and not out[-1].endswith('\n'):
    out.append('\n')
out.append('\n# PostgreSQL behind PgBouncer (migrate-to-postgresql.sh)\n')
out.extend(f'{key}={values[key]}\n' for key in
           ('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DIRECT_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'))
env.write_text(''.join(out))
PY
chown deploy:www-data "$SHARED/.env"
chmod 0640 "$SHARED/.env"
SWITCHED=1
artisan config:cache
chown deploy:www-data "$REL/bootstrap/cache/config.php"
chmod 0660 "$REL/bootstrap/cache/config.php"
php -r '$c=require $argv[1]; $p=$c["database"]["connections"]["pgsql"]; if ($c["database"]["default"]!=="pgsql" || (string)$p["port"]!=="6432" || (string)($p["direct"]["port"] ?? "")!=="5432") exit(1);' \
  "$REL/bootstrap/cache/config.php" || { echo 'Cached config does not point at PgBouncer with a direct endpoint.' >&2; exit 1; }

# --- Resume and smoke-test through the pooled path.
systemctl reload "$FPM"
systemctl start alphapulse-engine alphapulse-queue
artisan up
PAUSED=0
curl -fsS --retry 15 --retry-delay 2 --retry-connrefused --max-time 10 http://127.0.0.1:8090/health -o /dev/null
HOST=$(php -r '$c=require $argv[1]; echo parse_url($c["app"]["url"], PHP_URL_HOST);' "$REL/bootstrap/cache/config.php")
[[ "$HOST" =~ ^[A-Za-z0-9.-]+$ ]] || { echo 'Cannot derive the public host from APP_URL.' >&2; exit 1; }
smoke() {
  local path=$1 expected=$2 status
  status=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -H 'Accept: application/json' --resolve "$HOST:443:127.0.0.1" "https://$HOST$path")
  [[ "$status" == "$expected" ]] || { echo "Smoke $path returned $status, expected $expected." >&2; return 1; }
  echo "PASS: $path -> $status"
}
smoke /api/screener 200
smoke /api/instruments/NVDA 200
smoke /api/watchlist 401
smoke /api/admin/ping 401
artisan schedule:list | grep -q 'ingestion:pipeline' || { echo 'ingestion:pipeline (ingestion-pipeline) is not scheduled.' >&2; exit 1; }
SWITCHED=0
if [[ $CRON_PAUSED == 1 ]]; then cp -a "$BACKUP/cron.before" /etc/cron.d/alphapulse; CRON_PAUSED=0; fi
runuser -u postgres -- psql -X -h /var/run/postgresql -p 6432 -d pgbouncer -c 'SHOW POOLS;'
echo "Cut-over complete: Chartiko runs on PostgreSQL through PgBouncer. Private evidence: $BACKUP"
echo "Keep $DB (read-only rollback source) for at least 30 days; rollback steps: deploy/README.md."

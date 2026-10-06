#!/usr/bin/env bash
# Daily private pg_dump of each project database, straight to PostgreSQL over
# the Unix socket (never through PgBouncer). Run as root from a root-owned copy:
#   install -o root -g root -m 0700 deploy/backup-postgresql.sh /usr/local/sbin/chartiko-backup-postgresql
#   /etc/cron.d/chartiko-postgresql-backup (see deploy/README.md)
# Usage: backup-postgresql.sh [--restore-test] [database ...]   (default: chartiko)
# --restore-test restores the newest dump of each database into a scratch
# database, compares every table's row count with production and drops it.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run this script as root.' >&2; exit 1; }
RESTORE_TEST=0
if [[ ${1:-} == --restore-test ]]; then RESTORE_TEST=1; shift; fi
DATABASES=("$@")
[[ ${#DATABASES[@]} -gt 0 ]] || DATABASES=(chartiko)
DIR=${CHARTIKO_BACKUP_DIR:-/var/backups/chartiko-postgresql}
RETENTION_DAYS=${CHARTIKO_BACKUP_RETENTION_DAYS:-14}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
umask 077
install -d -o root -g root -m 0700 "$DIR"

as_postgres() { runuser -u postgres -- "$@"; }

counts() {
  # Exact row count of every table in the public schema, sorted by name.
  local database=$1
  as_postgres psql -X -At -d "$database" -v ON_ERROR_STOP=1 <<'SQL'
SELECT format('SELECT %L || ''|'' || count(*) FROM %I.%I', tablename, schemaname, tablename)
FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename \gexec
SQL
}

for database in "${DATABASES[@]}"; do
  [[ "$database" =~ ^[a-z][a-z0-9_]{1,30}$ ]] || { echo "Invalid database name: $database" >&2; exit 1; }

  if [[ $RESTORE_TEST == 0 ]]; then
    target="$DIR/$database-$STAMP.dump"
    # pg_dump runs as postgres but only root can write the private directory.
    as_postgres pg_dump -Fc -d "$database" > "$target.partial"
    pg_restore --list "$target.partial" > /dev/null
    mv -f -- "$target.partial" "$target"
    chmod 0600 "$target"
    echo "Backup $target ($(stat -c %s "$target") bytes)"
    find "$DIR" -maxdepth 1 -type f -name "$database-*.dump" -mtime +"$RETENTION_DAYS" -print -delete
    continue
  fi

  newest=$(find "$DIR" -maxdepth 1 -type f -name "$database-*.dump" -printf '%T@ %p\n' | sort -n | tail -n 1 | cut -d ' ' -f 2-)
  [[ -n "$newest" ]] || { echo "No backup found for $database in $DIR" >&2; exit 1; }
  scratch="${database}_restore_test"
  as_postgres dropdb --if-exists "$scratch"
  as_postgres createdb "$scratch"
  trap 'as_postgres dropdb --if-exists "$scratch" || true' EXIT
  # The dump is root-only; pg_restore reads it from stdin.
  as_postgres pg_restore --no-owner --no-privileges --exit-on-error -d "$scratch" < "$newest"
  if diff <(counts "$database") <(counts "$scratch"); then
    echo "Restore test passed for $newest: every table count matches $database."
    counts "$scratch"
  else
    echo "Restore test FAILED for $newest: counts differ (production < > restored)." >&2
    exit 1
  fi
  as_postgres dropdb "$scratch"
  trap - EXIT
done

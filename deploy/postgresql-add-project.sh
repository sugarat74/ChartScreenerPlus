#!/usr/bin/env bash
# Creates (or re-asserts) one project's PostgreSQL role + database and
# registers it in the shared PgBouncer. Run as root:
#   openssl rand -hex 32 | tee /dev/tty | bash postgresql-add-project.sh chartiko
# Put the same password in that project's shared/.env (DB_PASSWORD); it is
# read from stdin and never written to disk or argv by this script.
# Administration uses the postgres OS user over the Unix socket (peer); when
# PGHOST is set (CI), psql uses the PG* environment instead.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run this script as root.' >&2; exit 1; }
PROJECT=${1:-}
SERVER_HOST=${2:-127.0.0.1}
SERVER_PORT=${3:-5432}
[[ "$PROJECT" =~ ^[a-z][a-z0-9_]{1,30}$ ]] || {
  echo 'Usage: postgresql-add-project.sh <project> [server-host] [server-port] < password' >&2; exit 1;
}
[[ "$SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ && "$SERVER_PORT" =~ ^[0-9]{2,5}$ ]] || { echo 'Invalid server host/port.' >&2; exit 1; }
HERE=$(cd "$(dirname "$0")" && pwd)
ETC=/etc/pgbouncer
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/chartiko-pgbouncer-$STAMP-$PROJECT
[[ -f "$ETC/databases.ini" && -f "$ETC/userlist.txt" ]] || { echo 'Run install-pgbouncer.sh first.' >&2; exit 1; }

if [[ -t 0 ]]; then read -rsp "Password for role $PROJECT: " PROJECT_DB_PASSWORD; echo; else read -r PROJECT_DB_PASSWORD; fi
[[ ${#PROJECT_DB_PASSWORD} -ge 24 && "$PROJECT_DB_PASSWORD" =~ ^[A-Za-z0-9._~+/=-]+$ ]] || {
  echo 'Password must be at least 24 URL/env-safe characters (use: openssl rand -hex 32).' >&2; exit 1;
}
export PROJECT_DB_PASSWORD

admin_psql() {
  if [[ -n ${PGHOST:-} ]]; then
    psql -X -q -v ON_ERROR_STOP=1 "$@"
  else
    runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 "$@"
  fi
}

# The SQL file must be readable by the postgres OS user.
SQL=$(mktemp)
trap 'rm -f -- "$SQL"' EXIT
cp "$HERE/postgresql-project.sql" "$SQL"
chmod 0644 "$SQL"
admin_psql -d postgres -v "project=$PROJECT" -f "$SQL"
unset PROJECT_DB_PASSWORD

VERIFIER=$(admin_psql -d postgres -At -v "project=$PROJECT" <<'SQL'
SELECT rolpassword FROM pg_authid WHERE rolname = :'project';
SQL
)
[[ "$VERIFIER" == SCRAM-SHA-256\$* ]] || { echo 'Role has no SCRAM verifier; refusing to register it.' >&2; exit 1; }

install -d -m 0700 "$BACKUP"
cp -a "$ETC/databases.ini" "$ETC/userlist.txt" "$BACKUP/"
restore() {
  echo "PgBouncer rejected the change; restoring $BACKUP" >&2
  cp -a "$BACKUP/databases.ini" "$BACKUP/userlist.txt" "$ETC/"
  systemctl reload pgbouncer || systemctl restart pgbouncer || true
  exit 1
}
# Replace this project's lines only; other projects are untouched.
umask 077
grep -v "^$PROJECT = " "$BACKUP/databases.ini" > "$ETC/databases.ini.new" || true
printf '%s = host=%s port=%s dbname=%s\n' "$PROJECT" "$SERVER_HOST" "$SERVER_PORT" "$PROJECT" >> "$ETC/databases.ini.new"
grep -v "^\"$PROJECT\" " "$BACKUP/userlist.txt" > "$ETC/userlist.txt.new" || true
printf '"%s" "%s"\n' "$PROJECT" "$VERIFIER" >> "$ETC/userlist.txt.new"
for file in databases.ini userlist.txt; do
  chown postgres:postgres "$ETC/$file.new"
  chmod 0640 "$ETC/$file.new"
  mv -f -- "$ETC/$file.new" "$ETC/$file"
done
systemctl reload pgbouncer || restore
for _ in $(seq 1 10); do
  if runuser -u postgres -- psql -X -At -h /var/run/postgresql -p 6432 -d pgbouncer -c 'SHOW DATABASES;' | grep -q "^$PROJECT|"; then
    echo "Project $PROJECT: role and database ready; PgBouncer pool registered on 127.0.0.1:6432."
    echo "shared/.env: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=6432 DB_DIRECT_PORT=5432 DB_DATABASE=$PROJECT DB_USERNAME=$PROJECT"
    exit 0
  fi
  sleep 0.5
done
restore

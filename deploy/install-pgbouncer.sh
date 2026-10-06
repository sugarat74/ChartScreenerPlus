#!/usr/bin/env bash
# Installs the shared PgBouncer configuration. Run as root (setup-server.sh,
# the CI test-pgsql job, or the root console after reviewing a change).
# Keeps already registered projects (databases.ini, userlist.txt); backs up the
# previous configuration and restores it if PgBouncer does not come back.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run this script as root.' >&2; exit 1; }
HERE=$(cd "$(dirname "$0")" && pwd)
ETC=/etc/pgbouncer
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/chartiko-pgbouncer-$STAMP
command -v pgbouncer >/dev/null || { echo 'Install the pgbouncer package first.' >&2; exit 1; }
id postgres >/dev/null

install -d -m 0700 "$BACKUP"
install -d -o postgres -g postgres -m 0750 "$ETC"
for file in pgbouncer.ini pg_hba.conf databases.ini userlist.txt; do
  if [[ -e "$ETC/$file" ]]; then cp -a "$ETC/$file" "$BACKUP/$file"; fi
done

restore() {
  echo "PgBouncer did not start with the new configuration; restoring $BACKUP" >&2
  for file in pgbouncer.ini pg_hba.conf databases.ini userlist.txt; do
    if [[ -e "$BACKUP/$file" ]]; then cp -a "$BACKUP/$file" "$ETC/$file"; fi
  done
  systemctl restart pgbouncer || true
  exit 1
}

install -o postgres -g postgres -m 0640 "$HERE/pgbouncer.ini" "$ETC/pgbouncer.ini"
install -o postgres -g postgres -m 0640 "$HERE/pgbouncer-hba.conf" "$ETC/pg_hba.conf"
if [[ ! -s "$ETC/databases.ini" ]]; then
  printf '[databases]\n' > "$ETC/databases.ini"
fi
if [[ ! -e "$ETC/userlist.txt" ]]; then
  : > "$ETC/userlist.txt"
fi
chown postgres:postgres "$ETC/databases.ini" "$ETC/userlist.txt"
chmod 0640 "$ETC/databases.ini" "$ETC/userlist.txt"

systemctl enable pgbouncer >/dev/null
systemctl restart pgbouncer || restore
for _ in $(seq 1 20); do
  if ss -Hltn 'sport = :6432' | grep -q .; then break; fi
  sleep 0.5
done
LISTENERS=$(ss -Hltn 'sport = :6432' | awk '{print $4}')
[[ -n "$LISTENERS" ]] || restore
if grep -qv '^127\.0\.0\.1:6432$' <<< "$LISTENERS"; then
  echo "PgBouncer listens beyond loopback: $LISTENERS" >&2
  restore
fi
runuser -u postgres -- psql -X -q -h /var/run/postgresql -p 6432 -d pgbouncer -c 'SHOW VERSION;' >/dev/null || restore
echo "PgBouncer active on 127.0.0.1:6432 (transaction mode). Previous configuration: $BACKUP"

#!/usr/bin/env bash
# Existing Certbot-managed site only; retain all TLS and routing configuration.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
SITE=$(readlink -f /etc/nginx/sites-enabled/alphapulse)
[[ "$SITE" == /etc/nginx/sites-available/alphapulse && -f "$SITE" ]] || exit 1
BACKUP=/root/chartiko-nginx-$(date -u +%Y%m%dT%H%M%SZ).conf
cp -a "$SITE" "$BACKUP"
rollback() {
  result=$?
  trap - EXIT
  if [[ $result != 0 ]]; then
    cp -a "$BACKUP" "$SITE"
    nginx -t && systemctl reload nginx
    echo "Restored previous Nginx config from $BACKUP" >&2
  fi
  exit "$result"
}
trap rollback EXIT
python3 - "$SITE" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
s = p.read_text()
if '$chartiko_robots' not in s:
    marker = '    index index.html;'
    if s.count(marker) != 1:
        raise SystemExit('Expected exactly one SPA index; stopped without changing config')
    rules = '''map $request_uri $chartiko_robots {
    default "";
    ~^/(screener|chart|instruments|admin|portal|login|register)(/|\\?|$) "noindex, follow";
}

'''
    s = rules + s.replace(marker, marker + '\n    add_header X-Robots-Tag $chartiko_robots always;', 1)
    p.write_text(s)
PY
nginx -t
systemctl reload nginx
systemctl is-active nginx
echo "Nginx metadata applied; backup: $BACKUP"

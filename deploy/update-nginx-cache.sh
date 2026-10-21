#!/usr/bin/env bash
# Existing Certbot-managed site only; retain all TLS and routing configuration.
# Adds HTTP caching for the SPA: the HTML shell is always revalidated (a
# deploy shows at once) and the hashed build assets are immutable for a year.
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
if 'immutable' not in s:
    old = '''    # React SPA
    location / {
        try_files $uri /index.html;
    }
'''
    # A location-level add_header drops the server-level ones, so each block
    # repeats X-Robots-Tag. Cache-Control has no `always`: errors stay uncached.
    new = '''    # Hashed build assets never change: cache them for a year.
    location /assets/ {
        add_header X-Robots-Tag $chartiko_robots always;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    # React SPA: the HTML shell is always revalidated, so a deploy shows at once.
    location / {
        add_header X-Robots-Tag $chartiko_robots always;
        add_header Cache-Control "no-cache";
        try_files $uri /index.html;
    }
'''
    if s.count(old) != 1:
        raise SystemExit('Expected exactly one SPA location; stopped without changing config')
    p.write_text(s.replace(old, new, 1))
PY
nginx -t
systemctl reload nginx
systemctl is-active nginx
echo "Nginx cache headers applied; backup: $BACKUP"

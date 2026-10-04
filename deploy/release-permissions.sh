#!/usr/bin/env bash
# Run as deploy before activating a release; never follow links into shared data.
set -euo pipefail
APP=/var/www/alphapulse
REL=${1:?Usage: release-permissions.sh <absolute-release-directory>}
[[ "$REL" =~ ^/var/www/alphapulse/releases/[a-f0-9]{10,40}$ ]] || exit 1
[[ -d "$REL" && ! -L "$REL" && "$(realpath "$REL")" == "$REL" ]] || exit 1
[[ ! -L "$APP" && ! -L "$APP/releases" ]] || exit 1
chgrp www-data "$APP" "$APP/releases" "$REL"
chmod 0750 "$APP" "$APP/releases"
# find's default -P prevents traversal through storage/.env/database symlinks.
find "$REL" -xdev -type d -exec chgrp www-data {} + -exec chmod 0750 {} +
find "$REL" -xdev -type f -exec chgrp www-data {} + -exec chmod u+rw,g+r,g-w,o-rwx {} +
find "$REL" -xdev -type f -exec chmod u-s,g-s {} +
[[ ! -L "$REL/bootstrap/cache" ]] || exit 1
chgrp www-data "$REL/bootstrap/cache"
chmod 2770 "$REL/bootstrap/cache"
find "$REL/bootstrap/cache" -maxdepth 1 -type f -exec chmod 0660 {} +

#!/usr/bin/env python3
"""Publish the privacy policy and enforce server-log retention in the VPS
shared Laravel environment (docs/specs/legal-privacy-publication.md).

Same contract as configure-fundamentals-env.py, without a secret: pins the
owner name and contact email (privacy policy gate) and the daily log channel
with the 14-day retention stated in that policy. LEGAL_OWNER_TAX_ID,
LEGAL_OWNER_ADDRESS and LEGAL_OWNER_REGISTRY are never touched: the legal
notice stays withheld until the owner sets them by hand. Runs before the new
release caches Laravel configuration; prints no file content.
"""

import os
import tempfile
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
# The name has a space (double quotes) and a non-ASCII letter (written as UTF-8,
# which phpdotenv reads unchanged).
SETTINGS = {
    'LEGAL_OWNER_NAME': '"Roberto Rodríguez"',
    'LEGAL_CONTACT_EMAIL': 'contacto@chartiko.com',
    'LOG_CHANNEL': 'stack',
    'LOG_STACK': 'daily',
    'LOG_DAILY_DAYS': '14',
}


def build_env(original: str) -> str:
    settings = '\n'.join(f'{name}={value}' for name, value in SETTINGS.items())
    kept = [
        line for line in original.splitlines()
        if line.split('=', 1)[0].strip() not in SETTINGS
    ]
    return '\n'.join(kept).rstrip('\n') + '\n' + settings + '\n'


def main() -> None:
    if not ENV_PATH.is_file() or ENV_PATH.is_symlink():
        raise SystemExit('Shared environment file is unavailable.')

    updated = build_env(ENV_PATH.read_text(encoding='utf-8'))
    metadata = ENV_PATH.stat()
    fd, temporary = tempfile.mkstemp(prefix='.env.legal-', dir=ENV_PATH.parent)
    try:
        os.fchmod(fd, metadata.st_mode & 0o777)
        os.fchown(fd, metadata.st_uid, metadata.st_gid)
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as output:
            output.write(updated)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, ENV_PATH)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

    print('Legal publication and log retention server environment configured.')


if __name__ == '__main__':
    main()

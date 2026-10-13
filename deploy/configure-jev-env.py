#!/usr/bin/env python3
"""Install the CI-held Jev key in the VPS shared Laravel environment.

The key arrives on stdin over SSH; neither the value nor the environment file
is printed. This runs before the new release caches Laravel configuration.
"""

import os
import re
import sys
import tempfile
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
SETTING_NAMES = {
    'TYPESAFE_API_KEY', 'JEV_MODEL', 'JEV_MARKET_DATA_RIGHTS_VERIFIED', 'JEV_ENABLED',
}


def build_env(original: str, key: str) -> str:
    if len(key) < 20 or re.search(r'[\s"\'#$=]', key):
        raise ValueError('Invalid Jev key format.')
    settings = '\n'.join([
        f'TYPESAFE_API_KEY="{key}"',
        'JEV_MODEL=jev-1.13.0',
        'JEV_MARKET_DATA_RIGHTS_VERIFIED=true',
        'JEV_ENABLED=true',
    ])
    kept = [
        line for line in original.splitlines()
        if line.split('=', 1)[0].strip() not in SETTING_NAMES
    ]
    return '\n'.join(kept).rstrip('\n') + '\n' + settings + '\n'


def main() -> None:
    key = sys.stdin.read().strip()
    if not ENV_PATH.is_file() or ENV_PATH.is_symlink():
        raise SystemExit('Shared environment file is unavailable.')

    original = ENV_PATH.read_text(encoding='utf-8')
    try:
        updated = build_env(original, key)
    except ValueError as error:
        raise SystemExit(str(error)) from error
    metadata = ENV_PATH.stat()
    fd, temporary = tempfile.mkstemp(prefix='.env.jev-', dir=ENV_PATH.parent)
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

    print('Jev server environment configured.')


if __name__ == '__main__':
    main()

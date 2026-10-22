#!/usr/bin/env python3
"""Install the CI-held FRED API key in the VPS shared Laravel environment
(docs/specs/macro-series-fred.md).

Same contract as configure-ai-env.py: the key arrives on stdin over SSH,
neither the value nor the environment file is printed, and it runs before the
new release caches Laravel configuration. It writes FRED_API_KEY and
MACRO_ENABLED=true; MACRO_NFCI_ENABLED and MACRO_VIXCLS_ENABLED are never
touched (they stay off unless set by hand after their terms are cleared).

The key may instead be set by hand in shared/.env. With an empty stdin (no
FRED_API_KEY secret) this script changes nothing, so a hand-set key is never
removed, blanked or overwritten.
"""

import os
import re
import sys
import tempfile
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
SETTING_NAMES = {'FRED_API_KEY', 'MACRO_ENABLED'}
KEY_FORMAT = re.compile(r'[a-z0-9]{32}')


def build_env(original: str, key: str) -> str:
    # FRED keys are 32 lower-case letters and digits; anything else (quotes,
    # `$`, whitespace) is rejected before it reaches the dotenv file.
    if not KEY_FORMAT.fullmatch(key):
        raise ValueError('Invalid FRED key format.')
    kept = [
        line for line in original.splitlines()
        if line.split('=', 1)[0].strip() not in SETTING_NAMES
    ]
    settings = f'FRED_API_KEY="{key}"\nMACRO_ENABLED=true'
    return '\n'.join(kept).rstrip('\n') + '\n' + settings + '\n'


def main() -> None:
    key = sys.stdin.read().strip()
    if key == '':
        print('No FRED key provided; server environment unchanged.')
        return
    if not ENV_PATH.is_file() or ENV_PATH.is_symlink():
        raise SystemExit('Shared environment file is unavailable.')

    original = ENV_PATH.read_text(encoding='utf-8')
    try:
        updated = build_env(original, key)
    except ValueError as error:
        raise SystemExit(str(error)) from error
    metadata = ENV_PATH.stat()
    fd, temporary = tempfile.mkstemp(prefix='.env.macro-', dir=ENV_PATH.parent)
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

    print('FRED macro series server environment configured.')


if __name__ == '__main__':
    main()

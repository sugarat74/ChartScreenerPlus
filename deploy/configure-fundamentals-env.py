#!/usr/bin/env python3
"""Enable SEC EDGAR fundamentals in the VPS shared Laravel environment.

Same contract as configure-ai-env.py, without a secret: the SEC requires a
descriptive User-Agent (name + email) and nothing else. Runs before the new
release caches Laravel configuration; prints no file content.
"""

import os
import tempfile
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
# ASCII only: it is sent as an HTTP header to the SEC.
SETTINGS = {
    'SEC_FUNDAMENTALS_ENABLED': 'true',
    'SEC_USER_AGENT': '"Chartiko Roberto Rodriguez contacto@chartiko.com"',
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
    fd, temporary = tempfile.mkstemp(prefix='.env.sec-', dir=ENV_PATH.parent)
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

    print('SEC fundamentals server environment configured.')


if __name__ == '__main__':
    main()

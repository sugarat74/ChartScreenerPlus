#!/usr/bin/env python3
"""Install the CI-held AI company card key in the VPS shared Laravel environment.

Same contract as configure-jev-env.py: the key arrives on stdin over SSH,
neither the value nor the environment file is printed, and it runs before the
new release caches Laravel configuration. Provider, model and the market-data
flag are pinned here; usage limits keep their config/ai_card.php defaults.
"""

import os
import re
import sys
import tempfile
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
SETTINGS = {
    'AI_CARD_ENABLED': 'true',
    'AI_BASE_URL': 'https://inference.devexpert.io/v1',
    'AI_MODEL': 'chat',
    'AI_CARD_MARKET_DATA_RIGHTS_VERIFIED': 'true',
}
SETTING_NAMES = {'AI_API_KEY', *SETTINGS}


def build_env(original: str, key: str) -> str:
    # Double-quoted dotenv value: reject anything that would end the quotes,
    # trigger variable expansion or an escape sequence.
    if len(key) < 20 or re.search(r'[\s"\'$\\`]', key):
        raise ValueError('Invalid AI key format.')
    settings = '\n'.join([f'AI_API_KEY="{key}"'] + [f'{name}={value}' for name, value in SETTINGS.items()])
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
    fd, temporary = tempfile.mkstemp(prefix='.env.ai-', dir=ENV_PATH.parent)
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

    print('AI company card server environment configured.')


if __name__ == '__main__':
    main()

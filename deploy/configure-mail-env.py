#!/usr/bin/env python3
"""Outbound email (Brevo API, EU) settings in the VPS shared Laravel environment
(docs/specs/alerts-email-golive.md, D1).

The single source of truth for every non-secret mail setting. It rewrites only
its own keys in shared/.env through an atomic temporary file that keeps the
file's mode and owner, and it never prints a value.

CI mode (no option; runs on every deploy, no stdin is read):
  - writes the Brevo API settings, From/Reply-To, MAIL_PROVIDER* and both
    email flags (EMAIL_FLAGS below), and deletes the retired MAIL_URL and
    ALERT_EMAIL_PROVIDER* lines;
  - never prints the BREVO_API_KEY; it only checks its prefix;
  - without such a key it writes MAIL_MAILER=log and both flags false,
    whatever EMAIL_FLAGS says, so nothing can be sent or become pending.

Owner mode (`--api-key-stdin`): the owner pipes the Brevo API key on stdin from
his own PowerShell (deploy/README.md). The key is validated (a UTF-8 or
UTF-16 BOM, CR/LF and surrounding whitespace from Windows PowerShell 5.1 are
removed first); on a bad key the script exits 1 and writes nothing. Otherwise
it backs .env up (same mode and owner, newest 3 kept) and writes the settings
plus BREVO_API_KEY, replacing any earlier line (idempotent). Retired Bird SMTP
credentials are removed from the active environment.

`--flags-off` forces both flags off for this run (emergency rollback).
`--apply` then rebuilds the config cache as deploy (umask 007) in the current
release and restarts the services exactly as the sudoers rule allows.
`--env-file PATH` is for offline tests only (deploy/verify-security.sh).
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path


ENV_PATH = Path('/var/www/alphapulse/shared/.env')
APP_DIR = Path('/var/www/alphapulse/current')

# Owner accepted Brevo's received open pixel on 2026-10-24. Keep the API's
# per-recipient tracking consent false; do not opt users into individual tracking.
EMAIL_FLAGS = True

FLAG_NAMES = ('AUTH_EMAIL_VERIFICATION_ENABLED', 'ALERT_EMAIL_DIGEST_ENABLED')
SETTINGS = {
    'MAIL_MAILER': 'brevo',
    'MAIL_FROM_ADDRESS': 'no-reply@chartiko.com',
    'MAIL_FROM_NAME': 'Chartiko',
    'MAIL_REPLY_TO_ADDRESS': 'contacto@chartiko.com',
    'MAIL_PROVIDER': '"Brevo"',
    'MAIL_PROVIDER_LOCATION': '"UE"',
}
RETIRED_NAMES = {'MAIL_URL', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME',
                 'MAIL_PASSWORD', 'ALERT_EMAIL_PROVIDER', 'ALERT_EMAIL_PROVIDER_LOCATION'}
PASSWORD_NAME = 'BREVO_API_KEY'
KEY_FORMAT = re.compile(r'xkeysib-[A-Za-z0-9_-]{32,}')
BACKUP_PREFIX = '.env.backup-mail-'
BACKUPS_KEPT = 3
SERVICES = ('alphapulse-engine', 'alphapulse-queue')


def line_name(line: str) -> str:
    name = line.split('=', 1)[0].strip()
    if name.startswith('export '):
        name = name[len('export '):].strip()
    return name


def line_value(line: str) -> str:
    value = line.split('=', 1)[1].strip() if '=' in line else ''
    if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'':
        value = value[1:-1]
    return value


def key_installed(original: str) -> bool:
    """Whether the effective Brevo API key is installed."""
    values = [line_value(line) for line in original.splitlines() if line_name(line) == PASSWORD_NAME]
    return bool(values) and KEY_FORMAT.fullmatch(values[-1]) is not None


def build_env(original: str, flags: bool, key: str | None = None) -> tuple[str, bool]:
    """Return the new file content and whether a Brevo key is in it."""
    managed = set(SETTINGS) | set(FLAG_NAMES) | RETIRED_NAMES
    if key is not None:
        managed.add(PASSWORD_NAME)
    installed = key is not None or key_installed(original)
    kept = [line for line in original.splitlines() if line_name(line) not in managed]

    values = dict(SETTINGS)
    if not installed:
        values['MAIL_MAILER'] = 'log'
    flag_value = 'true' if flags and installed else 'false'
    values.update({name: flag_value for name in FLAG_NAMES})
    block = [f'{name}={value}' for name, value in values.items()]
    if key is not None:
        block.append(f'{PASSWORD_NAME}="{key}"')
    return '\n'.join(kept).rstrip('\n') + '\n' + '\n'.join(block) + '\n', installed


def read_key(raw: bytes) -> str:
    """Decode the piped key; tolerate what Windows PowerShell 5.1 adds."""
    if raw.startswith(b'\xff\xfe') or raw.startswith(b'\xfe\xff'):
        text = raw.decode('utf-16')
    else:
        text = raw.decode('utf-8-sig')
    key = text.replace('﻿', '').strip()
    if not KEY_FORMAT.fullmatch(key):
        raise ValueError('Invalid Brevo API key format.')
    return key


def write_atomic(path: Path, content: str) -> None:
    metadata = path.stat()
    fd, temporary = tempfile.mkstemp(prefix='.env.mail-', dir=path.parent)
    try:
        if hasattr(os, 'fchmod'):
            os.fchmod(fd, metadata.st_mode & 0o777)
        if hasattr(os, 'fchown'):
            os.fchown(fd, metadata.st_uid, metadata.st_gid)
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def backup(path: Path) -> str:
    """Copy .env next to itself (same mode and owner); keep the newest 3."""
    metadata = path.stat()
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    target = path.parent / f'{BACKUP_PREFIX}{stamp}'
    suffix = 0
    while target.exists():
        suffix += 1
        target = path.parent / f'{BACKUP_PREFIX}{stamp}-{suffix}'
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_BINARY', 0), 0o600)
    try:
        if hasattr(os, 'fchmod'):
            os.fchmod(fd, metadata.st_mode & 0o777)
        if hasattr(os, 'fchown'):
            os.fchown(fd, metadata.st_uid, metadata.st_gid)
        os.write(fd, path.read_bytes())
        os.fsync(fd)
    finally:
        os.close(fd)

    # The UTC stamp in the name sorts chronologically.
    backups = sorted(
        candidate for candidate in path.parent.glob(f'{BACKUP_PREFIX}*') if candidate.is_file()
    )
    for old in backups[:-BACKUPS_KEPT]:
        old.unlink()
    return target.name


def run_quietly(command: list[str], failure: str) -> str:
    # Output is never echoed: an error could quote configuration values.
    result = subprocess.run(command, cwd=APP_DIR, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode != 0:
        raise SystemExit(failure)
    return result.stdout


def apply_runtime() -> None:
    """Rebuild the config cache and restart what reads it (deploy user only)."""
    if os.geteuid() == 0:
        raise SystemExit('Run as deploy, not root.')
    if not APP_DIR.is_dir():
        raise SystemExit('Current release is unavailable.')
    # Same group access as release-permissions.sh: bootstrap/cache is setgid
    # www-data, so the new config.php is 0660 deploy:www-data.
    os.umask(0o007)
    run_quietly(['php', 'artisan', 'config:cache'], 'Configuration cache failed.')
    print('Configuration cached.')
    # Exactly the commands of the deploy sudoers rule (deploy/setup-server.sh).
    run_quietly(['sudo', '-n', 'systemctl', 'restart', *SERVICES], 'Service restart failed.')
    version = run_quietly(['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], 'PHP version unavailable.').strip()
    if not re.fullmatch(r'\d+\.\d+', version):
        raise SystemExit('PHP version unavailable.')
    run_quietly(['sudo', '-n', 'systemctl', 'reload', f'php{version}-fpm'], 'PHP-FPM reload failed.')
    print('Services restarted.')


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description='Configure outbound email (Brevo) in shared/.env.')
    parser.add_argument('--api-key-stdin', action='store_true', help='read the Brevo API key from stdin (owner only)')
    parser.add_argument('--flags-off', action='store_true', help='force both email flags off (rollback)')
    parser.add_argument('--apply', action='store_true', help='rebuild the config cache and restart services')
    parser.add_argument('--env-file', type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    if args.apply and args.env_file is not None:
        raise SystemExit('--apply cannot be combined with --env-file.')
    env_path = args.env_file if args.env_file is not None else ENV_PATH

    key = None
    if args.api_key_stdin:
        if sys.stdin is None or sys.stdin.isatty():
            raise SystemExit('Pipe the Brevo API key on stdin; it is never read from a terminal.')
        try:
            key = read_key(sys.stdin.buffer.read())
        except (ValueError, UnicodeDecodeError):
            raise SystemExit('Invalid Brevo API key format.') from None

    if not env_path.is_file() or env_path.is_symlink():
        raise SystemExit('Shared environment file is unavailable.')

    original = env_path.read_text(encoding='utf-8')
    flags = EMAIL_FLAGS and not args.flags_off
    updated, installed = build_env(original, flags, key)

    backup_name = backup(env_path) if key is not None else None
    if updated != original:
        write_atomic(env_path, updated)

    if key is not None:
        print(f'Brevo API key installed (backup {backup_name}).')
    elif not installed:
        print('Brevo API key not installed: mailer kept on log, email flags off.')
    else:
        print(f'Outbound email server environment configured (email flags {"on" if flags else "off"}).')
    if args.flags_off:
        print('Email flags forced off.')

    if args.apply:
        apply_runtime()


if __name__ == '__main__':
    main()

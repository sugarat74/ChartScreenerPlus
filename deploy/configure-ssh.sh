#!/usr/bin/env bash
# CI only: use a host key verified through the provider console, never ssh-keyscan.
set -euo pipefail
SSH_DIRECTORY=${1:?Usage: configure-ssh.sh <private-ssh-directory>}
: "${VPS_HOST:?VPS_HOST is required}"
: "${VPS_SSH_KEY:?VPS_SSH_KEY is required}"
: "${VPS_SSH_KNOWN_HOSTS:?Set VPS_SSH_KNOWN_HOSTS after independent host verification}"
[[ "$VPS_HOST" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]] || { echo 'Invalid VPS_HOST' >&2; exit 1; }
[[ ! -L "$SSH_DIRECTORY" ]] || { echo 'SSH directory must not be a symlink' >&2; exit 1; }
umask 077
mkdir -p "$SSH_DIRECTORY"
chmod 700 "$SSH_DIRECTORY"
printf '%s\n' "$VPS_SSH_KEY" > "$SSH_DIRECTORY/id_ed25519"
printf '%s\n' "$VPS_SSH_KNOWN_HOSTS" > "$SSH_DIRECTORY/known_hosts"
chmod 600 "$SSH_DIRECTORY/id_ed25519" "$SSH_DIRECTORY/known_hosts"
ssh-keygen -y -P '' -f "$SSH_DIRECTORY/id_ed25519" >/dev/null
ssh-keygen -l -f "$SSH_DIRECTORY/known_hosts" >/dev/null
ssh-keygen -F "$VPS_HOST" -f "$SSH_DIRECTORY/known_hosts" >/dev/null || {
  echo 'Pinned known_hosts does not contain VPS_HOST; deployment refused' >&2
  exit 1
}
cat > "$SSH_DIRECTORY/config" <<EOF
Host chartiko-vps
    HostName $VPS_HOST
    HostKeyAlias $VPS_HOST
    User deploy
    IdentityFile "$SSH_DIRECTORY/id_ed25519"
    UserKnownHostsFile "$SSH_DIRECTORY/known_hosts"
    GlobalKnownHostsFile /dev/null
    StrictHostKeyChecking yes
    BatchMode yes
    IdentitiesOnly yes
    ConnectTimeout 15
EOF
chmod 600 "$SSH_DIRECTORY/config"

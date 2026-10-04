#!/usr/bin/env bash
# Offline SSH regression checks; generates disposable keys, makes no connection.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
WORK=$(mktemp -d)
trap 'rm -rf -- "$WORK"' EXIT
ssh-keygen -q -t ed25519 -N '' -f "$WORK/client"
ssh-keygen -q -t ed25519 -N '' -f "$WORK/host"
export VPS_HOST=chartiko-test.invalid
export VPS_SSH_KEY
VPS_SSH_KEY=$(cat "$WORK/client")
export VPS_SSH_KNOWN_HOSTS
VPS_SSH_KNOWN_HOSTS="$VPS_HOST $(cut -d ' ' -f 1,2 "$WORK/host.pub")"
bash "$HERE/configure-ssh.sh" "$WORK/valid"
CONFIG=$(ssh -G -F "$WORK/valid/config" chartiko-vps 2>/dev/null)
grep -q '^stricthostkeychecking true$' <<< "$CONFIG"
grep -q '^batchmode yes$' <<< "$CONFIG"
grep -q '^identitiesonly yes$' <<< "$CONFIG"
grep -q '^hostname chartiko-test.invalid$' <<< "$CONFIG"
echo 'PASS: explicit pinned host, strict checking, batch mode and selected identity'
if VPS_SSH_KNOWN_HOSTS='' bash "$HERE/configure-ssh.sh" "$WORK/missing" >"$WORK/error" 2>&1; then
  echo 'FAIL: missing host pin accepted' >&2; exit 1
fi
echo 'PASS: missing pin refuses deployment'
if VPS_SSH_KNOWN_HOSTS="other.invalid $(cut -d ' ' -f 1,2 "$WORK/host.pub")" \
  bash "$HERE/configure-ssh.sh" "$WORK/wrong-host" >"$WORK/error" 2>&1; then
  echo 'FAIL: pin for another host accepted' >&2; exit 1
fi
echo 'PASS: pin for another host refuses deployment'
if VPS_SSH_KNOWN_HOSTS="$VPS_HOST ssh-ed25519 invalid-key" \
  bash "$HERE/configure-ssh.sh" "$WORK/invalid" >"$WORK/error" 2>&1; then
  echo 'FAIL: malformed host pin accepted' >&2; exit 1
fi
echo 'PASS: malformed pin refuses deployment'
if VPS_HOST='example.invalid;invalid' bash "$HERE/configure-ssh.sh" "$WORK/invalid-host" >"$WORK/error" 2>&1; then
  echo 'FAIL: invalid hostname accepted' >&2; exit 1
fi
echo 'PASS: hostname injection refused'
for script in "$HERE"/*.sh; do bash -n "$script"; done
echo 'PASS: deployment scripts parse'

# Optional: provider-verified public pin and existing deploy identity. No app writes.
if [[ -n ${CHARTIKO_VERIFY_HOST:-} ]]; then
  : "${CHARTIKO_VERIFY_IDENTITY:?Existing deploy identity required}"
  : "${CHARTIKO_VERIFY_KNOWN_HOSTS:?Provider-verified known_hosts line required}"
  printf '%s\n' "$CHARTIKO_VERIFY_KNOWN_HOSTS" > "$WORK/live-known-hosts"
  ssh -F /dev/null -i "$CHARTIKO_VERIFY_IDENTITY" -o BatchMode=yes -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=yes -o HostKeyAlgorithms=ssh-ed25519 -o ConnectTimeout=12 \
    -o GlobalKnownHostsFile=/dev/null -o "UserKnownHostsFile=$WORK/live-known-hosts" \
    "deploy@$CHARTIKO_VERIFY_HOST" 'printf "PASS: verified live host pin accepted\n"'
  printf '%s %s\n' "$CHARTIKO_VERIFY_HOST" "$(cut -d ' ' -f 1,2 "$WORK/host.pub")" > "$WORK/wrong-known-hosts"
  if ssh -F /dev/null -i "$CHARTIKO_VERIFY_IDENTITY" -o BatchMode=yes -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=yes -o HostKeyAlgorithms=ssh-ed25519 -o ConnectTimeout=12 \
    -o GlobalKnownHostsFile=/dev/null -o "UserKnownHostsFile=$WORK/wrong-known-hosts" \
    "deploy@$CHARTIKO_VERIFY_HOST" 'exit 0' >"$WORK/mismatch" 2>&1; then
    echo 'FAIL: live host accepted with a different pinned key' >&2; exit 1
  fi
  grep -q 'REMOTE HOST IDENTIFICATION HAS CHANGED' "$WORK/mismatch" || {
    echo 'FAIL: connection failed without proving host-key rejection' >&2; exit 1;
  }
  echo 'PASS: different live host pin rejected before authentication'
fi

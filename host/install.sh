#!/usr/bin/env bash
# Host-level configuration the containers depend on but cannot set themselves.
# Run once on a fresh VPS, as root:  host/install.sh
#
# Idempotent -- safe to re-run.
set -euo pipefail

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

HERE="$(cd "$(dirname "$0")" && pwd)"
DROPIN_DIR=/etc/systemd/system/mysql.service.d

log "Preserving mysqld's runtime directory across restarts"
mkdir -p "$DROPIN_DIR"
install -m 0644 "$HERE/mysql-preserve-runtime-dir.conf" "$DROPIN_DIR/preserve-runtime-dir.conf"
systemctl daemon-reload

actual=$(systemctl show mysql -p RuntimeDirectoryPreserve --value)
if [[ "$actual" != "yes" ]]; then
    echo "FAILED: RuntimeDirectoryPreserve is '$actual', expected 'yes'." >&2
    exit 1
fi

echo "RuntimeDirectoryPreserve=yes"
echo "/run/mysqld inode: $(stat -c %i /run/mysqld 2>/dev/null || echo 'MISSING -- is mysqld running?')"

log "Done"

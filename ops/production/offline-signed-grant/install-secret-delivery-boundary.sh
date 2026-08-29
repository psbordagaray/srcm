#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
    printf 'SRCM_SIGNING_SECRET_BOUNDARY_ERROR=root_required\n' >&2
    exit 77
fi

if [[ "$(hostname)" != "straleon-prod-01" ]]; then
    printf 'SRCM_SIGNING_SECRET_BOUNDARY_ERROR=unexpected_host\n' >&2
    exit 78
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
SYSTEMD_SOURCE="$REPO_ROOT/ops/production/systemd/php8.3-fpm.service.d/20-srcm-offline-signed-grant-secret.conf"
FPM_SOURCE="$REPO_ROOT/ops/production/php-fpm/99-srcm-offline-signed-grant-env.conf"
SYSTEMD_TARGET=/etc/systemd/system/php8.3-fpm.service.d/20-srcm-offline-signed-grant-secret.conf
FPM_TARGET=/etc/php/8.3/fpm/pool.d/99-srcm-offline-signed-grant-env.conf
SECRET_DIRECTORY=/etc/srcm/runtime-secrets
SECRET_FILE="$SECRET_DIRECTORY/offline-signed-grant.env"

[[ -f "$SYSTEMD_SOURCE" ]] || exit 66
[[ -f "$FPM_SOURCE" ]] || exit 66
[[ ! -L "$SECRET_DIRECTORY" ]] || exit 79

install -d -o root -g root -m 0700 "$SECRET_DIRECTORY"
[[ "$(readlink -f "$SECRET_DIRECTORY")" == "$SECRET_DIRECTORY" ]] || exit 79
install -d -o root -g root -m 0755 "$(dirname "$SYSTEMD_TARGET")"
install -d -o root -g root -m 0755 "$(dirname "$FPM_TARGET")"
install -o root -g root -m 0644 "$SYSTEMD_SOURCE" "$SYSTEMD_TARGET"
install -o root -g root -m 0644 "$FPM_SOURCE" "$FPM_TARGET"

# This foundation installs only the delivery boundary. It never creates the
# private secret file and intentionally remains valid while the file is absent.
if [[ -e "$SECRET_FILE" ]]; then
    [[ ! -L "$SECRET_FILE" ]] || exit 79
    owner_group="$(stat -c '%U:%G' "$SECRET_FILE")"
    mode="$(stat -c '%a' "$SECRET_FILE")"
    [[ "$owner_group" == 'root:root' ]] || exit 79
    [[ "$mode" == '600' ]] || exit 79
fi

systemctl daemon-reload
/usr/sbin/php-fpm8.3 -tt >/dev/null
systemctl restart php8.3-fpm.service

printf 'SRCM_SIGNING_SECRET_BOUNDARY_STATUS=GREEN_INSTALLED_NO_SECRET_CREATED\n'

#!/usr/bin/env bash
set -euo pipefail

RETIRED_KID="${1:-}"
SECRET_DIRECTORY=/etc/srcm/runtime-secrets
SECRET_FILE="$SECRET_DIRECTORY/offline-signed-grant.env"
RETIRED_FILE="$SECRET_DIRECTORY/.offline-signed-grant.env.retired-$RETIRED_KID"

fail() {
    printf 'SRCM_SIGNING_SECRET_RETIREMENT_ERROR=%s\n' "$1" >&2
    exit "${2:-1}"
}

[[ "$(id -u)" -eq 0 ]] || fail root_required 77
[[ "$(hostname)" == 'straleon-prod-01' ]] || fail unexpected_host 78
[[ "$RETIRED_KID" =~ ^sg-ed25519-[0-9a-f]{16}$ ]] || fail invalid_retired_kid 64
[[ ! -L "$SECRET_DIRECTORY" ]] || fail secret_directory_symlink_forbidden 77
[[ "$(readlink -f "$SECRET_DIRECTORY")" == "$SECRET_DIRECTORY" ]] || fail secret_directory_path_invalid 77
[[ -f "$RETIRED_FILE" ]] || fail retired_secret_missing 66
[[ ! -L "$RETIRED_FILE" ]] || fail retired_secret_symlink_forbidden 77
[[ "$(stat -c '%U:%G' "$RETIRED_FILE")" == 'root:root' ]] || fail retired_secret_owner_invalid 77
[[ "$(stat -c '%a' "$RETIRED_FILE")" == '600' ]] || fail retired_secret_mode_invalid 77
[[ -f "$SECRET_FILE" ]] || fail active_secret_missing 66
[[ ! -L "$SECRET_FILE" ]] || fail active_secret_symlink_forbidden 77

active_kid="$(SRCM_ACTIVE_SECRET_PATH="$SECRET_FILE" php -r '
$path = getenv("SRCM_ACTIVE_SECRET_PATH");
$line = is_string($path) ? trim((string) file_get_contents($path)) : "";
$prefix = "SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL=";
if (!str_starts_with($line, $prefix)) { exit(10); }
$encoded = substr($line, strlen($prefix));
$padding = (4 - (strlen($encoded) % 4)) % 4;
$raw = base64_decode(strtr($encoded.str_repeat("=", $padding), "-_", "+/"), true);
if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { exit(11); }
$public = sodium_crypto_sign_publickey_from_secretkey($raw);
$fingerprint = hash("sha256", $public);
sodium_memzero($raw);
printf("sg-ed25519-%s", substr($fingerprint, 0, 16));
' 2>/dev/null)" || fail active_secret_validation_failed 70

[[ "$active_kid" != "$RETIRED_KID" ]] || fail cannot_retire_active_secret 70
rm -f "$RETIRED_FILE"
printf 'RETIRED_PRIVATE_SECRET_KID=%s\n' "$RETIRED_KID"
printf 'SRCM_SIGNING_SECRET_RETIREMENT_STATUS=GREEN_EXPLICITLY_RETIRED\n'

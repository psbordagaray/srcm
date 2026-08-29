#!/usr/bin/env bash
set -euo pipefail

EXPECTED_KID="${1:-}"
EXPECTED_PUBLIC_FINGERPRINT="${2:-}"
MODE="${3:-initial}"
SECRET_DIRECTORY=/etc/srcm/runtime-secrets
SECRET_FILE="$SECRET_DIRECTORY/offline-signed-grant.env"
STAGED_FILE="$SECRET_DIRECTORY/.offline-signed-grant.env.staged-$EXPECTED_KID"

fail() {
    printf 'SRCM_SIGNING_SECRET_PROMOTION_ERROR=%s\n' "$1" >&2
    exit "${2:-1}"
}

[[ "$(id -u)" -eq 0 ]] || fail root_required 77
[[ "$(hostname)" == 'straleon-prod-01' ]] || fail unexpected_host 78
[[ "$EXPECTED_KID" =~ ^sg-ed25519-[0-9a-f]{16}$ ]] || fail invalid_expected_kid 64
[[ "$EXPECTED_PUBLIC_FINGERPRINT" =~ ^[0-9a-f]{64}$ ]] || fail invalid_expected_fingerprint 64
[[ "$MODE" == 'initial' || "$MODE" == 'rotate' || "$MODE" == 'compromise' ]] || fail invalid_mode 64
[[ ! -L "$SECRET_DIRECTORY" ]] || fail secret_directory_symlink_forbidden 77
[[ "$(readlink -f "$SECRET_DIRECTORY")" == "$SECRET_DIRECTORY" ]] || fail secret_directory_path_invalid 77
[[ -f "$STAGED_FILE" ]] || fail staged_secret_missing 66
[[ ! -L "$STAGED_FILE" ]] || fail staged_secret_symlink_forbidden 77
[[ "$(stat -c '%U:%G' "$STAGED_FILE")" == 'root:root' ]] || fail staged_secret_owner_invalid 77
[[ "$(stat -c '%a' "$STAGED_FILE")" == '600' ]] || fail staged_secret_mode_invalid 77

if [[ "$MODE" == 'initial' && -e "$SECRET_FILE" ]]; then
    fail initial_secret_already_exists 73
fi
if [[ "$MODE" != 'initial' && ! -f "$SECRET_FILE" ]]; then
    fail current_secret_missing 66
fi
if [[ -e "$SECRET_FILE" && -L "$SECRET_FILE" ]]; then
    fail current_secret_symlink_forbidden 77
fi

verify_file() {
    local path="$1"
    SRCM_STAGE_PATH="$path" php -r '
$path = getenv("SRCM_STAGE_PATH");
if (!is_string($path) || $path === "") { exit(10); }
$lines = file($path, FILE_IGNORE_NEW_LINES);
if (!is_array($lines) || count($lines) !== 1) { exit(11); }
$prefix = "SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL=";
if (!str_starts_with($lines[0], $prefix)) { exit(12); }
$encoded = substr($lines[0], strlen($prefix));
if (!is_string($encoded) || preg_match("/^[A-Za-z0-9_-]{86}$/D", $encoded) !== 1) { exit(13); }
$padding = (4 - (strlen($encoded) % 4)) % 4;
$raw = base64_decode(strtr($encoded.str_repeat("=", $padding), "-_", "+/"), true);
if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { exit(14); }
$public = sodium_crypto_sign_publickey_from_secretkey($raw);
$fingerprint = hash("sha256", $public);
$kid = "sg-ed25519-".substr($fingerprint, 0, 16);
sodium_memzero($raw);
printf("%s|%s", $kid, $fingerprint);
' 2>/dev/null
}

verification="$(verify_file "$STAGED_FILE")" || fail staged_secret_validation_failed 70
actual_kid="${verification%%|*}"
actual_fingerprint="${verification#*|}"
[[ "$actual_kid" == "$EXPECTED_KID" ]] || fail staged_kid_mismatch 70
[[ "$actual_fingerprint" == "$EXPECTED_PUBLIC_FINGERPRINT" ]] || fail staged_fingerprint_mismatch 70

previous_kid=''
if [[ "$MODE" != 'initial' ]]; then
    current_verification="$(verify_file "$SECRET_FILE")" || fail current_secret_validation_failed 70
    previous_kid="${current_verification%%|*}"
    [[ "$previous_kid" != "$EXPECTED_KID" ]] || fail rotation_must_use_new_kid 70

    if [[ "$MODE" == 'rotate' ]]; then
        retired="$SECRET_DIRECTORY/.offline-signed-grant.env.retired-$previous_kid"
        [[ ! -e "$retired" ]] || fail retired_secret_already_exists 73
        install -o root -g root -m 0600 "$SECRET_FILE" "$retired"
    fi
fi

installing="$SECRET_DIRECTORY/.offline-signed-grant.env.installing-$$"
trap 'rm -f "$installing"' EXIT
install -o root -g root -m 0600 "$STAGED_FILE" "$installing"
mv -f "$installing" "$SECRET_FILE"
trap - EXIT
rm -f "$STAGED_FILE"

# EnvironmentFile changes require a process restart. A reload is deliberately
# forbidden here because it would leave the old systemd service environment.
systemctl restart php8.3-fpm.service

printf 'PROMOTED_KID=%s\n' "$actual_kid"
printf 'PUBLIC_FINGERPRINT_SHA256=%s\n' "$actual_fingerprint"
if [[ "$MODE" == 'rotate' ]]; then
    printf 'PREVIOUS_PRIVATE_SECRET_RETIRED_KID=%s\n' "$previous_kid"
fi
printf 'SRCM_SIGNING_SECRET_PROMOTION_STATUS=GREEN_SECRET_INSTALLED_FPM_RESTARTED\n'

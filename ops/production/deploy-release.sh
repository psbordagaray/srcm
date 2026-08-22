#!/usr/bin/env bash
set -euo pipefail

RELEASE_SHA="${1:-}"
ARTIFACT="${2:-}"
CHECKSUM_FILE="${3:-}"
READINESS_URL="${4:-}"

ROOT=/srv/srcm
RELEASES=/srv/srcm/releases
CURRENT=/srv/srcm/current
SHARED=/srv/srcm/shared
BACKUPS=/var/backups/srcm

fail() {
    printf 'SRCM_DEPLOY_ERROR=%s\n' "$1" >&2
    exit "${2:-1}"
}

[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail invalid_release_sha 64
[[ "$READINESS_URL" =~ ^https:// ]] || fail readiness_url_must_be_https 64
[[ -f "$ARTIFACT" ]] || fail artifact_missing 66
[[ -f "$CHECKSUM_FILE" ]] || fail checksum_missing 66

cd "$(dirname "$ARTIFACT")"
sha256sum --check "$(basename "$CHECKSUM_FILE")" >/dev/null

[[ -d "$RELEASES" ]] || fail releases_directory_missing 69
[[ -d "$SHARED" ]] || fail shared_directory_missing 69
[[ -f "$SHARED/.env" ]] || fail shared_dotenv_missing 69
[[ -f "$SHARED/database/database.sqlite" ]] || fail shared_sqlite_missing 69
[[ -d "$SHARED/storage" ]] || fail shared_storage_missing 69
[[ -d "$BACKUPS" ]] || fail backup_directory_missing 69
[[ -L "$CURRENT" ]] || fail initial_production_cutover_must_be_separate 69

previous_release="$(readlink -f "$CURRENT")"
[[ -d "$previous_release" ]] || fail current_release_invalid 69

# Existing readiness is a mandatory pre-migration freshness gate. The endpoint
# includes database, queue, failed_jobs, structured logging and verified backup.
curl --fail --silent --show-error --max-time 20 \
    "${READINESS_URL%/}/api/health/ready" >/dev/null \
    || fail current_release_not_ready_or_backup_not_fresh 70

final_release="$RELEASES/$RELEASE_SHA"
incoming_release="$RELEASES/.incoming-$RELEASE_SHA"
[[ ! -e "$final_release" ]] || fail release_already_exists 73
rm -rf "$incoming_release"
mkdir -p "$incoming_release"
tar -xzf "$ARTIFACT" -C "$incoming_release"

[[ ! -e "$incoming_release/.env" ]] || fail artifact_contains_dotenv 74
[[ ! -e "$incoming_release/database/database.sqlite" ]] || fail artifact_contains_sqlite 74
[[ -d "$incoming_release/vendor" ]] || fail artifact_vendor_missing 74
[[ -f "$incoming_release/public/build/manifest.json" ]] || fail artifact_vite_manifest_missing 74

ln -s "$SHARED/.env" "$incoming_release/.env"
mkdir -p "$incoming_release/database"
ln -s "$SHARED/database/database.sqlite" "$incoming_release/database/database.sqlite"
rm -rf "$incoming_release/storage"
ln -s "$SHARED/storage" "$incoming_release/storage"
rm -f "$incoming_release/public/storage"
ln -s "$SHARED/storage/app/public" "$incoming_release/public/storage"

cd "$incoming_release"
php artisan srcm:release-preflight
php artisan optimize

# Single-host SQLite migration policy: the existing production release must be
# healthy and have a fresh verified backup before maintenance begins. Database
# rollback is never automatic.
cd "$previous_release"
php artisan down --retry=60

maintenance_active=1
activated=0
cleanup_on_error() {
    code=$?
    if [[ "$code" -ne 0 ]]; then
        if [[ "$activated" -eq 1 ]]; then
            temp_link="$ROOT/.current-rollback-$RELEASE_SHA"
            ln -s "$previous_release" "$temp_link"
            mv -Tf "$temp_link" "$CURRENT"
            sudo systemctl restart srcm-queue.service || true
            sudo systemctl reload php8.3-fpm.service || true
        fi
        if [[ "$maintenance_active" -eq 1 && -x "$previous_release/artisan" ]]; then
            cd "$previous_release"
            php artisan up || true
        fi
    fi
    exit "$code"
}
trap cleanup_on_error ERR

cd "$incoming_release"
php artisan migrate --force

mv "$incoming_release" "$final_release"
temp_link="$ROOT/.current-$RELEASE_SHA"
ln -s "$final_release" "$temp_link"
mv -Tf "$temp_link" "$CURRENT"
activated=1

cd "$CURRENT"
sudo systemctl restart srcm-queue.service
sudo systemctl reload php8.3-fpm.service
php artisan up
maintenance_active=0

if ! curl --fail --silent --show-error --max-time 20 \
    "${READINESS_URL%/}/api/health/ready" >/dev/null; then
    # Code-only rollback. The database is intentionally left at its migrated
    # state; any database recovery requires explicit OWNER_OR_TECH_ADMIN action.
    temp_link="$ROOT/.current-readiness-rollback-$RELEASE_SHA"
    ln -s "$previous_release" "$temp_link"
    mv -Tf "$temp_link" "$CURRENT"
    sudo systemctl restart srcm-queue.service
    sudo systemctl reload php8.3-fpm.service
    cd "$CURRENT"
    php artisan up || true
    fail post_deploy_readiness_failed_code_rolled_back_database_unchanged 75
fi

trap - ERR
rm -rf "$(dirname "$ARTIFACT")"
printf 'SRCM_DEPLOY_RELEASE_SHA=%s\n' "$RELEASE_SHA"
printf 'SRCM_DEPLOY_STATUS=GREEN\n'

#!/usr/bin/env bash
set -euo pipefail

RELEASE_SHA="${1:-}"
ARTIFACT="${2:-}"
CHECKSUM_FILE="${3:-}"

ROOT=/srv/srcm
RELEASES=/srv/srcm/releases
CURRENT=/srv/srcm/current
SHARED=/srv/srcm/shared
BACKUPS=/var/backups/srcm
EXPECTED_DB_SHA256=b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e
EXPECTED_DB_SIZE=3694592
EXPECTED_MIGRATIONS=122

fail() {
    printf 'SRCM_INITIAL_BOOTSTRAP_ERROR=%s\n' "$1" >&2
    exit "${2:-1}"
}

assert_services_inactive() {
    local unit
    for unit in srcm-queue.service srcm-schedule.service srcm-schedule.timer nginx.service php8.3-fpm.service; do
        if systemctl is-active --quiet "$unit"; then
            fail "service_must_remain_inactive_${unit//./_}" 70
        fi
    done
}

assert_database_exact() {
    local db="$SHARED/database/database.sqlite"
    local actual_sha actual_size

    [[ -f "$db" ]] || fail shared_sqlite_missing 69
    [[ ! -e "$db-wal" && ! -e "$db-shm" && ! -e "$db-journal" ]] \
        || fail sqlite_sidecar_present 70

    actual_sha="$(sha256sum "$db" | awk '{print $1}')"
    actual_size="$(stat -c '%s' "$db")"
    [[ "$actual_sha" == "$EXPECTED_DB_SHA256" ]] || fail production_sqlite_sha_mismatch 70
    [[ "$actual_size" == "$EXPECTED_DB_SIZE" ]] || fail production_sqlite_size_mismatch 70

    SRCM_BOOTSTRAP_DB="$db" SRCM_BOOTSTRAP_MIGRATIONS="$EXPECTED_MIGRATIONS" php -r '
        $path = getenv("SRCM_BOOTSTRAP_DB");
        $expectedMigrations = (int) getenv("SRCM_BOOTSTRAP_MIGRATIONS");
        if (!is_string($path) || $path === "") { exit(10); }
        $pdo = new PDO("sqlite:".$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("PRAGMA query_only=ON");
        $quick = $pdo->query("PRAGMA quick_check")->fetchAll(PDO::FETCH_COLUMN);
        if ($quick !== ["ok"]) { exit(11); }
        $integrity = $pdo->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN);
        if ($integrity !== ["ok"]) { exit(12); }
        $fk = $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC);
        if ($fk !== []) { exit(13); }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        if ($count !== $expectedMigrations) { exit(14); }
    ' || fail production_sqlite_logical_verification_failed 70
}

[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail invalid_release_sha 64
expected_remote_dir="/tmp/srcm-initial-bootstrap-$RELEASE_SHA"
expected_artifact="$expected_remote_dir/srcm-initial-$RELEASE_SHA.tar.gz"
expected_checksum="$expected_artifact.sha256"
[[ "$ARTIFACT" == "$expected_artifact" ]] || fail artifact_path_contract_invalid 64
[[ "$CHECKSUM_FILE" == "$expected_checksum" ]] || fail checksum_path_contract_invalid 64
[[ -f "$ARTIFACT" ]] || fail artifact_missing 66
[[ -f "$CHECKSUM_FILE" ]] || fail checksum_missing 66

cd "$expected_remote_dir"
sha256sum --check "$(basename "$CHECKSUM_FILE")" >/dev/null \
    || fail artifact_checksum_invalid 74

[[ -d "$RELEASES" ]] || fail releases_directory_missing 69
[[ -d "$SHARED" ]] || fail shared_directory_missing 69
[[ -f "$SHARED/.env" ]] || fail shared_dotenv_missing 69
[[ -d "$SHARED/storage" ]] || fail shared_storage_missing 69
[[ -d "$SHARED/storage/app/public" ]] || fail shared_public_storage_missing 69
[[ -d "$BACKUPS" ]] || fail backup_directory_missing 69
[[ -r "$SHARED/.env" ]] || fail shared_dotenv_not_readable_by_deploy_identity 69
[[ -r "$SHARED/database/database.sqlite" ]] || fail shared_sqlite_not_readable_by_deploy_identity 69

[[ ! -e "$CURRENT" && ! -L "$CURRENT" ]] || fail initial_current_must_be_absent 69

shopt -s nullglob dotglob
release_entries=("$RELEASES"/*)
shopt -u nullglob dotglob
[[ "${#release_entries[@]}" -eq 0 ]] || fail initial_releases_directory_must_be_empty 69

assert_services_inactive
assert_database_exact

final_release="$RELEASES/$RELEASE_SHA"
incoming_release="$RELEASES/.incoming-$RELEASE_SHA"
[[ ! -e "$final_release" && ! -L "$final_release" ]] || fail release_already_exists 73
[[ ! -e "$incoming_release" && ! -L "$incoming_release" ]] || fail incoming_release_already_exists 73

while IFS= read -r entry; do
    case "$entry" in
        /*|../*|*/../*|*/..|..) fail artifact_path_traversal_detected 74 ;;
    esac
done < <(tar -tzf "$ARTIFACT")

mkdir -p "$incoming_release"
cleanup_on_exit() {
    code=$?
    trap - EXIT
    if [[ "$code" -ne 0 && -e "$incoming_release" ]]; then
        rm -rf "$incoming_release"
    fi
    exit "$code"
}
trap cleanup_on_exit EXIT

tar -xzf "$ARTIFACT" -C "$incoming_release"

[[ ! -e "$incoming_release/.env" && ! -L "$incoming_release/.env" ]] \
    || fail artifact_contains_dotenv 74
[[ ! -e "$incoming_release/database/database.sqlite" && ! -L "$incoming_release/database/database.sqlite" ]] \
    || fail artifact_contains_sqlite 74
[[ -f "$incoming_release/vendor/autoload.php" ]] || fail artifact_vendor_missing 74
[[ -f "$incoming_release/public/build/manifest.json" ]] || fail artifact_vite_manifest_missing 74
[[ -f "$incoming_release/artisan" ]] || fail artifact_artisan_missing 74
[[ -f "$incoming_release/config/release.php" ]] || fail artifact_release_config_missing 74

ln -s "$SHARED/.env" "$incoming_release/.env"
mkdir -p "$incoming_release/database"
ln -s "$SHARED/database/database.sqlite" "$incoming_release/database/database.sqlite"
rm -rf "$incoming_release/storage"
ln -s "$SHARED/storage" "$incoming_release/storage"
rm -f "$incoming_release/public/storage"
ln -s "$SHARED/storage/app/public" "$incoming_release/public/storage"

cd "$incoming_release"
php artisan srcm:release-preflight --ci
php artisan optimize

assert_database_exact
assert_services_inactive
[[ ! -e "$CURRENT" && ! -L "$CURRENT" ]] || fail current_created_during_bootstrap 75

mv "$incoming_release" "$final_release"
trap - EXIT

assert_database_exact
assert_services_inactive
[[ ! -e "$CURRENT" && ! -L "$CURRENT" ]] || fail current_created_after_bootstrap 75
[[ -d "$final_release" ]] || fail final_release_missing_after_install 75

rm -rf "$expected_remote_dir"
printf 'SRCM_INITIAL_BOOTSTRAP_RELEASE_SHA=%s\n' "$RELEASE_SHA"
printf 'SRCM_INITIAL_BOOTSTRAP_DATABASE_SHA256=%s\n' "$EXPECTED_DB_SHA256"
printf 'SRCM_INITIAL_BOOTSTRAP_MIGRATIONS=%s\n' "$EXPECTED_MIGRATIONS"
printf 'SRCM_INITIAL_BOOTSTRAP_CURRENT=ABSENT\n'
printf 'SRCM_INITIAL_BOOTSTRAP_SERVICES=INACTIVE\n'
printf 'SRCM_INITIAL_BOOTSTRAP_MIGRATE=NO\n'
printf 'SRCM_INITIAL_BOOTSTRAP_STATUS=GREEN_INACTIVE_RELEASE_INSTALLED\n'

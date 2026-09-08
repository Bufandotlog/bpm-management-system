#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

failures=0
check() {
    local description="$1"
    shift
    if "$@"; then
        printf 'PASS %s\n' "$description"
    else
        printf 'FAIL %s\n' "$description"
        failures=$((failures + 1))
    fi
}

check_php_syntax() {
    local file
    while IFS= read -r -d '' file; do
        php -l "$file" >/dev/null
    done < <(find admin api/hukum -type f -name '*.php' -print0)
    php -l hukum.php >/dev/null
    php -l hukum-detail.php >/dev/null
}

check_schema_contract() {
    local expected table
    for table in hukum_dokumen hukum_bab hukum_pasal hukum_workspace hukum_pasal_versi \
        hukum_staging hukum_staging_versi hukum_commit hukum_commit_approval \
        hukum_relasi_pasal hukum_notifikasi hukum_audit_log hukum_referensi_inline; do
        grep -q "CREATE TABLE.*${table}" databases/migrations/2026-09-08-hukum-schema.sql || return 1
        grep -q "CREATE TABLE.*${table}" databases/migrations/2026-09-08-hukum-schema.pgsql.sql || return 1
    done
}

check_public_visibility() {
    grep -Fq "status = \\'aktif\\'" hukum.php &&
        grep -Fq "status = \\'aktif\\'" hukum-detail.php
}

check_permission_contract() {
    grep -q "hukum_require_permission('hukum.view')" api/hukum/documents.php &&
        grep -q "hukum_require_permission('hukum.document.create')" api/hukum/documents.php &&
        grep -q "hukum_require_permission('hukum.staging.review')" api/hukum/review.php &&
        grep -q "hukum_require_permission('hukum.commit.create')" api/hukum/commit.php
}

check_migration_safety() {
    grep -q -- "--apply" databases/migrations/2026-09-08-hukum-data-migration.php &&
        grep -q -- "--retire" databases/migrations/2026-09-08-hukum-data-migration.php &&
        grep -q "CREATE TABLE" databases/migrations/2026-09-08-hukum-data-migration.php &&
        grep -q "_backup_" databases/migrations/2026-09-08-hukum-data-migration.php &&
        grep -q "beginTransaction" databases/migrations/2026-09-08-hukum-data-migration.php
}

check "PHP syntax" check_php_syntax
check "canonical schema tables" check_schema_contract
check "public active-only visibility" check_public_visibility
check "API permission contract" check_permission_contract
check "migration backup/transaction safety" check_migration_safety

if (( failures > 0 )); then
    printf '%d release check(s) failed.\n' "$failures" >&2
    exit 1
fi
printf 'All Hukum release checks passed.\n'

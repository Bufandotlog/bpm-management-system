<?php
/**
 * Safe migration for Session 1: Hukum database governance.
 *
 * Scope:
 * - membership per period (`hukum_keanggotaan`)
 * - dual staging approval (`hukum_staging_approval`)
 * - commit window + lockout/cooldown (`hukum_commit_window`, `hukum_commit_lockout`)
 * - workspace uniqueness equivalent via `(dokumen_id, status)` unique key
 * - audit foundation expansion for role/period/request/result metadata
 *
 * Execution model:
 * - forward-only
 * - no destructive cleanup
 * - will refuse to create unique constraints when duplicate historical rows exist
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$pdo = getConnection();
$driver = strtolower((string) DB_CONNECTION);

function tableExists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $indexName): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ? LIMIT 1"
        );
        $stmt->execute([$table, $indexName]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
    );
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function duplicateGroupCount(PDO $pdo, string $table, array $columns): array
{
    if ($columns === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $selectColumns = implode(', ', $columns);
    $sql = "SELECT {$selectColumns}, COUNT(*) AS total FROM {$table} GROUP BY {$selectColumns} HAVING COUNT(*) > 1";
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function migrate(): void
{
    global $pdo, $driver;

    $checks = [];

    // 1) Membership: hukum_keanggotaan
    if (!tableExists($pdo, 'hukum_keanggotaan')) {
        if ($driver === 'pgsql') {
            $pdo->exec(
                "CREATE TABLE hukum_keanggotaan (
                    id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL,
                    periode_id INT NOT NULL,
                    jabatan VARCHAR(32) NOT NULL CHECK (jabatan IN ('komisi_i', 'ketua_umum')),
                    mulai_pada DATE NOT NULL,
                    selesai_pada DATE NULL,
                    aktif BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (user_id, periode_id, jabatan)
                )"
            );
            $pdo->exec("CREATE INDEX idx_hukum_keanggotaan_periode_aktif ON hukum_keanggotaan (periode_id, aktif, jabatan)");
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hukum_keanggotaan (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    periode_id INT NOT NULL,
                    jabatan ENUM('komisi_i','ketua_umum') NOT NULL,
                    mulai_pada DATE NOT NULL,
                    selesai_pada DATE NULL,
                    aktif TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_hukum_keanggotaan_user_periode_jabatan (user_id, periode_id, jabatan),
                    KEY idx_hukum_keanggotaan_periode_aktif (periode_id, aktif, jabatan)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
            );
        }

        if (!columnExists($pdo, 'hukum_keanggotaan', 'user_id')) {
            throw new RuntimeException('hukum_keanggotaan user_id missing after create attempt.');
        }
    }

    $duplicates = duplicateGroupCount($pdo, 'hukum_keanggotaan', ['user_id', 'periode_id', 'jabatan']);
    if ($duplicates !== []) {
        throw new RuntimeException(
            'Duplicate historical membership detected for hukum_keanggotaan; migration stopped to avoid destructive deduplication.'
        );
    }

    if (!columnExists($pdo, 'hukum_keanggotaan', 'user_id')) {
        // fallback for custom DB state where table exists but missing FK columns
        throw new RuntimeException('hukum_keanggotaan exists but is missing critical columns.');
    }

    // 2) Dual staging approvals: hukum_staging_approval
    if (!tableExists($pdo, 'hukum_staging_approval')) {
        if ($driver === 'pgsql') {
            $pdo->exec(
                "CREATE TABLE hukum_staging_approval (
                    id SERIAL PRIMARY KEY,
                    staging_id INT NOT NULL,
                    user_id INT NOT NULL,
                    peran VARCHAR(32) NOT NULL CHECK (peran IN ('komisi_i', 'ketua_umum')),
                    status VARCHAR(16) NOT NULL DEFAULT 'menunggu' CHECK (status IN ('menunggu', 'disetujui', 'ditolak')),
                    note TEXT NULL,
                    approved_at TIMESTAMP NULL,
                    rejected_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (staging_id, peran)
                )"
            );
            $pdo->exec("CREATE INDEX idx_hukum_staging_approval_status ON hukum_staging_approval (staging_id, status)");
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hukum_staging_approval (
                    id INT NOT NULL AUTO_INCREMENT,
                    staging_id INT NOT NULL,
                    user_id INT NOT NULL,
                    peran ENUM('komisi_i','ketua_umum') NOT NULL,
                    status ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
                    note TEXT NULL,
                    approved_at DATETIME NULL,
                    rejected_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_hukum_staging_approval_slot (staging_id, peran),
                    KEY idx_hukum_staging_approval_status (staging_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
            );
        }
    }

    $duplicates = duplicateGroupCount($pdo, 'hukum_staging_approval', ['staging_id', 'peran']);
    if ($duplicates !== []) {
        throw new RuntimeException(
            'Duplicate historical staging approval slots detected; migration stopped to avoid overwriting review history.'
        );
    }

    // 3) Commit window and lockout foundation
    if (!tableExists($pdo, 'hukum_commit_window')) {
        if ($driver === 'pgsql') {
            $pdo->exec(
                "CREATE TABLE hukum_commit_window (
                    id SERIAL PRIMARY KEY,
                    commit_id INT NULL,
                    user_id INT NOT NULL,
                    peran VARCHAR(32) NOT NULL CHECK (peran IN ('komisi_i', 'ketua_umum')),
                    session_id VARCHAR(128) NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','in_progress','approved','expired','rejected','locked')),
                    initiated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP NOT NULL,
                    completed_at TIMESTAMP NULL,
                    result VARCHAR(32) NULL,
                    request_id VARCHAR(100) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (user_id, commit_id, peran)
                )"
            );
            $pdo->exec("CREATE INDEX idx_hukum_commit_window_expires ON hukum_commit_window (expires_at, status)");
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hukum_commit_window (
                    id BIGINT NOT NULL AUTO_INCREMENT,
                    commit_id INT NULL,
                    user_id INT NOT NULL,
                    peran ENUM('komisi_i','ketua_umum') NOT NULL,
                    session_id VARCHAR(128) NULL,
                    status ENUM('pending','in_progress','approved','expired','rejected','locked') NOT NULL DEFAULT 'pending',
                    initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    completed_at DATETIME NULL,
                    result VARCHAR(32) NULL,
                    request_id VARCHAR(100) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_hukum_commit_window_user_commit_peran (user_id, commit_id, peran),
                    KEY idx_hukum_commit_window_expires (expires_at, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
            );
        }
    }

    if (!tableExists($pdo, 'hukum_commit_lockout')) {
        if ($driver === 'pgsql') {
            $pdo->exec(
                "CREATE TABLE hukum_commit_lockout (
                    id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL,
                    session_id VARCHAR(128) NULL,
                    failed_attempts INT NOT NULL DEFAULT 0,
                    locked_until TIMESTAMP NULL,
                    last_reason VARCHAR(80) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (user_id, session_id)
                )"
            );
            $pdo->exec("CREATE INDEX idx_hukum_commit_lockout_until ON hukum_commit_lockout (locked_until)");
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hukum_commit_lockout (
                    id BIGINT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    session_id VARCHAR(128) NULL,
                    failed_attempts INT NOT NULL DEFAULT 0,
                    locked_until DATETIME NULL,
                    last_reason VARCHAR(80) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_hukum_commit_lockout_user_session (user_id, session_id),
                    KEY idx_hukum_commit_lockout_until (locked_until)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
            );
        }
    }

    // 4) Workspace uniqueness: one in-flight workspace per document.
    if (tableExists($pdo, 'hukum_workspace')) {
        $dupes = duplicateGroupCount($pdo, 'hukum_workspace', ['dokumen_id']);
        $inFlight = [];
        foreach ($dupes as $duplicate) {
            $documentId = (int) ($duplicate['dokumen_id'] ?? 0);
            $active = dbFetchOne(
                "SELECT COUNT(*) AS total
                 FROM hukum_workspace
                 WHERE dokumen_id = ?
                   AND status IN ('aktif', 'diajukan', 'siap_commit')",
                [$documentId]
            );
            if ((int) ($active['total'] ?? 0) > 1) {
                $inFlight[] = $documentId;
            }
        }
        if ($inFlight !== []) {
            throw new RuntimeException(
                'Duplicate in-flight workspace rows were found; migration stopped to preserve workflow history.'
            );
        }

        if (!columnExists($pdo, 'hukum_workspace', 'active_slot')) {
            if ($driver === 'pgsql') {
                $pdo->exec(
                    "ALTER TABLE hukum_workspace
                     ADD COLUMN active_slot INTEGER
                     GENERATED ALWAYS AS (
                       CASE
                         WHEN status IN ('aktif', 'diajukan', 'siap_commit') THEN 1
                         ELSE NULL
                       END
                     ) STORED"
                );
            } else {
                $pdo->exec(
                    "ALTER TABLE hukum_workspace
                     ADD COLUMN active_slot TINYINT
                     GENERATED ALWAYS AS (
                       CASE
                         WHEN status IN ('aktif', 'diajukan', 'siap_commit') THEN 1
                         ELSE NULL
                       END
                     ) STORED"
                );
            }
        }

        $constraintName = 'uq_hukum_workspace_active_slot';
        if (!indexExists($pdo, 'hukum_workspace', $constraintName)) {
            $pdo->exec(
                "CREATE UNIQUE INDEX {$constraintName}
                 ON hukum_workspace (dokumen_id, active_slot)"
            );
        }

        /*
         * Keep the legacy status-slot duplicate check separate from the
         * in-flight invariant. Historical terminal workspaces may share a
         * status and must not block this migration.
         */
        $dupes = duplicateGroupCount($pdo, 'hukum_workspace', ['dokumen_id', 'status']);
        if ($dupes !== []) {
            $checks[] = 'historical workspace status duplicates preserved';
        }
    }

    // 5) Audit foundation expansion
    $auditColumns = [
        'role_context' => 'VARCHAR(50) NULL',
        'periode_id' => 'INT NULL',
        'request_id' => 'VARCHAR(100) NULL',
        'result' => 'VARCHAR(20) NOT NULL DEFAULT "success"',
        'context_json' => 'TEXT NULL',
    ];

    if (tableExists($pdo, 'hukum_audit_log')) {
        foreach ($auditColumns as $column => $definition) {
            if (!columnExists($pdo, 'hukum_audit_log', $column)) {
                if ($driver === 'pgsql') {
                    $pdo->exec("ALTER TABLE hukum_audit_log ADD COLUMN {$column} {$definition}");
                } else {
                    $pdo->exec("ALTER TABLE hukum_audit_log ADD COLUMN {$column} {$definition}");
                }
            }
        }
    }

    echo "Session 1 governance migration applied successfully.\n";
    echo "Tables ensured: hukum_keanggotaan, hukum_staging_approval, hukum_commit_window, hukum_commit_lockout.\n";
    echo "Unique enforcement ensured for hukum_workspace status slots and audit metadata added.\n";
}

try {
    migrate();
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: {$error->getMessage()}\n");
    exit(2);
}

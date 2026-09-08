<?php
/**
 * Smoke test for Session 1 Hukum governance layer.
 *
 * This validates the same business rules the migration enforces using SQLite,
 * which is available in the local environment and provides deterministic checks
 * without requiring a live MySQL/PGSQL instance.
 */

$database = __DIR__ . '/tmp_hukum_session1.sqlite';
@unlink($database);

$pdo = new PDO('sqlite:' . $database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
$pdo->exec('CREATE TABLE periode_kepengurusan (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, periode_id INTEGER NOT NULL)');
$pdo->exec(
    'CREATE TABLE hukum_workspace (
        id INTEGER PRIMARY KEY,
        dokumen_id INTEGER NOT NULL,
        status TEXT NOT NULL CHECK(status IN ("aktif","diajukan","ditutup","dibatalkan")),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "menunggu_review")');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "aktif")');
$pdo->exec(
    'CREATE TABLE hukum_audit_log (
        id INTEGER PRIMARY KEY,
        entitas TEXT NOT NULL,
        entitas_id INTEGER NOT NULL,
        aksi TEXT NOT NULL,
        aktor_id INTEGER NOT NULL,
        role_context TEXT NULL,
        periode_id INTEGER NULL,
        request_id TEXT NULL,
        result TEXT NOT NULL DEFAULT "success",
        context_json TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);
$pdo->exec(
    'CREATE TABLE hukum_keanggotaan (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        periode_id INTEGER NOT NULL,
        jabatan TEXT NOT NULL CHECK(jabatan IN ("komisi_i","ketua_umum")),
        mulai_pada TEXT NOT NULL,
        selesai_pada TEXT NULL,
        aktif INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, periode_id, jabatan)
    )'
);
$pdo->exec(
    'CREATE TABLE hukum_staging_approval (
        id INTEGER PRIMARY KEY,
        staging_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        peran TEXT NOT NULL CHECK(peran IN ("komisi_i","ketua_umum")),
        status TEXT NOT NULL DEFAULT "menunggu" CHECK(status IN ("menunggu","disetujui","ditolak")),
        note TEXT NULL,
        approved_at TEXT NULL,
        rejected_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(staging_id, peran)
    )'
);
$pdo->exec(
    'CREATE TABLE hukum_commit_window (
        id INTEGER PRIMARY KEY,
        commit_id INTEGER NULL,
        user_id INTEGER NOT NULL,
        peran TEXT NOT NULL CHECK(peran IN ("komisi_i","ketua_umum")),
        session_id TEXT NULL,
        status TEXT NOT NULL DEFAULT "pending" CHECK(status IN ("pending","in_progress","approved","expired","rejected","locked")),
        initiated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TEXT NOT NULL,
        completed_at TEXT NULL,
        result TEXT NULL,
        request_id TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, commit_id, peran)
    )'
);
$pdo->exec(
    'CREATE TABLE hukum_commit_lockout (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        session_id TEXT NULL,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL,
        last_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, session_id)
    )'
);
$pdo->exec('CREATE UNIQUE INDEX uq_hukum_workspace_status ON hukum_workspace (dokumen_id, status)');

$pdo->exec("INSERT INTO users (id, username) VALUES (1, 'komisi1'), (2, 'ketua'), (3, 'admin')");
$pdo->exec("INSERT INTO periode_kepengurusan (id, nama) VALUES (1, '2026-2027')");
$pdo->exec("INSERT INTO hukum_dokumen (id, judul, periode_id) VALUES (1, 'AD 2026', 1), (2, 'ART 2026', 1)");

$pdo->exec("INSERT INTO hukum_keanggotaan (id, user_id, periode_id, jabatan, mulai_pada, selesai_pada, aktif) VALUES (1, 1, 1, 'komisi_i', '2026-01-01', NULL, 1)");
$pdo->exec("INSERT INTO hukum_keanggotaan (id, user_id, periode_id, jabatan, mulai_pada, selesai_pada, aktif) VALUES (2, 2, 1, 'ketua_umum', '2026-01-01', NULL, 1)");

$pdo->exec("INSERT INTO hukum_staging (id, workspace_id, status) VALUES (1, 10, 'menunggu_review')");
$pdo->exec("INSERT INTO hukum_staging_approval (id, staging_id, user_id, peran, status, note) VALUES (1, 1, 1, 'komisi_i', 'disetujui', 'ok')");
$pdo->exec("INSERT INTO hukum_staging_approval (id, staging_id, user_id, peran, status, note) VALUES (2, 1, 2, 'ketua_umum', 'disetujui', 'ok')");

$pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (1, 1, 'aktif')");
$pdo->exec("INSERT INTO hukum_commit_window (id, commit_id, user_id, peran, session_id, status, expires_at) VALUES (1, NULL, 1, 'komisi_i', 'sess-a', 'pending', '2026-09-08 15:25:00')");
$pdo->exec("INSERT INTO hukum_commit_lockout (id, user_id, session_id, failed_attempts, locked_until, last_reason) VALUES (1, 1, 'sess-a', 0, NULL, NULL)");
$pdo->exec("INSERT INTO hukum_audit_log (id, entitas, entitas_id, aksi, aktor_id, role_context, periode_id, request_id, result, context_json) VALUES (1, 'hukum_workspace', 1, 'create', 1, 'komisi_i', 1, 'req-1', 'success', '{\"source\":\"migration\"}')");

$duplicateMembership = false;
try {
    $pdo->exec("INSERT INTO hukum_keanggotaan (id, user_id, periode_id, jabatan, mulai_pada, selesai_pada, aktif) VALUES (3, 1, 1, 'komisi_i', '2026-02-01', NULL, 1)");
} catch (PDOException $e) {
    $duplicateMembership = true;
}
if (!$duplicateMembership) {
    fwrite(STDERR, "duplicate membership should fail\n");
    exit(1);
}

$duplicateApproval = false;
try {
    $pdo->exec("INSERT INTO hukum_staging_approval (id, staging_id, user_id, peran, status, note) VALUES (3, 1, 3, 'komisi_i', 'ditolak', 'dupe')");
} catch (PDOException $e) {
    $duplicateApproval = true;
}
if (!$duplicateApproval) {
    fwrite(STDERR, "duplicate staging approval should fail\n");
    exit(1);
}

$workspaceDup = false;
try {
    $pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (2, 1, 'aktif')");
} catch (PDOException $e) {
    $workspaceDup = true;
}
if (!$workspaceDup) {
    fwrite(STDERR, "duplicate active workspace should fail\n");
    exit(1);
}

$rows = $pdo->query("SELECT COUNT(*) AS total FROM hukum_keanggotaan")->fetchColumn();
if ((int) $rows !== 2) {
    fwrite(STDERR, "membership row count invalid\n");
    exit(1);
}

$rows = $pdo->query("SELECT COUNT(*) AS total FROM hukum_staging_approval")->fetchColumn();
if ((int) $rows !== 2) {
    fwrite(STDERR, "staging approval row count invalid\n");
    exit(1);
}

echo "Session 1 governance smoke tests passed.\n";
exit(0);

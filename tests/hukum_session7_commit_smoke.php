<?php

session_start();

if (!function_exists('dbFetchOne')) {
    function dbFetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('dbFetchAll')) {
    function dbFetchAll(string $sql, array $params = []): array
    {
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hukum_current_user_id')) {
    function hukum_current_user_id(): int { return (int) ($_SESSION['admin_id'] ?? 0); }
}
if (!function_exists('hukum_current_user_role')) {
    function hukum_current_user_role(): string { return strtolower(trim((string) ($_SESSION['admin_role'] ?? ''))); }
}
if (!function_exists('hukum_current_user_periode_id')) {
    function hukum_current_user_periode_id(): int { return (int) ($_SESSION['admin_periode_id'] ?? 0); }
}
if (!function_exists('hukum_period_for_document')) {
    function hukum_period_for_document(int $documentId): ?int {
        $row = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ? LIMIT 1', [$documentId]);
        return $row !== null && isset($row['periode_id']) ? (int) $row['periode_id'] : null;
    }
}
if (!function_exists('hukum_business_membership_for_user')) {
    function hukum_business_membership_for_user(int $userId, int $periodeId, string $jabatan): bool {
        $row = dbFetchOne(
            'SELECT id FROM hukum_keanggotaan WHERE user_id = ? AND periode_id = ? AND jabatan = ? AND aktif = 1 LIMIT 1',
            [$userId, $periodeId, $jabatan]
        );
        return $row !== null;
    }
}
if (!function_exists('hukum_is_komisi_i')) {
    function hukum_is_komisi_i(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool {
        if ($userId <= 0) { $userId = hukum_current_user_id(); }
        if ($documentId !== null && $documentId > 0) { $periodeId = hukum_period_for_document($documentId) ?? $periodeId; }
        if ($periodeId === null) { $periodeId = hukum_current_user_periode_id(); }
        if ($periodeId <= 0) { return false; }
        return hukum_business_membership_for_user($userId, (int) $periodeId, 'komisi_i');
    }
}
if (!function_exists('hukum_is_ketua_umum')) {
    function hukum_is_ketua_umum(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool {
        if ($userId <= 0) { $userId = hukum_current_user_id(); }
        if ($documentId !== null && $documentId > 0) { $periodeId = hukum_period_for_document($documentId) ?? $periodeId; }
        if ($periodeId === null) { $periodeId = hukum_current_user_periode_id(); }
        if ($periodeId <= 0) { return false; }
        return hukum_business_membership_for_user($userId, (int) $periodeId, 'ketua_umum');
    }
}

$_SESSION['admin_id'] = 2;
$_SESSION['admin_role'] = 'superadmin';
$_SESSION['admin_periode_id'] = 1;
$_SESSION['admin_can_access_all'] = 1;

$serviceSource = file_get_contents(__DIR__ . '/../api/hukum/commit_service.php');
$serviceSource = preg_replace('/^<\?php\s*/', '', $serviceSource, 1);
$serviceSource = preg_replace('/require_once __DIR__ \. \'\/\_bootstrap\.php\';\s*/', '', $serviceSource, 1);
eval($serviceSource);

$database = __DIR__ . '/tmp_hukum_session7.sqlite';
@unlink($database);
$GLOBALS['pdo'] = new PDO('sqlite:' . $database);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo = $GLOBALS['pdo'];

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password TEXT NOT NULL)');
$pdo->exec('CREATE TABLE periode_kepengurusan (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_keanggotaan (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, periode_id INTEGER NOT NULL, jabatan TEXT NOT NULL, mulai_pada TEXT NOT NULL DEFAULT CURRENT_DATE, selesai_pada TEXT NULL, aktif INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, periode_id INTEGER NOT NULL, judul TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft", updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "aktif", updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "menunggu_review", diajukan_oleh INTEGER NOT NULL, review_note TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_staging_approval (id INTEGER PRIMARY KEY, staging_id INTEGER NOT NULL, user_id INTEGER NOT NULL, peran TEXT NOT NULL, status TEXT NOT NULL DEFAULT "menunggu", note TEXT NULL, approved_at TEXT NULL, rejected_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(staging_id, peran))');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT NOT NULL, judul_pasal TEXT NULL, urutan INTEGER NOT NULL, UNIQUE(dokumen_id, nomor_label), UNIQUE(dokumen_id, urutan))');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, workspace_id INTEGER NOT NULL, isi TEXT NOT NULL, hash_konten TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft")');
$pdo->exec('CREATE TABLE hukum_staging_versi (staging_id INTEGER NOT NULL, pasal_versi_id INTEGER NOT NULL, PRIMARY KEY (staging_id, pasal_versi_id))');
$pdo->exec('CREATE TABLE hukum_relasi_pasal (id INTEGER PRIMARY KEY, pasal_anak_id INTEGER NOT NULL, pasal_induk_id INTEGER NOT NULL, source_version_id INTEGER NULL, target_version_id INTEGER NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, dibuat_oleh TEXT NOT NULL DEFAULT "auto")');
$pdo->exec('CREATE TABLE hukum_graph_snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT, commit_id INTEGER NOT NULL, dokumen_id INTEGER NOT NULL, pasal_id INTEGER NOT NULL, pasal_version_id INTEGER NULL, nomor_label TEXT NULL, payload_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INTEGER NOT NULL, source_pasal_id INTEGER NOT NULL, target_pasal_id INTEGER NOT NULL, source_version_id INTEGER NULL, target_version_id INTEGER NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu", metadata_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, staging_id INTEGER NOT NULL, parent_commit_id INTEGER NULL, hash_commit TEXT NOT NULL, snapshot_tree TEXT NOT NULL, forum_tipe TEXT NOT NULL, tanggal_forum TEXT NOT NULL, status TEXT NOT NULL DEFAULT "aktif", dibuat_oleh INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, replaced_at TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_commit_window (id INTEGER PRIMARY KEY, commit_id INTEGER NULL, user_id INTEGER NOT NULL, peran TEXT NOT NULL, session_id TEXT NULL, status TEXT NOT NULL DEFAULT "pending", initiated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL, completed_at TEXT NULL, result TEXT NULL, request_id TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_commit_lockout (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, session_id TEXT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT NULL, last_reason TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_audit_log (id INTEGER PRIMARY KEY, entitas TEXT NOT NULL, entitas_id INTEGER NOT NULL, aksi TEXT NOT NULL, aktor_id INTEGER NOT NULL, role_context TEXT NULL, periode_id INTEGER NULL, request_id TEXT NULL, result TEXT NOT NULL DEFAULT "success", context_json TEXT NULL, sebelum_json TEXT NULL, sesudah_json TEXT NULL, ip_address TEXT NULL)');

$pdo->exec("INSERT INTO users (id, username, password) VALUES (1, 'pengaju', 'hash'), (2, 'komisi', '" . password_hash('secret', PASSWORD_DEFAULT) . "'), (3, 'ketua', '" . password_hash('secret', PASSWORD_DEFAULT) . "')");
$pdo->exec("INSERT INTO periode_kepengurusan (id, nama) VALUES (1, '2026-2027')");
$pdo->exec("INSERT INTO hukum_keanggotaan (user_id, periode_id, jabatan, mulai_pada, aktif) VALUES (2, 1, 'komisi_i', '2026-01-01', 1)");
$pdo->exec("INSERT INTO hukum_keanggotaan (user_id, periode_id, jabatan, mulai_pada, aktif) VALUES (3, 1, 'ketua_umum', '2026-01-01', 1)");
$pdo->exec("INSERT INTO hukum_dokumen (id, periode_id, judul, status) VALUES (1, 1, 'Dokumen Uji', 'draft')");
$pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (1, 1, 'diajukan')");
$pdo->exec("INSERT INTO hukum_staging (id, workspace_id, status, diajukan_oleh) VALUES (1, 1, 'menunggu_review', 1)");
$pdo->exec("INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status) VALUES (1, 2, 'komisi_i', 'disetujui')");
$pdo->exec("INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status) VALUES (1, 3, 'ketua_umum', 'disetujui')");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, isi, hash_konten, status) VALUES (10, 1, 1, '{\"isi\":\"teks\"}', 'hash-10', 'staged')");
$pdo->exec("INSERT INTO hukum_staging_versi (staging_id, pasal_versi_id) VALUES (1, 10)");

$_SESSION['admin_id'] = 2;
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_periode_id'] = 1;

$windowKomisi = hukum_commit_create_window($pdo, 2, 'komisi_i', 'secret', 'sess-k1');
if (($windowKomisi['status'] ?? '') !== 'approved') { fwrite(STDERR, "Komisi I window should be approved\n"); exit(1); }

$windowKetua = hukum_commit_create_window($pdo, 3, 'ketua_umum', 'secret', 'sess-k2');
if (($windowKetua['status'] ?? '') !== 'approved') { fwrite(STDERR, "Ketua Umum window should be approved\n"); exit(1); }

$result = hukum_commit_finalize($pdo, 1, 2, 'secret', 'req-1', 'sess-k1');
if (($result['success'] ?? false) !== true) { fwrite(STDERR, "Two-party commit should succeed\n"); exit(1); }
if (!hukum_commit_verify_snapshot($pdo, (int) $result['id'])) { fwrite(STDERR, "Stored commit hash should verify\n"); exit(1); }

$_SESSION['admin_id'] = 2;
try {
    hukum_commit_finalize($pdo, 1, 2, 'wrong', 'req-2', 'sess-k1');
    fwrite(STDERR, "Wrong password commit should fail\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

try {
    hukum_commit_finalize($pdo, 1, 2, 'secret', 'req-3', 'sess-k1');
    fwrite(STDERR, "Duplicate finalize should fail\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

$pdo->exec("INSERT INTO hukum_commit_window (user_id, peran, session_id, status, initiated_at, expires_at, created_at, updated_at) VALUES (2, 'komisi_i', 'sess-exp', 'approved', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00')");
if (hukum_commit_is_window_approved($pdo, 2, 'komisi_i')) {
    fwrite(STDERR, "Expired window should be rejected\n");
    exit(1);
}

$failed = dbFetchOne('SELECT failed_attempts FROM hukum_commit_lockout WHERE user_id = ? AND session_id = ? LIMIT 1', [2, 'sess-k1']);
if (($failed['failed_attempts'] ?? 0) < 1) { fwrite(STDERR, "Failed password attempts should be recorded\n"); exit(1); }

echo "Session 7 commit smoke tests passed.\n";
exit(0);

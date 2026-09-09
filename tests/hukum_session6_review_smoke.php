<?php

session_start();

if (!function_exists('dbFetchOne')) {
    function dbFetchOne(string $sql, array $params = []): ?array
    {
        $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql);
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('dbFetchAll')) {
    function dbFetchAll(string $sql, array $params = []): array
    {
        $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql);
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function hukum_current_user_id(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function hukum_current_user_role(): string
{
    return strtolower(trim((string) ($_SESSION['admin_role'] ?? '')));
}

function hukum_current_user_periode_id(): int
{
    return (int) ($_SESSION['admin_periode_id'] ?? 0);
}

function hukum_period_for_document(int $documentId): ?int
{
    $row = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ? LIMIT 1', [$documentId]);
    return $row !== null ? (int) $row['periode_id'] : null;
}

function hukum_business_membership_for_user(int $userId, int $periodeId, string $jabatan): bool
{
    $row = dbFetchOne(
        'SELECT id FROM hukum_keanggotaan WHERE user_id = ? AND periode_id = ? AND jabatan = ? AND aktif = 1 LIMIT 1',
        [$userId, $periodeId, $jabatan]
    );
    return $row !== null;
}

function hukum_is_komisi_i(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool
{
    if ($userId <= 0) {
        $userId = hukum_current_user_id();
    }
    if ($documentId !== null && $documentId > 0) {
        $periodeId = hukum_period_for_document($documentId) ?? $periodeId;
    }
    if ($periodeId === null) {
        $periodeId = hukum_current_user_periode_id();
    }
    if ($periodeId <= 0) {
        return false;
    }
    return hukum_business_membership_for_user($userId, (int) $periodeId, 'komisi_i');
}

function hukum_is_ketua_umum(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool
{
    if ($userId <= 0) {
        $userId = hukum_current_user_id();
    }
    if ($documentId !== null && $documentId > 0) {
        $periodeId = hukum_period_for_document($documentId) ?? $periodeId;
    }
    if ($periodeId === null) {
        $periodeId = hukum_current_user_periode_id();
    }
    if ($periodeId <= 0) {
        return false;
    }
    return hukum_business_membership_for_user($userId, (int) $periodeId, 'ketua_umum');
}

require_once __DIR__ . '/../api/hukum/review_service.php';

$database = __DIR__ . '/tmp_hukum_session6.sqlite';
@unlink($database);
$GLOBALS['pdo'] = new PDO('sqlite:' . $database);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo = $GLOBALS['pdo'];

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
$pdo->exec('CREATE TABLE periode_kepengurusan (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_keanggotaan (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, periode_id INTEGER NOT NULL, jabatan TEXT NOT NULL, mulai_pada TEXT NOT NULL DEFAULT CURRENT_DATE, selesai_pada TEXT NULL, aktif INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, periode_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "draft")');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "aktif", updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "menunggu_review", diajukan_oleh INTEGER NOT NULL, diajukan_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, direview_oleh INTEGER NULL, direview_at TEXT NULL, review_note TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_staging_approval (id INTEGER PRIMARY KEY, staging_id INTEGER NOT NULL, user_id INTEGER NOT NULL, peran TEXT NOT NULL, status TEXT NOT NULL DEFAULT "menunggu", note TEXT NULL, approved_at TEXT NULL, rejected_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(staging_id, peran))');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, workspace_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "draft", rejected_at TEXT NULL, rejected_by INTEGER NULL, rejection_reason TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_staging_versi (staging_id INTEGER NOT NULL, pasal_versi_id INTEGER NOT NULL, PRIMARY KEY (staging_id, pasal_versi_id))');

$pdo->exec("INSERT INTO users (id, username) VALUES (1, 'pengaju'), (2, 'komisi1'), (3, 'ketum')");
$pdo->exec("INSERT INTO periode_kepengurusan (id, nama) VALUES (1, '2026-2027')");
$pdo->exec("INSERT INTO hukum_keanggotaan (user_id, periode_id, jabatan, mulai_pada, aktif) VALUES (2, 1, 'komisi_i', '2026-01-01', 1)");
$pdo->exec("INSERT INTO hukum_keanggotaan (user_id, periode_id, jabatan, mulai_pada, aktif) VALUES (3, 1, 'ketua_umum', '2026-01-01', 1)");
$pdo->exec("INSERT INTO hukum_dokumen (id, judul, periode_id, status) VALUES (1, 'Dokumen Uji', 1, 'draft')");
$pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (1, 1, 'diajukan')");
$pdo->exec("INSERT INTO hukum_staging (id, workspace_id, status, diajukan_oleh) VALUES (1, 1, 'menunggu_review', 1)");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, status) VALUES (10, 1, 1, 'staged')");
$pdo->exec("INSERT INTO hukum_staging_versi (staging_id, pasal_versi_id) VALUES (1, 10)");

$_SESSION['admin_id'] = 2;
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_periode_id'] = 1;
$_SESSION['admin_can_access_all'] = 0;

$result = hukum_review_apply_decision($pdo, 1, 'approve', null, 2);
if ($result['status'] !== 'menunggu_review') {
    fwrite(STDERR, "one approval should not finalize staging\n");
    exit(1);
}

$_SESSION['admin_id'] = 3;
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_periode_id'] = 1;
$result = hukum_review_apply_decision($pdo, 1, 'approve', null, 3);
if ($result['status'] !== 'disetujui') {
    fwrite(STDERR, "two approvals should finalize staging\n");
    exit(1);
}

$pdo->exec("INSERT INTO hukum_staging (id, workspace_id, status, diajukan_oleh) VALUES (2, 1, 'menunggu_review', 1)");
$pdo->exec("INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status, note) VALUES (2, 2, 'komisi_i', 'menunggu', NULL)");
$pdo->exec("INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status, note) VALUES (2, 3, 'ketua_umum', 'menunggu', NULL)");

$_SESSION['admin_id'] = 2;
$_SESSION['admin_role'] = 'admin';
try {
    hukum_review_apply_decision($pdo, 2, 'reject', '', 2);
    fwrite(STDERR, "reject without note should fail\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

$_SESSION['admin_id'] = 2;
$result = hukum_review_apply_decision($pdo, 2, 'reject', 'invalid content', 2);
if ($result['status'] !== 'ditolak') {
    fwrite(STDERR, "reject should mark staging rejected\n");
    exit(1);
}

$slot = dbFetchOne('SELECT status FROM hukum_staging_approval WHERE staging_id = 2 AND peran = ?', ['komisi_i']);
if (($slot['status'] ?? '') !== 'ditolak') {
    fwrite(STDERR, "reject should update all approval slots\n");
    exit(1);
}

$_SESSION['admin_id'] = 99;
$_SESSION['admin_role'] = 'sekretaris';
try {
    hukum_review_apply_decision($pdo, 1, 'approve', null, 99);
    fwrite(STDERR, "non-member should not approve\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

echo "Session 6 review smoke tests passed.\n";
exit(0);

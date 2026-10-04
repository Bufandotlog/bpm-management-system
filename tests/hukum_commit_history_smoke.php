<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../api/hukum/commit_service.php');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = preg_replace('/require_once __DIR__ \. \'\/_bootstrap\.php\';\s*/', '', $source, 1);
$source = preg_replace('/require_once __DIR__ \. \'\/\.\.\/\.\.\/admin\/core\/hukum-clock\.php\';\s*/', '', $source, 1);
eval($source);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, periode_id INTEGER, judul TEXT, jenis TEXT, slug TEXT)');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER)');
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER, status TEXT, diajukan_at TEXT, direview_at TEXT, review_note TEXT, direview_oleh INTEGER)');
$pdo->exec('CREATE TABLE hukum_staging_approval (id INTEGER PRIMARY KEY, staging_id INTEGER, user_id INTEGER, peran TEXT, status TEXT, note TEXT, approved_at TEXT)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER, staging_id INTEGER, parent_commit_id INTEGER, hash_commit TEXT, forum_tipe TEXT, tanggal_forum TEXT, status TEXT, dibuat_oleh INTEGER, created_at TEXT, replaced_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'Komisi I', 'komisi'), (2, 'Ketua Umum', 'ketua')");
$pdo->exec("INSERT INTO hukum_dokumen VALUES (10, 100, 'Dokumen Periode 100', 'PERATURAN', 'dokumen-100'), (20, 200, 'Dokumen Periode 200', 'PERATURAN', 'dokumen-200')");
$pdo->exec("INSERT INTO hukum_workspace VALUES (30, 10), (31, 10), (40, 20)");
$pdo->exec("INSERT INTO hukum_staging VALUES (50, 30, 'disetujui', '2026-10-01 10:00:00', '2026-10-01 11:00:00', NULL, 2), (51, 31, 'disetujui', '2026-10-02 10:00:00', '2026-10-02 11:00:00', NULL, 2), (60, 40, 'disetujui', '2026-10-02 10:00:00', '2026-10-02 11:00:00', NULL, 2)");
$pdo->exec("INSERT INTO hukum_staging_approval VALUES (70, 50, 1, 'komisi_i', 'disetujui', NULL, '2026-10-01 10:30:00'), (80, 50, 2, 'admin', 'disetujui', NULL, '2026-10-01 11:00:00'), (90, 51, 1, 'komisi_i', 'disetujui', NULL, '2026-10-02 10:30:00'), (91, 51, 2, 'admin', 'disetujui', NULL, '2026-10-02 11:00:00'), (100, 60, 1, 'komisi_i', 'disetujui', NULL, '2026-10-02 10:30:00'), (101, 60, 2, 'admin', 'disetujui', NULL, '2026-10-02 11:00:00')");
$pdo->exec("INSERT INTO hukum_commit VALUES (110, 10, 50, NULL, 'hash-110', 'finalisasi', '2026-10-01', 'digantikan', 2, '2026-10-01 11:30:00', '2026-10-02 11:30:00'), (120, 10, 51, 110, 'hash-120', 'finalisasi', '2026-10-02', 'aktif', 2, '2026-10-02 11:30:00', NULL), (130, 20, 60, NULL, 'hash-130', 'finalisasi', '2026-10-02', 'aktif', 2, '2026-10-02 12:30:00', NULL)");

$all = hukum_commit_history_rows($pdo);
if (count($all) !== 3 || ($all[0]['status'] ?? '') !== 'aktif') {
    throw new RuntimeException('Commit history should return all finalized commits newest first.');
}
$period = hukum_commit_history_rows($pdo, null, 100);
if (count($period) !== 2 || (int) $period[0]['periode_id'] !== 100) {
    throw new RuntimeException('Commit history must filter to the requested period.');
}
$document = hukum_commit_history_rows($pdo, 10);
if (count($document) !== 2 || (int) $document[0]['dokumen_id'] !== 10) {
    throw new RuntimeException('Commit history must filter to the requested document.');
}
$latest = $document[0];
if (($latest['commit_status'] ?? '') !== 'aktif'
    || ($latest['staging_status'] ?? '') !== 'disetujui'
    || ($latest['komisi_i_name'] ?? '') !== 'Komisi I'
    || ($latest['admin_name'] ?? '') !== 'Ketua Umum'
    || ($latest['finalizer_name'] ?? '') !== 'Ketua Umum') {
    throw new RuntimeException('Commit and staging statuses or actor attribution were not returned independently.');
}

echo "Hukum commit history smoke test passed.\n";

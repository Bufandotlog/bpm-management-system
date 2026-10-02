<?php

require_once __DIR__ . '/../api/hukum/review_preview_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, format_mukadimah TEXT, mukadimah_json TEXT, mukadimah_legacy TEXT)');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_bab (id INTEGER PRIMARY KEY, nomor_label TEXT, judul_bab TEXT, urutan INTEGER)');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, bab_id INTEGER, nomor_label TEXT NOT NULL, judul_pasal TEXT, urutan INTEGER)');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, status TEXT NOT NULL, isi TEXT NOT NULL, created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_staging_versi (staging_id INTEGER NOT NULL, pasal_versi_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot (commit_id INTEGER NOT NULL, dokumen_id INTEGER NOT NULL, pasal_id INTEGER NOT NULL, pasal_version_id INTEGER, nomor_label TEXT, is_active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE hukum_staging_deletion (id INTEGER PRIMARY KEY, staging_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, reason TEXT NOT NULL, snapshot_json TEXT NOT NULL)');

$pdo->exec("INSERT INTO hukum_dokumen VALUES (1, 'Anggaran Dasar', 'json', '{\"teks\":\"Pembukaan dokumen\"}', NULL)");
$pdo->exec('INSERT INTO hukum_workspace VALUES (10, 1)');
$pdo->exec("INSERT INTO hukum_staging VALUES (20, 10, 'menunggu_review')");
$pdo->exec("INSERT INTO hukum_bab VALUES (1, 'I', 'Ketentuan Umum', 1)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (101, 1, 1, '1', 'Ruang Lingkup', 1)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (102, 1, 1, '2', 'Asas', 2)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (103, 1, 1, '3', 'Tujuan', 3)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (104, 1, 1, '4', 'Keanggotaan', 4)");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1001, 101, 'committed', '{\"teks_utama\":\"Lama\"}', '2026-01-01')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1002, 101, 'staged', '{\"teks_utama\":\"Baru\"}', '2026-02-01')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1003, 102, 'committed', '{\"teks_utama\":\"Tetap\"}', '2026-01-01')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1004, 103, 'staged', '{\"teks_utama\":\"Tambahan\"}', '2026-02-01')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1005, 104, 'committed', '{\"teks_utama\":\"Dihapus\"}', '2026-01-01')");
$pdo->exec("INSERT INTO hukum_staging_versi VALUES (20, 1002)");
$pdo->exec("INSERT INTO hukum_staging_versi VALUES (20, 1004)");
$pdo->exec("INSERT INTO hukum_commit VALUES (5, 1, 'aktif')");
$pdo->exec('INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label) VALUES (5, 1, 101, 1001, "1")');
$pdo->exec('INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label) VALUES (5, 1, 102, 1003, "2")');
$pdo->exec('INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label) VALUES (5, 1, 104, 1005, "4")');
$pdo->exec("INSERT INTO hukum_staging_deletion VALUES (1, 20, 'pasal', 104, 'Tidak lagi relevan', '{\"entity_type\":\"pasal\",\"entity_id\":104,\"pasal\":{\"pasal_id\":104},\"pasals\":[{\"pasal_id\":104,\"pasal_versi_id\":1005,\"nomor_label\":\"4\",\"judul_pasal\":\"Keanggotaan\",\"urutan\":4,\"bab_id\":1,\"bab_nomor_label\":\"I\",\"bab_judul\":\"Ketentuan Umum\",\"bab_urutan\":1,\"isi\":{\"teks_utama\":\"Dihapus\"}}]}')");

$preview = hukum_review_preview_pasals($pdo, 20);
$pasals = $preview['pasals'];
if ($preview['document']['judul'] !== 'Anggaran Dasar'
    || $preview['document']['pembukaan'] !== 'Pembukaan dokumen'
    || $preview['base_commit_id'] !== 5) {
    throw new RuntimeException('Review preview should include document metadata and active commit.');
}
if (count($pasals) !== 4
    || $pasals[0]['pasal_id'] !== 101
    || $pasals[0]['change'] !== 'modified'
    || $pasals[0]['before']['isi']['teks_utama'] !== 'Lama'
    || $pasals[0]['after']['isi']['teks_utama'] !== 'Baru') {
    throw new RuntimeException('Review preview should compare changed content against the active snapshot.');
}
if ($pasals[1]['pasal_id'] !== 102 || $pasals[1]['change'] !== 'unchanged') {
    throw new RuntimeException('Review preview should retain unchanged document content.');
}
if ($pasals[2]['pasal_id'] !== 103 || $pasals[2]['change'] !== 'added' || $pasals[2]['before'] !== null) {
    throw new RuntimeException('Review preview should include staged Pasal additions.');
}
if ($pasals[3]['pasal_id'] !== 104 || $pasals[3]['change'] !== 'removed'
    || $pasals[3]['deletion_reason'] !== 'Tidak lagi relevan'
    || $pasals[3]['after'] !== null) {
    throw new RuntimeException('Review preview should show removed Pasal with its reason.');
}

echo "Hukum review preview smoke tests passed.\n";

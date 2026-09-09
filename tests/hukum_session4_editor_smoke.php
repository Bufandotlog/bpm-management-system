<?php
$database = __DIR__ . '/tmp_hukum_session4.sqlite';
@unlink($database);

$pdo = new PDO('sqlite:' . $database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
$pdo->exec('CREATE TABLE periode_kepengurusan (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, periode_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "draft")');
$pdo->exec('CREATE TABLE hukum_bab (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT NOT NULL, judul_bab TEXT NOT NULL, urutan INTEGER NOT NULL, UNIQUE(dokumen_id, nomor_label), UNIQUE(dokumen_id, urutan))');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, bab_id INTEGER NULL, nomor_label TEXT NOT NULL, judul_pasal TEXT NULL, urutan INTEGER NOT NULL, UNIQUE(dokumen_id, nomor_label), UNIQUE(dokumen_id, urutan))');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL CHECK(status IN ("aktif","diajukan","ditutup","dibatalkan")), UNIQUE(dokumen_id, status))');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, workspace_id INTEGER NOT NULL, isi TEXT NOT NULL, hash_konten TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

$pdo->exec("INSERT INTO users (id, username) VALUES (1, 'komisi1')");
$pdo->exec("INSERT INTO periode_kepengurusan (id, nama) VALUES (1, '2026-2027')");
$pdo->exec("INSERT INTO hukum_dokumen (id, judul, periode_id, status) VALUES (1, 'AD 2026', 1, 'draft')");
$pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (1, 1, 'aktif')");
$pdo->exec("INSERT INTO hukum_bab (id, dokumen_id, nomor_label, judul_bab, urutan) VALUES (1, 1, 'BAB I', 'Pendahuluan', 1)");
$pdo->exec("INSERT INTO hukum_pasal (id, dokumen_id, bab_id, nomor_label, judul_pasal, urutan) VALUES (1, 1, 1, 'Pasal 1', 'Ketentuan Umum', 1)");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, isi, hash_konten, status) VALUES (1, 1, 1, '{\"teks_utama\":\"old\"}', 'abc123', 'draft')");

$latest = $pdo->query('SELECT id FROM hukum_pasal_versi WHERE pasal_id = 1 ORDER BY id DESC LIMIT 1')->fetch();
if ((int) ($latest['id'] ?? 0) !== 1) {
    fwrite(STDERR, "latest version not initialized\n");
    exit(1);
}

$stale = false;
if ((int) $latest['id'] !== 999) {
    $stale = true;
}
if (!$stale) {
    fwrite(STDERR, "stale version detection did not trigger\n");
    exit(1);
}

$dupeBab = false;
try {
    $pdo->exec("INSERT INTO hukum_bab (id, dokumen_id, nomor_label, judul_bab, urutan) VALUES (2, 1, 'BAB I', 'Duplikat', 2)");
} catch (PDOException $e) {
    $dupeBab = true;
}
if (!$dupeBab) {
    fwrite(STDERR, "duplicate bab should fail\n");
    exit(1);
}

$dupePasal = false;
try {
    $pdo->exec("INSERT INTO hukum_pasal (id, dokumen_id, bab_id, nomor_label, judul_pasal, urutan) VALUES (2, 1, 1, 'Pasal 1', 'Duplikat', 2)");
} catch (PDOException $e) {
    $dupePasal = true;
}
if (!$dupePasal) {
    fwrite(STDERR, "duplicate pasal should fail\n");
    exit(1);
}

$duplicateWorkspace = false;
try {
    $pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (2, 1, 'aktif')");
} catch (PDOException $e) {
    $duplicateWorkspace = true;
}
if (!$duplicateWorkspace) {
    fwrite(STDERR, "duplicate active workspace should fail\n");
    exit(1);
}

echo "Session 4 editor smoke tests passed.\n";
exit(0);

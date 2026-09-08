<?php

function dbFetchOne(string $sql, array $params = []): ?array
{
    $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql);
    $stmt = $GLOBALS['pdo']->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function dbFetchAll(string $sql, array $params = []): array
{
    $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql);
    $stmt = $GLOBALS['pdo']->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hukum_extract_inline_references(array $value): array
{
    $references = [];
    $walk = static function (mixed $item, string $field) use (&$walk, &$references): void {
        if (is_array($item)) {
            foreach ($item as $child) {
                $walk($child, $field);
            }
            return;
        }
        if (!is_string($item)) {
            return;
        }
        if (preg_match_all('/\[\[PASAL:([0-9]+)(?:\/AYAT:([0-9]+))?\]\]/i', $item, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $references[] = [
                    'pasal_tujuan_nomor' => (string) (int) $match[1],
                    'ayat_tujuan_nomor' => isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null,
                    'konteks_field' => $field,
                ];
            }
        }
    };
    $walk($value, 'teks_utama');
    return $references;
}

session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_periode_id'] = 1;
$_SESSION['admin_can_access_all'] = 1;
$_SESSION['_last_activity'] = time();
$_SESSION['_auth_last_check'] = time();
$_SESSION['session_token'] = '';

$database = __DIR__ . '/tmp_hukum_session5.sqlite';
@unlink($database);
$GLOBALS['pdo'] = new PDO('sqlite:' . $database);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

require_once __DIR__ . '/../api/hukum/staging_service.php';

$pdo = $GLOBALS['pdo'];
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
$pdo->exec('CREATE TABLE periode_kepengurusan (id INTEGER PRIMARY KEY, nama TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, periode_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "draft")');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT NOT NULL, judul_pasal TEXT NULL, urutan INTEGER NOT NULL, UNIQUE(dokumen_id, nomor_label), UNIQUE(dokumen_id, urutan))');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL CHECK(status IN ("aktif","diajukan","ditutup","dibatalkan")), UNIQUE(dokumen_id, status))');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, workspace_id INTEGER NOT NULL, isi TEXT NOT NULL, hash_konten TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_relasi_pasal (id INTEGER PRIMARY KEY, pasal_anak_id INTEGER NOT NULL, pasal_induk_id INTEGER NOT NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu")');
$pdo->exec('CREATE TABLE hukum_staging (id INTEGER PRIMARY KEY, workspace_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "menunggu_review", diajukan_oleh INTEGER NOT NULL, diajukan_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_notifikasi (id INTEGER PRIMARY KEY, relasi_id INTEGER NOT NULL, pasal_anak_id INTEGER NOT NULL, pasal_induk_id INTEGER NOT NULL, dipicu_oleh_versi_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "perlu_ditinjau")');
$pdo->exec('CREATE TABLE hukum_staging_versi (staging_id INTEGER NOT NULL, pasal_versi_id INTEGER NOT NULL, PRIMARY KEY (staging_id, pasal_versi_id))');

$pdo->exec("INSERT INTO users (id, username) VALUES (1, 'komisi1')");
$pdo->exec("INSERT INTO periode_kepengurusan (id, nama) VALUES (1, '2026-2027')");
$pdo->exec("INSERT INTO hukum_dokumen (id, judul, periode_id, status) VALUES (1, 'Dokumen Uji', 1, 'draft')");
$pdo->exec("INSERT INTO hukum_workspace (id, dokumen_id, status) VALUES (1, 1, 'aktif')");
$pdo->exec("INSERT INTO hukum_pasal (id, dokumen_id, nomor_label, judul_pasal, urutan) VALUES (1, 1, 'Pasal 1', 'Umum', 1)");
$pdo->exec("INSERT INTO hukum_pasal (id, dokumen_id, nomor_label, judul_pasal, urutan) VALUES (2, 1, 'Pasal 2', 'Ketentuan', 2)");
$pdo->exec("INSERT INTO hukum_relasi_pasal (id, pasal_anak_id, pasal_induk_id, jenis_relasi) VALUES (1, 1, 2, 'mengacu')");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, isi, hash_konten, status) VALUES (1, 1, 1, '{\"teks_utama\":\"[[PASAL:2]]\"}', 'hash1', 'draft')");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, isi, hash_konten, status) VALUES (2, 2, 1, '{\"teks_utama\":\"teks\"}', 'hash2', 'draft')");

$validPlan = hukum_staging_validate_submission($pdo, 1, [1, 2]);
if ((int) $validPlan['workspace_id'] !== 1) {
    fwrite(STDERR, "staging validation failed on a valid workspace\n");
    exit(1);
}
if (count($validPlan['impact']['relations']) < 1) {
    fwrite(STDERR, "impact analysis did not detect the related pasal\n");
    exit(1);
}
if ($validPlan['self_commit_valid'] !== true) {
    fwrite(STDERR, "self-commit validation unexpectedly rejected a valid draft set\n");
    exit(1);
}

$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, workspace_id, isi, hash_konten, status) VALUES (3, 1, 1, '{\"teks_utama\":\"[[PASAL:999]]\"}', 'hash3', 'draft')");
try {
    hukum_staging_validate_submission($pdo, 1, [3]);
    fwrite(STDERR, "broken inline reference should have failed\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

$pdo->exec("INSERT INTO hukum_staging (id, workspace_id, status, diajukan_oleh) VALUES (1, 1, 'menunggu_review', 1)");
try {
    hukum_staging_validate_submission($pdo, 1, [1, 2]);
    fwrite(STDERR, "duplicate staging should have been rejected\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

echo "Session 5 staging smoke tests passed.\n";
exit(0);

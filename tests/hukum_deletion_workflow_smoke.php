<?php
declare(strict_types=1);

function hukum_require_permission(string $permission): void {}
function hukum_require_document_period(int $periodeId): void {}
function hukum_current_user_id(): int { return 7; }
function hukum_audit(PDO $pdo, string $entity, int $entityId, string $action, ?array $before = null, ?array $after = null, ?array $extra = null): void {}
function hukum_extract_inline_references(array $value): array
{
    $references = [];
    $walk = static function (mixed $item) use (&$walk, &$references): void {
        if (is_array($item)) {
            foreach ($item as $child) $walk($child);
        } elseif (is_string($item) && preg_match_all('/\[\[PASAL:([0-9]+)(?:\/AYAT:([0-9]+))?\]\]/i', $item, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $references[] = ['pasal_tujuan_nomor' => (string) (int) $match[1]];
        }
    };
    $walk($value);
    return $references;
}

$serviceSource = file_get_contents(__DIR__ . '/../api/hukum/deletions_service.php');
$serviceSource = preg_replace('/^<\?php\s*/', '', $serviceSource, 1);
$serviceSource = preg_replace('/require_once __DIR__ \. \'\/\_bootstrap\.php\';\s*/', '', $serviceSource, 1);
eval($serviceSource);
require_once __DIR__ . '/../admin/core/hukum-clock.php';
$commitSource = file_get_contents(__DIR__ . '/../api/hukum/commit_service.php');
$commitSource = preg_replace('/^<\?php\s*/', '', $commitSource, 1);
$commitSource = preg_replace('/require_once __DIR__ \. \'\/\_bootstrap\.php\';\s*/', '', $commitSource, 1);
$commitSource = preg_replace('/require_once __DIR__ \. \'\/\.\.\/\.\.\/admin\/core\/hukum-clock\.php\';\s*/', '', $commitSource, 1);
eval($commitSource);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, periode_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_workspace (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, staging_id INTEGER NOT NULL, parent_commit_id INTEGER, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_bab (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT, judul_bab TEXT, urutan INTEGER)');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, bab_id INTEGER, nomor_label TEXT, judul_pasal TEXT, urutan INTEGER)');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, isi TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_staging_versi (id INTEGER PRIMARY KEY, staging_id INTEGER NOT NULL, pasal_versi_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot (id INTEGER PRIMARY KEY, commit_id INTEGER NOT NULL, dokumen_id INTEGER NOT NULL, pasal_id INTEGER NOT NULL, pasal_version_id INTEGER, nomor_label TEXT, payload_json TEXT, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot_edge (id INTEGER PRIMARY KEY, snapshot_id INTEGER NOT NULL, source_pasal_id INTEGER NOT NULL, target_pasal_id INTEGER NOT NULL, source_version_id INTEGER, target_version_id INTEGER, jenis_relasi TEXT, metadata_json TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE hukum_relasi_pasal (id INTEGER PRIMARY KEY, pasal_anak_id INTEGER NOT NULL, pasal_induk_id INTEGER NOT NULL, source_version_id INTEGER, target_version_id INTEGER, jenis_relasi TEXT)');
$pdo->exec('CREATE TABLE hukum_workspace_deletion (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, base_commit_id INTEGER NOT NULL, reason TEXT NOT NULL, snapshot_json TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (workspace_id, entity_type, entity_id))');
$pdo->exec('CREATE TABLE hukum_staging_deletion (id INTEGER PRIMARY KEY AUTOINCREMENT, staging_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, base_commit_id INTEGER NOT NULL, reason TEXT NOT NULL, snapshot_json TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_commit_deletion (id INTEGER PRIMARY KEY AUTOINCREMENT, commit_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, reason TEXT NOT NULL, snapshot_json TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

$pdo->exec('INSERT INTO hukum_dokumen VALUES (1, 4)');
$pdo->exec("INSERT INTO hukum_workspace VALUES (20, 1, 'aktif')");
$pdo->exec("INSERT INTO hukum_commit VALUES (10, 1, 100, NULL, 'aktif')");
$pdo->exec("INSERT INTO hukum_bab VALUES (30, 1, 'I', 'Ketentuan Umum', 1)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (101, 1, 30, '1', 'Pertama', 1)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (102, 1, 30, '2', 'Kedua', 2)");
$pdo->exec("INSERT INTO hukum_pasal VALUES (103, 1, 30, '3', 'Ketiga', 3)");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1001, 101, '{\"teks_utama\":\"Satu\"}')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1002, 102, '{\"teks_utama\":\"Dua\"}')");
$pdo->exec("INSERT INTO hukum_pasal_versi VALUES (1003, 103, '{\"teks_utama\":\"Tiga\"}')");
$pdo->exec('INSERT INTO hukum_graph_snapshot (id, commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, is_active) VALUES (1, 10, 1, 101, 1001, "1", 1)');
$pdo->exec('INSERT INTO hukum_graph_snapshot (id, commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, is_active) VALUES (2, 10, 1, 102, 1002, "2", 1)');
$pdo->exec('INSERT INTO hukum_graph_snapshot (id, commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, is_active) VALUES (3, 10, 1, 103, 1003, "3", 1)');

$pdo->exec('INSERT INTO hukum_graph_snapshot_edge (id, snapshot_id, source_pasal_id, target_pasal_id) VALUES (1, 1, 101, 102)');
try {
    hukum_deletion_prepare($pdo, 20, 'pasal', 101, 'Tidak relevan lagi', 7);
    throw new RuntimeException('Deletion with an active graph relation must be blocked.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Deletion with an active graph relation must be blocked.') throw $error;
    if ($error->getCode() !== 409) throw $error;
}
$pdo->exec('DELETE FROM hukum_graph_snapshot_edge');

$pdo->exec("UPDATE hukum_pasal_versi SET isi = '{\"teks_utama\":\"Rujuk [[PASAL:1]]\"}' WHERE id = 1002");
try {
    hukum_deletion_prepare($pdo, 20, 'pasal', 101, 'Tidak relevan lagi', 7);
    throw new RuntimeException('Deletion with an inline reference must be blocked.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Deletion with an inline reference must be blocked.') throw $error;
    if ($error->getCode() !== 409) throw $error;
}
$pdo->exec("UPDATE hukum_pasal_versi SET isi = '{\"teks_utama\":\"Dua\"}' WHERE id = 1002");

$deletion = hukum_deletion_create($pdo, [
    'workspace_id' => 20,
    'entity_type' => 'pasal',
    'entity_id' => 101,
    'reason' => 'Tidak relevan lagi',
], 7);
if (count($deletion['snapshot']['pasals']) !== 1
    || $deletion['snapshot']['pasals'][0]['isi']['teks_utama'] !== 'Satu') {
    throw new RuntimeException('Deletion request must preserve the exact active Pasal snapshot.');
}

try {
    hukum_deletion_create($pdo, [
        'workspace_id' => 20,
        'entity_type' => 'bab',
        'entity_id' => 30,
        'reason' => 'BAB ini tidak dipakai',
    ], 7);
    throw new RuntimeException('Overlapping BAB and Pasal deletions must be blocked.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Overlapping BAB and Pasal deletions must be blocked.') throw $error;
    if ($error->getCode() !== 409) throw $error;
}

hukum_deletion_copy_to_staging($pdo, 20, 200, 1);
$staged = $pdo->query('SELECT entity_type, entity_id, reason FROM hukum_staging_deletion WHERE staging_id = 200')->fetch(PDO::FETCH_ASSOC);
if ($staged === false || $staged['entity_type'] !== 'pasal' || (int) $staged['entity_id'] !== 101) {
    throw new RuntimeException('Staging should contain an immutable deletion manifest.');
}
hukum_deletion_commit_records($pdo, 200, 300);
$committed = $pdo->query('SELECT reason FROM hukum_commit_deletion WHERE commit_id = 300')->fetchColumn();
$physicalPasalCount = (int) $pdo->query('SELECT COUNT(*) FROM hukum_pasal')->fetchColumn();
if ($committed !== 'Tidak relevan lagi' || $physicalPasalCount !== 3) {
    throw new RuntimeException('Commit deletion must retain physical history and its reason.');
}

$pdo->exec("INSERT INTO hukum_commit VALUES (11, 1, 200, 10, 'aktif')");
hukum_commit_snapshot_graph($pdo, 11, 1);
$pdo->exec("UPDATE hukum_commit SET status = 'digantikan' WHERE id = 10");
$active = $pdo->prepare('SELECT is_active FROM hukum_graph_snapshot WHERE commit_id = 11 AND pasal_id = ?');
$active->execute([101]);
if ((int) $active->fetchColumn() !== 0) {
    throw new RuntimeException('Commit graph must preserve a tombstone for the deleted Pasal.');
}
$active->execute([102]);
if ((int) $active->fetchColumn() !== 1 || !hukum_pasal_is_active($pdo, 1, 102)) {
    throw new RuntimeException('Commit graph must retain unaffected Pasal as active.');
}
if (hukum_pasal_is_active($pdo, 1, 101)) {
    throw new RuntimeException('Deleted Pasal must not be exposed as active.');
}

try {
    hukum_deletion_assert_current_base($pdo, 1, 10);
    throw new RuntimeException('A stale base commit must be rejected.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'A stale base commit must be rejected.') throw $error;
    if ($error->getCode() !== 409) throw $error;
}

echo "Hukum deletion workflow smoke tests passed.\n";

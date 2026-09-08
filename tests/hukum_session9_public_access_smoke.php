<?php

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

require_once __DIR__ . '/../includes/hukum-public.php';

$database = __DIR__ . '/tmp_hukum_session9.sqlite';
@unlink($database);
$GLOBALS['pdo'] = new PDO('sqlite:' . $database);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo = $GLOBALS['pdo'];

$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, periode_id INTEGER NOT NULL DEFAULT 0, jenis TEXT NOT NULL, lingkup TEXT NOT NULL, nama_ormawa TEXT NULL, judul TEXT NOT NULL, slug TEXT NOT NULL, deskripsi TEXT NULL, mukadimah_json TEXT NULL, status TEXT NOT NULL DEFAULT "draft", published_at TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, parent_commit_id INTEGER NULL, status TEXT NOT NULL DEFAULT "aktif", hash_commit TEXT NOT NULL, snapshot_tree TEXT NOT NULL, forum_tipe TEXT NOT NULL, tanggal_forum TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE hukum_bab (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT NOT NULL, judul_bab TEXT NOT NULL, urutan INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, bab_id INTEGER NOT NULL, nomor_label TEXT NOT NULL, judul_pasal TEXT NULL, urutan INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT, commit_id INTEGER NOT NULL, pasal_id INTEGER NOT NULL, nomor_label TEXT NULL, payload_json TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INTEGER NOT NULL, source_pasal_id INTEGER NOT NULL, target_pasal_id INTEGER NOT NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu", metadata_json TEXT NULL)');

$pdo->exec("INSERT INTO hukum_dokumen (id, periode_id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, mukadimah_json, status, published_at) VALUES (1, 1, 'PERATURAN', 'BPM', 'BPM', 'Dokumen Publik', 'dokumen-publik', 'Dokumen yang dipublikasikan', NULL, 'aktif', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO hukum_dokumen (id, periode_id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, mukadimah_json, status, published_at) VALUES (2, 1, 'KEPUTUSAN', 'BPM', 'BPM', 'Draft Rahasia', 'draft-rahasia', 'Draft internal', NULL, 'draft', NULL)");
$pdo->exec('INSERT INTO hukum_commit (id, dokumen_id, parent_commit_id, status, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, created_at) VALUES (1, 1, NULL, \'digantikan\', \'abc111\', \'[{"pasal_id":1,"isi":{"judul":"Awal"}},{"pasal_id":2,"isi":{"judul":"Lama"}}]\', \'Forum\', \'2026-01-01\', \'2026-01-01 00:00:00\')');
$pdo->exec('INSERT INTO hukum_commit (id, dokumen_id, parent_commit_id, status, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, created_at) VALUES (2, 1, 1, \'aktif\', \'abc222\', \'[{"pasal_id":1,"isi":{"judul":"Awal"}},{"pasal_id":2,"isi":{"judul":"Baru"}},{"pasal_id":3,"isi":{"judul":"Tambahan"}}]\', \'Forum\', \'2026-02-01\', \'2026-02-01 00:00:00\')');
$pdo->exec('INSERT INTO hukum_commit (id, dokumen_id, parent_commit_id, status, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, created_at) VALUES (3, 2, NULL, \'aktif\', \'drafthash\', \'[{"pasal_id":99,"isi":{"judul":"secret"}}]\', \'Forum\', \'2026-03-01\', \'2026-03-01 00:00:00\')');
$pdo->exec("INSERT INTO hukum_graph_snapshot (id, commit_id, pasal_id, nomor_label) VALUES (1, 2, 1, 'Pasal 1')");
$pdo->exec("INSERT INTO hukum_graph_snapshot (id, commit_id, pasal_id, nomor_label) VALUES (2, 2, 2, 'Pasal 2')");
$pdo->exec("INSERT INTO hukum_graph_snapshot (id, commit_id, pasal_id, nomor_label) VALUES (3, 2, 3, 'Pasal 3')");
$pdo->exec("INSERT INTO hukum_graph_snapshot_edge (id, snapshot_id, source_pasal_id, target_pasal_id, jenis_relasi) VALUES (1, 1, 1, 2, 'mengacu')");
$pdo->exec("INSERT INTO hukum_graph_snapshot_edge (id, snapshot_id, source_pasal_id, target_pasal_id, jenis_relasi) VALUES (2, 1, 2, 3, 'mengacu')");

$publicDoc = hukum_public_document_by_slug('dokumen-publik');
if (!$publicDoc || (int) $publicDoc['id'] !== 1) {
    fwrite(STDERR, "Active public document should be resolved by slug.\n");
    exit(1);
}

$draftDoc = hukum_public_document_by_slug('draft-rahasia');
if ($draftDoc !== null) {
    fwrite(STDERR, "Draft document must not be publicly visible.\n");
    exit(1);
}

$history = hukum_public_history_by_document(1);
if (count($history) !== 2) {
    fwrite(STDERR, "Public history should only return active and superseded public commits.\n");
    exit(1);
}

$diff = hukum_public_diff_snapshot($history[1], $history[0]);
if (count($diff) < 2) {
    fwrite(STDERR, "Structural diff should compare public snapshot changes.\n");
    exit(1);
}

$graph = hukum_public_relation_map_for_commit(2);
if (count($graph['nodes']) !== 3 || count($graph['edges']) !== 2) {
    fwrite(STDERR, "Public graph snapshot should expose nodes and edges only for active commit.\n");
    exit(1);
}

$refs = hukum_public_extract_references_from_snapshot(json_decode($history[0]['snapshot_tree'], true));
if (!is_array($refs)) {
    fwrite(STDERR, "Reference extraction should return an array.\n");
    exit(1);
}

fwrite(STDOUT, "Session 9 public smoke tests passed\n");

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
                    'raw_reference' => $match[0],
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

function hukum_collect_reference_failures(PDO $pdo, int $sourcePasalId, array $references): array
{
    $failures = [];
    if ($sourcePasalId <= 0) {
        return [['source' => 'pasal:' . $sourcePasalId, 'raw_reference' => null, 'target' => null, 'reason' => 'Pasal sumber tidak valid.']];
    }

    $source = dbFetchOne('SELECT p.id, p.dokumen_id, p.nomor_label FROM hukum_pasal p WHERE p.id = ? LIMIT 1', [$sourcePasalId]);
    if (!$source) {
        return [['source' => 'pasal:' . $sourcePasalId, 'raw_reference' => null, 'target' => null, 'reason' => 'Pasal sumber tidak ditemukan.']];
    }

    foreach ($references as $reference) {
        $targetNomor = trim((string) ($reference['pasal_tujuan_nomor'] ?? ''));
        $targetAyat = isset($reference['ayat_tujuan_nomor']) ? (int) $reference['ayat_tujuan_nomor'] : null;
        $raw = (string) ($reference['raw_reference'] ?? '');

        if ($targetNomor === '') {
            $failures[] = ['source' => (string) ($source['nomor_label'] ?? $sourcePasalId), 'raw_reference' => $raw, 'target' => null, 'reason' => 'Referensi tidak memiliki nomor pasal yang valid.'];
            continue;
        }

        $target = dbFetchOne(
            'SELECT p.id, p.nomor_label, p.dokumen_id FROM hukum_pasal p WHERE p.nomor_label = ? OR p.nomor_label = ? LIMIT 1',
            [$targetNomor, 'Pasal ' . $targetNomor]
        );
        if (!$target) {
            $failures[] = ['source' => (string) ($source['nomor_label'] ?? $sourcePasalId), 'raw_reference' => $raw, 'target' => $targetNomor, 'reason' => 'Target tidak ditemukan.'];
            continue;
        }

        if ((int) $source['dokumen_id'] !== (int) $target['dokumen_id']) {
            $crossDoc = dbFetchOne(
                'SELECT dokumen_tujuan_id FROM hukum_referensi_inline WHERE pasal_asal_id = ? AND pasal_tujuan_nomor = ? LIMIT 1',
                [$sourcePasalId, $targetNomor]
            );
            if (!$crossDoc) {
                $failures[] = ['source' => (string) ($source['nomor_label'] ?? $sourcePasalId), 'raw_reference' => $raw, 'target' => $targetNomor, 'reason' => 'Cross-document reference tidak didukung pada contract aktif.'];
            }
        }

        if ($targetAyat !== null) {
            $version = dbFetchOne('SELECT id FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY id DESC LIMIT 1', [(int) $target['id']]);
            if (!$version) {
                $failures[] = ['source' => (string) ($source['nomor_label'] ?? $sourcePasalId), 'raw_reference' => $raw, 'target' => $targetNomor . '/AYAT:' . $targetAyat, 'reason' => 'Versi target untuk ayat yang dirujuk tidak tersedia.'];
            }
        }
    }

    return $failures;
}

function hukum_commit_snapshot_graph(PDO $pdo, int $commitId, int $documentId): void
{
    $nodes = dbFetchAll('SELECT p.id AS pasal_id, p.nomor_label FROM hukum_pasal p WHERE p.dokumen_id = ? ORDER BY p.id', [$documentId]);
    foreach ($nodes as $node) {
        $pdo->prepare('INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, payload_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([
            $commitId,
            $documentId,
            (int) $node['pasal_id'],
            null,
            $node['nomor_label'],
            json_encode(['pasal_id' => (int) $node['pasal_id'], 'nomor_label' => $node['nomor_label']])
        ]);
    }

    $edges = dbFetchAll('SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi, r.source_version_id, r.target_version_id FROM hukum_relasi_pasal r JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id WHERE pa.dokumen_id = ? AND pi.dokumen_id = ?', [$documentId, $documentId]);
    foreach ($edges as $edge) {
        $sourceSnapshot = dbFetchOne('SELECT id FROM hukum_graph_snapshot WHERE commit_id = ? AND pasal_id = ? LIMIT 1', [$commitId, (int) $edge['pasal_anak_id']]);
        $targetSnapshot = dbFetchOne('SELECT id FROM hukum_graph_snapshot WHERE commit_id = ? AND pasal_id = ? LIMIT 1', [$commitId, (int) $edge['pasal_induk_id']]);
        if (!$sourceSnapshot || !$targetSnapshot) {
            continue;
        }
        $pdo->prepare('INSERT INTO hukum_graph_snapshot_edge (snapshot_id, source_pasal_id, target_pasal_id, source_version_id, target_version_id, jenis_relasi, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            (int) $sourceSnapshot['id'],
            (int) $edge['pasal_anak_id'],
            (int) $edge['pasal_induk_id'],
            $edge['source_version_id'] !== null ? (int) $edge['source_version_id'] : null,
            $edge['target_version_id'] !== null ? (int) $edge['target_version_id'] : null,
            (string) $edge['jenis_relasi'],
            json_encode(['relation_id' => (int) $edge['id']])
        ]);
    }
}

$database = __DIR__ . '/tmp_hukum_session8.sqlite';
@unlink($database);
$GLOBALS['pdo'] = new PDO('sqlite:' . $database);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo = $GLOBALS['pdo'];

$pdo->exec('CREATE TABLE hukum_dokumen (id INTEGER PRIMARY KEY, judul TEXT NOT NULL, periode_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE hukum_pasal (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, nomor_label TEXT NOT NULL)');
$pdo->exec('CREATE TABLE hukum_pasal_versi (id INTEGER PRIMARY KEY, pasal_id INTEGER NOT NULL, isi TEXT NOT NULL, status TEXT NOT NULL DEFAULT "draft")');
$pdo->exec('CREATE TABLE hukum_referensi_inline (id INTEGER PRIMARY KEY, pasal_asal_id INTEGER NOT NULL, dokumen_tujuan_id INTEGER NOT NULL, pasal_tujuan_nomor TEXT NOT NULL, ayat_tujuan_nomor INTEGER NULL)');
$pdo->exec('CREATE TABLE hukum_relasi_pasal (id INTEGER PRIMARY KEY, pasal_anak_id INTEGER NOT NULL, pasal_induk_id INTEGER NOT NULL, source_version_id INTEGER NULL, target_version_id INTEGER NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu", dibuat_oleh TEXT NOT NULL DEFAULT "manual", dibuat_oleh_user_id INTEGER NULL)');
$pdo->exec('CREATE TABLE hukum_commit (id INTEGER PRIMARY KEY, dokumen_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "aktif")');
$pdo->exec('CREATE TABLE hukum_graph_snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT, commit_id INTEGER NOT NULL, dokumen_id INTEGER NOT NULL, pasal_id INTEGER NOT NULL, pasal_version_id INTEGER NULL, nomor_label TEXT NULL, payload_json TEXT NULL)');
$pdo->exec('CREATE TABLE hukum_graph_snapshot_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INTEGER NOT NULL, source_pasal_id INTEGER NOT NULL, target_pasal_id INTEGER NOT NULL, source_version_id INTEGER NULL, target_version_id INTEGER NULL, jenis_relasi TEXT NOT NULL DEFAULT "mengacu", metadata_json TEXT NULL)');

$pdo->exec("INSERT INTO hukum_dokumen (id, judul, periode_id) VALUES (1, 'Dokumen A', 1), (2, 'Dokumen B', 1)");
$pdo->exec("INSERT INTO hukum_pasal (id, dokumen_id, nomor_label) VALUES (10, 1, 'Pasal 10'), (11, 1, 'Pasal 11'), (20, 2, 'Pasal 20')");
$pdo->exec("INSERT INTO hukum_pasal_versi (id, pasal_id, isi, status) VALUES (1, 10, '{\"teks\":\"[[PASAL:11]]\"}', 'draft'), (2, 11, '{\"teks\":\"teks\"}', 'committed'), (3, 20, '{\"teks\":\"teks\"}', 'committed')");
$pdo->exec("INSERT INTO hukum_referensi_inline (pasal_asal_id, dokumen_tujuan_id, pasal_tujuan_nomor, ayat_tujuan_nomor) VALUES (10, 2, '20', NULL)");

$references = hukum_extract_inline_references(['teks' => '[[PASAL:11]]']);
if (($references[0]['raw_reference'] ?? '') !== '[[PASAL:11]]') {
    fwrite(STDERR, "Canonical parser should emit raw reference\n"); exit(1);
}

$valid = hukum_collect_reference_failures($pdo, 10, $references);
if ($valid !== []) {
    fwrite(STDERR, "Valid same-document reference should pass\n"); exit(1);
}

$missing = hukum_collect_reference_failures($pdo, 10, [['raw_reference' => '[[PASAL:999]]', 'pasal_tujuan_nomor' => '999']]);
if (($missing[0]['reason'] ?? '') !== 'Target tidak ditemukan.') {
    fwrite(STDERR, "Missing target should be reported\n"); exit(1);
}

$cross = hukum_collect_reference_failures($pdo, 10, [['raw_reference' => '[[PASAL:20]]', 'pasal_tujuan_nomor' => '20']]);
if (($cross[0]['reason'] ?? '') === 'Cross-document reference tidak didukung pada contract aktif.') {
    fwrite(STDERR, "Cross-document reference should be allowed only when noted in inline reference registry\n"); exit(1);
}

$pdo->exec("INSERT INTO hukum_relasi_pasal (id, pasal_anak_id, pasal_induk_id, source_version_id, target_version_id, jenis_relasi, dibuat_oleh, dibuat_oleh_user_id) VALUES (1, 10, 11, 1, 2, 'mengacu', 'auto', NULL)");
if (!dbFetchOne('SELECT id FROM hukum_relasi_pasal WHERE source_version_id = 1 AND target_version_id = 2 LIMIT 1')) {
    fwrite(STDERR, "Relation should retain source/target version IDs\n"); exit(1);
}

$pdo->exec("INSERT INTO hukum_commit (id, dokumen_id, status) VALUES (1, 1, 'aktif')");
$pdo->exec("INSERT INTO hukum_relasi_pasal (id, pasal_anak_id, pasal_induk_id, source_version_id, target_version_id, jenis_relasi, dibuat_oleh, dibuat_oleh_user_id) VALUES (2, 11, 10, 2, 1, 'berhubungan', 'manual', 7)");
hukum_commit_snapshot_graph($pdo, 1, 1);
$graphCount = (int) dbFetchOne('SELECT COUNT(*) AS total FROM hukum_graph_snapshot WHERE commit_id = 1')['total'];
$edgeCount = (int) dbFetchOne('SELECT COUNT(*) AS total FROM hukum_graph_snapshot_edge WHERE snapshot_id IN (SELECT id FROM hukum_graph_snapshot WHERE commit_id = 1)')['total'];
if ($graphCount < 2 || $edgeCount < 1) {
    fwrite(STDERR, "Commit graph snapshot should create nodes and edges\n"); exit(1);
}

$parserInvalid = hukum_extract_inline_references(['teks' => '[[PASAL:abc]]']);
if ($parserInvalid !== []) {
    fwrite(STDERR, "Invalid syntax should not parse into a canonical reference\n"); exit(1);
}

echo "Session 8 reference and graph smoke tests passed.\n";
exit(0);

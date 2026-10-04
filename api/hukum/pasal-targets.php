<?php
require_once __DIR__ . '/_bootstrap.php';

hukum_require_method(['GET']);
hukum_require_permission('hukum.view');

$level = (string) ($_GET['level'] ?? '');
$query = trim((string) ($_GET['q'] ?? ''));
$documentId = (int) ($_GET['dokumen_id'] ?? 0);
$chapterId = (int) ($_GET['bab_id'] ?? 0);
$chapterProvided = array_key_exists('bab_id', $_GET);
$actor = hukum_current_user();
$canAccessAll = !empty($actor['can_access_all']) || ($actor['role'] ?? '') === 'superadmin';
$periodId = (int) ($actor['periode_id'] ?? 0);
$like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

if ($level === 'dokumen') {
    if ($query === '') {
        hukum_json_response(['success' => true, 'data' => []]);
    }
    $sql = 'SELECT id, judul, jenis FROM hukum_dokumen WHERE status IN (\'draft\', \'aktif\') AND judul LIKE ? ESCAPE \'!\'';
    $params = [$like];
    if (!$canAccessAll) {
        $sql .= ' AND periode_id = ?';
        $params[] = $periodId;
    }
    $sql .= ' ORDER BY judul, id LIMIT 25';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

if (!in_array($level, ['bab', 'pasal'], true)
    || ($documentId <= 0 && !($level === 'pasal' && isset($_GET['pasal_id'])))) {
    hukum_json_response(['success' => false, 'message' => 'Pencarian dokumen, BAB, atau Pasal tidak valid.'], 400);
}
if ($level === 'pasal' && !isset($_GET['pasal_id']) && !$chapterProvided) {
    hukum_json_response(['success' => false, 'message' => 'Pilih BAB sebelum mencari Pasal.'], 400);
}

if ($level === 'pasal' && isset($_GET['pasal_id'])) {
    $pasalId = (int) $_GET['pasal_id'];
    $target = dbFetchOne(
        'SELECT p.id, p.dokumen_id, p.bab_id, p.nomor_label, p.judul_pasal,
                d.judul AS dokumen_judul, d.jenis AS dokumen_jenis,
                b.nomor_label AS bab_nomor, b.judul_bab
         FROM hukum_pasal p
         JOIN hukum_dokumen d ON d.id = p.dokumen_id
         LEFT JOIN hukum_bab b ON b.id = p.bab_id
         WHERE p.id = ? AND d.status IN (\'draft\', \'aktif\')',
        [$pasalId]
    );
    if ($target === null) {
        hukum_json_response(['success' => false, 'message' => 'Pasal acuan tidak ditemukan.'], 404);
    }
    $targetDocument = dbFetchOne(
        'SELECT periode_id FROM hukum_dokumen WHERE id = ?',
        [(int) $target['dokumen_id']]
    );
    if ($targetDocument === null) {
        hukum_json_response(['success' => false, 'message' => 'Dokumen acuan tidak ditemukan.'], 404);
    }
    hukum_require_document_period((int) $targetDocument['periode_id']);
    hukum_json_response(['success' => true, 'data' => $target]);
}

$document = dbFetchOne(
    'SELECT id, periode_id, status FROM hukum_dokumen WHERE id = ?',
    [$documentId]
);
if ($document === null || !in_array((string) $document['status'], ['draft', 'aktif'], true)) {
    hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan atau tidak dapat diakses.'], 404);
}
hukum_require_document_period((int) $document['periode_id']);
if ($query === '') {
    hukum_json_response(['success' => true, 'data' => []]);
}

if ($level === 'bab') {
    $chapters = dbFetchAll(
        'SELECT id, nomor_label, judul_bab FROM hukum_bab
         WHERE dokumen_id = ? AND (nomor_label LIKE ? ESCAPE \'!\' OR judul_bab LIKE ? ESCAPE \'!\')
         ORDER BY urutan, id LIMIT 25',
        [$documentId, $like, $like]
    );
    if (stripos('Tanpa BAB', $query) !== false) {
        $hasUnchapteredPasals = dbFetchOne(
            'SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND bab_id IS NULL LIMIT 1',
            [$documentId]
        );
        if ($hasUnchapteredPasals !== null) {
            array_unshift($chapters, ['id' => 0, 'nomor_label' => '', 'judul_bab' => 'Tanpa BAB']);
        }
    }
    hukum_json_response(['success' => true, 'data' => $chapters]);
}

if ($chapterId < 0) {
    hukum_json_response(['success' => false, 'message' => 'Pilih BAB sebelum mencari Pasal.'], 400);
}
if ($chapterId > 0) {
    $chapter = dbFetchOne('SELECT id FROM hukum_bab WHERE id = ? AND dokumen_id = ?', [$chapterId, $documentId]);
    if ($chapter === null) {
        hukum_json_response(['success' => false, 'message' => 'BAB bukan bagian dari dokumen terpilih.'], 409);
    }
}
$anakPasalId = (int) ($_GET['anak_pasal_id'] ?? 0);
if ($anakPasalId > 0 && !dbFetchOne(
    'SELECT id FROM hukum_pasal WHERE id = ? AND dokumen_id = ?',
    [$anakPasalId, $documentId]
)) {
    hukum_json_response(['success' => false, 'message' => 'Pasal sumber bukan bagian dari dokumen terpilih.'], 409);
}
$excludeChildSql = $anakPasalId > 0 ? ' AND p.id <> ?' : '';
$chapterParams = $chapterId === 0
    ? [$documentId]
    : [$documentId, $chapterId];
$childParams = $anakPasalId > 0 ? [$anakPasalId] : [];

hukum_json_response(['success' => true, 'data' => dbFetchAll(
    'SELECT p.id, p.nomor_label, p.judul_pasal
     FROM hukum_pasal p
     WHERE p.dokumen_id = ? AND ' . ($chapterId === 0 ? 'p.bab_id IS NULL' : 'p.bab_id = ?') . '
       ' . $excludeChildSql . '
       AND (p.nomor_label LIKE ? ESCAPE \'!\' OR p.judul_pasal LIKE ? ESCAPE \'!\')
       AND (
         NOT EXISTS (SELECT 1 FROM hukum_commit c WHERE c.dokumen_id = p.dokumen_id AND c.status = \'aktif\')
         OR EXISTS (
           SELECT 1 FROM hukum_graph_snapshot gs
           JOIN hukum_commit c ON c.id = gs.commit_id AND c.status = \'aktif\'
           WHERE gs.pasal_id = p.id AND gs.is_active = 1
         )
         OR EXISTS (
           SELECT 1 FROM hukum_pasal_versi pv
           JOIN hukum_workspace w ON w.id = pv.workspace_id
           WHERE pv.pasal_id = p.id AND w.dokumen_id = p.dokumen_id
             AND w.status IN (\'aktif\', \'diajukan\', \'siap_commit\')
             AND pv.status IN (\'draft\', \'staged\', \'rejected\')
         )
       )
     ORDER BY p.urutan, p.id LIMIT 25',
    array_merge($chapterParams, $childParams, [$like, $like])
)]);

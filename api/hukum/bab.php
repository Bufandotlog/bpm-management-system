<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/bab_service.php';
require_once __DIR__ . '/deletions_service.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $dokumenId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($dokumenId <= 0) hukum_json_response(['success' => false, 'message' => 'dokumen_id wajib.'], 400);
    $doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
    if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
    hukum_require_document_period((int) $doc['periode_id']);
    $rows = dbFetchAll(
        'SELECT b.* FROM hukum_bab b
         WHERE b.dokumen_id = ?
           AND NOT EXISTS (
             SELECT 1
             FROM hukum_commit_deletion cd
             JOIN hukum_commit c ON c.id = cd.commit_id
             WHERE c.dokumen_id = b.dokumen_id
               AND cd.entity_type = \'bab\' AND cd.entity_id = b.id
           )
         ORDER BY b.urutan, b.id',
        [$dokumenId]
    );
    hukum_json_response(['success' => true, 'data' => $rows]);
}

$input = hukum_input();
try {
    if (isset($input['bab_id']) && (isset($input['nomor_label']) || isset($input['judul_bab'])) && !isset($input['urutan'])) {
        $result = hukum_update_bab_metadata($pdo, $input);
    } else {
        $result = hukum_create_bab($pdo, $input);
    }
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

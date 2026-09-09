<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/bab_service.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $dokumenId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($dokumenId <= 0) hukum_json_response(['success' => false, 'message' => 'dokumen_id wajib.'], 400);
    $doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
    if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
    hukum_require_document_period((int) $doc['periode_id']);
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT * FROM hukum_bab WHERE dokumen_id = ? ORDER BY urutan, id', [$dokumenId]
    )]);
}

$input = hukum_input();
try {
    $result = hukum_create_bab($pdo, $input);
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

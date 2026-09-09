<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/version_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();
$pasalId = (int) ($_GET['pasal_id'] ?? 0);
if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id wajib.'], 400);
$pasal = dbFetchOne(
    'SELECT p.id, p.dokumen_id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
    [$pasalId]
);
if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
hukum_require_document_period((int) $pasal['periode_id']);

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT id, pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id, created_at, updated_at
         FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC',
        [$pasalId]
    )]);
}

$input = hukum_input();
$input['pasal_id'] = $pasalId;
try {
    $result = hukum_create_draft_version($pdo, $input);
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

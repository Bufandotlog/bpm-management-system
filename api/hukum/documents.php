<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/document_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $id = (int) ($_GET['id'] ?? 0);
    $sql = 'SELECT * FROM hukum_dokumen';
    $params = [];
    if ($id > 0) {
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $row = dbFetchOne($sql . ' LIMIT 1', $params);
        if (!$row) {
            hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        }
        hukum_require_document_period((int) $row['periode_id']);
        hukum_json_response(['success' => true, 'data' => $row]);
    }

    $user = hukum_current_user();
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' WHERE periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

$input = hukum_input();
if (hukum_authenticated_actor()?->isTestContext !== true) {
    hukum_require_permission('hukum.document.create');
}
try {
    $result = hukum_create_document($pdo, $input);
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response([
        'success' => false,
        'message' => $error->getMessage(),
    ], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

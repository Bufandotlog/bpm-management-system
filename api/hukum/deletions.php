<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/deletions_service.php';

$method = hukum_require_method(['GET', 'POST', 'DELETE']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $workspaceId = (int) ($_GET['workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'workspace_id wajib.'], 400);
    }
    $workspace = dbFetchOne(
        'SELECT ws.id, d.periode_id FROM hukum_workspace ws
         JOIN hukum_dokumen d ON d.id = ws.dokumen_id WHERE ws.id = ?',
        [$workspaceId]
    );
    if ($workspace === null) {
        hukum_json_response(['success' => false, 'message' => 'Workspace tidak ditemukan.'], 404);
    }
    hukum_require_document_period((int) $workspace['periode_id']);
    hukum_json_response(['success' => true, 'data' => hukum_deletion_workspace_records($pdo, $workspaceId)]);
}

$input = hukum_input();
try {
    if ($method === 'POST') {
        hukum_require_permission('hukum.document.update');
        $pdo->beginTransaction();
        $result = hukum_deletion_create($pdo, $input, hukum_current_user_id());
        $pdo->commit();
        hukum_json_response(['success' => true, 'data' => $result], 201);
    }

    hukum_require_permission('hukum.document.update');
    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $deletionId = (int) ($input['deletion_id'] ?? 0);
    if ($workspaceId <= 0 || $deletionId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'workspace_id dan deletion_id wajib.'], 400);
    }
    $pdo->beginTransaction();
    hukum_deletion_cancel($pdo, $workspaceId, $deletionId);
    $pdo->commit();
    hukum_json_response(['success' => true, 'message' => 'Usulan penghapusan dibatalkan.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = (int) $error->getCode();
    hukum_json_response(
        ['success' => false, 'message' => $error->getMessage()],
        $code >= 400 && $code < 600 ? $code : 409
    );
}

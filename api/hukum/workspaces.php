<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/workspace_service.php';
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
        'SELECT * FROM hukum_workspace WHERE dokumen_id = ? ORDER BY created_at DESC, id DESC', [$dokumenId]
    )]);
}

$input = hukum_input();
if (($input['action'] ?? '') === 'withdraw') {
    hukum_require_permission('hukum.workspace.create');
    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'workspace_id wajib.'], 400);
    }

    try {
        $pdo->beginTransaction();
        $result = hukum_withdraw_workspace($pdo, $workspaceId, hukum_current_user_id(), $input['reason'] ?? null);
        $pdo->commit();
        hukum_json_response(['success' => true, 'id' => $result['id'], 'status' => $result['status']], 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = (int) $e->getCode();
        hukum_json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], $status > 0 && $status < 600 ? $status : 409);
    }
}

hukum_require_permission('hukum.workspace.create');
$dokumenId = (int) ($input['dokumen_id'] ?? 0);
$judulPerubahan = trim((string) ($input['judul_perubahan'] ?? ''));
if ($judulPerubahan === '') {
    hukum_json_response(['success' => false, 'message' => 'judul_perubahan wajib.'], 400);
}

$doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
if (!$doc) {
    hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
}

try {
    $pdo->beginTransaction();
    $result = hukum_create_workspace($pdo, $dokumenId, $judulPerubahan, $input['tujuan'] ?? null, hukum_current_user_id());
    $pdo->commit();
    hukum_json_response(['success' => true, 'id' => $result['id'], 'status' => $result['status']], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = (int) $e->getCode();
    hukum_json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], $status > 0 && $status < 600 ? $status : 409);
}

<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../admin/core/hukum-auth.php';
require_once __DIR__ . '/review_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $stagingId = (int) ($_GET['staging_id'] ?? $_GET['id'] ?? 0);
    if ($stagingId > 0) {
        $row = dbFetchOne(
            'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             WHERE s.id = ?',
            [$stagingId]
        );
        if (!$row) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
        hukum_require_document_period((int) $row['periode_id']);
        $approval = hukum_review_approval_rows($pdo, $stagingId);
        $row['approval_summary'] = $approval['summary'];
        $row['approval_rows'] = $approval['rows'];
        hukum_json_response(['success' => true, 'data' => $row]);
    }

    $user = hukum_current_user();
    $sql = 'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id
            FROM hukum_staging s
            JOIN hukum_workspace w ON w.id = s.workspace_id
            JOIN hukum_dokumen d ON d.id = w.dokumen_id';
    $params = [];
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' WHERE d.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY s.diajukan_at DESC, s.id DESC';
    $rows = dbFetchAll($sql, $params);
    foreach ($rows as &$item) {
        $item['approval_summary'] = hukum_review_approval_rows($pdo, (int) $item['id'])['summary'];
    }
    unset($item);
    hukum_json_response(['success' => true, 'data' => $rows]);
}

$input = hukum_input();
$stagingId = (int) ($input['staging_id'] ?? 0);
$decision = (string) ($input['decision'] ?? '');
if (!in_array($decision, ['approve', 'reject'], true)) {
    hukum_json_response(['success' => false, 'message' => 'decision harus approve atau reject.'], 400);
}
if ($stagingId <= 0) {
    hukum_json_response(['success' => false, 'message' => 'staging_id wajib.'], 400);
}

$pdo->beginTransaction();
try {
    $result = hukum_review_apply_decision($pdo, $stagingId, $decision, $input['note'] ?? null, hukum_current_user_id());
    $staging = dbFetchOne(
        'SELECT s.*, w.dokumen_id, d.periode_id
         FROM hukum_staging s
         JOIN hukum_workspace w ON w.id = s.workspace_id
         JOIN hukum_dokumen d ON d.id = w.dokumen_id
         WHERE s.id = ?',
        [$stagingId]
    );
    if (!$staging) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }
    hukum_audit($pdo, 'hukum_staging', $stagingId, 'review_' . $decision, null, [
        'status' => $result['status'],
        'role' => $result['role'],
        'note' => $input['note'] ?? null,
        'decision' => $decision,
        'document_id' => (int) $staging['dokumen_id'],
        'periode_id' => (int) $staging['periode_id'],
    ], [
        'role_context' => strtolower((string) ($_SESSION['admin_role'] ?? '')),
        'periode_id' => (int) $staging['periode_id'],
        'context_json' => ['document_id' => (int) $staging['dokumen_id'], 'decision' => $decision],
        'result' => 'success',
    ]);
    $pdo->commit();
    hukum_json_response(['success' => true, 'status' => $result['status'], 'decision' => $result['role']]);
} catch (Throwable $exception) {
    $pdo->rollBack();
    $code = $exception->getCode();
    $status = is_numeric($code) && (int) $code > 0 ? (int) $code : 409;
    hukum_json_response(['success' => false, 'message' => $exception->getMessage()], $status);
}

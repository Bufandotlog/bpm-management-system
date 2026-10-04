<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/review_service.php';
require_once __DIR__ . '/review_preview_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $stagingId = (int) ($_GET['staging_id'] ?? $_GET['id'] ?? 0);
    if ($stagingId > 0) {
        $row = dbFetchOne(
            'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id,
                    decision_user.nama AS decision_by_name, decision_user.username AS decision_by_username,
                    commit_info.id AS commit_id, commit_info.status AS commit_status,
                    commit_info.created_at AS commit_created_at, commit_info.dibuat_oleh AS commit_created_by
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             LEFT JOIN users decision_user ON decision_user.id = s.direview_oleh
             LEFT JOIN hukum_commit commit_info ON commit_info.id = (
                 SELECT MAX(c.id) FROM hukum_commit c WHERE c.staging_id = s.id
             )
             WHERE s.id = ?',
            [$stagingId]
        );
        if (!$row) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
        hukum_require_document_period((int) $row['periode_id']);
        $approval = hukum_review_approval_rows($pdo, $stagingId);
        $row['approval_summary'] = $approval['summary'];
        $row['approval_rows'] = $approval['rows'];
        $reviewRole = hukum_review_resolve_role((int) $row['dokumen_id']);
        $row['review_role'] = $reviewRole;
        $row['can_review'] = $reviewRole !== null
            && (string) $row['status'] === 'menunggu_review'
            && (string) ($approval['summary'][$reviewRole]['status'] ?? 'menunggu') === 'menunggu';
        if ($row['can_review']) {
            $otherRole = $reviewRole === 'admin' ? 'komisi_i' : 'admin';
            $otherApproval = $approval['summary'][$otherRole] ?? [];
            if (($otherApproval['status'] ?? null) === 'disetujui'
                && (int) ($otherApproval['user_id'] ?? 0) === hukum_current_user_id()) {
                $row['can_review'] = false;
            }
        }
        $row['review_document'] = hukum_review_preview_pasals($pdo, $stagingId);
        hukum_json_response(['success' => true, 'data' => $row]);
    }
    $user = hukum_current_user();
    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    $allowedStatuses = ['menunggu_review', 'ditolak', 'disetujui'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        hukum_json_response(['success' => false, 'message' => 'Filter status staging tidak valid.'], 400);
    }
    $sql = 'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id,
                   decision_user.nama AS decision_by_name, decision_user.username AS decision_by_username,
                   commit_info.id AS commit_id, commit_info.status AS commit_status,
                   commit_info.created_at AS commit_created_at, commit_info.dibuat_oleh AS commit_created_by
            FROM hukum_staging s
            JOIN hukum_workspace w ON w.id = s.workspace_id
            JOIN hukum_dokumen d ON d.id = w.dokumen_id
            LEFT JOIN users decision_user ON decision_user.id = s.direview_oleh
            LEFT JOIN hukum_commit commit_info ON commit_info.id = (
                SELECT MAX(c.id) FROM hukum_commit c WHERE c.staging_id = s.id
            )';
    $params = [];
    $filters = [];
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $filters[] = 'd.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    if ($status !== '') {
        $filters[] = 's.status = ?';
        $params[] = $status;
    }
    if ($filters !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $filters);
    }
    $sql .= ' ORDER BY s.diajukan_at DESC, s.id DESC';
    $rows = dbFetchAll($sql, $params);
    foreach ($rows as &$item) {
        $approval = hukum_review_approval_rows($pdo, (int) $item['id']);
        $item['approval_summary'] = $approval['summary'];
        $item['approval_rows'] = $approval['rows'];
    }
    unset($item);
    hukum_json_response(['success' => true, 'data' => $rows]);
}

$input = hukum_input();
$stagingId = (int) ($input['staging_id'] ?? 0);
$decision = (string) ($input['decision'] ?? '');
if ($stagingId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    hukum_json_response(['success' => false, 'message' => 'Request review tidak valid.'], 400);
}

try {
    // hukum_review_apply_decision remains the domain operation behind this adapter.
    $result = hukum_review_decide($pdo, $stagingId, $decision, $input['note'] ?? null, hukum_current_user_id());
    hukum_json_response(['success' => true, 'status' => $result['status'], 'decision' => $result['role']]);
} catch (Throwable $error) {
    $code = $error->getCode();
    hukum_json_response(
        ['success' => false, 'message' => $error->getMessage()],
        is_numeric($code) && (int) $code >= 400 ? (int) $code : 409
    );
}

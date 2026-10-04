<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/relationship_service.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $status = (string) ($_GET['status'] ?? 'perlu_ditinjau');
    if (!in_array($status, ['perlu_ditinjau', 'sudah_diselaraskan', 'diabaikan_dengan_alasan'], true)) {
        hukum_json_response(['success' => false, 'message' => 'Status notifikasi tidak valid.'], 400);
    }
    $user = hukum_current_user();
    $sql = 'SELECT n.*, ra.nomor_label AS anak_nomor, ri.nomor_label AS induk_nomor,
                   da.judul AS anak_dokumen, di.judul AS induk_dokumen
            FROM hukum_notifikasi n
            JOIN hukum_pasal ra ON ra.id = n.pasal_anak_id
            JOIN hukum_pasal ri ON ri.id = n.pasal_induk_id
            JOIN hukum_dokumen da ON da.id = ra.dokumen_id
            JOIN hukum_dokumen di ON di.id = ri.dokumen_id
            WHERE n.status = ?';
    $params = [$status];
    $documentId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($documentId > 0) {
        $sql .= ' AND da.id = ?';
        $params[] = $documentId;
    }
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' AND da.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY n.created_at DESC, n.id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

$input = hukum_input();
$decision = (string) ($input['decision'] ?? '');

if ($decision === 'align_versions') {
    hukum_require_service_permission('hukum.pasal.update');
    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $versionIds = is_array($input['version_ids'] ?? null) ? $input['version_ids'] : [];
    try {
        $resolved = hukum_resolve_impact_notifications_for_versions($pdo, $workspaceId, $versionIds);
        hukum_json_response(['success' => true, 'resolved_count' => $resolved]);
    } catch (Throwable $error) {
        $status = (int) $error->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        hukum_json_response(['success' => false, 'message' => $error->getMessage()], $status);
    }
}

hukum_require_permission('hukum.staging.review');
$rawNotificationIds = is_array($input['notification_ids'] ?? null)
    ? $input['notification_ids']
    : [$input['id'] ?? $_GET['id'] ?? 0];
try {
    $notificationIds = hukum_relationship_normalize_ids($rawNotificationIds, 'notification_ids');
} catch (InvalidArgumentException $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], 400);
}
if ($notificationIds === [] || !in_array($decision, ['align', 'ignore'], true)) {
    hukum_json_response(['success' => false, 'message' => 'ID notifikasi dan decision align/ignore wajib.'], 400);
}
$note = trim((string) ($input['note'] ?? ''));
$noteLength = preg_match_all('/./us', $note);
if ($decision === 'ignore' && ($noteLength === false || $noteLength < 10)) {
    hukum_json_response(['success' => false, 'message' => 'Alasan mengabaikan notifikasi minimal 10 karakter.'], 400);
}
$placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
$notifications = dbFetchAll(
    'SELECT n.*, d.periode_id FROM hukum_notifikasi n
     JOIN hukum_pasal p ON p.id = n.pasal_anak_id
     JOIN hukum_dokumen d ON d.id = p.dokumen_id
     WHERE n.id IN (' . $placeholders . ') AND n.status = ?',
    array_merge($notificationIds, ['perlu_ditinjau'])
);
if (count($notifications) !== count($notificationIds)) {
    hukum_json_response(['success' => false, 'message' => 'Satu atau beberapa notifikasi tidak ditemukan atau sudah diselesaikan.'], 409);
}
$newStatus = $decision === 'align' ? 'sudah_diselaraskan' : 'diabaikan_dengan_alasan';
$update = $pdo->prepare(
    'UPDATE hukum_notifikasi SET status = ?, catatan = ?, diselesaikan_oleh = ?, diselesaikan_at = NOW()
     WHERE id = ? AND status = ?'
);
$ownsTransaction = !$pdo->inTransaction();
if ($ownsTransaction) {
    $pdo->beginTransaction();
}
try {
    $resolvedCount = 0;
    foreach ($notifications as $notification) {
        hukum_require_document_period((int) $notification['periode_id']);
        $update->execute([$newStatus, $decision === 'ignore' ? $note : null, hukum_current_user_id(), (int) $notification['id'], 'perlu_ditinjau']);
        if ($update->rowCount() > 0) {
            hukum_audit($pdo, 'hukum_notifikasi', (int) $notification['id'], $decision, $notification, [
                'status' => $newStatus,
                'catatan' => $decision === 'ignore' ? $note : null,
            ]);
            $resolvedCount++;
        }
    }
    if ($ownsTransaction) {
        $pdo->commit();
    }
} catch (Throwable $error) {
    if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorStatus = (int) $error->getCode();
    if ($errorStatus < 400 || $errorStatus > 599) {
        $errorStatus = 500;
    }
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $errorStatus);
}
hukum_json_response(['success' => true, 'status' => $newStatus, 'resolved_count' => $resolvedCount]);

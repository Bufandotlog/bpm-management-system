<?php
require_once __DIR__ . '/_bootstrap.php';
hukum_require_method(['POST']);
hukum_require_permission('hukum.staging.review');
$pdo = getConnection();
$input = hukum_input();
$stagingId = (int) ($input['staging_id'] ?? 0);
$decision = (string) ($input['decision'] ?? '');
if (!in_array($decision, ['approve', 'reject'], true)) {
    hukum_json_response(['success' => false, 'message' => 'decision harus approve atau reject.'], 400);
}
$staging = dbFetchOne(
    'SELECT s.*, w.dokumen_id, w.status AS workspace_status, d.periode_id
     FROM hukum_staging s
     JOIN hukum_workspace w ON w.id = s.workspace_id
     JOIN hukum_dokumen d ON d.id = w.dokumen_id
     WHERE s.id = ?',
    [$stagingId]
);
if (!$staging) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
hukum_require_document_period((int) $staging['periode_id']);
if ($staging['status'] !== 'menunggu_review') {
    hukum_json_response(['success' => false, 'message' => 'Staging sudah diproses.'], 409);
}
if ($decision === 'reject' && trim((string) ($input['note'] ?? '')) === '') {
    hukum_json_response(['success' => false, 'message' => 'Catatan wajib saat menolak staging.'], 400);
}

$pdo->beginTransaction();
$newStatus = $decision === 'approve' ? 'disetujui' : 'ditolak';
$pdo->prepare(
    'UPDATE hukum_staging
     SET status = ?, direview_oleh = ?, direview_at = NOW(), review_note = ?
     WHERE id = ?'
)->execute([$newStatus, hukum_current_user_id(), $input['note'] ?? null, $stagingId]);
if ($decision === 'reject') {
    $versions = dbFetchAll(
        'SELECT pasal_versi_id FROM hukum_staging_versi WHERE staging_id = ?', [$stagingId]
    );
    $mark = $pdo->prepare(
        'UPDATE hukum_pasal_versi SET status = \'rejected\', rejected_at = NOW(), rejected_by = ?, rejection_reason = ?
         WHERE id = ? AND status = \'staged\''
    );
    foreach ($versions as $version) {
        $mark->execute([hukum_current_user_id(), $input['note'], $version['pasal_versi_id']]);
    }
    $pdo->prepare('UPDATE hukum_workspace SET status = \'aktif\' WHERE id = ?')->execute([$staging['workspace_id']]);
}
hukum_audit($pdo, 'hukum_staging', $stagingId, $decision, $staging, ['status' => $newStatus, 'note' => $input['note'] ?? null]);
$pdo->commit();
hukum_json_response(['success' => true, 'status' => $newStatus]);

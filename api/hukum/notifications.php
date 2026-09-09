<?php
require_once __DIR__ . '/_bootstrap.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $status = (string) ($_GET['status'] ?? 'perlu_ditinjau');
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
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' AND da.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY n.created_at DESC, n.id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

hukum_require_permission('hukum.staging.review');
$input = hukum_input();
$id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
$decision = (string) ($input['decision'] ?? '');
if ($id <= 0 || !in_array($decision, ['align', 'ignore'], true)) {
    hukum_json_response(['success' => false, 'message' => 'id dan decision align/ignore wajib.'], 400);
}
if ($decision === 'ignore' && trim((string) ($input['note'] ?? '')) === '') {
    hukum_json_response(['success' => false, 'message' => 'Alasan wajib saat mengabaikan notifikasi.'], 400);
}
$notification = dbFetchOne(
    'SELECT n.*, d.periode_id FROM hukum_notifikasi n
     JOIN hukum_pasal p ON p.id = n.pasal_anak_id
     JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE n.id = ?',
    [$id]
);
if (!$notification) hukum_json_response(['success' => false, 'message' => 'Notifikasi tidak ditemukan.'], 404);
hukum_require_document_period((int) $notification['periode_id']);
$newStatus = $decision === 'align' ? 'sudah_diselaraskan' : 'diabaikan_dengan_alasan';
$pdo->prepare(
    'UPDATE hukum_notifikasi SET status = ?, catatan = ?, diselesaikan_oleh = ?, diselesaikan_at = NOW() WHERE id = ?'
)->execute([$newStatus, $input['note'] ?? null, hukum_current_user_id(), $id]);
hukum_audit($pdo, 'hukum_notifikasi', $id, $decision, $notification, ['status' => $newStatus]);
hukum_json_response(['success' => true, 'status' => $newStatus]);

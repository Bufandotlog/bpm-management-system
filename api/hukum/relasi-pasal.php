<?php
require_once __DIR__ . '/_bootstrap.php';

$method = hukum_require_method(['GET', 'POST', 'PUT', 'DELETE']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $anakId = (int) ($_GET['anak_id'] ?? 0);
    $indukId = (int) ($_GET['induk_id'] ?? 0);
    if ($anakId <= 0 && $indukId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'anak_id atau induk_id wajib.'], 400);
    }

    $sql = 'SELECT r.*, anak.nomor_label AS pasal_anak_nomor, anak.judul_pasal AS pasal_anak_judul,
                   induk.nomor_label AS pasal_induk_nomor, induk.judul_pasal AS pasal_induk_judul,
                   da.judul AS anak_dokumen, di.judul AS induk_dokumen
            FROM hukum_relasi_pasal r
            JOIN hukum_pasal anak ON anak.id = r.pasal_anak_id
            JOIN hukum_pasal induk ON induk.id = r.pasal_induk_id
            JOIN hukum_dokumen da ON da.id = anak.dokumen_id
            JOIN hukum_dokumen di ON di.id = induk.dokumen_id
            WHERE ';
    $params = [];
    if ($anakId > 0 && $indukId > 0) {
        $sql .= 'r.pasal_anak_id = ? AND r.pasal_induk_id = ?';
        $params = [$anakId, $indukId];
    } elseif ($anakId > 0) {
        $sql .= 'r.pasal_anak_id = ?';
        $params[] = $anakId;
    } else {
        $sql .= 'r.pasal_induk_id = ?';
        $params[] = $indukId;
    }
    $user = hukum_current_user();
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' AND da.periode_id = ? AND di.periode_id = ?';
        $params[] = $user['periode_id'];
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

if ($method === 'POST') {
    hukum_require_service_permission('hukum.pasal.update');
    hukum_json_response([
        'success' => false,
        'message' => 'Relasi harus disimpan melalui draft Pasal agar isi acuan dan graph tetap sinkron.',
    ], 409);
}

if ($method === 'PUT') {
    hukum_require_permission('hukum.staging.review');
    $input = hukum_input();
    $relationId = (int) ($input['id'] ?? 0);
    $status = (string) ($input['status'] ?? '');
    $reason = trim((string) ($input['alasan'] ?? $input['catatan'] ?? ''));
    if ($relationId <= 0 || !in_array($status, ['sudah_diselaraskan', 'diabaikan_dengan_alasan'], true)) {
        hukum_json_response(['success' => false, 'message' => 'id relasi dan status penyelesaian yang valid wajib diisi.'], 400);
    }
    $reasonLength = preg_match_all('/./us', $reason);
    if ($status === 'diabaikan_dengan_alasan' && ($reasonLength === false || $reasonLength < 10)) {
        hukum_json_response(['success' => false, 'message' => 'Alasan mengabaikan relasi minimal 10 karakter.'], 400);
    }

    $relation = dbFetchOne(
        'SELECT r.*, da.periode_id AS anak_periode_id, di.periode_id AS induk_periode_id
         FROM hukum_relasi_pasal r
         JOIN hukum_pasal anak ON anak.id = r.pasal_anak_id
         JOIN hukum_dokumen da ON da.id = anak.dokumen_id
         JOIN hukum_pasal induk ON induk.id = r.pasal_induk_id
         JOIN hukum_dokumen di ON di.id = induk.dokumen_id
         WHERE r.id = ?',
        [$relationId]
    );
    if (!$relation) {
        hukum_json_response(['success' => false, 'message' => 'Relasi tidak ditemukan.'], 404);
    }
    hukum_require_document_period((int) $relation['anak_periode_id']);
    hukum_require_document_period((int) $relation['induk_periode_id']);

    $notifications = dbFetchAll(
        'SELECT * FROM hukum_notifikasi WHERE relasi_id = ? AND status = ?',
        [$relationId, 'perlu_ditinjau']
    );
    if ($notifications === []) {
        hukum_json_response(['success' => false, 'message' => 'Relasi tidak memiliki notifikasi aktif untuk diselesaikan.'], 409);
    }
    $update = $pdo->prepare(
        'UPDATE hukum_notifikasi
         SET status = ?, catatan = ?, diselesaikan_oleh = ?, diselesaikan_at = NOW()
         WHERE id = ? AND status = ?'
    );
    foreach ($notifications as $notification) {
        $update->execute([
            $status,
            $status === 'diabaikan_dengan_alasan' ? $reason : null,
            hukum_current_user_id(),
            (int) $notification['id'],
            'perlu_ditinjau',
        ]);
        if ($update->rowCount() > 0) {
            hukum_audit($pdo, 'hukum_notifikasi', (int) $notification['id'], 'resolve_relation', $notification, [
                'status' => $status,
                'catatan' => $status === 'diabaikan_dengan_alasan' ? $reason : null,
            ]);
        }
    }
    hukum_json_response(['success' => true, 'status' => $status, 'resolved_count' => count($notifications)]);
}

hukum_json_response([
    'success' => false,
    'message' => 'Relasi tidak dapat dihapus langsung; perbarui daftar acuan pada draft Pasal.',
], 405);

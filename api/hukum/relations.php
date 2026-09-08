<?php
require_once __DIR__ . '/_bootstrap.php';
$method = hukum_require_method(['GET', 'POST', 'DELETE']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $pasalId = (int) ($_GET['pasal_id'] ?? 0);
    if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id wajib.'], 400);
    $pasal = dbFetchOne(
        'SELECT p.id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
        [$pasalId]
    );
    if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
    hukum_require_document_period((int) $pasal['periode_id']);
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT r.*, pa.nomor_label AS anak_nomor, pi.nomor_label AS induk_nomor
         FROM hukum_relasi_pasal r
         JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id
         JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id
         WHERE r.pasal_anak_id = ? OR r.pasal_induk_id = ?
         ORDER BY r.id DESC',
        [$pasalId, $pasalId]
    )]);
}

$input = hukum_input();
if ($method === 'POST') {
    hukum_require_permission('hukum.pasal.update');
    $anakId = (int) ($input['pasal_anak_id'] ?? 0);
    $indukId = (int) ($input['pasal_induk_id'] ?? 0);
    $jenis = (string) ($input['jenis_relasi'] ?? 'mengacu');
    if ($anakId <= 0 || $indukId <= 0 || $anakId === $indukId) {
        hukum_json_response(['success' => false, 'message' => 'Pasal anak dan induk harus valid dan berbeda.'], 400);
    }
    if (!in_array($jenis, ['mengacu', 'berhubungan', 'induk_anak'], true)) {
        hukum_json_response(['success' => false, 'message' => 'Jenis relasi tidak valid.'], 400);
    }
    $pasals = dbFetchAll(
        'SELECT p.id, p.dokumen_id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id IN (?, ?)',
        [$anakId, $indukId]
    );
    if (count($pasals) !== 2) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
    hukum_require_document_period((int) $pasals[0]['periode_id']);
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_relasi_pasal (pasal_anak_id, pasal_induk_id, jenis_relasi, dibuat_oleh, dibuat_oleh_user_id)
         VALUES (?, ?, ?, \'manual\', ?)'
    );
    $stmt->execute([$anakId, $indukId, $jenis, hukum_current_user_id()]);
    $id = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_relasi_pasal', $id, 'create', null, $input);
    hukum_json_response(['success' => true, 'id' => $id], 201);
}

hukum_require_permission('hukum.pasal.update');
$id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id relasi wajib.'], 400);
$relation = dbFetchOne(
    'SELECT r.id, d.periode_id
     FROM hukum_relasi_pasal r
     JOIN hukum_pasal p ON p.id = r.pasal_anak_id
     JOIN hukum_dokumen d ON d.id = p.dokumen_id
     WHERE r.id = ?',
    [$id]
);
if (!$relation) hukum_json_response(['success' => false, 'message' => 'Relasi tidak ditemukan.'], 404);
hukum_require_document_period((int) $relation['periode_id']);
$pdo->prepare('DELETE FROM hukum_relasi_pasal WHERE id = ?')->execute([$id]);
hukum_audit($pdo, 'hukum_relasi_pasal', $id, 'delete', $relation, null);
hukum_json_response(['success' => true]);

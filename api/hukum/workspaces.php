<?php
require_once __DIR__ . '/_bootstrap.php';
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

hukum_require_permission('hukum.workspace.create');
$input = hukum_input();
$dokumenId = (int) ($input['dokumen_id'] ?? 0);
$judulPerubahan = trim((string) ($input['judul_perubahan'] ?? ''));
if ($judulPerubahan === '') {
    hukum_json_response(['success' => false, 'message' => 'judul_perubahan wajib.'], 400);
}
$pdo->beginTransaction();
$doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ? FOR UPDATE', [$dokumenId]);
if (!$doc) {
    $pdo->rollBack();
    hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
}
hukum_require_document_period((int) $doc['periode_id']);
$active = dbFetchOne(
    'SELECT id FROM hukum_workspace WHERE dokumen_id = ? AND status IN (\'aktif\', \'diajukan\') LIMIT 1 FOR UPDATE',
    [$dokumenId]
);
if ($active) {
    $pdo->rollBack();
    hukum_json_response(['success' => false, 'message' => 'Dokumen sudah memiliki workspace aktif.'], 409);
}
$stmt = $pdo->prepare(
    'INSERT INTO hukum_workspace (dokumen_id, judul_perubahan, tujuan, status, dibuat_oleh)
     VALUES (?, ?, ?, \'aktif\', ?)'
);
$stmt->execute([$dokumenId, $judulPerubahan, $input['tujuan'] ?? null, hukum_current_user_id()]);
$id = (int) $pdo->lastInsertId();
hukum_audit($pdo, 'hukum_workspace', $id, 'create', null, $input);
$pdo->commit();
hukum_json_response(['success' => true, 'id' => $id], 201);

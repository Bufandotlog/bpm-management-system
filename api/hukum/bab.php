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
        'SELECT * FROM hukum_bab WHERE dokumen_id = ? ORDER BY urutan, id', [$dokumenId]
    )]);
}

hukum_require_permission('hukum.document.update');
$input = hukum_input();
$dokumenId = (int) ($input['dokumen_id'] ?? 0);
$doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
hukum_require_document_period((int) $doc['periode_id']);
if ($doc['status'] !== 'draft') hukum_json_response(['success' => false, 'message' => 'BAB hanya dapat dibuat pada dokumen draft.'], 409);
foreach (['nomor_label', 'judul_bab', 'urutan'] as $field) {
    if (!isset($input[$field]) || trim((string) $input[$field]) === '') hukum_json_response(['success' => false, 'message' => "{$field} wajib."], 400);
}
$stmt = $pdo->prepare('INSERT INTO hukum_bab (dokumen_id, nomor_label, judul_bab, bagian_label, urutan) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$dokumenId, $input['nomor_label'], $input['judul_bab'], $input['bagian_label'] ?? null, (int) $input['urutan']]);
$id = (int) $pdo->lastInsertId();
hukum_audit($pdo, 'hukum_bab', $id, 'create', null, $input);
hukum_json_response(['success' => true, 'id' => $id], 201);

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
        'SELECT p.*, v.id AS active_version_id, v.hash_konten AS active_hash
         FROM hukum_pasal p
         LEFT JOIN hukum_pasal_versi v ON v.pasal_id = p.id AND v.status = \'committed\'
         WHERE p.dokumen_id = ? ORDER BY p.urutan, p.id',
        [$dokumenId]
    )]);
}

hukum_require_permission('hukum.pasal.create');
$input = hukum_input();
$dokumenId = (int) ($input['dokumen_id'] ?? 0);
$doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
hukum_require_document_period((int) $doc['periode_id']);
if ($doc['status'] !== 'draft') hukum_json_response(['success' => false, 'message' => 'Pasal hanya dapat dibuat pada dokumen draft.'], 409);
foreach (['nomor_label', 'urutan'] as $field) {
    if (!isset($input[$field]) || trim((string) $input[$field]) === '') hukum_json_response(['success' => false, 'message' => "{$field} wajib."], 400);
}
$babId = isset($input['bab_id']) ? (int) $input['bab_id'] : null;
if ($babId !== null && !dbFetchOne('SELECT id FROM hukum_bab WHERE id = ? AND dokumen_id = ?', [$babId, $dokumenId])) {
    hukum_json_response(['success' => false, 'message' => 'BAB bukan milik dokumen.'], 409);
}
$stmt = $pdo->prepare('INSERT INTO hukum_pasal (dokumen_id, bab_id, nomor_label, judul_pasal, urutan) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$dokumenId, $babId, $input['nomor_label'], $input['judul_pasal'] ?? null, (int) $input['urutan']]);
$id = (int) $pdo->lastInsertId();
hukum_audit($pdo, 'hukum_pasal', $id, 'create', null, $input);
hukum_json_response(['success' => true, 'id' => $id], 201);

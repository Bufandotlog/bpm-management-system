<?php
require_once __DIR__ . '/_bootstrap.php';
hukum_require_method(['GET']);
hukum_require_permission('hukum.view');
$pdo = getConnection();
$pasalId = (int) ($_GET['pasal_id'] ?? 0);
if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id wajib.'], 400);
$pasal = dbFetchOne(
    'SELECT p.id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
    [$pasalId]
);
if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
hukum_require_document_period((int) $pasal['periode_id']);
hukum_json_response(['success' => true, 'data' => dbFetchAll(
    'SELECT r.*, p.nomor_label AS pasal_asal_nomor, d.judul AS dokumen_tujuan
     FROM hukum_referensi_inline r
     JOIN hukum_pasal p ON p.id = r.pasal_asal_id
     JOIN hukum_dokumen d ON d.id = r.dokumen_tujuan_id
     WHERE r.pasal_asal_id = ? ORDER BY r.id',
    [$pasalId]
)]);

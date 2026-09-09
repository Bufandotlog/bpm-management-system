<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/pasal_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $documentId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($documentId > 0) {
        $doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ?', [$documentId]);
        if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        hukum_require_document_period((int) $doc['periode_id']);
        hukum_json_response(['success' => true, 'data' => dbFetchAll(
            'SELECT p.*, v.id AS active_version_id, v.hash_konten AS active_hash
             FROM hukum_pasal p
             LEFT JOIN hukum_pasal_versi v ON v.pasal_id = p.id AND v.status = \'committed\'
             WHERE p.dokumen_id = ? ORDER BY p.urutan, p.id',
            [$documentId]
        )]);
    }

    $pasalId = (int) ($_GET['pasal_id'] ?? 0);
    if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id atau dokumen_id wajib.'], 400);
    $pasal = dbFetchOne(
        'SELECT p.id, p.dokumen_id, p.nomor_label, p.judul_pasal, d.periode_id
         FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
        [$pasalId]
    );
    if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
    hukum_require_document_period((int) $pasal['periode_id']);
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT id, pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id, created_at, updated_at
         FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC',
        [$pasalId]
    )]);
}

$input = hukum_input();
try {
    if (isset($input['isi']) || !empty($input['workspace_id']) || isset($input['pasal_id'])) {
        $result = hukum_create_pasal_draft($pdo, $input);
    } else {
        $result = hukum_create_pasal($pdo, $input);
    }
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

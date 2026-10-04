<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/pasal_service.php';
require_once __DIR__ . '/deletions_service.php';

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
             LEFT JOIN hukum_pasal_versi v ON v.id = COALESCE(
                 (
                     SELECT gs.pasal_version_id
                     FROM hukum_graph_snapshot gs
                     JOIN hukum_commit c ON c.id = gs.commit_id AND c.status = \'aktif\'
                     WHERE gs.dokumen_id = p.dokumen_id
                       AND gs.pasal_id = p.id
                       AND gs.is_active = 1
                     ORDER BY c.id DESC
                     LIMIT 1
                 ),
                 (
                     SELECT MAX(previous.id)
                     FROM hukum_pasal_versi previous
                     WHERE previous.pasal_id = p.id
                       AND previous.status = \'committed\'
                 )
             )
             WHERE p.dokumen_id = ?
               AND (NOT EXISTS (SELECT 1 FROM hukum_commit c WHERE c.dokumen_id = p.dokumen_id AND c.status = \'aktif\')
                    OR EXISTS (
                      SELECT 1 FROM hukum_graph_snapshot gs
                      JOIN hukum_commit c ON c.id = gs.commit_id
                      WHERE c.dokumen_id = p.dokumen_id AND c.status = \'aktif\'
                        AND gs.pasal_id = p.id AND gs.is_active = 1
                    )
                    OR EXISTS (
                      SELECT 1 FROM hukum_pasal_versi pv
                      JOIN hukum_workspace w ON w.id = pv.workspace_id
                      WHERE pv.pasal_id = p.id
                        AND w.dokumen_id = p.dokumen_id
                        AND w.status IN (\'aktif\', \'diajukan\', \'siap_commit\')
                        AND pv.status IN (\'draft\', \'staged\', \'rejected\')
                    ))
             ORDER BY p.urutan, p.id',
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
    $activeCommitId = hukum_deletion_active_commit($pdo, (int) $pasal['dokumen_id']);
    if ($activeCommitId !== null && !hukum_pasal_is_active($pdo, (int) $pasal['dokumen_id'], $pasalId)) {
        $workspaceVersion = dbFetchOne(
            'SELECT pv.id
             FROM hukum_pasal_versi pv
             JOIN hukum_workspace w ON w.id = pv.workspace_id
             WHERE pv.pasal_id = ?
               AND w.dokumen_id = ?
               AND w.status IN (\'aktif\', \'diajukan\', \'siap_commit\')
               AND pv.status IN (\'draft\', \'staged\', \'rejected\')
               AND NOT EXISTS (
                   SELECT 1 FROM hukum_graph_snapshot gs
                   WHERE gs.commit_id = ? AND gs.pasal_id = pv.pasal_id AND gs.is_active = 0
               )
             LIMIT 1',
            [$pasalId, (int) $pasal['dokumen_id'], $activeCommitId]
        );
        if ($workspaceVersion === null) {
            hukum_json_response(['success' => false, 'message' => 'Pasal tidak lagi menjadi bagian dari dokumen aktif.'], 404);
        }
    }
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT id, pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id, created_at, updated_at
         FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC',
        [$pasalId]
    )]);
}

$input = hukum_input();
try {
    if (isset($input['isi'])) {
        $result = hukum_create_pasal_draft($pdo, $input);
    } elseif (isset($input['pasal_id']) && (isset($input['nomor_label']) || isset($input['judul_pasal'])) && !isset($input['urutan'])) {
        $result = hukum_update_pasal_metadata($pdo, $input);
    } elseif (isset($input['pasal_id'])) {
        $result = hukum_create_pasal_draft($pdo, $input);
    } else {
        $result = hukum_create_pasal($pdo, $input);
    }
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $error) {
    hukum_json_response(['success' => false, 'message' => $error->getMessage()], $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 500);
}

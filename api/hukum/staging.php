<?php
require_once __DIR__ . '/_bootstrap.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $stagingId = (int) ($_GET['id'] ?? 0);
    if ($stagingId > 0) {
        $row = dbFetchOne(
            'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.periode_id, d.judul
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             WHERE s.id = ?',
            [$stagingId]
        );
        if (!$row) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
        hukum_require_document_period((int) $row['periode_id']);
        $row['versi'] = dbFetchAll(
            'SELECT pv.id, pv.pasal_id, pv.hash_konten, pv.status, pv.isi
             FROM hukum_staging_versi sv
             JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
             WHERE sv.staging_id = ? ORDER BY pv.pasal_id',
            [$stagingId]
        );
        hukum_json_response(['success' => true, 'data' => $row]);
    }

    $user = hukum_current_user();
    $sql = 'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id
            FROM hukum_staging s
            JOIN hukum_workspace w ON w.id = s.workspace_id
            JOIN hukum_dokumen d ON d.id = w.dokumen_id';
    $params = [];
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' WHERE d.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY s.diajukan_at DESC, s.id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

hukum_require_permission('hukum.workspace.submit');
$input = hukum_input();
$workspaceId = (int) ($input['workspace_id'] ?? 0);
$workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
if (!$workspace || $workspace['status'] !== 'aktif') {
    hukum_json_response(['success' => false, 'message' => 'Workspace tidak ditemukan atau tidak aktif.'], 409);
}
$doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ?', [$workspace['dokumen_id']]);
hukum_require_document_period((int) $doc['periode_id']);

$versionIds = $input['pasal_versi_ids'] ?? [];
if (!is_array($versionIds) || $versionIds === []) {
    hukum_json_response(['success' => false, 'message' => 'pasal_versi_ids wajib berupa array.'], 400);
}
$versionIds = array_values(array_unique(array_map('intval', $versionIds)));
$pdo->beginTransaction();
$versions = [];
foreach ($versionIds as $versionId) {
    $version = dbFetchOne(
        'SELECT pv.id, pv.pasal_id, pv.status, p.dokumen_id
         FROM hukum_pasal_versi pv
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         WHERE pv.id = ? AND pv.workspace_id = ? FOR UPDATE',
        [$versionId, $workspaceId]
    );
    if (!$version || $version['status'] !== 'draft' || (int) $version['dokumen_id'] !== (int) $workspace['dokumen_id']) {
        $pdo->rollBack();
        hukum_json_response(['success' => false, 'message' => "Versi {$versionId} tidak valid untuk staging."], 409);
    }
    $versions[] = $version;
}
$existing = dbFetchOne(
    'SELECT id FROM hukum_staging WHERE workspace_id = ? AND status = \'menunggu_review\' LIMIT 1 FOR UPDATE',
    [$workspaceId]
);
if ($existing) {
    $pdo->rollBack();
    hukum_json_response(['success' => false, 'message' => 'Workspace sudah memiliki staging yang menunggu review.'], 409);
}
$stmt = $pdo->prepare(
    'INSERT INTO hukum_staging (workspace_id, status, diajukan_oleh) VALUES (?, \'menunggu_review\', ?)'
);
$stmt->execute([$workspaceId, hukum_current_user_id()]);
$stagingId = (int) $pdo->lastInsertId();
$link = $pdo->prepare('INSERT INTO hukum_staging_versi (staging_id, pasal_versi_id) VALUES (?, ?)');
$mark = $pdo->prepare('UPDATE hukum_pasal_versi SET status = \'staged\' WHERE id = ?');
foreach ($versions as $version) {
    $link->execute([$stagingId, $version['id']]);
    $mark->execute([$version['id']]);
}
$pdo->prepare('UPDATE hukum_workspace SET status = \'diajukan\' WHERE id = ?')->execute([$workspaceId]);
hukum_audit($pdo, 'hukum_staging', $stagingId, 'submit', null, ['workspace_id' => $workspaceId, 'pasal_versi_ids' => $versionIds]);
$pdo->commit();
hukum_json_response(['success' => true, 'id' => $stagingId, 'status' => 'menunggu_review'], 201);

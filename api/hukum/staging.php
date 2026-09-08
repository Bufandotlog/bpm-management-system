<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/staging_service.php';
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
$versionIds = hukum_staging_normalize_version_ids($input['pasal_versi_ids'] ?? []);
if ($versionIds === []) {
    hukum_json_response(['success' => false, 'message' => 'pasal_versi_ids wajib berupa array.'], 400);
}

$workspace = dbFetchOne(
    'SELECT ws.id, ws.dokumen_id, ws.status, d.periode_id
     FROM hukum_workspace ws
     JOIN hukum_dokumen d ON d.id = ws.dokumen_id
     WHERE ws.id = ? LIMIT 1',
    [$workspaceId]
);
if (!$workspace || (string) $workspace['status'] !== 'aktif') {
    hukum_json_response(['success' => false, 'message' => 'Workspace tidak ditemukan atau tidak aktif.'], 409);
}
hukum_require_document_period((int) $workspace['periode_id']);

$pdo->beginTransaction();
try {
    $submission = hukum_staging_validate_submission($pdo, $workspaceId, $versionIds);
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_staging (workspace_id, status, diajukan_oleh) VALUES (?, \'menunggu_review\', ?)'
    );
    $stmt->execute([$workspaceId, hukum_current_user_id()]);
    $stagingId = (int) $pdo->lastInsertId();

    $approvalInsert = $pdo->prepare(
        'INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status, note, approved_at, rejected_at)
         VALUES (?, ?, ?, \'menunggu\', NULL, NULL, NULL)'
    );
    foreach (['komisi_i', 'ketua_umum'] as $peran) {
        $approvalInsert->execute([$stagingId, hukum_current_user_id(), $peran]);
    }

    $link = $pdo->prepare('INSERT INTO hukum_staging_versi (staging_id, pasal_versi_id) VALUES (?, ?)');
    $mark = $pdo->prepare('UPDATE hukum_pasal_versi SET status = \'staged\' WHERE id = ?');
    foreach ($submission['versions'] as $version) {
        $link->execute([$stagingId, (int) $version['id']]);
        $mark->execute([(int) $version['id']]);
    }

    $impact = $submission['impact'];
    if (!empty($impact['relations'])) {
        $notificationCheck = $pdo->prepare(
            'SELECT id FROM hukum_notifikasi WHERE relasi_id = ? AND dipicu_oleh_versi_id = ? AND status = \'perlu_ditinjau\' LIMIT 1'
        );
        $notificationInsert = $pdo->prepare(
            'INSERT INTO hukum_notifikasi (relasi_id, pasal_anak_id, pasal_induk_id, dipicu_oleh_versi_id, status)
             VALUES (?, ?, ?, ?, \'perlu_ditinjau\')'
        );
        foreach ($impact['relations'] as $relation) {
            $sourceVersionId = 0;
            foreach ($submission['versions'] as $version) {
                if ((int) $version['pasal_id'] === (int) $relation['pasal_anak_id'] || (int) $version['pasal_id'] === (int) $relation['pasal_induk_id']) {
                    $sourceVersionId = (int) $version['id'];
                    break;
                }
            }
            if ($sourceVersionId <= 0) {
                continue;
            }
            $notificationCheck->execute([(int) $relation['id'], $sourceVersionId]);
            if ($notificationCheck->fetchColumn() !== false) {
                continue;
            }
            $notificationInsert->execute([
                (int) $relation['id'],
                (int) $relation['pasal_anak_id'],
                (int) $relation['pasal_induk_id'],
                $sourceVersionId,
            ]);
        }
    }

    $pdo->prepare('UPDATE hukum_workspace SET status = \'diajukan\' WHERE id = ?')->execute([$workspaceId]);
    hukum_audit($pdo, 'hukum_staging', $stagingId, 'submit', null, [
        'workspace_id' => $workspaceId,
        'pasal_versi_ids' => $versionIds,
        'impact_count' => count($impact['relations'] ?? []),
        'self_commit_valid' => $submission['self_commit_valid'],
    ]);
    $pdo->commit();
    hukum_json_response([
        'success' => true,
        'id' => $stagingId,
        'status' => 'menunggu_review',
        'impact_count' => count($impact['relations'] ?? []),
        'self_commit_valid' => $submission['self_commit_valid'],
    ], 201);
} catch (Throwable $exception) {
    $pdo->rollBack();
    $message = $exception->getMessage();
    $code = $exception->getCode();
    $status = is_numeric($code) && (int) $code > 0 ? (int) $code : 409;
    hukum_json_response(['success' => false, 'message' => $message], $status);
}

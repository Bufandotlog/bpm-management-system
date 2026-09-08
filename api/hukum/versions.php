<?php
require_once __DIR__ . '/_bootstrap.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();
$pasalId = (int) ($_GET['pasal_id'] ?? 0);
if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id wajib.'], 400);
$pasal = dbFetchOne(
    'SELECT p.id, p.dokumen_id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
    [$pasalId]
);
if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
hukum_require_document_period((int) $pasal['periode_id']);

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    hukum_json_response(['success' => true, 'data' => dbFetchAll(
        'SELECT id, pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id, created_at, updated_at
         FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC',
        [$pasalId]
    )]);
}

hukum_require_permission('hukum.pasal.update');
$input = hukum_input();
$workspaceId = (int) ($input['workspace_id'] ?? 0);
$workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $pasal['dokumen_id'] || $workspace['status'] !== 'aktif') {
    hukum_json_response(['success' => false, 'message' => 'Workspace aktif tidak valid untuk pasal ini.'], 409);
}
$isi = hukum_decode_json_field($input['isi'] ?? null, 'isi');
$canonical = hukum_canonical_json($isi);
$hash = hash('sha256', $canonical);
$parentId = isset($input['dibuat_dari_versi_id']) ? (int) $input['dibuat_dari_versi_id'] : null;
$stmt = $pdo->prepare(
    'INSERT INTO hukum_pasal_versi
     (pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id)
     VALUES (?, ?, ?, ?, \'draft\', ?, ?)'
);
$stmt->execute([$pasalId, $workspaceId, $canonical, $hash, hukum_current_user_id(), $parentId]);
$id = (int) $pdo->lastInsertId();
hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $isi);
hukum_audit($pdo, 'hukum_pasal_versi', $id, 'create_draft', null, ['pasal_id' => $pasalId, 'hash_konten' => $hash]);
hukum_json_response(['success' => true, 'id' => $id, 'hash_konten' => $hash], 201);

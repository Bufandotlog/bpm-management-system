<?php
require_once __DIR__ . '/_bootstrap.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $dokumenId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($dokumenId > 0) {
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

    $pasalId = (int) ($_GET['pasal_id'] ?? 0);
    if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id atau dokumen_id wajib.'], 400);
    $pasal = dbFetchOne(
        'SELECT p.id, p.dokumen_id, p.nomor_label, p.judul_pasal, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
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
if (isset($input['isi']) || !empty($input['workspace_id']) || isset($input['pasal_id'])) {
    hukum_require_permission('hukum.pasal.update');
    $pasalId = (int) ($input['pasal_id'] ?? 0);
    if ($pasalId <= 0) hukum_json_response(['success' => false, 'message' => 'pasal_id wajib.'], 400);
    $pasal = dbFetchOne(
        'SELECT p.id, p.dokumen_id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
        [$pasalId]
    );
    if (!$pasal) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);
    hukum_require_document_period((int) $pasal['periode_id']);

    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
    if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $pasal['dokumen_id'] || $workspace['status'] !== 'aktif') {
        hukum_json_response(['success' => false, 'message' => 'Workspace aktif tidak valid untuk pasal ini.'], 409);
    }

    $latestVersion = dbFetchOne(
        'SELECT id, updated_at FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
        [$pasalId]
    );
    $expectedVersion = isset($input['expected_version']) ? (int) $input['expected_version'] : null;
    if ($expectedVersion !== null && $latestVersion !== null && (int) $latestVersion['id'] !== $expectedVersion) {
        hukum_json_response([
            'success' => false,
            'code' => 'STALE_VERSION',
            'message' => 'Versi konten sudah berubah. Silakan muat ulang editor dan simpan ulang.',
            'latest_version_id' => (int) ($latestVersion['id'] ?? 0),
            'current_updated_at' => $latestVersion['updated_at'] ?? null,
        ], 409);
    }

    $expectedUpdatedAt = isset($input['expected_updated_at']) ? trim((string) $input['expected_updated_at']) : null;
    if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '' && $latestVersion !== null && !empty($latestVersion['updated_at'])) {
        if ((string) $latestVersion['updated_at'] !== $expectedUpdatedAt) {
            hukum_json_response([
                'success' => false,
                'code' => 'STALE_VERSION',
                'message' => 'Dokumen telah diperbarui oleh editor lain. Silakan refresh untuk melanjutkan.',
                'latest_version_id' => (int) $latestVersion['id'],
                'current_updated_at' => $latestVersion['updated_at'],
            ], 409);
        }
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
    hukum_audit($pdo, 'hukum_pasal_versi', $id, 'create_draft', null, ['pasal_id' => $pasalId, 'hash_konten' => $hash, 'expected_version' => $expectedVersion]);
    hukum_json_response(['success' => true, 'id' => $id, 'hash_konten' => $hash, 'latest_version_id' => $id], 201);
}

hukum_require_permission('hukum.pasal.create');
$dokumenId = (int) ($input['dokumen_id'] ?? 0);
$doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$dokumenId]);
if (!$doc) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
hukum_require_document_period((int) $doc['periode_id']);
if ($doc['status'] !== 'draft') hukum_json_response(['success' => false, 'message' => 'Pasal hanya dapat dibuat pada dokumen draft.'], 409);
foreach (['nomor_label', 'urutan'] as $field) {
    if (!isset($input[$field]) || trim((string) $input[$field]) === '') hukum_json_response(['success' => false, 'message' => "{$field} wajib."], 400);
}
$urutan = (int) $input['urutan'];
if ($urutan <= 0) hukum_json_response(['success' => false, 'message' => 'urutan harus lebih dari 0.'], 400);
$nomorLabel = trim((string) $input['nomor_label']);
if (dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1', [$dokumenId, $nomorLabel])) {
    hukum_json_response(['success' => false, 'message' => 'Nomor pasal duplikat untuk dokumen ini.'], 409);
}
if (dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND urutan = ? LIMIT 1', [$dokumenId, $urutan])) {
    hukum_json_response(['success' => false, 'message' => 'Urutan pasal sudah digunakan.'], 409);
}
$babId = isset($input['bab_id']) ? (int) $input['bab_id'] : null;
if ($babId !== null && !dbFetchOne('SELECT id FROM hukum_bab WHERE id = ? AND dokumen_id = ?', [$babId, $dokumenId])) {
    hukum_json_response(['success' => false, 'message' => 'BAB bukan milik dokumen.'], 409);
}
$stmt = $pdo->prepare('INSERT INTO hukum_pasal (dokumen_id, bab_id, nomor_label, judul_pasal, urutan) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$dokumenId, $babId, $nomorLabel, trim((string) ($input['judul_pasal'] ?? '')) !== '' ? trim((string) $input['judul_pasal']) : null, $urutan]);
$id = (int) $pdo->lastInsertId();
hukum_audit($pdo, 'hukum_pasal', $id, 'create', null, $input);
hukum_json_response(['success' => true, 'id' => $id], 201);

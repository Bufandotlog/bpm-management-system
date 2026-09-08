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
        'SELECT id, dokumen_id, staging_id, parent_commit_id, hash_commit, forum_tipe, tanggal_forum,
                status, dibuat_oleh, created_at, replaced_at
         FROM hukum_commit WHERE dokumen_id = ? ORDER BY created_at DESC, id DESC',
        [$dokumenId]
    )]);
}

hukum_require_permission('hukum.commit.create');
$input = hukum_input();
$stagingId = (int) ($input['staging_id'] ?? 0);
foreach (['forum_tipe', 'tanggal_forum'] as $field) {
    if (trim((string) ($input[$field] ?? '')) === '') hukum_json_response(['success' => false, 'message' => "{$field} wajib."], 400);
}

$staging = dbFetchOne(
    'SELECT s.*, w.dokumen_id, d.periode_id
     FROM hukum_staging s
     JOIN hukum_workspace w ON w.id = s.workspace_id
     JOIN hukum_dokumen d ON d.id = w.dokumen_id
     WHERE s.id = ?',
    [$stagingId]
);
if (!$staging) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
hukum_require_document_period((int) $staging['periode_id']);
if ($staging['status'] !== 'disetujui') {
    hukum_json_response(['success' => false, 'message' => 'Staging harus disetujui sebelum commit.'], 409);
}

$pdo->beginTransaction();
$active = dbFetchOne(
    'SELECT id, hash_commit FROM hukum_commit WHERE dokumen_id = ? AND status = \'aktif\' ORDER BY id DESC LIMIT 1 FOR UPDATE',
    [$staging['dokumen_id']]
);
$links = dbFetchAll(
    'SELECT pv.id, pv.pasal_id, pv.hash_konten, pv.isi
     FROM hukum_staging_versi sv
     JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
     WHERE sv.staging_id = ? AND pv.status = \'staged\' FOR UPDATE',
    [$stagingId]
);
if ($links === []) {
    $pdo->rollBack();
    hukum_json_response(['success' => false, 'message' => 'Staging tidak memiliki versi staged.'], 409);
}
$stagedByPasal = [];
foreach ($links as $link) {
    $stagedByPasal[(int) $link['pasal_id']] = $link;
}
$pasals = dbFetchAll(
    'SELECT id FROM hukum_pasal WHERE dokumen_id = ? ORDER BY urutan, id FOR UPDATE',
    [$staging['dokumen_id']]
);
$tree = [];
foreach ($pasals as $pasal) {
    $pasalId = (int) $pasal['id'];
    $link = $stagedByPasal[$pasalId] ?? dbFetchOne(
        'SELECT id, pasal_id, hash_konten, isi
         FROM hukum_pasal_versi
         WHERE pasal_id = ? AND status IN (\'committed\', \'replaced\')
         ORDER BY id DESC LIMIT 1',
        [$pasalId]
    );
    if (!$link) {
        continue;
    }
    $tree[] = [
        'pasal_id' => $pasalId,
        'versi_id' => (int) $link['id'],
        'hash_konten' => $link['hash_konten'],
        'isi' => json_decode($link['isi'], true),
    ];
}
usort($tree, static fn (array $a, array $b): int => $a['pasal_id'] <=> $b['pasal_id']);
$snapshot = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$hash = hash('sha256', json_encode([
    'parent_hash' => $active['hash_commit'] ?? null,
    'dokumen_id' => (int) $staging['dokumen_id'],
    'forum_tipe' => trim((string) $input['forum_tipe']),
    'tanggal_forum' => $input['tanggal_forum'],
    'tree' => $tree,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$stmt = $pdo->prepare(
    'INSERT INTO hukum_commit
     (dokumen_id, staging_id, parent_commit_id, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, status, dibuat_oleh)
     VALUES (?, ?, ?, ?, ?, ?, ?, \'aktif\', ?)'
);
$stmt->execute([
    $staging['dokumen_id'], $stagingId, $active['id'] ?? null, $hash, $snapshot,
    trim((string) $input['forum_tipe']), $input['tanggal_forum'], hukum_current_user_id(),
]);
$commitId = (int) $pdo->lastInsertId();
if ($active) {
    $pdo->prepare('UPDATE hukum_commit SET status = \'digantikan\', replaced_at = NOW() WHERE id = ?')
        ->execute([$active['id']]);
}
$markCommitted = $pdo->prepare(
    'UPDATE hukum_pasal_versi SET status = \'committed\' WHERE id = ? AND status = \'staged\''
);
foreach ($links as $link) {
    $markCommitted->execute([$link['id']]);
}
$notify = $pdo->prepare(
    'INSERT INTO hukum_notifikasi
     (relasi_id, pasal_anak_id, pasal_induk_id, dipicu_oleh_versi_id, status)
     SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, ?, \'perlu_ditinjau\'
     FROM hukum_relasi_pasal r
     WHERE r.pasal_induk_id = ?
       AND NOT EXISTS (
           SELECT 1 FROM hukum_notifikasi n
           WHERE n.relasi_id = r.id
             AND n.dipicu_oleh_versi_id = ?
             AND n.status = \'perlu_ditinjau\'
       )'
);
foreach ($links as $link) {
    $notify->execute([(int) $link['id'], (int) $link['pasal_id'], (int) $link['id']]);
}
$pdo->prepare('UPDATE hukum_staging SET status = \'disetujui\' WHERE id = ?')->execute([$stagingId]);
$pdo->prepare('UPDATE hukum_workspace SET status = \'ditutup\', closed_at = NOW(), closed_by = ? WHERE id = ?')
    ->execute([hukum_current_user_id(), $staging['workspace_id']]);
$pdo->prepare('UPDATE hukum_dokumen SET status = \'aktif\', published_at = COALESCE(published_at, NOW()), diperbarui_oleh = ? WHERE id = ?')
    ->execute([hukum_current_user_id(), $staging['dokumen_id']]);
hukum_audit($pdo, 'hukum_commit', $commitId, 'commit', null, ['hash_commit' => $hash, 'staging_id' => $stagingId]);
$pdo->commit();
hukum_json_response(['success' => true, 'id' => $commitId, 'hash_commit' => $hash], 201);

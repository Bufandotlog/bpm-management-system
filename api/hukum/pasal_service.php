<?php
declare(strict_types=1);

require_once __DIR__ . '/document_service.php';

function hukum_create_pasal(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.pasal.create');
    $documentId = (int) ($input['dokumen_id'] ?? 0);
    $doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$documentId]);
    if (!$doc) {
        throw new RuntimeException('Dokumen tidak ditemukan.', 404);
    }
    hukum_require_service_period((int) $doc['periode_id']);
    if ($doc['status'] !== 'draft') {
        throw new RuntimeException('Pasal hanya dapat dibuat pada dokumen draft.', 409);
    }
    foreach (['nomor_label', 'urutan'] as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException("{$field} wajib.", 400);
        }
    }
    $order = (int) $input['urutan'];
    $label = trim((string) $input['nomor_label']);
    if ($order <= 0) {
        throw new InvalidArgumentException('urutan harus lebih dari 0.', 400);
    }
    if (dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1', [$documentId, $label])
        || dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND urutan = ? LIMIT 1', [$documentId, $order])) {
        throw new RuntimeException('Nomor atau urutan pasal sudah digunakan.', 409);
    }
    $babId = isset($input['bab_id']) ? (int) $input['bab_id'] : null;
    if ($babId !== null && !dbFetchOne('SELECT id FROM hukum_bab WHERE id = ? AND dokumen_id = ?', [$babId, $documentId])) {
        throw new RuntimeException('BAB bukan milik dokumen.', 409);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_pasal (dokumen_id, bab_id, nomor_label, judul_pasal, urutan) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $documentId, $babId, $label,
        trim((string) ($input['judul_pasal'] ?? '')) ?: null,
        $order,
    ]);
    $id = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_pasal', $id, 'create', null, $input);
    return ['id' => $id];
}

function hukum_create_pasal_draft(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.pasal.update');
    $pasalId = (int) ($input['pasal_id'] ?? 0);
    $pasal = dbFetchOne(
        'SELECT p.id, p.dokumen_id, d.periode_id FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
        [$pasalId]
    );
    if (!$pasal) {
        throw new RuntimeException('Pasal tidak ditemukan.', 404);
    }
    hukum_require_service_period((int) $pasal['periode_id']);
    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
    if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $pasal['dokumen_id'] || $workspace['status'] !== 'aktif') {
        throw new RuntimeException('Workspace aktif tidak valid untuk pasal ini.', 409);
    }
    $latest = dbFetchOne(
        'SELECT id, updated_at FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
        [$pasalId]
    );
    $expected = isset($input['expected_version']) ? (int) $input['expected_version'] : null;
    if ($expected !== null && $latest !== null && (int) $latest['id'] !== $expected) {
        throw new RuntimeException('Versi konten sudah berubah.', 409);
    }
    $contents = hukum_decode_json_field($input['isi'] ?? null, 'isi');
    $canonical = hukum_canonical_json($contents);
    $hash = hash('sha256', $canonical);
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_pasal_versi
         (pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id)
         VALUES (?, ?, ?, ?, \'draft\', ?, ?)'
    );
    $stmt->execute([
        $pasalId, $workspaceId, $canonical, $hash, hukum_current_user_id(),
        isset($input['dibuat_dari_versi_id']) ? (int) $input['dibuat_dari_versi_id'] : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $contents);
    hukum_audit($pdo, 'hukum_pasal_versi', $id, 'create_draft', null, [
        'pasal_id' => $pasalId,
        'hash_konten' => $hash,
    ]);
    return ['id' => $id, 'hash_konten' => $hash, 'latest_version_id' => $id];
}

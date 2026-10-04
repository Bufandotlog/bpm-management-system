<?php
declare(strict_types=1);

require_once __DIR__ . '/document_service.php';

function hukum_create_pasal(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.pasal.create');
    $workspaceId = isset($input['workspace_id']) ? (int) $input['workspace_id'] : 0;
    if ($workspaceId > 0) {
        $ws = dbFetchOne('SELECT dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
        if (!$ws) throw new RuntimeException('Workspace tidak ditemukan.', 404);
        $documentId = (int) $ws['dokumen_id'];
        $doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$documentId]);
        if (!$doc) throw new RuntimeException('Dokumen tidak ditemukan.', 404);
        if (!in_array((string) $ws['status'], ['aktif', 'diajukan'], true)) {
            throw new RuntimeException('Workspace tidak aktif untuk membuat Pasal.', 409);
        }
        if (isset($input['dokumen_id']) && (int) $input['dokumen_id'] !== $documentId) {
            throw new RuntimeException('Dokumen tidak sesuai dengan workspace.', 409);
        }
        hukum_require_service_period((int) $doc['periode_id']);
    } else {
        $documentId = (int) ($input['dokumen_id'] ?? 0);
        $doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$documentId]);
        if (!$doc) {
            throw new RuntimeException('Dokumen tidak ditemukan.', 404);
        }
        hukum_require_service_period((int) $doc['periode_id']);
        if ($doc['status'] !== 'draft') {
            throw new RuntimeException('Pasal hanya dapat dibuat pada dokumen draft.', 409);
        }
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
    if (dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1', [$documentId, $label])) {
        throw new RuntimeException("Nomor pasal '{$label}' sudah digunakan dalam dokumen ini.", 409);
    }
    if (dbFetchOne('SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND urutan = ? LIMIT 1', [$documentId, $order])) {
        throw new RuntimeException("Urutan pasal {$order} sudah digunakan dalam dokumen ini.", 409);
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

function hukum_update_pasal_metadata(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.pasal.update');
    $pasalId = (int) ($input['pasal_id'] ?? 0);
    $pasal = dbFetchOne(
        'SELECT p.id, p.dokumen_id, p.nomor_label, p.judul_pasal, d.periode_id
         FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id WHERE p.id = ?',
        [$pasalId]
    );
    if (!$pasal) {
        throw new RuntimeException('Pasal tidak ditemukan.', 404);
    }
    hukum_require_service_period((int) $pasal['periode_id']);

    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
    if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $pasal['dokumen_id']
        || !in_array((string) $workspace['status'], ['aktif', 'diajukan'], true)) {
        throw new RuntimeException('Workspace tidak valid untuk pasal ini.', 409);
    }

    $newLabel = isset($input['nomor_label']) ? trim((string) $input['nomor_label']) : null;
    $newJudul = isset($input['judul_pasal']) ? trim((string) $input['judul_pasal']) : null;

    $changed = false;
    $oldValues = ['nomor_label' => $pasal['nomor_label'], 'judul_pasal' => $pasal['judul_pasal']];

    if ($newLabel !== null && $newLabel !== '' && $newLabel !== (string) $pasal['nomor_label']) {
        $duplicate = dbFetchOne(
            'SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND nomor_label = ? AND id <> ? LIMIT 1',
            [(int) $pasal['dokumen_id'], $newLabel, $pasalId]
        );
        if ($duplicate) {
            throw new RuntimeException("Nomor pasal '{$newLabel}' sudah digunakan dalam dokumen ini.", 409);
        }
        $pdo->prepare('UPDATE hukum_pasal SET nomor_label = ? WHERE id = ?')->execute([$newLabel, $pasalId]);
        $changed = true;
    }

    if ($newJudul !== null && $newJudul !== (string) ($pasal['judul_pasal'] ?? '')) {
        $pdo->prepare('UPDATE hukum_pasal SET judul_pasal = ? WHERE id = ?')->execute([$newJudul ?: null, $pasalId]);
        $changed = true;
    }

    if ($changed) {
        hukum_audit($pdo, 'hukum_pasal', $pasalId, 'update_metadata', $oldValues, [
            'nomor_label' => $newLabel ?? $pasal['nomor_label'],
            'judul_pasal' => $newJudul ?? $pasal['judul_pasal'],
        ]);
    }

    return ['id' => $pasalId, 'updated' => $changed];
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
    if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $pasal['dokumen_id']
        || !in_array((string) $workspace['status'], ['aktif', 'diajukan'], true)) {
        throw new RuntimeException('Workspace tidak valid untuk pasal ini.', 409);
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
    $existing = dbFetchOne(
        'SELECT id, workspace_id, status, rejected_at, rejected_by, rejection_reason
         FROM hukum_pasal_versi WHERE pasal_id = ? AND hash_konten = ? LIMIT 1',
        [$pasalId, $hash]
    );
    if ($existing !== null) {
        if ((string) $existing['status'] === 'committed') {
            return [
                'id' => (int) $existing['id'],
                'hash_konten' => $hash,
                'latest_version_id' => (int) $existing['id'],
                'reused' => true,
                'status' => 'committed',
            ];
        }
        if ((string) $existing['status'] === 'rejected') {
            $updated = $pdo->prepare(
                'UPDATE hukum_pasal_versi SET status = \'draft\', workspace_id = ? WHERE id = ? AND status = \'rejected\''
            );
            $updated->execute([$workspaceId, (int) $existing['id']]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Status versi berubah. Muat ulang dokumen lalu coba lagi.', 409);
            }
            hukum_audit($pdo, 'hukum_pasal_versi', (int) $existing['id'], 'reopen_rejected_draft', [
                'status' => 'rejected',
                'workspace_id' => $existing['workspace_id'],
                'rejected_at' => $existing['rejected_at'],
                'rejected_by' => $existing['rejected_by'] !== null ? (int) $existing['rejected_by'] : null,
                'rejection_reason' => $existing['rejection_reason'],
            ], [
                'status' => 'draft',
                'workspace_id' => $workspaceId,
                'rejection_history_preserved' => true,
            ]);
            hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $contents);
            return [
                'id' => (int) $existing['id'],
                'hash_konten' => $hash,
                'latest_version_id' => (int) $existing['id'],
                'reused' => true,
                'reopened' => true,
                'status' => 'draft',
            ];
        }
        if ((int) $existing['workspace_id'] !== $workspaceId) {
            throw new RuntimeException(
                'Konten identik sudah tercatat pada versi dengan status ' . (string) $existing['status'] . '.',
                409
            );
        }
        if (!in_array((string) $existing['status'], ['draft', 'staged'], true)) {
            throw new RuntimeException(
                'Konten identik sudah tercatat pada versi dengan status ' . (string) $existing['status'] . '.',
                409
            );
        }
        hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $contents);
        return [
            'id' => (int) $existing['id'],
            'hash_konten' => $hash,
            'latest_version_id' => (int) $existing['id'],
            'reused' => true,
            'status' => (string) $existing['status'],
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO hukum_pasal_versi
         (pasal_id, workspace_id, isi, hash_konten, status, dibuat_oleh, dibuat_dari_versi_id)
         VALUES (?, ?, ?, ?, \'draft\', ?, ?)'
    );
    try {
        $stmt->execute([
            $pasalId, $workspaceId, $canonical, $hash, hukum_current_user_id(),
            isset($input['dibuat_dari_versi_id']) ? (int) $input['dibuat_dari_versi_id'] : null,
        ]);
    } catch (PDOException $error) {
        $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $isUniqueViolation = $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 1062], true));
        if (!$isUniqueViolation) {
            throw $error;
        }

        $existing = dbFetchOne(
            'SELECT id, workspace_id, status FROM hukum_pasal_versi WHERE pasal_id = ? AND hash_konten = ? LIMIT 1',
            [$pasalId, $hash]
        );
        if ($existing === null
            || (int) $existing['workspace_id'] !== $workspaceId
            || !in_array((string) $existing['status'], ['draft', 'staged'], true)) {
            throw $error;
        }
        hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $contents);
        return [
            'id' => (int) $existing['id'],
            'hash_konten' => $hash,
            'latest_version_id' => (int) $existing['id'],
            'reused' => true,
        ];
    }
    $id = (int) $pdo->lastInsertId();
    hukum_sync_inline_references($pdo, $pasalId, (int) $pasal['dokumen_id'], $contents);
    hukum_audit($pdo, 'hukum_pasal_versi', $id, 'create_draft', null, [
        'pasal_id' => $pasalId,
        'hash_konten' => $hash,
    ]);
    return ['id' => $id, 'hash_konten' => $hash, 'latest_version_id' => $id, 'reused' => false];
}

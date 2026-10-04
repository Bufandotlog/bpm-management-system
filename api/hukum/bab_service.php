<?php
declare(strict_types=1);

require_once __DIR__ . '/document_service.php';

function hukum_create_bab(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.document.update');
    $workspaceId = isset($input['workspace_id']) ? (int) $input['workspace_id'] : 0;
    if ($workspaceId > 0) {
        $ws = dbFetchOne('SELECT dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
        if (!$ws) throw new RuntimeException('Workspace tidak ditemukan.', 404);
        $documentId = (int) $ws['dokumen_id'];
        $doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$documentId]);
        if (!$doc) throw new RuntimeException('Dokumen tidak ditemukan.', 404);
        if (!in_array((string) $ws['status'], ['aktif', 'diajukan'], true)) {
            throw new RuntimeException('Workspace tidak aktif untuk membuat BAB.', 409);
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
            throw new RuntimeException('BAB hanya dapat dibuat pada dokumen draft.', 409);
        }
    }
    foreach (['nomor_label', 'judul_bab', 'urutan'] as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException("{$field} wajib.", 400);
        }
    }
    $order = (int) $input['urutan'];
    if ($order <= 0) {
        throw new InvalidArgumentException('urutan harus lebih dari 0.', 400);
    }
    $label = trim((string) $input['nomor_label']);
    if (dbFetchOne('SELECT id FROM hukum_bab WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1', [$documentId, $label])
        || dbFetchOne('SELECT id FROM hukum_bab WHERE dokumen_id = ? AND urutan = ? LIMIT 1', [$documentId, $order])) {
        throw new RuntimeException('Nomor atau urutan BAB sudah digunakan.', 409);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_bab (dokumen_id, nomor_label, judul_bab, bagian_label, urutan) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$documentId, $label, trim((string) $input['judul_bab']), $input['bagian_label'] ?? null, $order]);
    $id = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_bab', $id, 'create', null, $input);
    return ['id' => $id];
}

function hukum_update_bab_metadata(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.document.update');
    $babId = (int) ($input['bab_id'] ?? 0);
    $bab = dbFetchOne(
        'SELECT b.id, b.dokumen_id, b.nomor_label, b.judul_bab, d.periode_id
         FROM hukum_bab b JOIN hukum_dokumen d ON d.id = b.dokumen_id WHERE b.id = ?',
        [$babId]
    );
    if (!$bab) {
        throw new RuntimeException('BAB tidak ditemukan.', 404);
    }
    hukum_require_service_period((int) $bab['periode_id']);

    $workspaceId = (int) ($input['workspace_id'] ?? 0);
    $workspace = dbFetchOne('SELECT id, dokumen_id, status FROM hukum_workspace WHERE id = ?', [$workspaceId]);
    if (!$workspace || (int) $workspace['dokumen_id'] !== (int) $bab['dokumen_id']
        || !in_array((string) $workspace['status'], ['aktif', 'diajukan'], true)) {
        throw new RuntimeException('Workspace tidak valid untuk BAB ini.', 409);
    }

    $newLabel = isset($input['nomor_label']) ? trim((string) $input['nomor_label']) : null;
    $newJudul = isset($input['judul_bab']) ? trim((string) $input['judul_bab']) : null;

    $changed = false;
    $oldValues = ['nomor_label' => $bab['nomor_label'], 'judul_bab' => $bab['judul_bab']];

    if ($newLabel !== null && $newLabel !== '' && $newLabel !== (string) $bab['nomor_label']) {
        $duplicate = dbFetchOne(
            'SELECT id FROM hukum_bab WHERE dokumen_id = ? AND nomor_label = ? AND id <> ? LIMIT 1',
            [(int) $bab['dokumen_id'], $newLabel, $babId]
        );
        if ($duplicate) {
            throw new RuntimeException("Nomor BAB '{$newLabel}' sudah digunakan dalam dokumen ini.", 409);
        }
        $pdo->prepare('UPDATE hukum_bab SET nomor_label = ? WHERE id = ?')->execute([$newLabel, $babId]);
        $changed = true;
    }

    if ($newJudul !== null && $newJudul !== (string) ($bab['judul_bab'] ?? '')) {
        $pdo->prepare('UPDATE hukum_bab SET judul_bab = ? WHERE id = ?')->execute([$newJudul ?: null, $babId]);
        $changed = true;
    }

    if ($changed) {
        hukum_audit($pdo, 'hukum_bab', $babId, 'update_metadata', $oldValues, [
            'nomor_label' => $newLabel ?? $bab['nomor_label'],
            'judul_bab' => $newJudul ?? $bab['judul_bab'],
        ]);
    }

    return ['id' => $babId, 'updated' => $changed];
}

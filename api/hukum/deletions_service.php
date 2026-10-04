<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function hukum_deletion_for_update(PDO $pdo): string
{
    return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite'
        ? ''
        : ' FOR UPDATE';
}

function hukum_deletion_json_encode(array $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Snapshot penghapusan tidak dapat disimpan.', 500);
    }
    return $encoded;
}

function hukum_deletion_json_decode(string $value): array
{
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Snapshot penghapusan tidak dapat dibaca.', 500, $error);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Format snapshot penghapusan tidak valid.', 500);
    }
    return $decoded;
}

function hukum_deletion_active_commit(PDO $pdo, int $documentId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM hukum_commit WHERE dokumen_id = ? AND status = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$documentId, 'aktif']);
    $commitId = $stmt->fetchColumn();
    return $commitId === false ? null : (int) $commitId;
}

function hukum_deletion_active_pasal_snapshot(PDO $pdo, int $commitId, int $documentId): array
{
    $stmt = $pdo->prepare(
        'SELECT gs.pasal_id, gs.pasal_version_id, gs.nomor_label, p.bab_id,
                p.nomor_label AS current_nomor_label, p.judul_pasal, p.urutan,
                b.nomor_label AS bab_nomor_label, b.judul_bab, b.urutan AS bab_urutan,
                pv.isi
         FROM hukum_graph_snapshot gs
         JOIN hukum_pasal p ON p.id = gs.pasal_id
         LEFT JOIN hukum_bab b ON b.id = p.bab_id
         LEFT JOIN hukum_pasal_versi pv ON pv.id = gs.pasal_version_id
         WHERE gs.commit_id = ? AND gs.dokumen_id = ? AND gs.is_active = 1
         ORDER BY COALESCE(b.urutan, 0), p.urutan, p.id'
    );
    $stmt->execute([$commitId, $documentId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $content = [];
        if ($row['isi'] !== null) {
            try {
                $content = json_decode((string) $row['isi'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Isi versi aktif tidak dapat dibaca.', 500, $error);
            }
            if (!is_array($content)) {
                throw new RuntimeException('Format isi versi aktif tidak valid.', 500);
            }
        }
        $rows[(int) $row['pasal_id']] = [
            'pasal_id' => (int) $row['pasal_id'],
            'pasal_versi_id' => $row['pasal_version_id'] !== null ? (int) $row['pasal_version_id'] : null,
            'nomor_label' => (string) ($row['nomor_label'] ?? $row['current_nomor_label'] ?? ''),
            'judul_pasal' => (string) ($row['judul_pasal'] ?? ''),
            'urutan' => (int) $row['urutan'],
            'bab_id' => $row['bab_id'] !== null ? (int) $row['bab_id'] : null,
            'bab_nomor_label' => (string) ($row['bab_nomor_label'] ?? ''),
            'bab_judul' => (string) ($row['judul_bab'] ?? ''),
            'bab_urutan' => (int) ($row['bab_urutan'] ?? 0),
            'isi' => $content,
        ];
    }
    return $rows;
}

function hukum_deletion_target_snapshot(PDO $pdo, int $documentId, int $commitId, string $entityType, int $entityId): array
{
    $activePasals = hukum_deletion_active_pasal_snapshot($pdo, $commitId, $documentId);
    if ($entityType === 'pasal') {
        if (!isset($activePasals[$entityId])) {
            throw new RuntimeException('Pasal tidak aktif pada commit dasar.', 409);
        }
        return [
            'entity_type' => 'pasal',
            'entity_id' => $entityId,
            'pasal' => $activePasals[$entityId],
            'pasals' => [$activePasals[$entityId]],
        ];
    }

    $babStmt = $pdo->prepare(
        'SELECT id, nomor_label, judul_bab, urutan FROM hukum_bab WHERE id = ? AND dokumen_id = ? LIMIT 1'
    );
    $babStmt->execute([$entityId, $documentId]);
    $bab = $babStmt->fetch(PDO::FETCH_ASSOC);
    if ($bab === false) {
        throw new RuntimeException('BAB bukan bagian dari dokumen.', 404);
    }
    $alreadyDeleted = $pdo->prepare(
        'SELECT 1
         FROM hukum_commit_deletion cd
         JOIN hukum_commit c ON c.id = cd.commit_id
         WHERE c.dokumen_id = ? AND cd.entity_type = ? AND cd.entity_id = ?
         LIMIT 1'
    );
    $alreadyDeleted->execute([$documentId, 'bab', $entityId]);
    if ($alreadyDeleted->fetchColumn() !== false) {
        throw new RuntimeException('BAB ini sudah dihapus pada commit sebelumnya.', 409);
    }

    $children = array_values(array_filter(
        $activePasals,
        static fn (array $pasal): bool => (int) ($pasal['bab_id'] ?? 0) === $entityId
    ));
    return [
        'entity_type' => 'bab',
        'entity_id' => $entityId,
        'bab' => [
            'id' => (int) $bab['id'],
            'nomor_label' => (string) $bab['nomor_label'],
            'judul_bab' => (string) $bab['judul_bab'],
            'urutan' => (int) $bab['urutan'],
        ],
        'pasals' => $children,
    ];
}

function hukum_deletion_assert_no_active_relations(PDO $pdo, int $commitId, array $deletedPasalIds): void
{
    if ($deletedPasalIds === []) {
        return;
    }
    $deleted = array_fill_keys(array_map('intval', $deletedPasalIds), true);
    $placeholders = implode(',', array_fill(0, count($deletedPasalIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT e.source_pasal_id, e.target_pasal_id
         FROM hukum_graph_snapshot_edge e
         JOIN hukum_graph_snapshot s ON s.id = e.snapshot_id
         JOIN hukum_commit source_commit
           ON source_commit.id = s.commit_id AND source_commit.status = ?
         JOIN hukum_pasal target_pasal ON target_pasal.id = e.target_pasal_id
         JOIN (
           SELECT dokumen_id, MAX(id) AS commit_id
           FROM hukum_commit
           WHERE status = ?
           GROUP BY dokumen_id
         ) target_commit ON target_commit.dokumen_id = target_pasal.dokumen_id
         JOIN hukum_graph_snapshot target_node
           ON target_node.commit_id = target_commit.commit_id
          AND target_node.pasal_id = e.target_pasal_id
          AND target_node.is_active = 1
         WHERE s.is_active = 1
           AND s.pasal_id = e.source_pasal_id
           AND ((e.source_pasal_id IN (' . $placeholders . ') AND s.commit_id = ?)
                OR (e.target_pasal_id IN (' . $placeholders . ') AND target_node.commit_id = ?))'
    );
    $stmt->execute(array_merge(['aktif', 'aktif'], array_keys($deleted), [$commitId], array_keys($deleted), [$commitId]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        $sourceId = (int) $edge['source_pasal_id'];
        $targetId = (int) $edge['target_pasal_id'];
        if (isset($deleted[$sourceId]) xor isset($deleted[$targetId])) {
            throw new RuntimeException(
                'Penghapusan diblokir karena Pasal masih memiliki relasi aktif ke Pasal lain. Perbaiki relasi melalui alur yang disediakan sebelum mencoba lagi.',
                409
            );
        }
    }
}

function hukum_deletion_assert_no_inline_references(PDO $pdo, int $commitId, array $deletedPasalIds, array $replacementContents = []): void
{
    if ($deletedPasalIds === []) {
        return;
    }
    $deletedLabels = [];
    $labelStmt = $pdo->prepare(
        'SELECT gs.pasal_id, gs.nomor_label
         FROM hukum_graph_snapshot gs
         WHERE gs.commit_id = ? AND gs.is_active = 1'
    );
    $labelStmt->execute([$commitId]);
    foreach ($labelStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array((int) $row['pasal_id'], $deletedPasalIds, true)) {
            $label = hukum_deletion_normalize_pasal_label((string) $row['nomor_label']);
            $deletedLabels[$label] = true;
        }
    }

    $contentStmt = $pdo->prepare(
        'SELECT p.id, pv.isi
         FROM hukum_graph_snapshot gs
         JOIN hukum_pasal p ON p.id = gs.pasal_id
         JOIN hukum_pasal_versi pv ON pv.id = gs.pasal_version_id
         WHERE gs.commit_id = ? AND gs.is_active = 1'
    );
    $contentStmt->execute([$commitId]);
    $contents = [];
    foreach ($contentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pasalId = (int) $row['id'];
        if (in_array($pasalId, $deletedPasalIds, true)) {
            continue;
        }
        $contents[$pasalId] = hukum_deletion_json_decode((string) $row['isi']);
    }
    foreach ($replacementContents as $pasalId => $content) {
        if (!in_array((int) $pasalId, $deletedPasalIds, true) && is_array($content)) {
            $contents[(int) $pasalId] = $content;
        }
    }
    foreach ($contents as $content) {
        foreach (hukum_extract_inline_references($content) as $reference) {
            $label = hukum_deletion_normalize_pasal_label((string) ($reference['pasal_tujuan_nomor'] ?? ''));
            if (isset($deletedLabels[$label])) {
                throw new RuntimeException(
                    'Penghapusan diblokir karena isi Pasal lain masih memiliki rujukan langsung ke Pasal yang akan dihapus.',
                    409
                );
            }
        }
    }
}

function hukum_deletion_normalize_pasal_label(string $label): string
{
    $label = strtolower(preg_replace('/^pasal\\s*/i', '', trim($label)));
    return ctype_digit($label) ? (string) ((int) $label) : $label;
}

function hukum_deletion_assert_current_base(PDO $pdo, int $documentId, int $baseCommitId): void
{
    $currentCommitId = hukum_deletion_active_commit($pdo, $documentId);
    if ($currentCommitId === null || $currentCommitId !== $baseCommitId) {
        throw new RuntimeException(
            'Commit dasar sudah berubah sejak penghapusan disiapkan. Muat ulang dokumen dan buat ulang catatan penghapusan.',
            409
        );
    }
}

function hukum_deletion_prepare(PDO $pdo, int $workspaceId, string $entityType, int $entityId, string $reason, int $actorId): array
{
    if (!in_array($entityType, ['bab', 'pasal'], true) || $entityId <= 0 || $workspaceId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Data penghapusan tidak valid.', 400);
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
        throw new RuntimeException('Alasan penghapusan harus berisi 10 sampai 2000 karakter.', 400);
    }

    $workspaceStmt = $pdo->prepare(
        'SELECT ws.id, ws.dokumen_id, ws.status, d.periode_id
         FROM hukum_workspace ws
         JOIN hukum_dokumen d ON d.id = ws.dokumen_id
         WHERE ws.id = ?' . hukum_deletion_for_update($pdo)
    );
    $workspaceStmt->execute([$workspaceId]);
    $workspace = $workspaceStmt->fetch(PDO::FETCH_ASSOC);
    if ($workspace === false || (string) $workspace['status'] !== 'aktif') {
        throw new RuntimeException('Penghapusan hanya dapat dicatat pada workspace aktif.', 409);
    }
    hukum_require_permission($entityType === 'pasal' ? 'hukum.pasal.delete' : 'hukum.document.update');
    hukum_require_document_period((int) $workspace['periode_id']);

    $documentId = (int) $workspace['dokumen_id'];
    $baseCommitId = hukum_deletion_active_commit($pdo, $documentId);
    if ($baseCommitId === null) {
        throw new RuntimeException('Penghapusan hanya dapat diajukan untuk entitas yang sudah ada pada commit aktif.', 409);
    }
    $snapshot = hukum_deletion_target_snapshot($pdo, $documentId, $baseCommitId, $entityType, $entityId);
    $deletedPasalIds = array_map(
        static fn (array $pasal): int => (int) $pasal['pasal_id'],
        $snapshot['pasals']
    );
    if ($entityType === 'pasal') {
        $target = $snapshot['pasal'];
        if ((int) ($target['bab_id'] ?? 0) > 0) {
            $siblings = array_filter(
                hukum_deletion_active_pasal_snapshot($pdo, $baseCommitId, $documentId),
                static fn (array $pasal): bool => (int) ($pasal['bab_id'] ?? 0) === (int) $target['bab_id']
            );
            if (count($siblings) <= 1) {
                throw new RuntimeException('Pasal terakhir dalam BAB tidak dapat dihapus sendiri. Ajukan penghapusan BAB agar struktur dokumen tetap utuh.', 409);
            }
        }
    }
    $existingDeletions = hukum_deletion_workspace_records($pdo, $workspaceId);
    foreach ($existingDeletions as $existing) {
        $existingPasalIds = array_map(
            static fn (array $pasal): int => (int) $pasal['pasal_id'],
            $existing['snapshot']['pasals'] ?? []
        );
        if (array_intersect($deletedPasalIds, $existingPasalIds) !== []
            || ($entityType === 'bab' && $existing['entity_type'] === 'bab' && (int) $existing['entity_id'] === $entityId)) {
            throw new RuntimeException('Usulan ini tumpang tindih dengan penghapusan yang sudah dicatat. Batalkan catatan sebelumnya terlebih dahulu.', 409);
        }
    }
    hukum_deletion_assert_no_active_relations($pdo, $baseCommitId, $deletedPasalIds);
    hukum_deletion_assert_no_inline_references($pdo, $baseCommitId, $deletedPasalIds);

    return [
        'workspace_id' => $workspaceId,
        'document_id' => $documentId,
        'base_commit_id' => $baseCommitId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'reason' => $reason,
        'snapshot' => $snapshot,
        'created_by' => $actorId,
    ];
}

function hukum_deletion_create(PDO $pdo, array $input, int $actorId): array
{
    $record = hukum_deletion_prepare(
        $pdo,
        (int) ($input['workspace_id'] ?? 0),
        (string) ($input['entity_type'] ?? ''),
        (int) ($input['entity_id'] ?? 0),
        (string) ($input['reason'] ?? ''),
        $actorId
    );
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_workspace_deletion
         (workspace_id, entity_type, entity_id, base_commit_id, reason, snapshot_json, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([
            $record['workspace_id'],
            $record['entity_type'],
            $record['entity_id'],
            $record['base_commit_id'],
            $record['reason'],
            hukum_deletion_json_encode($record['snapshot']),
            $record['created_by'],
        ]);
    } catch (PDOException $error) {
        $state = (string) ($error->errorInfo[0] ?? $error->getCode());
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if (!($state === '23505' || ($state === '23000' && in_array($driverCode, [19, 1062], true)))) {
            throw $error;
        }
        throw new RuntimeException('Penghapusan entitas ini sudah tercatat pada workspace.', 409, $error);
    }
    $id = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_workspace_deletion', $id, 'request_delete', null, $record);
    return ['id' => $id] + $record;
}

function hukum_deletion_cancel(PDO $pdo, int $workspaceId, int $deletionId): void
{
    $stmt = $pdo->prepare(
        'SELECT wd.*, d.periode_id
         FROM hukum_workspace_deletion wd
         JOIN hukum_workspace ws ON ws.id = wd.workspace_id
         JOIN hukum_dokumen d ON d.id = ws.dokumen_id
         WHERE wd.id = ? AND wd.workspace_id = ? AND ws.status = ?' . hukum_deletion_for_update($pdo)
    );
    $stmt->execute([$deletionId, $workspaceId, 'aktif']);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record === false) {
        throw new RuntimeException('Catatan penghapusan tidak ditemukan pada workspace aktif.', 404);
    }
    hukum_require_document_period((int) $record['periode_id']);
    $pdo->prepare('DELETE FROM hukum_workspace_deletion WHERE id = ?')->execute([$deletionId]);
    hukum_audit($pdo, 'hukum_workspace_deletion', $deletionId, 'cancel_delete', $record, null);
}

function hukum_deletion_workspace_records(PDO $pdo, int $workspaceId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, workspace_id, entity_type, entity_id, base_commit_id, reason, snapshot_json, created_by, created_at
         FROM hukum_workspace_deletion WHERE workspace_id = ? ORDER BY id'
    );
    $stmt->execute([$workspaceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['entity_id'] = (int) $row['entity_id'];
        $row['base_commit_id'] = (int) $row['base_commit_id'];
        $row['snapshot'] = hukum_deletion_json_decode((string) $row['snapshot_json']);
        unset($row['snapshot_json']);
    }
    unset($row);
    return $rows;
}

function hukum_deletion_copy_to_staging(PDO $pdo, int $workspaceId, int $stagingId, int $documentId): array
{
    $records = hukum_deletion_workspace_records($pdo, $workspaceId);
    if ($records === []) {
        return [];
    }
    $baseCommitId = hukum_deletion_active_commit($pdo, $documentId);
    if ($baseCommitId === null) {
        throw new RuntimeException('Commit aktif tidak ditemukan untuk dasar penghapusan.', 409);
    }
    $deletedPasalIds = [];
    foreach ($records as $record) {
        hukum_deletion_assert_current_base($pdo, $documentId, (int) $record['base_commit_id']);
        foreach (($record['snapshot']['pasals'] ?? []) as $pasal) {
            $deletedPasalIds[] = (int) $pasal['pasal_id'];
        }
    }
    $deletedPasalIds = array_values(array_unique($deletedPasalIds));
    hukum_deletion_assert_no_active_relations($pdo, $baseCommitId, $deletedPasalIds);
    hukum_deletion_assert_no_inline_references($pdo, $baseCommitId, $deletedPasalIds);

    $insert = $pdo->prepare(
        'INSERT INTO hukum_staging_deletion
         (staging_id, entity_type, entity_id, base_commit_id, reason, snapshot_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($records as $record) {
        $insert->execute([
            $stagingId,
            $record['entity_type'],
            (int) $record['entity_id'],
            (int) $record['base_commit_id'],
            (string) $record['reason'],
            hukum_deletion_json_encode($record['snapshot']),
        ]);
    }
    return $records;
}

function hukum_deletion_commit_records(PDO $pdo, int $stagingId, int $commitId): array
{
    $stmt = $pdo->prepare(
        'SELECT entity_type, entity_id, reason, snapshot_json, base_commit_id
         FROM hukum_staging_deletion WHERE staging_id = ? ORDER BY id'
    );
    $stmt->execute([$stagingId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(
        'INSERT INTO hukum_commit_deletion (commit_id, entity_type, entity_id, reason, snapshot_json)
         VALUES (?, ?, ?, ?, ?)'
    );
    $result = [];
    foreach ($records as $record) {
        $insert->execute([
            $commitId,
            (string) $record['entity_type'],
            (int) $record['entity_id'],
            (string) $record['reason'],
            (string) $record['snapshot_json'],
        ]);
        $result[] = [
            'entity_type' => (string) $record['entity_type'],
            'entity_id' => (int) $record['entity_id'],
            'reason' => (string) $record['reason'],
            'base_commit_id' => (int) $record['base_commit_id'],
            'snapshot' => hukum_deletion_json_decode((string) $record['snapshot_json']),
        ];
    }
    return $result;
}

function hukum_pasal_is_active(PDO $pdo, int $documentId, int $pasalId): bool
{
    $stmt = $pdo->prepare(
        'SELECT c.id
         FROM hukum_commit c
         JOIN hukum_graph_snapshot gs ON gs.commit_id = c.id
         WHERE c.dokumen_id = ? AND c.status = ? AND gs.pasal_id = ? AND gs.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$documentId, 'aktif', $pasalId]);
    return $stmt->fetchColumn() !== false;
}

function hukum_bab_is_active(PDO $pdo, int $documentId, int $babId): bool
{
    $stmt = $pdo->prepare(
        'SELECT c.id
         FROM hukum_commit_deletion cd
         JOIN hukum_commit c ON c.id = cd.commit_id
         WHERE c.dokumen_id = ? AND cd.entity_type = ? AND cd.entity_id = ?
         LIMIT 1'
    );
    $stmt->execute([$documentId, 'bab', $babId]);
    return $stmt->fetchColumn() === false;
}

<?php
declare(strict_types=1);

function hukum_relationship_normalize_ids(array $values, string $field): array
{
    $ids = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException("{$field} harus berisi ID positif.", 400);
        }
        $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

function hukum_sync_acuan_relasi(PDO $pdo, int $pasalAnakId, int $dokumenId, array $isi, int $versionId): void
{
    if (!array_key_exists('acuan_pasal_id', $isi) && !array_key_exists('acuan', $isi)) {
        return;
    }

    $pasalIndukId = null;
    if (array_key_exists('acuan_pasal_id', $isi)) {
        $rawTargetId = $isi['acuan_pasal_id'];
        if ($rawTargetId !== null && $rawTargetId !== '') {
            $validatedId = filter_var($rawTargetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedId === false) {
                throw new InvalidArgumentException('Pasal acuan harus berupa ID Pasal yang valid.', 400);
            }
            $pasalIndukId = (int) $validatedId;
        }
    } else {
        $legacyReferences = $isi['acuan'];
        if (!is_array($legacyReferences)) {
            throw new InvalidArgumentException('Acuan harus berupa satu Pasal.', 400);
        }
        $labels = array_values(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $legacyReferences),
            static fn (string $value): bool => $value !== ''
        ));
        if (count($labels) > 1) {
            throw new RuntimeException('Satu Pasal hanya dapat mengacu ke satu Pasal.', 409);
        }
        if ($labels !== []) {
            $label = $labels[0];
            $number = trim((string) preg_replace('/^pasal\s*/i', '', $label));
            $target = $pdo->prepare(
                'SELECT id FROM hukum_pasal
                 WHERE dokumen_id = ?
                   AND LOWER(TRIM(nomor_label)) IN (LOWER(?), LOWER(?))
                 ORDER BY id'
            );
            $target->execute([$dokumenId, $label, 'Pasal ' . $number]);
            $targets = $target->fetchAll(PDO::FETCH_COLUMN);
            if (count($targets) !== 1) {
                throw new RuntimeException(
                    count($targets) === 0
                        ? "Pasal acuan '{$label}' tidak ditemukan pada dokumen ini."
                        : "Nomor Pasal acuan '{$label}' tidak unik.",
                    409
                );
            }
            $pasalIndukId = (int) $targets[0];
        }
    }

    if ($pasalIndukId === $pasalAnakId) {
        throw new InvalidArgumentException('Pasal tidak dapat mengacu kepada dirinya sendiri.', 400);
    }

    $existingStmt = $pdo->prepare(
        'SELECT id, pasal_induk_id FROM hukum_relasi_pasal
         WHERE pasal_anak_id = ? AND jenis_relasi = ? ORDER BY id'
    );
    $existingStmt->execute([$pasalAnakId, 'mengacu']);
    $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    $matchingRelation = null;
    foreach ($existing as $relation) {
        if ($pasalIndukId !== null && (int) $relation['pasal_induk_id'] === $pasalIndukId && $matchingRelation === null) {
            $matchingRelation = (int) $relation['id'];
            continue;
        }
        $pdo->prepare('DELETE FROM hukum_relasi_pasal WHERE id = ?')
            ->execute([(int) $relation['id']]);
    }

    if ($pasalIndukId === null) {
        return;
    }

    $target = dbFetchOne(
        'SELECT p.id, p.dokumen_id, d.periode_id, d.status
         FROM hukum_pasal p JOIN hukum_dokumen d ON d.id = p.dokumen_id
         WHERE p.id = ?',
        [$pasalIndukId]
    );
    if ($target === null || !in_array((string) $target['status'], ['draft', 'aktif'], true)) {
        throw new RuntimeException('Pasal acuan tidak ditemukan atau tidak dapat diakses.', 404);
    }
    hukum_require_service_period((int) $target['periode_id']);
    $activeCommit = dbFetchOne(
        'SELECT id FROM hukum_commit WHERE dokumen_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
        [(int) $target['dokumen_id'], 'aktif']
    );
    if ($activeCommit !== null) {
        $targetIsActive = dbFetchOne(
            'SELECT id FROM hukum_graph_snapshot
             WHERE commit_id = ? AND pasal_id = ? AND is_active = 1 LIMIT 1',
            [(int) $activeCommit['id'], $pasalIndukId]
        );
        $targetHasDraft = dbFetchOne(
            'SELECT pv.id
             FROM hukum_pasal_versi pv
             JOIN hukum_workspace w ON w.id = pv.workspace_id
             WHERE pv.pasal_id = ? AND w.dokumen_id = ?
               AND w.status IN (?, ?, ?) AND pv.status IN (?, ?, ?)
             LIMIT 1',
            [$pasalIndukId, (int) $target['dokumen_id'], 'aktif', 'diajukan', 'siap_commit', 'draft', 'staged', 'rejected']
        );
        if ($targetIsActive === null && $targetHasDraft === null) {
            throw new RuntimeException('Pasal acuan tidak termasuk dalam versi dokumen yang dapat dirujuk.', 409);
        }
    }

    $targetVersion = dbFetchOne(
        'SELECT gs.pasal_version_id AS version_id
         FROM hukum_graph_snapshot gs
         JOIN hukum_commit c ON c.id = gs.commit_id AND c.status = ?
         WHERE gs.pasal_id = ? AND gs.is_active = 1 AND gs.pasal_version_id IS NOT NULL
         ORDER BY c.id DESC LIMIT 1',
        ['aktif', $pasalIndukId]
    );
    if ($targetVersion === null) {
        $targetVersion = dbFetchOne(
            'SELECT id AS version_id FROM hukum_pasal_versi
             WHERE pasal_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$pasalIndukId, 'committed']
        );
    }
    $targetVersionId = $targetVersion !== null ? (int) $targetVersion['version_id'] : null;

    if ($matchingRelation !== null) {
        $pdo->prepare('UPDATE hukum_relasi_pasal SET source_version_id = ?, target_version_id = ? WHERE id = ?')
            ->execute([$versionId, $targetVersionId, $matchingRelation]);
        return;
    }

    $userId = function_exists('hukum_current_user_id') ? hukum_current_user_id() : null;
    $pdo->prepare(
        'INSERT INTO hukum_relasi_pasal
         (pasal_anak_id, pasal_induk_id, source_version_id, target_version_id, jenis_relasi, dibuat_oleh, dibuat_oleh_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $pasalAnakId,
        $pasalIndukId,
        $versionId,
        $targetVersionId,
        'mengacu',
        'manual',
        $userId && $userId > 0 ? $userId : null,
    ]);
}

function hukum_relationship_descendant_edges(PDO $pdo, int $documentId, array $rootPasalIds): array
{
    $queue = array_values(array_unique(array_filter(array_map('intval', $rootPasalIds), static fn (int $id): bool => $id > 0)));
    $visited = [];
    $relations = [];

    while ($queue !== []) {
        $indukId = array_shift($queue);
        if (isset($visited[$indukId])) {
            continue;
        }
        $visited[$indukId] = true;
        $stmt = $pdo->prepare(
            'SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi,
                    anak.dokumen_id AS anak_dokumen_id, induk.dokumen_id AS induk_dokumen_id
             FROM hukum_relasi_pasal r
             JOIN hukum_pasal anak ON anak.id = r.pasal_anak_id
             JOIN hukum_pasal induk ON induk.id = r.pasal_induk_id
             WHERE r.pasal_induk_id = ? AND r.jenis_relasi = ?'
        );
        $stmt->execute([$indukId, 'mengacu']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relationId = (int) $row['id'];
            $relations[$relationId] = [
                'id' => $relationId,
                'pasal_anak_id' => (int) $row['pasal_anak_id'],
                'pasal_induk_id' => (int) $row['pasal_induk_id'],
                'jenis_relasi' => (string) $row['jenis_relasi'],
                'anak_dokumen_id' => (int) $row['anak_dokumen_id'],
                'induk_dokumen_id' => (int) $row['induk_dokumen_id'],
            ];
            $queue[] = (int) $row['pasal_anak_id'];
        }
    }

    return array_values($relations);
}

function hukum_create_impact_notifications(PDO $pdo, int $pasalId, int $documentId, int $sourceVersionId): int
{
    $relations = hukum_relationship_descendant_edges($pdo, $documentId, [$pasalId]);
    if ($relations === []) {
        return 0;
    }

    $findActive = $pdo->prepare(
        'SELECT id FROM hukum_notifikasi
         WHERE relasi_id = ? AND status = ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO hukum_notifikasi
         (relasi_id, pasal_anak_id, pasal_induk_id, dipicu_oleh_versi_id, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $created = 0;
    foreach ($relations as $relation) {
        $findActive->execute([$relation['id'], 'perlu_ditinjau']);
        if ($findActive->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([
            $relation['id'],
            $relation['pasal_anak_id'],
            $relation['pasal_induk_id'],
            $sourceVersionId,
            'perlu_ditinjau',
        ]);
        $created++;
    }
    return $created;
}

function hukum_relationship_version_has_text_changes(PDO $pdo, int $versionId): bool
{
    $versionStmt = $pdo->prepare(
        'SELECT isi, dibuat_dari_versi_id FROM hukum_pasal_versi WHERE id = ?'
    );
    $versionStmt->execute([$versionId]);
    $version = $versionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$version || $version['dibuat_dari_versi_id'] === null) {
        return false;
    }
    $baseStmt = $pdo->prepare('SELECT isi FROM hukum_pasal_versi WHERE id = ?');
    $baseStmt->execute([(int) $version['dibuat_dari_versi_id']]);
    $baseJson = $baseStmt->fetchColumn();
    $current = json_decode((string) $version['isi'], true);
    $base = json_decode((string) $baseJson, true);
    if (!is_array($current) || !is_array($base)) {
        throw new RuntimeException('Isi versi tidak valid untuk menentukan penyelarasan.', 409);
    }
    unset($current['acuan'], $base['acuan'], $current['acuan_pasal_id'], $base['acuan_pasal_id']);
    return hukum_canonical_json($current) !== hukum_canonical_json($base);
}

function hukum_resolve_impact_notifications_for_versions(PDO $pdo, int $workspaceId, array $versionIds): int
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $resolved = hukum_resolve_impact_notifications_for_versions_internal($pdo, $workspaceId, $versionIds);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $resolved;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function hukum_resolve_impact_notifications_for_versions_internal(PDO $pdo, int $workspaceId, array $versionIds): int
{
    $versionIds = hukum_relationship_normalize_ids($versionIds, 'version_ids');
    if ($workspaceId <= 0 || $versionIds === []) {
        throw new InvalidArgumentException('Workspace dan versi draft yang diselaraskan wajib diisi.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($versionIds), '?'));
    $versionsStmt = $pdo->prepare(
        'SELECT pv.id, pv.pasal_id, pv.isi, pv.dibuat_dari_versi_id, d.periode_id
         FROM hukum_pasal_versi pv
         JOIN hukum_workspace w ON w.id = pv.workspace_id
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         JOIN hukum_dokumen d ON d.id = p.dokumen_id
         WHERE pv.id IN (' . $placeholders . ')
           AND pv.workspace_id = ? AND pv.status = ? AND w.status = ?'
    );
    $versionsStmt->execute(array_merge($versionIds, [$workspaceId, 'draft', 'aktif']));
    $versions = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($versions) !== count($versionIds)) {
        throw new RuntimeException('Versi yang diselaraskan tidak termasuk draft aktif pada workspace ini.', 409);
    }

    $periodIds = array_unique(array_map(static fn (array $row): int => (int) $row['periode_id'], $versions));
    if (count($periodIds) !== 1) {
        throw new RuntimeException('Versi draft harus berasal dari satu periode dokumen.', 409);
    }
    hukum_require_service_period((int) reset($periodIds));

    $changedPasalIds = [];
    foreach ($versions as $version) {
        if (hukum_relationship_version_has_text_changes($pdo, (int) $version['id'])) {
            $changedPasalIds[] = (int) $version['pasal_id'];
        }
    }
    $pasalIds = array_values(array_unique($changedPasalIds));
    if ($pasalIds === []) {
        return 0;
    }
    $pasalPlaceholders = implode(',', array_fill(0, count($pasalIds), '?'));
    $pendingStmt = $pdo->prepare(
        'SELECT n.* FROM hukum_notifikasi n
         WHERE n.status = ? AND n.pasal_anak_id IN (' . $pasalPlaceholders . ')'
    );
    $pendingStmt->execute(array_merge(['perlu_ditinjau'], $pasalIds));
    $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        'UPDATE hukum_notifikasi
         SET status = ?, catatan = ?, diselesaikan_oleh = ?, diselesaikan_at = NOW()
         WHERE id = ? AND status = ?'
    );
    $resolved = 0;
    foreach ($pending as $notification) {
        $update->execute([
            'sudah_diselaraskan',
            null,
            hukum_current_user_id(),
            (int) $notification['id'],
            'perlu_ditinjau',
        ]);
        if ($update->rowCount() > 0) {
            hukum_audit($pdo, 'hukum_notifikasi', (int) $notification['id'], 'align_saved_draft', $notification, [
                'status' => 'sudah_diselaraskan',
                'pasal_versi_ids' => $versionIds,
            ]);
            $resolved++;
        }
    }
    return $resolved;
}

function hukum_assert_no_pending_impact_notifications(PDO $pdo, int $documentId): void
{
    $stmt = $pdo->prepare(
        'SELECT anak.nomor_label AS anak_nomor, induk.nomor_label AS induk_nomor
         FROM hukum_notifikasi n
         JOIN hukum_pasal anak ON anak.id = n.pasal_anak_id
         JOIN hukum_pasal induk ON induk.id = n.pasal_induk_id
         WHERE n.status = ? AND anak.dokumen_id = ?
         ORDER BY n.id'
    );
    $stmt->execute(['perlu_ditinjau', $documentId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($pending === []) {
        return;
    }

    $examples = array_map(
        static fn (array $row): string => (string) $row['anak_nomor'] . ' (mengacu ke ' . (string) $row['induk_nomor'] . ')',
        array_slice($pending, 0, 5)
    );
    $remaining = count($pending) > count($examples) ? ' dan ' . (count($pending) - count($examples)) . ' relasi lainnya' : '';
    throw new RuntimeException(
        'Staging diblokir: dampak perubahan acuan masih perlu ditinjau pada ' . implode(', ', $examples) . $remaining . '.',
        409
    );
}

<?php

require_once __DIR__ . '/_bootstrap.php';

function hukum_staging_normalize_version_ids(array $versionIds): array
{
    $normalized = [];
    foreach ($versionIds as $versionId) {
        $value = (int) $versionId;
        if ($value > 0) {
            $normalized[] = $value;
        }
    }
    return array_values(array_unique($normalized));
}

function hukum_staging_validate_submission(PDO $pdo, int $workspaceId, array $versionIds): array
{
    $versionIds = hukum_staging_normalize_version_ids($versionIds);
    if ($workspaceId <= 0 || $versionIds === []) {
        throw new RuntimeException('Workspace dan pasal versi yang akan distaging wajib valid.', 400);
    }
    $workspaceStmt = $pdo->prepare(
        'SELECT ws.id, ws.dokumen_id, ws.status, d.periode_id
         FROM hukum_workspace ws JOIN hukum_dokumen d ON d.id = ws.dokumen_id
         WHERE ws.id = ? FOR UPDATE',
    );
    $workspaceStmt->execute([$workspaceId]);
    $workspace = $workspaceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$workspace) {
        throw new RuntimeException('Workspace tidak ditemukan.', 404);
    }
    if ((string) $workspace['status'] !== 'aktif') {
        throw new RuntimeException('Workspace tidak aktif untuk submit staging.', 409);
    }
    $documentId = (int) $workspace['dokumen_id'];
    $versions = [];
    $pasalIds = [];
    foreach ($versionIds as $versionId) {
        $versionStmt = $pdo->prepare(
            'SELECT pv.id, pv.pasal_id, pv.isi, pv.status, pv.hash_konten, p.dokumen_id, p.nomor_label
             FROM hukum_pasal_versi pv JOIN hukum_pasal p ON p.id = pv.pasal_id
             WHERE pv.id = ? AND pv.workspace_id = ? FOR UPDATE',
        );
        $versionStmt->execute([$versionId, $workspaceId]);
        $version = $versionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$version || (string) $version['status'] !== 'draft') {
            throw new RuntimeException("Versi {$versionId} tidak valid untuk staging.", 409);
        }
        if ((int) $version['dokumen_id'] !== $documentId) {
            throw new RuntimeException("Versi {$versionId} tidak termasuk dalam dokumen yang sama.", 409);
        }
        $decoded = json_decode((string) $version['isi'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Versi {$versionId} memiliki isi JSON yang tidak valid.", 409);
        }
        $failures = hukum_collect_reference_failures(
            $pdo,
            (int) $version['pasal_id'],
            hukum_extract_inline_references($decoded)
        );
        if ($failures !== []) {
            $issue = $failures[0];
            throw new RuntimeException(
                sprintf('Referensi invalid: source=%s, raw=%s, target=%s, reason=%s',
                    $issue['source'] ?? '-', $issue['raw_reference'] ?? '-',
                    $issue['target'] ?? '-', $issue['reason'] ?? 'tidak valid'),
                409
            );
        }
        $versions[] = [
            'id' => (int) $version['id'],
            'pasal_id' => (int) $version['pasal_id'],
            'hash_konten' => (string) $version['hash_konten'],
            'isi' => $decoded,
            'nomor_label' => (string) $version['nomor_label'],
        ];
        $pasalIds[] = (int) $version['pasal_id'];
    }
    $existingStmt = $pdo->prepare(
        'SELECT id FROM hukum_staging
         WHERE workspace_id = ? AND status IN (\'menunggu_review\', \'disetujui\')
         LIMIT 1 FOR UPDATE',
    );
    $existingStmt->execute([$workspaceId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        throw new RuntimeException('Workspace sudah memiliki staging yang aktif.', 409);
    }
    $pasalIds = array_values(array_unique($pasalIds));
    $impact = hukum_staging_impact_analysis($pdo, $documentId, $pasalIds, 1);
    return [
        'workspace_id' => $workspaceId,
        'dokumen_id' => $documentId,
        'periode_id' => (int) $workspace['periode_id'],
        'versions' => $versions,
        'pasal_ids' => $pasalIds,
        'impact' => $impact,
        'self_commit_valid' => hukum_staging_self_commit_valid($pdo, $workspaceId, $documentId, $pasalIds, $impact['relations']),
    ];
}

function hukum_submit_staging(PDO $pdo, int $workspaceId, array $versionIds, ?int $actorId = null): array
{
    $actorId = $actorId ?? hukum_current_user_id();
    $actor = hukum_authenticated_actor();
    if ($actorId <= 0 || $actor === null) {
        throw new RuntimeException('Sesi tidak valid untuk submit staging.', 401);
    }
    $pdo->beginTransaction();
    try {
    $workspaceStmt = $pdo->prepare(
        'SELECT ws.id, ws.dokumen_id, ws.status, d.periode_id
         FROM hukum_workspace ws JOIN hukum_dokumen d ON d.id = ws.dokumen_id
         WHERE ws.id = ? FOR UPDATE',
    );
    $workspaceStmt->execute([$workspaceId]);
    $workspace = $workspaceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$workspace || (string) $workspace['status'] !== 'aktif') {
        throw new RuntimeException('Workspace tidak ditemukan atau tidak aktif.', 409);
    }
    if (!$actor->canAccessAll && $actor->technicalRole !== 'superadmin'
        && $actor->periodId !== (int) $workspace['periode_id']) {
        throw new RuntimeException('Anda tidak memiliki akses ke periode staging ini.', 403);
    }
    $membership = $pdo->prepare(
        'SELECT id FROM hukum_keanggotaan
         WHERE user_id = ? AND periode_id = ? AND jabatan = \'komisi_i\' AND aktif = 1
           AND (selesai_pada IS NULL OR selesai_pada >= CURDATE()) LIMIT 1'
    );
    $membership->execute([$actorId, (int) $workspace['periode_id']]);
    if ($membership->fetch(PDO::FETCH_ASSOC) === false) {
        throw new RuntimeException('Hanya Komisi I yang dapat submit staging.', 403);
    }
        $submission = hukum_staging_validate_submission($pdo, $workspaceId, $versionIds);
        $stmt = $pdo->prepare(
            'INSERT INTO hukum_staging (workspace_id, status, diajukan_oleh)
             VALUES (?, \'menunggu_review\', ?)'
        );
        $stmt->execute([$workspaceId, $actorId]);
        $stagingId = (int) $pdo->lastInsertId();
        $approval = $pdo->prepare(
            'INSERT INTO hukum_staging_approval
             (staging_id, user_id, peran, status, note, approved_at, rejected_at)
             VALUES (?, ?, ?, \'menunggu\', NULL, NULL, NULL)'
        );
        foreach (['komisi_i', 'ketua_umum'] as $role) {
            $approval->execute([$stagingId, $actorId, $role]);
        }
        $link = $pdo->prepare('INSERT INTO hukum_staging_versi (staging_id, pasal_versi_id) VALUES (?, ?)');
        $mark = $pdo->prepare('UPDATE hukum_pasal_versi SET status = \'staged\' WHERE id = ?');
        foreach ($submission['versions'] as $version) {
            $link->execute([$stagingId, $version['id']]);
            $mark->execute([$version['id']]);
        }
        if (!empty($submission['impact']['relations'])) {
            $check = $pdo->prepare(
                'SELECT id FROM hukum_notifikasi
                 WHERE relasi_id = ? AND dipicu_oleh_versi_id = ? AND status = \'perlu_ditinjau\' LIMIT 1'
            );
            $insert = $pdo->prepare(
                'INSERT INTO hukum_notifikasi
                 (relasi_id, pasal_anak_id, pasal_induk_id, dipicu_oleh_versi_id, status)
                 VALUES (?, ?, ?, ?, \'perlu_ditinjau\')'
            );
            foreach ($submission['impact']['relations'] as $relation) {
                $sourceVersionId = 0;
                foreach ($submission['versions'] as $version) {
                    if ((int) $version['pasal_id'] === (int) $relation['pasal_anak_id']
                        || (int) $version['pasal_id'] === (int) $relation['pasal_induk_id']) {
                        $sourceVersionId = (int) $version['id'];
                        break;
                    }
                }
                if ($sourceVersionId <= 0) {
                    continue;
                }
                $check->execute([(int) $relation['id'], $sourceVersionId]);
                if ($check->fetchColumn() === false) {
                    $insert->execute([
                        (int) $relation['id'],
                        (int) $relation['pasal_anak_id'],
                        (int) $relation['pasal_induk_id'],
                        $sourceVersionId,
                    ]);
                }
            }
        }
        $pdo->prepare('UPDATE hukum_workspace SET status = \'diajukan\' WHERE id = ?')
            ->execute([$workspaceId]);
        hukum_audit($pdo, 'hukum_staging', $stagingId, 'submit', null, [
            'workspace_id' => $workspaceId,
            'pasal_versi_ids' => $versionIds,
            'impact_count' => count($submission['impact']['relations'] ?? []),
            'self_commit_valid' => $submission['self_commit_valid'],
        ]);
        $pdo->commit();
        return [
            'id' => $stagingId,
            'status' => 'menunggu_review',
            'impact_count' => count($submission['impact']['relations'] ?? []),
            'self_commit_valid' => $submission['self_commit_valid'],
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function hukum_staging_impact_analysis(PDO $pdo, int $documentId, array $pasalIds, int $maxDepth = 1): array
{
    $queue = array_map(static fn (int $id): array => ['pasal_id' => $id, 'depth' => 0], array_unique(array_map('intval', $pasalIds)));
    $seen = [];
    $relations = [];
    while ($queue !== []) {
        $current = array_shift($queue);
        if ($current['depth'] >= $maxDepth || isset($seen[$current['pasal_id']])) {
            continue;
        }
        $seen[$current['pasal_id']] = true;
        $stmt = $pdo->prepare(
            'SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi
             FROM hukum_relasi_pasal r
             JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id
             JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id
             WHERE (r.pasal_anak_id = ? OR r.pasal_induk_id = ?)
               AND pa.dokumen_id = ? AND pi.dokumen_id = ?'
        );
        $stmt->execute([$current['pasal_id'], $current['pasal_id'], $documentId, $documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $relations[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'pasal_anak_id' => (int) $row['pasal_anak_id'],
                'pasal_induk_id' => (int) $row['pasal_induk_id'],
                'jenis_relasi' => (string) $row['jenis_relasi'],
            ];
            $other = (int) $row['pasal_anak_id'] === $current['pasal_id']
                ? (int) $row['pasal_induk_id'] : (int) $row['pasal_anak_id'];
            $queue[] = ['pasal_id' => $other, 'depth' => $current['depth'] + 1];
        }
    }
    return ['pasal_ids' => array_keys($seen), 'relations' => array_values($relations), 'depth_limit' => $maxDepth];
}

function hukum_staging_self_commit_valid(PDO $pdo, int $workspaceId, int $documentId, array $pasalIds, array $relations): bool
{
    if ($relations === []) {
        return false;
    }
    $ids = array_values(array_unique(array_map('intval', $pasalIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total FROM hukum_pasal_versi pv
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         WHERE p.dokumen_id = ? AND pv.workspace_id = ? AND pv.status = \'draft\'
           AND pv.pasal_id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$documentId, $workspaceId], $ids));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['total'] ?? 0) > 0;
}

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
    $workspaceId = (int) $workspaceId;
    $versionIds = hukum_staging_normalize_version_ids($versionIds);
    if ($workspaceId <= 0 || $versionIds === []) {
        throw new RuntimeException('Workspace dan pasal versi yang akan distaging wajib valid.', 400);
    }

    $workspace = dbFetchOne(
        'SELECT ws.id, ws.dokumen_id, ws.status, d.periode_id
         FROM hukum_workspace ws
         JOIN hukum_dokumen d ON d.id = ws.dokumen_id
         WHERE ws.id = ? FOR UPDATE',
        [$workspaceId]
    );
    if (!$workspace) {
        throw new RuntimeException('Workspace tidak ditemukan.', 404);
    }
    if ((string) $workspace['status'] !== 'aktif') {
        throw new RuntimeException('Workspace tidak aktif untuk submit staging.', 409);
    }

    $documentId = (int) $workspace['dokumen_id'];
    $periodeId = (int) $workspace['periode_id'];
    $versions = [];
    $pasalIds = [];
    foreach ($versionIds as $versionId) {
        $version = dbFetchOne(
            'SELECT pv.id, pv.pasal_id, pv.isi, pv.status, pv.hash_konten, p.dokumen_id, p.nomor_label
             FROM hukum_pasal_versi pv
             JOIN hukum_pasal p ON p.id = pv.pasal_id
             WHERE pv.id = ? AND pv.workspace_id = ? FOR UPDATE',
            [$versionId, $workspaceId]
        );
        if (!$version) {
            throw new RuntimeException("Versi {$versionId} tidak ditemukan pada workspace ini.", 409);
        }
        if ((string) $version['status'] !== 'draft') {
            throw new RuntimeException("Versi {$versionId} bukan draft dan tidak dapat distaging.", 409);
        }
        if ((int) $version['dokumen_id'] !== $documentId) {
            throw new RuntimeException("Versi {$versionId} tidak termasuk dalam dokumen yang sama.", 409);
        }

        $decoded = json_decode((string) $version['isi'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Versi {$versionId} memiliki isi JSON yang tidak valid.", 409);
        }

        $referenceFailures = hukum_collect_reference_failures($pdo, (int) $version['pasal_id'], hukum_extract_inline_references($decoded));
        if ($referenceFailures !== []) {
            $issue = $referenceFailures[0];
            throw new RuntimeException(
                sprintf(
                    'Referensi invalid: source=%s, raw=%s, target=%s, reason=%s',
                    (string) ($issue['source'] ?? '-'),
                    (string) ($issue['raw_reference'] ?? '-'),
                    (string) ($issue['target'] ?? '-'),
                    (string) ($issue['reason'] ?? 'tidak valid')
                ),
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

    $existing = dbFetchOne(
        'SELECT id FROM hukum_staging WHERE workspace_id = ? AND status IN (\'menunggu_review\', \'disetujui\') LIMIT 1 FOR UPDATE',
        [$workspaceId]
    );
    if ($existing) {
        throw new RuntimeException('Workspace sudah memiliki staging yang aktif.', 409);
    }

    $pasalIds = array_values(array_unique($pasalIds));
    $impact = hukum_staging_impact_analysis($pdo, $documentId, $pasalIds, 1);
    $selfCommitValid = hukum_staging_self_commit_valid($pdo, $workspaceId, $documentId, $pasalIds, $impact['relations']);

    return [
        'workspace_id' => $workspaceId,
        'dokumen_id' => $documentId,
        'periode_id' => $periodeId,
        'versions' => $versions,
        'pasal_ids' => $pasalIds,
        'impact' => $impact,
        'self_commit_valid' => $selfCommitValid,
    ];
}

function hukum_staging_impact_analysis(PDO $pdo, int $documentId, array $pasalIds, int $maxDepth = 1): array
{
    $documentId = (int) $documentId;
    $pasalIds = array_values(array_unique(array_map('intval', $pasalIds)));
    if ($documentId <= 0 || $pasalIds === []) {
        return ['pasal_ids' => [], 'relations' => [], 'depth_limit' => $maxDepth];
    }

    $queue = [];
    foreach ($pasalIds as $pasalId) {
        $queue[] = ['pasal_id' => $pasalId, 'depth' => 0];
    }

    $seenPasal = [];
    $relations = [];
    while ($queue !== []) {
        $current = array_shift($queue);
        $pasalId = (int) $current['pasal_id'];
        $depth = (int) $current['depth'];
        if ($depth >= $maxDepth) {
            continue;
        }
        if (isset($seenPasal[$pasalId])) {
            continue;
        }
        $seenPasal[$pasalId] = true;

        $neighbors = dbFetchAll(
            'SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi
             FROM hukum_relasi_pasal r
             JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id
             JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id
             WHERE (r.pasal_anak_id = ? OR r.pasal_induk_id = ?)
               AND pa.dokumen_id = ? AND pi.dokumen_id = ?',
            [$pasalId, $pasalId, $documentId, $documentId]
        );

        foreach ($neighbors as $row) {
            $relationId = (int) $row['id'];
            $relatedPasalId = (int) $row['pasal_anak_id'] === $pasalId ? (int) $row['pasal_induk_id'] : (int) $row['pasal_anak_id'];
            if (!isset($relations[$relationId])) {
                $relations[$relationId] = [
                    'id' => $relationId,
                    'pasal_anak_id' => (int) $row['pasal_anak_id'],
                    'pasal_induk_id' => (int) $row['pasal_induk_id'],
                    'jenis_relasi' => (string) $row['jenis_relasi'],
                ];
            }
            if (!isset($seenPasal[$relatedPasalId])) {
                $queue[] = ['pasal_id' => $relatedPasalId, 'depth' => $depth + 1];
            }
        }
    }

    return [
        'pasal_ids' => array_keys($seenPasal),
        'relations' => array_values($relations),
        'depth_limit' => $maxDepth,
    ];
}

function hukum_staging_self_commit_valid(PDO $pdo, int $workspaceId, int $documentId, array $pasalIds, array $relations): bool
{
    if ($relations === []) {
        return false;
    }

    $pasalIds = array_values(array_unique(array_map('intval', $pasalIds)));
    if ($pasalIds === []) {
        return false;
    }

    $linkedIds = [];
    foreach ($relations as $relation) {
        $linkedIds[] = (int) $relation['pasal_anak_id'];
        $linkedIds[] = (int) $relation['pasal_induk_id'];
    }
    $linkedIds = array_values(array_unique($linkedIds));
    $linkedIdList = implode(',', array_fill(0, count($linkedIds), '?'));
    $notificationParams = array_merge($linkedIds, $linkedIds);

    $activeNotifications = dbFetchOne(
        'SELECT COUNT(*) AS total
         FROM hukum_notifikasi n
         JOIN hukum_relasi_pasal r ON r.id = n.relasi_id
         WHERE (r.pasal_anak_id IN (' . $linkedIdList . ') OR r.pasal_induk_id IN (' . $linkedIdList . '))
           AND n.status IN (\'perlu_ditinjau\', \'diabaikan_dengan_alasan\')',
        $notificationParams
    );
    if ((int) ($activeNotifications['total'] ?? 0) > 0) {
        return false;
    }

    $draftCount = dbFetchOne(
        'SELECT COUNT(*) AS total
         FROM hukum_pasal_versi pv
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         WHERE p.dokumen_id = ? AND pv.workspace_id = ? AND pv.status = \'draft\' AND pv.pasal_id IN (' . implode(',', array_fill(0, count($pasalIds), '?')) . ')',
        array_merge([(int) $documentId, (int) $workspaceId], $pasalIds)
    );

    return (int) ($draftCount['total'] ?? 0) > 0;
}

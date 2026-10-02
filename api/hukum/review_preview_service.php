<?php

function hukum_review_preview_decode_content(string $content): array
{
    try {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Isi versi Pasal tidak dapat dibaca.', 500, $error);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Format isi versi Pasal tidak valid.', 500);
    }

    return $decoded;
}

function hukum_review_preview_document_opening(array $document): string
{
    $legacy = trim((string) ($document['mukadimah_legacy'] ?? ''));
    if (($document['format_mukadimah'] ?? '') !== 'json' || empty($document['mukadimah_json'])) {
        return $legacy;
    }

    try {
        $decoded = json_decode((string) $document['mukadimah_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Mukadimah dokumen tidak dapat dibaca.', 500, $error);
    }

    if (is_string($decoded)) {
        return trim($decoded);
    }
    if (is_array($decoded)) {
        foreach (['teks', 'text', 'isi', 'pembukaan'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                return trim($decoded[$key]);
            }
        }
    }

    return $legacy;
}

function hukum_review_preview_pasals(PDO $pdo, int $stagingId): array
{
    $stagingStmt = $pdo->prepare(
        'SELECT s.id, s.status, w.dokumen_id, d.judul, d.format_mukadimah,
                d.mukadimah_json, d.mukadimah_legacy
         FROM hukum_staging s
         JOIN hukum_workspace w ON w.id = s.workspace_id
         JOIN hukum_dokumen d ON d.id = w.dokumen_id
         WHERE s.id = ?'
    );
    $stagingStmt->execute([$stagingId]);
    $staging = $stagingStmt->fetch(PDO::FETCH_ASSOC);
    if ($staging === false) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $documentId = (int) $staging['dokumen_id'];
    $currentCommitStmt = $pdo->prepare(
        'SELECT id FROM hukum_commit
         WHERE dokumen_id = ? AND status = ?
         ORDER BY id DESC LIMIT 1'
    );
    $currentCommitStmt->execute([$documentId, 'aktif']);
    $currentCommit = $currentCommitStmt->fetch(PDO::FETCH_ASSOC);
    $baseCommitId = $currentCommit !== false ? (int) $currentCommit['id'] : null;

    $basePasals = [];
    $hasBaseSnapshot = false;
    if ($baseCommitId !== null) {
        $snapshotCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM hukum_graph_snapshot WHERE commit_id = ? AND dokumen_id = ?'
        );
        $snapshotCountStmt->execute([$baseCommitId, $documentId]);
        $hasBaseSnapshot = (int) $snapshotCountStmt->fetchColumn() > 0;
        $baseStmt = $pdo->prepare(
            'SELECT gs.pasal_id, gs.pasal_version_id, gs.nomor_label AS snapshot_nomor_label,
                    p.dokumen_id, p.bab_id, p.judul_pasal, p.urutan,
                    b.nomor_label AS bab_nomor_label, b.judul_bab, b.urutan AS bab_urutan,
                    pv.isi
             FROM hukum_graph_snapshot gs
             JOIN hukum_pasal p ON p.id = gs.pasal_id
             LEFT JOIN hukum_bab b ON b.id = p.bab_id
             LEFT JOIN hukum_pasal_versi pv ON pv.id = gs.pasal_version_id
             WHERE gs.commit_id = ? AND gs.dokumen_id = ? AND gs.is_active = 1
             ORDER BY COALESCE(b.urutan, 0), p.urutan, p.id'
        );
        $baseStmt->execute([$baseCommitId, $documentId]);
        foreach ($baseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pasalId = (int) $row['pasal_id'];
            $basePasals[$pasalId] = [
                'pasal_id' => $pasalId,
                'bab_id' => $row['bab_id'] !== null ? (int) $row['bab_id'] : null,
                'bab_nomor_label' => $row['bab_nomor_label'],
                'bab_judul' => $row['judul_bab'],
                'bab_urutan' => (int) ($row['bab_urutan'] ?? 0),
                'nomor_label' => $row['snapshot_nomor_label'] ?? '',
                'judul_pasal' => $row['judul_pasal'] ?? '',
                'urutan' => (int) $row['urutan'],
                'before' => $row['pasal_version_id'] !== null && $row['isi'] !== null
                    ? [
                        'versi_id' => (int) $row['pasal_version_id'],
                        'isi' => hukum_review_preview_decode_content((string) $row['isi']),
                    ]
                    : null,
                'staged' => false,
            ];
        }
    }

    if (!$hasBaseSnapshot) {
        $fallbackStmt = $pdo->prepare(
            'SELECT p.id AS pasal_id, p.bab_id, p.nomor_label, p.judul_pasal, p.urutan,
                    b.nomor_label AS bab_nomor_label, b.judul_bab, b.urutan AS bab_urutan,
                    pv.id AS pasal_version_id, pv.isi
             FROM hukum_pasal p
             LEFT JOIN hukum_bab b ON b.id = p.bab_id
             JOIN hukum_pasal_versi pv ON pv.pasal_id = p.id AND pv.status = ?
             WHERE p.dokumen_id = ?
             ORDER BY pv.created_at DESC, pv.id DESC'
        );
        $fallbackStmt->execute(['committed', $documentId]);
        foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pasalId = (int) $row['pasal_id'];
            if (isset($basePasals[$pasalId])) {
                continue;
            }
            $basePasals[$pasalId] = [
                'pasal_id' => $pasalId,
                'bab_id' => $row['bab_id'] !== null ? (int) $row['bab_id'] : null,
                'bab_nomor_label' => $row['bab_nomor_label'],
                'bab_judul' => $row['judul_bab'],
                'bab_urutan' => (int) ($row['bab_urutan'] ?? 0),
                'nomor_label' => $row['nomor_label'],
                'judul_pasal' => $row['judul_pasal'] ?? '',
                'urutan' => (int) $row['urutan'],
                'before' => [
                    'versi_id' => (int) $row['pasal_version_id'],
                    'isi' => hukum_review_preview_decode_content((string) $row['isi']),
                ],
                'staged' => false,
            ];
        }
    }

    $stagedStmt = $pdo->prepare(
        'SELECT pv.id AS pasal_version_id, pv.pasal_id, pv.isi,
                p.bab_id, p.nomor_label, p.judul_pasal, p.urutan,
                b.nomor_label AS bab_nomor_label, b.judul_bab, b.urutan AS bab_urutan
         FROM hukum_staging_versi sv
         JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         LEFT JOIN hukum_bab b ON b.id = p.bab_id
         WHERE sv.staging_id = ? AND p.dokumen_id = ?
         ORDER BY COALESCE(b.urutan, 0), p.urutan, p.id'
    );
    $stagedStmt->execute([$stagingId, $documentId]);
    $pasals = $basePasals;
    foreach ($stagedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pasalId = (int) $row['pasal_id'];
        $pasal = $pasals[$pasalId] ?? [
            'pasal_id' => $pasalId,
            'before' => null,
        ];
        $pasal['bab_id'] = $row['bab_id'] !== null ? (int) $row['bab_id'] : null;
        $pasal['bab_nomor_label'] = $row['bab_nomor_label'];
        $pasal['bab_judul'] = $row['judul_bab'];
        $pasal['bab_urutan'] = (int) ($row['bab_urutan'] ?? 0);
        $pasal['nomor_label'] = $row['nomor_label'];
        $pasal['judul_pasal'] = $row['judul_pasal'] ?? '';
        $pasal['urutan'] = (int) $row['urutan'];
        $pasal['after'] = [
            'versi_id' => (int) $row['pasal_version_id'],
            'isi' => hukum_review_preview_decode_content((string) $row['isi']),
        ];
        $pasal['staged'] = true;
        $pasals[$pasalId] = $pasal;
    }

    $deletionStmt = $pdo->prepare(
        'SELECT entity_type, entity_id, reason, snapshot_json
         FROM hukum_staging_deletion WHERE staging_id = ? ORDER BY id'
    );
    $deletionStmt->execute([$stagingId]);
    $deletedBabs = [];
    foreach ($deletionStmt->fetchAll(PDO::FETCH_ASSOC) as $deletion) {
        $snapshot = hukum_review_preview_decode_content((string) $deletion['snapshot_json']);
        $type = (string) $deletion['entity_type'];
        $reason = (string) $deletion['reason'];
        if ($type === 'bab') {
            $deletedBabs[] = [
                'bab' => $snapshot['bab'] ?? [],
                'reason' => $reason,
            ];
        }
        foreach (($snapshot['pasals'] ?? []) as $removedPasal) {
            $pasalId = (int) ($removedPasal['pasal_id'] ?? 0);
            if ($pasalId <= 0) {
                continue;
            }
            $pasals[$pasalId] = [
                'pasal_id' => $pasalId,
                'bab_id' => isset($removedPasal['bab_id']) ? (int) $removedPasal['bab_id'] : null,
                'bab_nomor_label' => (string) ($removedPasal['bab_nomor_label'] ?? ''),
                'bab_judul' => (string) ($removedPasal['bab_judul'] ?? ''),
                'bab_urutan' => (int) ($removedPasal['bab_urutan'] ?? 0),
                'nomor_label' => (string) ($removedPasal['nomor_label'] ?? ''),
                'judul_pasal' => (string) ($removedPasal['judul_pasal'] ?? ''),
                'urutan' => (int) ($removedPasal['urutan'] ?? 0),
                'before' => [
                    'versi_id' => (int) ($removedPasal['pasal_versi_id'] ?? 0),
                    'isi' => is_array($removedPasal['isi'] ?? null) ? $removedPasal['isi'] : [],
                ],
                'after' => null,
                'staged' => true,
                'removed' => true,
                'deletion_reason' => $reason,
                'deletion_entity_type' => $type,
            ];
        }
    }

    foreach ($pasals as &$pasal) {
        $pasal['after'] = !empty($pasal['removed']) ? null : ($pasal['after'] ?? $pasal['before']);
        $pasal['change'] = !empty($pasal['removed'])
            ? 'removed'
            : (!$pasal['staged']
            ? 'unchanged'
            : ($pasal['before'] === null
                ? 'added'
                : ($pasal['before']['versi_id'] === $pasal['after']['versi_id']
                    ? 'unchanged'
                    : 'modified')));
        unset($pasal['staged']);
    }
    unset($pasal);

    usort($pasals, static function (array $left, array $right): int {
        return [
            (int) ($left['bab_urutan'] ?? 0),
            (int) ($left['urutan'] ?? 0),
            (int) $left['pasal_id'],
        ] <=> [
            (int) ($right['bab_urutan'] ?? 0),
            (int) ($right['urutan'] ?? 0),
            (int) $right['pasal_id'],
        ];
    });

    return [
        'document' => [
            'id' => $documentId,
            'judul' => (string) $staging['judul'],
            'pembukaan' => hukum_review_preview_document_opening($staging),
        ],
        'status' => (string) $staging['status'],
        'base_commit_id' => $baseCommitId,
        'pasals' => array_values($pasals),
        'deleted_babs' => $deletedBabs,
    ];
}

<?php

function hukum_review_resolve_role(int $documentId, ?int $userId = null): ?string
{
    $candidateId = $userId ?? hukum_current_user_id();
    if ($candidateId <= 0 || $documentId <= 0) {
        return null;
    }

    $periodeId = hukum_period_for_document($documentId);
    if ($periodeId === null || $periodeId <= 0) {
        return null;
    }

    $role = strtolower((string) hukum_current_user_role());
    $technicalAdmin = in_array($role, ['admin', 'superadmin', 'ketua_umum_bpm'], true);

    if (hukum_is_komisi_i($candidateId, (int) $periodeId) && in_array($role, ['komisi_i', 'admin', 'superadmin'], true)) {
        return 'komisi_i';
    }

    if (hukum_is_ketua_umum($candidateId, (int) $periodeId) && $technicalAdmin) {
        return 'ketua_umum';
    }

    return null;
}

function hukum_review_approval_rows(PDO $pdo, int $stagingId): array
{
    $rows = dbFetchAll(
        'SELECT id, staging_id, user_id, peran, status, note, approved_at, rejected_at, created_at
         FROM hukum_staging_approval
         WHERE staging_id = ?
         ORDER BY CASE peran WHEN \'komisi_i\' THEN 1 WHEN \'ketua_umum\' THEN 2 ELSE 3 END, id ASC',
        [$stagingId]
    );

    $summary = ['komisi_i' => ['status' => 'menunggu', 'user_id' => null, 'note' => null], 'ketua_umum' => ['status' => 'menunggu', 'user_id' => null, 'note' => null], 'approved' => 0, 'total' => 2];
    foreach ($rows as $row) {
        $role = (string) ($row['peran'] ?? '');
        if (isset($summary[$role])) {
            $summary[$role] = [
                'status' => (string) $row['status'],
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'note' => $row['note'] ?? null,
                'approved_at' => $row['approved_at'] ?? null,
                'rejected_at' => $row['rejected_at'] ?? null,
            ];
        }
        if ((string) $row['status'] === 'disetujui') {
            $summary['approved'] += 1;
        }
    }

    $summary['progress'] = $summary['approved'] . '/2';
    return ['rows' => $rows, 'summary' => $summary];
}

function hukum_review_apply_decision(PDO $pdo, int $stagingId, string $decision, ?string $note, ?int $actorId = null): array
{
    $actorId = $actorId ?? hukum_current_user_id();
    if ($stagingId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        throw new RuntimeException('Decision tidak valid.', 400);
    }

    $staging = dbFetchOne(
        'SELECT s.*, w.dokumen_id, w.status AS workspace_status, d.periode_id, d.judul
         FROM hukum_staging s
         JOIN hukum_workspace w ON w.id = s.workspace_id
         JOIN hukum_dokumen d ON d.id = w.dokumen_id
         WHERE s.id = ? FOR UPDATE',
        [$stagingId]
    );
    if (!$staging) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $documentId = (int) $staging['dokumen_id'];
    $periodeId = (int) $staging['periode_id'];
    $role = hukum_review_resolve_role($documentId, $actorId);
    if ($role === null) {
        throw new RuntimeException('Anda tidak berwenang meninjau staging dokumen ini.', 403);
    }

    $existing = dbFetchOne(
        'SELECT * FROM hukum_staging_approval WHERE staging_id = ? AND peran = ? FOR UPDATE',
        [$stagingId, $role]
    );
    if (!$existing) {
        $pdo->prepare(
            'INSERT INTO hukum_staging_approval (staging_id, user_id, peran, status, note, created_at, updated_at)
             VALUES (?, ?, ?, \'menunggu\', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([$stagingId, $actorId, $role]);
    }

    if ((string) $staging['status'] === 'ditolak') {
        throw new RuntimeException('Staging sudah ditolak.', 409);
    }

    if ($decision === 'reject') {
        if (trim((string) ($note ?? '')) === '') {
            throw new RuntimeException('Catatan wajib saat menolak staging.', 400);
        }
        $pdo->prepare(
            'UPDATE hukum_staging_approval
             SET user_id = ?, status = \'ditolak\', note = ?, rejected_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE staging_id = ? AND peran = ?'
        )->execute([$actorId, trim((string) $note), $stagingId, $role]);
        $pdo->prepare(
            'UPDATE hukum_staging_approval
             SET user_id = ?, status = \'ditolak\', note = ?, rejected_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE staging_id = ? AND peran <> ?'
        )->execute([$actorId, trim((string) $note), $stagingId, $role]);
        $pdo->prepare(
            'UPDATE hukum_staging
             SET status = \'ditolak\', direview_oleh = ?, direview_at = CURRENT_TIMESTAMP, review_note = ?
             WHERE id = ?'
        )->execute([$actorId, trim((string) $note), $stagingId]);
        $pdo->prepare(
            'UPDATE hukum_workspace SET status = \'aktif\', updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$staging['workspace_id']]);
        $versions = dbFetchAll('SELECT pasal_versi_id FROM hukum_staging_versi WHERE staging_id = ?', [$stagingId]);
        foreach ($versions as $version) {
            $pdo->prepare(
                'UPDATE hukum_pasal_versi
                 SET status = \'rejected\', rejected_at = CURRENT_TIMESTAMP, rejected_by = ?, rejection_reason = ?
                 WHERE id = ? AND status = \'staged\''
            )->execute([$actorId, trim((string) $note), (int) $version['pasal_versi_id']]);
        }
        return ['status' => 'ditolak', 'role' => $role, 'note' => trim((string) $note)];
    }

    $ownApproval = dbFetchOne(
        'SELECT * FROM hukum_staging_approval WHERE staging_id = ? AND peran = ? FOR UPDATE',
        [$stagingId, $role]
    );
    if (!$ownApproval || (string) $ownApproval['status'] !== 'menunggu') {
        throw new RuntimeException('Approval untuk peran Anda sudah diproses.', 409);
    }

    $pdo->prepare(
        'UPDATE hukum_staging_approval
         SET user_id = ?, status = \'disetujui\', note = ?, approved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE staging_id = ? AND peran = ? AND status = \'menunggu\''
    )->execute([$actorId, $note ?? null, $stagingId, $role]);

    $approvalRows = dbFetchAll('SELECT peran, status FROM hukum_staging_approval WHERE staging_id = ?', [$stagingId]);
    $allApproved = true;
    foreach (['komisi_i', 'ketua_umum'] as $requiredRole) {
        $found = false;
        foreach ($approvalRows as $row) {
            if ((string) $row['peran'] === $requiredRole && (string) $row['status'] === 'disetujui') {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $allApproved = false;
            break;
        }
    }

    if ($allApproved) {
        $pdo->prepare(
            'UPDATE hukum_staging
             SET status = \'disetujui\', direview_oleh = ?, direview_at = CURRENT_TIMESTAMP, review_note = ?
             WHERE id = ?'
        )->execute([$actorId, $note ?? null, $stagingId]);
        return ['status' => 'disetujui', 'role' => $role, 'total_approved' => 2];
    }

    $pdo->prepare(
        'UPDATE hukum_staging
         SET status = \'menunggu_review\', direview_oleh = ?, direview_at = CURRENT_TIMESTAMP, review_note = ?
         WHERE id = ?'
    )->execute([$actorId, $note ?? null, $stagingId]);
    return ['status' => 'menunggu_review', 'role' => $role, 'total_approved' => 1];
}

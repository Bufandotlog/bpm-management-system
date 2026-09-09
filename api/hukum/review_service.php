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

function hukum_review_resolve_role_on(PDO $pdo, int $documentId, int $userId, int $periodeId): ?string
{
    if ($documentId <= 0 || $userId <= 0 || $periodeId <= 0) {
        return null;
    }

    $role = strtolower((string) hukum_current_user_role());
    $membership = $pdo->prepare(
        'SELECT jabatan FROM hukum_keanggotaan
         WHERE user_id = ? AND periode_id = ? AND aktif = 1
           AND jabatan IN (\'komisi_i\', \'ketua_umum\')
           AND (selesai_pada IS NULL OR selesai_pada >= CURDATE())'
    );
    $membership->execute([$userId, $periodeId]);
    $roles = [];
    foreach ($membership->fetchAll(PDO::FETCH_COLUMN) as $membershipRole) {
        $roles[(string) $membershipRole] = true;
    }

    if (isset($roles['komisi_i']) && in_array($role, ['komisi_i', 'admin', 'superadmin'], true)) {
        return 'komisi_i';
    }
    if (isset($roles['ketua_umum']) && hukum_technical_role_is_admin($role)) {
        return 'ketua_umum';
    }

    return null;
}

function hukum_review_approval_rows(PDO $pdo, int $stagingId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, staging_id, user_id, peran, status, note, approved_at, rejected_at, created_at
         FROM hukum_staging_approval
         WHERE staging_id = ?
         ORDER BY CASE peran WHEN \'komisi_i\' THEN 1 WHEN \'ketua_umum\' THEN 2 ELSE 3 END, id ASC'
    );
    $stmt->execute([$stagingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $stagingStmt = $pdo->prepare(
        'SELECT s.*, w.dokumen_id, w.status AS workspace_status, d.periode_id, d.judul
         FROM hukum_staging s
         JOIN hukum_workspace w ON w.id = s.workspace_id
         JOIN hukum_dokumen d ON d.id = w.dokumen_id
         WHERE s.id = ? FOR UPDATE',
    );
    $stagingStmt->execute([$stagingId]);
    $staging = $stagingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staging) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $documentId = (int) $staging['dokumen_id'];
    $periodeId = (int) $staging['periode_id'];
    $role = hukum_review_resolve_role_on($pdo, $documentId, $actorId, $periodeId);
    if ($role === null) {
        throw new RuntimeException('Anda tidak berwenang meninjau staging dokumen ini.', 403);
    }
    if ((string) $staging['status'] !== 'menunggu_review') {
        throw new RuntimeException('Staging tidak berada pada status review.', 409);
    }

    $existingStmt = $pdo->prepare(
        'SELECT * FROM hukum_staging_approval WHERE staging_id = ? AND peran = ? FOR UPDATE',
    );
    $existingStmt->execute([$stagingId, $role]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
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

        $otherStmt = $pdo->prepare(
            'SELECT user_id FROM hukum_staging_approval
             WHERE staging_id = ? AND peran <> ? AND status = \'disetujui\' LIMIT 1',
        );
        $otherStmt->execute([$stagingId, $role]);
        $other = $otherStmt->fetch(PDO::FETCH_ASSOC);
        if ($other !== null && (int) $other['user_id'] === $actorId) {
            throw new RuntimeException('Satu aktor tidak dapat memenuhi dua approval.', 403);
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
        $versionsStmt = $pdo->prepare('SELECT pasal_versi_id FROM hukum_staging_versi WHERE staging_id = ?');
        $versionsStmt->execute([$stagingId]);
        $versions = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($versions as $version) {
            $pdo->prepare(
                'UPDATE hukum_pasal_versi
                 SET status = \'rejected\', rejected_at = CURRENT_TIMESTAMP, rejected_by = ?, rejection_reason = ?
                 WHERE id = ? AND status = \'staged\''
            )->execute([$actorId, trim((string) $note), (int) $version['pasal_versi_id']]);
        }

        return ['status' => 'ditolak', 'role' => $role, 'note' => trim((string) $note)];
    }

    $ownApprovalStmt = $pdo->prepare(
        'SELECT * FROM hukum_staging_approval WHERE staging_id = ? AND peran = ? FOR UPDATE',
    );
    $ownApprovalStmt->execute([$stagingId, $role]);
    $ownApproval = $ownApprovalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ownApproval || (string) $ownApproval['status'] !== 'menunggu') {
        throw new RuntimeException('Approval untuk peran Anda sudah diproses.', 409);
    }

    $pdo->prepare(
        'UPDATE hukum_staging_approval
         SET user_id = ?, status = \'disetujui\', note = ?, approved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE staging_id = ? AND peran = ? AND status = \'menunggu\''
    )->execute([$actorId, $note ?? null, $stagingId, $role]);

    $approvalRowsStmt = $pdo->prepare('SELECT peran, status FROM hukum_staging_approval WHERE staging_id = ?');
    $approvalRowsStmt->execute([$stagingId]);
    $approvalRows = $approvalRowsStmt->fetchAll(PDO::FETCH_ASSOC);
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
        $pdo->prepare(
            'UPDATE hukum_workspace
             SET status = \'siap_commit\', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = \'diajukan\''
        )->execute([(int) $staging['workspace_id']]);
        return ['status' => 'disetujui', 'role' => $role, 'total_approved' => 2];
    }

    $pdo->prepare(
        'UPDATE hukum_staging
         SET status = \'menunggu_review\', direview_oleh = ?, direview_at = CURRENT_TIMESTAMP, review_note = ?
         WHERE id = ?'
    )->execute([$actorId, $note ?? null, $stagingId]);
    return ['status' => 'menunggu_review', 'role' => $role, 'total_approved' => 1];
}

function hukum_review_decide(PDO $pdo, int $stagingId, string $decision, ?string $note = null, ?int $actorId = null): array
{
    $actorId = $actorId ?? hukum_current_user_id();
    $pdo->beginTransaction();
    try {
        $result = hukum_review_apply_decision($pdo, $stagingId, $decision, $note, $actorId);
        $stagingStmt = $pdo->prepare(
            'SELECT s.workspace_id, w.dokumen_id, d.periode_id
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             WHERE s.id = ?',
        );
        $stagingStmt->execute([$stagingId]);
        $staging = $stagingStmt->fetch(PDO::FETCH_ASSOC);
        if ($staging === null) {
            throw new RuntimeException('Staging tidak ditemukan.', 404);
        }
        hukum_audit($pdo, 'hukum_staging', $stagingId, 'review_' . $decision, null, [
            'status' => $result['status'],
            'role' => $result['role'],
            'decision' => $decision,
        ], [
            'periode_id' => (int) $staging['periode_id'],
            'context_json' => ['document_id' => (int) $staging['dokumen_id'], 'decision' => $decision],
            'result' => 'success',
        ]);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

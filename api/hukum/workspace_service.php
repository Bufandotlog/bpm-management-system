<?php
require_once __DIR__ . '/_bootstrap.php';

function hukum_transition_workspace_status(PDO $pdo, int $workspaceId, string $newStatus, int $actorId, array $meta = []): array
{
    $workspace = dbFetchOne(
        'SELECT ws.*, d.periode_id FROM hukum_workspace ws JOIN hukum_dokumen d ON d.id = ws.dokumen_id WHERE ws.id = ? LIMIT 1',
        [$workspaceId]
    );

    if (!$workspace) {
        throw new RuntimeException('Workspace tidak ditemukan.', 404);
    }

    $allowed = [
        'aktif',
        'diajukan',
        'siap_commit',
        'committed',
        'ditutup',
        'dibatalkan',
    ];

    if (!in_array($newStatus, $allowed, true)) {
        throw new RuntimeException('Status workspace tidak valid.', 400);
    }

    $before = [
        'id' => (int) $workspace['id'],
        'status' => (string) $workspace['status'],
        'dokumen_id' => (int) $workspace['dokumen_id'],
    ];

    $pdo->prepare(
        'UPDATE hukum_workspace SET status = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$newStatus, $workspaceId]);

    $after = [
        'id' => (int) $workspaceId,
        'status' => $newStatus,
        'dokumen_id' => (int) $workspace['dokumen_id'],
    ];

    hukum_audit($pdo, 'hukum_workspace', $workspaceId, 'status_transition', $before, $after, [
        'role_context' => strtolower((string) ($_SESSION['admin_role'] ?? '')),
        'periode_id' => (int) $workspace['periode_id'],
        'context_json' => ['actor_id' => $actorId, 'new_status' => $newStatus, 'meta' => $meta],
        'result' => 'success',
    ]);

    return ['before' => $before, 'after' => $after];
}

function hukum_create_workspace(PDO $pdo, int $documentId, string $judulPerubahan, ?string $tujuan = null, int $actorId = 0): array
{
    $actorId = $actorId > 0 ? $actorId : hukum_current_user_id();

    $doc = dbFetchOne('SELECT id, periode_id FROM hukum_dokumen WHERE id = ? FOR UPDATE', [$documentId]);
    if (!$doc) {
        throw new RuntimeException('Dokumen tidak ditemukan.', 404);
    }

    if ($actorId <= 0) {
        throw new RuntimeException('Sesi tidak valid untuk membuat workspace.', 401);
    }

    if (!hukum_can_review_staging($documentId, $actorId) && !hukum_is_komisi_i($actorId, (int) $doc['periode_id']) && !hukum_is_ketua_umum($actorId, (int) $doc['periode_id'])) {
        throw new RuntimeException('Anda tidak berwenang membuat workspace untuk dokumen ini.', 403);
    }

    $active = dbFetchOne(
        "SELECT id FROM hukum_workspace WHERE dokumen_id = ? AND status IN ('aktif','diajukan','siap_commit') LIMIT 1 FOR UPDATE",
        [$documentId]
    );

    if ($active) {
        throw new RuntimeException('Dokumen sudah memiliki workspace aktif atau diajukan.', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO hukum_workspace (dokumen_id, judul_perubahan, tujuan, status, dibuat_oleh) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $documentId,
        trim($judulPerubahan),
        $tujuan,
        'aktif',
        $actorId,
    ]);

    $workspaceId = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_workspace', $workspaceId, 'create', null, [
        'dokumen_id' => $documentId,
        'judul_perubahan' => trim($judulPerubahan),
        'status' => 'aktif',
    ], [
        'role_context' => strtolower((string) ($_SESSION['admin_role'] ?? '')),
        'periode_id' => (int) $doc['periode_id'],
        'context_json' => ['actor_id' => $actorId],
        'result' => 'success',
    ]);

    return ['id' => $workspaceId, 'status' => 'aktif'];
}

function hukum_withdraw_workspace(PDO $pdo, int $workspaceId, int $actorId, ?string $reason = null): array
{
    $actorId = $actorId > 0 ? $actorId : hukum_current_user_id();

    $workspace = dbFetchOne(
        'SELECT ws.*, d.periode_id FROM hukum_workspace ws JOIN hukum_dokumen d ON d.id = ws.dokumen_id WHERE ws.id = ? LIMIT 1',
        [$workspaceId]
    );

    if (!$workspace) {
        throw new RuntimeException('Workspace tidak ditemukan.', 404);
    }

    $allowedStatus = ['diajukan', 'aktif'];
    if (!in_array((string) $workspace['status'], $allowedStatus, true)) {
        throw new RuntimeException('Withdrawal hanya diizinkan untuk workspace yang masih aktif atau diajukan.', 409);
    }

    $stage = dbFetchOne('SELECT id, status FROM hukum_staging WHERE workspace_id = ? LIMIT 1', [$workspaceId]);
    if ($stage && in_array((string) $stage['status'], ['menunggu_review', 'disetujui'], true)) {
        throw new RuntimeException('Workspace tidak dapat ditarik setelah staging atau approval final berjalan.', 409);
    }

    if ($stage) {
        $pdo->prepare('UPDATE hukum_staging SET status = ?, review_note = ? WHERE id = ?')
            ->execute(['dibatalkan', $reason ?: 'Ditarik kembali oleh pemegang otoritas', $stage['id']]);
    }

    $pdo->prepare('UPDATE hukum_workspace SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['aktif', $workspaceId]);

    $before = ['id' => (int) $workspaceId, 'status' => (string) $workspace['status']];
    $after = ['id' => (int) $workspaceId, 'status' => 'aktif'];

    hukum_audit($pdo, 'hukum_workspace', $workspaceId, 'withdraw', $before, $after, [
        'role_context' => strtolower((string) ($_SESSION['admin_role'] ?? '')),
        'periode_id' => (int) $workspace['periode_id'],
        'context_json' => ['actor_id' => $actorId, 'reason' => $reason ?: null, 'staging_id' => $stage['id'] ?? null],
        'result' => 'success',
    ]);

    return ['id' => (int) $workspaceId, 'status' => 'aktif'];
}

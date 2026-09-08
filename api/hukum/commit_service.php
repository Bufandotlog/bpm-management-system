<?php

require_once __DIR__ . '/_bootstrap.php';

function hukum_commit_sort_recursive(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = hukum_commit_sort_recursive($item);
        }
        if (array_keys($normalized) !== range(0, count($normalized) - 1)) {
            ksort($normalized);
        }
        return $normalized;
    }

    return $value;
}

function hukum_commit_canonical_json(mixed $value): string
{
    $encoded = json_encode(hukum_commit_sort_recursive($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === false) {
        throw new RuntimeException('Snapshot tidak dapat dikanonisasi.', 500);
    }
    return $encoded;
}

function hukum_commit_hash_from_snapshot(?string $parentHash, array $snapshot, array $meta): string
{
    $payload = [
        'schema_version' => 'hukum-commit-v1',
        'parent_hash' => $parentHash,
        'snapshot' => $snapshot,
        'meta' => hukum_commit_sort_recursive($meta),
    ];

    return hash('sha256', hukum_commit_canonical_json($payload));
}

function hukum_commit_period_for_document_id(int $documentId): ?int
{
    $row = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ? LIMIT 1', [$documentId]);
    return $row === null || !isset($row['periode_id']) ? null : (int) $row['periode_id'];
}

function hukum_commit_user_must_be_business_role(int $userId, int $documentId, string $peran): bool
{
    $periodId = hukum_commit_period_for_document_id($documentId);
    if ($periodId === null || $periodId <= 0 || $userId <= 0) {
        return false;
    }

    $peran = strtolower(trim($peran));
    if (!in_array($peran, ['komisi_i', 'ketua_umum'], true)) {
        return false;
    }

    if ($peran === 'komisi_i') {
        return hukum_is_komisi_i($userId, $periodId);
    }

    return hukum_is_ketua_umum($userId, $periodId);
}

function hukum_commit_verify_password(PDO $pdo, int $userId, string $password): bool
{
    if ($userId <= 0 || trim($password) === '') {
        return false;
    }

    $row = dbFetchOne('SELECT password FROM users WHERE id = ? LIMIT 1', [$userId]);
    if ($row === null || !isset($row['password'])) {
        return false;
    }

    return password_verify($password, (string) $row['password']);
}

function hukum_commit_cooldown_row(PDO $pdo, int $userId, ?string $sessionId = null): ?array
{
    $sessionId = $sessionId ?? session_id();
    $row = dbFetchOne(
        'SELECT * FROM hukum_commit_lockout WHERE user_id = ? AND session_id = ? LIMIT 1',
        [$userId, $sessionId]
    );
    return $row !== false && $row !== null ? $row : null;
}

function hukum_commit_apply_failed_attempt(PDO $pdo, int $userId, string $reason, ?string $sessionId = null): void
{
    $sessionId = $sessionId ?? session_id();
    $row = hukum_commit_cooldown_row($pdo, $userId, $sessionId);
    $failedAttempts = (int) ($row['failed_attempts'] ?? 0) + 1;
    $lockedUntil = null;
    if ($failedAttempts >= 3) {
        $lockedUntil = date('Y-m-d H:i:s', time() + 300);
    }

    if ($row) {
        $pdo->prepare(
            'UPDATE hukum_commit_lockout SET failed_attempts = ?, locked_until = ?, last_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$failedAttempts, $lockedUntil, $reason, (int) $row['id']]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO hukum_commit_lockout (user_id, session_id, failed_attempts, locked_until, last_reason, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    )->execute([$userId, $sessionId, $failedAttempts, $lockedUntil, $reason]);
}

function hukum_commit_check_cooldown(PDO $pdo, int $userId, ?string $sessionId = null): void
{
    $sessionId = $sessionId ?? session_id();
    $row = hukum_commit_cooldown_row($pdo, $userId, $sessionId);
    if ($row === null) {
        return;
    }
    $lockedUntil = $row['locked_until'] ?? null;
    if ($lockedUntil !== null && strtotime((string) $lockedUntil) > time()) {
        throw new RuntimeException('Akses commit dibatasi sementara karena password salah.', 423);
    }
    if ($lockedUntil !== null && strtotime((string) $lockedUntil) <= time()) {
        $pdo->prepare('DELETE FROM hukum_commit_lockout WHERE id = ?')->execute([(int) $row['id']]);
    }
}

function hukum_commit_window_row(PDO $pdo, int $userId, string $peran, ?int $commitId = null): ?array
{
    $peran = strtolower(trim($peran));
    if ($commitId === null) {
        $row = dbFetchOne(
            'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1',
            [$userId, $peran]
        );
    } else {
        $row = dbFetchOne(
            'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id = ? ORDER BY id DESC LIMIT 1',
            [$userId, $peran, $commitId]
        );
    }
    return $row !== null && $row !== false ? $row : null;
}

function hukum_commit_create_window(PDO $pdo, int $userId, string $peran, ?string $password = null, ?string $sessionId = null): array
{
    $sessionId = $sessionId ?? session_id();
    $peran = strtolower(trim($peran));
    if (!in_array($peran, ['komisi_i', 'ketua_umum'], true)) {
        throw new RuntimeException('Peran commit tidak valid.', 400);
    }

    $row = dbFetchOne(
        'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1',
        [$userId, $peran]
    );

    if ($password !== null && trim($password) !== '') {
        if (!hukum_commit_verify_password($pdo, $userId, $password)) {
            hukum_commit_apply_failed_attempt($pdo, $userId, 'wrong_password', $sessionId);
            throw new RuntimeException('Password verifikasi commit tidak valid.', 403);
        }

        hukum_commit_check_cooldown($pdo, $userId, $sessionId);
        if ($row) {
            $pdo->prepare(
                'UPDATE hukum_commit_window SET status = ?, completed_at = CURRENT_TIMESTAMP, result = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute(['approved', 'verified', date('Y-m-d H:i:s', time() + 300), (int) $row['id']]);
            return ['status' => 'approved', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => date('Y-m-d H:i:s', time() + 300)];
        }

        $pdo->prepare(
            'INSERT INTO hukum_commit_window (user_id, peran, session_id, status, initiated_at, expires_at, completed_at, result, created_at, updated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([$userId, $peran, $sessionId, 'approved', date('Y-m-d H:i:s', time() + 300), 'verified']);
        return ['status' => 'approved', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => date('Y-m-d H:i:s', time() + 300)];
    }

    if ($row) {
        return ['status' => $row['status'], 'peran' => $peran, 'user_id' => $userId, 'expires_at' => $row['expires_at'] ?? null];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    $pdo->prepare(
        'INSERT INTO hukum_commit_window (user_id, peran, session_id, status, initiated_at, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    )->execute([$userId, $peran, $sessionId, 'pending', $expiresAt]);

    return ['status' => 'pending', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => $expiresAt];
}

function hukum_commit_snapshot_for_staging(PDO $pdo, int $stagingId): array
{
    $staging = dbFetchOne(
        'SELECT s.id, s.workspace_id, w.dokumen_id FROM hukum_staging s JOIN hukum_workspace w ON w.id = s.workspace_id WHERE s.id = ? LIMIT 1',
        [$stagingId]
    );
    if ($staging === null || !isset($staging['dokumen_id'])) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $links = dbFetchAll(
        'SELECT sv.pasal_versi_id, pv.pasal_id, pv.hash_konten, pv.isi
         FROM hukum_staging_versi sv
         JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
         WHERE sv.staging_id = ?
         ORDER BY pv.pasal_id ASC',
        [$stagingId]
    );

    $snapshot = [];
    foreach ($links as $link) {
        $snapshot[] = [
            'pasal_id' => (int) $link['pasal_id'],
            'versi_id' => (int) $link['pasal_versi_id'],
            'hash_konten' => (string) $link['hash_konten'],
            'isi' => json_decode((string) $link['isi'], true) ?? [],
        ];
    }

    return [
        'dokumen_id' => (int) $staging['dokumen_id'],
        'workspace_id' => (int) $staging['workspace_id'],
        'snapshot' => $snapshot,
    ];
}

function hukum_commit_snapshot_graph(PDO $pdo, int $commitId, int $documentId): void
{
    $documentId = (int) $documentId;
    $commitId = (int) $commitId;
    if ($commitId <= 0 || $documentId <= 0) {
        throw new RuntimeException('Commit dan dokumen snapshot wajib valid.', 400);
    }

    $nodes = dbFetchAll(
        'SELECT p.id AS pasal_id, p.nomor_label, pv.id AS pasal_version_id
         FROM hukum_pasal p
         LEFT JOIN hukum_pasal_versi pv ON pv.pasal_id = p.id AND pv.status IN (\'committed\', \'staged\', \'draft\')
         WHERE p.dokumen_id = ?
         ORDER BY p.id ASC, pv.id DESC',
        [$documentId]
    );

    foreach ($nodes as $node) {
        $insert = $pdo->prepare(
            'INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $commitId,
            $documentId,
            (int) $node['pasal_id'],
            $node['pasal_version_id'] !== null ? (int) $node['pasal_version_id'] : null,
            $node['nomor_label'] ?? null,
            json_encode([
                'pasal_id' => (int) $node['pasal_id'],
                'nomor_label' => $node['nomor_label'] ?? null,
                'pasal_version_id' => $node['pasal_version_id'] !== null ? (int) $node['pasal_version_id'] : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $edges = dbFetchAll(
        'SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi, r.source_version_id, r.target_version_id
         FROM hukum_relasi_pasal r
         JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id
         JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id
         WHERE pa.dokumen_id = ? AND pi.dokumen_id = ?',
        [$documentId, $documentId]
    );

    foreach ($edges as $edge) {
        $snapshotId = dbFetchOne(
            'SELECT id FROM hukum_graph_snapshot WHERE commit_id = ? AND pasal_id = ? ORDER BY id DESC LIMIT 1',
            [$commitId, (int) $edge['pasal_anak_id']]
        );
        $targetSnapshot = dbFetchOne(
            'SELECT id FROM hukum_graph_snapshot WHERE commit_id = ? AND pasal_id = ? ORDER BY id DESC LIMIT 1',
            [$commitId, (int) $edge['pasal_induk_id']]
        );
        if (!$snapshotId || !$targetSnapshot) {
            continue;
        }

        $pdo->prepare(
            'INSERT INTO hukum_graph_snapshot_edge (snapshot_id, source_pasal_id, target_pasal_id, source_version_id, target_version_id, jenis_relasi, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([
            (int) $snapshotId['id'],
            (int) $edge['pasal_anak_id'],
            (int) $edge['pasal_induk_id'],
            $edge['source_version_id'] !== null ? (int) $edge['source_version_id'] : null,
            $edge['target_version_id'] !== null ? (int) $edge['target_version_id'] : null,
            (string) $edge['jenis_relasi'],
            json_encode([
                'relation_id' => (int) $edge['id'],
                'source_version_id' => $edge['source_version_id'] !== null ? (int) $edge['source_version_id'] : null,
                'target_version_id' => $edge['target_version_id'] !== null ? (int) $edge['target_version_id'] : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function hukum_commit_verify_snapshot(PDO $pdo, int $commitId): bool
{
    $commit = dbFetchOne(
        'SELECT * FROM hukum_commit WHERE id = ? LIMIT 1',
        [$commitId]
    );
    if ($commit === null) {
        return false;
    }

    $parentHash = null;
    if (!empty($commit['parent_commit_id'])) {
        $parent = dbFetchOne('SELECT hash_commit FROM hukum_commit WHERE id = ? LIMIT 1', [(int) $commit['parent_commit_id']]);
        if ($parent && isset($parent['hash_commit'])) {
            $parentHash = (string) $parent['hash_commit'];
        }
    }

    $snapshot = json_decode((string) ($commit['snapshot_tree'] ?? '[]'), true);
    $expected = hukum_commit_hash_from_snapshot($parentHash, is_array($snapshot) ? $snapshot : [], [
        'dokumen_id' => (int) ($commit['dokumen_id'] ?? 0),
        'forum_tipe' => (string) ($commit['forum_tipe'] ?? ''),
        'tanggal_forum' => (string) ($commit['tanggal_forum'] ?? ''),
        'created_at' => (string) ($commit['created_at'] ?? ''),
        'staging_id' => (int) ($commit['staging_id'] ?? 0),
    ]);

    return hash_equals((string) $commit['hash_commit'], $expected);
}

function hukum_commit_is_window_approved(PDO $pdo, int $userId, string $peran): bool
{
    $row = dbFetchOne(
        'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND status = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1',
        [$userId, strtolower(trim($peran)), 'approved']
    );
    if ($row === null) {
        return false;
    }
    $expiresAt = $row['expires_at'] ?? null;
    if ($expiresAt !== null && strtotime((string) $expiresAt) < time()) {
        $pdo->prepare('UPDATE hukum_commit_window SET status = ?, result = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['expired', 'expired', (int) $row['id']]);
        return false;
    }
    return true;
}

function hukum_commit_finalize(PDO $pdo, int $stagingId, int $actorId, string $password, ?string $requestId = null, ?string $sessionId = null): array
{
    if ($stagingId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Data commit tidak valid.', 400);
    }

    $staging = dbFetchOne(
        'SELECT s.*, w.dokumen_id, w.status AS workspace_status, d.periode_id
         FROM hukum_staging s
         JOIN hukum_workspace w ON w.id = s.workspace_id
         JOIN hukum_dokumen d ON d.id = w.dokumen_id
         WHERE s.id = ? LIMIT 1',
        [$stagingId]
    );
    if ($staging === null) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $documentId = (int) $staging['dokumen_id'];
    $periodId = (int) $staging['periode_id'];
    $role = null;
    foreach (['komisi_i', 'ketua_umum'] as $candidate) {
        if (hukum_commit_user_must_be_business_role($actorId, $documentId, $candidate)) {
            $role = $candidate;
            break;
        }
    }

    if ($role === null) {
        throw new RuntimeException('Aktor tidak berwenang untuk commit dokumen ini.', 403);
    }

    $approvalRows = dbFetchAll(
        'SELECT user_id, peran, status FROM hukum_staging_approval WHERE staging_id = ? ORDER BY peran ASC',
        [$stagingId]
    );
    $approvedRoles = [];
    foreach ($approvalRows as $row) {
        if ((string) $row['status'] === 'disetujui') {
            $approvedRoles[(string) $row['peran']] = (int) $row['user_id'];
        }
    }
    if (!isset($approvedRoles['komisi_i']) || !isset($approvedRoles['ketua_umum'])) {
        throw new RuntimeException('Staging belum memiliki dua persetujuan yang valid.', 409);
    }

    if (!hukum_commit_verify_password($pdo, $actorId, $password)) {
        hukum_commit_apply_failed_attempt($pdo, $actorId, 'wrong_password', $sessionId ?? session_id());
        throw new RuntimeException('Password commit tidak valid.', 403);
    }
    hukum_commit_check_cooldown($pdo, $actorId, $sessionId ?? session_id());

    $requiredRoles = ['komisi_i', 'ketua_umum'];
    $windowOk = true;
    foreach ($requiredRoles as $requiredRole) {
        $memberId = (int) ($approvedRoles[$requiredRole] ?? 0);
        if ($memberId <= 0 || !hukum_commit_is_window_approved($pdo, $memberId, $requiredRole)) {
            $windowOk = false;
            break;
        }
    }
    if (!$windowOk) {
        throw new RuntimeException('Window commit untuk dua pihak belum aktif atau sudah kadaluarsa.', 409);
    }

    $currentCommit = dbFetchOne(
        'SELECT id, hash_commit FROM hukum_commit WHERE dokumen_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
        [$documentId, 'aktif']
    );

    $snapshotData = hukum_commit_snapshot_for_staging($pdo, $stagingId);
    $parentHash = $currentCommit !== null && isset($currentCommit['hash_commit']) ? (string) $currentCommit['hash_commit'] : null;
    $hash = hukum_commit_hash_from_snapshot($parentHash, $snapshotData['snapshot'], [
        'dokumen_id' => $documentId,
        'staging_id' => $stagingId,
        'forum_tipe' => 'finalisasi',
        'tanggal_forum' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO hukum_commit (dokumen_id, staging_id, parent_commit_id, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, status, dibuat_oleh, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([
            $documentId,
            $stagingId,
            $currentCommit !== null ? (int) $currentCommit['id'] : null,
            $hash,
            json_encode($snapshotData['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finalisasi',
            date('Y-m-d'),
            'aktif',
            $actorId,
        ]);

        $commitId = (int) $pdo->lastInsertId();

        if ($currentCommit !== null) {
            $pdo->prepare('UPDATE hukum_commit SET status = ?, replaced_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['digantikan', (int) $currentCommit['id']]);
        }

        $pdo->prepare('UPDATE hukum_workspace SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['ditutup', (int) $staging['workspace_id']]);
        $pdo->prepare('UPDATE hukum_dokumen SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['aktif', $documentId]);
        $pdo->prepare('UPDATE hukum_staging SET status = ? WHERE id = ?')->execute(['disetujui', $stagingId]);

        $pdo->prepare(
            'UPDATE hukum_commit_window SET commit_id = ?, status = ?, completed_at = CURRENT_TIMESTAMP, result = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND peran = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1'
        )->execute([$commitId, 'approved', 'finalized', $actorId, $role]);

        $pdo->prepare(
            'UPDATE hukum_pasal_versi SET status = ? WHERE id IN (SELECT pasal_versi_id FROM hukum_staging_versi WHERE staging_id = ?)'
        )->execute(['committed', $stagingId]);

        hukum_commit_snapshot_graph($pdo, $commitId, $documentId);

        $requestIdValue = $requestId ?? ('hukum-commit-' . bin2hex(random_bytes(4)));
        $pdo->prepare(
            'INSERT INTO hukum_audit_log (entitas, entitas_id, aksi, aktor_id, role_context, periode_id, request_id, result, context_json, sebelum_json, sesudah_json, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'hukum_commit',
            $commitId,
            'finalize',
            $actorId,
            $role,
            $periodId,
            $requestIdValue,
            'success',
            json_encode(['staging_id' => $stagingId, 'role' => $role]),
            json_encode(['before' => 'pending']),
            json_encode(['after' => 'committed', 'hash_commit' => $hash]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $pdo->commit();
        return ['success' => true, 'id' => $commitId, 'hash_commit' => $hash, 'status' => 'committed'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

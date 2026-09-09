<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../admin/core/hukum-clock.php';

function hukum_commit_test_failure_inject(string $point): void
{
    $environment = strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')));
    if ($environment === 'test' && ($GLOBALS['hukum_commit_test_failure_point'] ?? null) === $point) {
        throw new RuntimeException('TEST commit failure injection: ' . $point, 500);
    }
}

function hukum_commit_test_set_failure_point(?string $point): void
{
    $environment = strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')));
    if ($environment !== 'test') {
        throw new RuntimeException('Failure injection hanya tersedia pada APP_ENV=test.', 403);
    }
    $GLOBALS['hukum_commit_test_failure_point'] = $point;
}

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

function hukum_commit_user_must_be_business_role_on(PDO $pdo, int $userId, int $periodId, string $peran): bool
{
    if ($userId <= 0 || $periodId <= 0 || !in_array($peran, ['komisi_i', 'ketua_umum'], true)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM hukum_keanggotaan
         WHERE user_id = ? AND periode_id = ? AND jabatan = ? AND aktif = 1
           AND (selesai_pada IS NULL OR selesai_pada >= CURDATE())
         LIMIT 1'
    );
    $stmt->execute([$userId, $periodId, $peran]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function hukum_commit_verify_password(PDO $pdo, int $userId, string $password): bool
{
    if ($userId <= 0 || trim($password) === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === null || !isset($row['password'])) {
        return false;
    }

    return password_verify($password, (string) $row['password']);
}

function hukum_commit_cooldown_row(PDO $pdo, int $userId, ?string $sessionId = null): ?array
{
    $sessionId = $sessionId ?? session_id();
    $stmt = $pdo->prepare('SELECT * FROM hukum_commit_lockout WHERE user_id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$userId, $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false && $row !== null ? $row : null;
}

function hukum_commit_apply_failed_attempt(PDO $pdo, int $userId, string $reason, ?string $sessionId = null): void
{
    $sessionId = $sessionId ?? session_id();
    $row = hukum_commit_cooldown_row($pdo, $userId, $sessionId);
    $failedAttempts = (int) ($row['failed_attempts'] ?? 0) + 1;
    $lockedUntil = null;
    if ($failedAttempts >= 3) {
        $lockedUntil = hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s');
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
    if ($lockedUntil !== null && strtotime((string) $lockedUntil) > hukum_now_timestamp()) {
        throw new RuntimeException('Akses commit dibatasi sementara karena password salah.', 423);
    }
    if ($lockedUntil !== null && strtotime((string) $lockedUntil) <= hukum_now_timestamp()) {
        $pdo->prepare('DELETE FROM hukum_commit_lockout WHERE id = ?')->execute([(int) $row['id']]);
    }
}

function hukum_commit_window_row(PDO $pdo, int $userId, string $peran, ?int $commitId = null): ?array
{
    $peran = strtolower(trim($peran));
    if ($commitId === null) {
        $stmt = $pdo->prepare(
            'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $peran]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $peran, $commitId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== null && $row !== false ? $row : null;
}

function hukum_commit_create_window(PDO $pdo, int $userId, string $peran, ?string $password = null, ?string $sessionId = null): array
{
    $lockName = 'hukum_commit_window:' . $userId;
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Authorization commit sedang diproses oleh request lain.', 409);
    }

    try {
        return hukum_commit_create_window_unlocked($pdo, $userId, $peran, $password, $sessionId);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function hukum_commit_create_window_unlocked(PDO $pdo, int $userId, string $peran, ?string $password = null, ?string $sessionId = null): array
{
    $sessionId = $sessionId ?? session_id();
    $peran = strtolower(trim($peran));
    if (!in_array($peran, ['komisi_i', 'ketua_umum'], true)) {
        throw new RuntimeException('Peran commit tidak valid.', 400);
    }
    $otherRole = $peran === 'komisi_i' ? 'ketua_umum' : 'komisi_i';
    $stmt = $pdo->prepare(
        'SELECT id, status FROM hukum_commit_window
         WHERE user_id = ? AND peran = ? AND status IN (\'pending\', \'approved\')
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $otherRole]);
    $otherWindow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($otherWindow !== false) {
        throw new RuntimeException('Satu aktor tidak dapat menjadi dua pihak commit.', 403);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM hukum_commit_window WHERE user_id = ? AND peran = ? AND commit_id IS NULL ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $peran]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($password !== null && trim($password) !== '') {
        if (!hukum_commit_verify_password($pdo, $userId, $password)) {
            hukum_commit_apply_failed_attempt($pdo, $userId, 'wrong_password', $sessionId);
            throw new RuntimeException('Password verifikasi commit tidak valid.', 403);
        }

        hukum_commit_check_cooldown($pdo, $userId, $sessionId);
        if ($row) {
            $pdo->prepare(
                'UPDATE hukum_commit_window SET status = ?, completed_at = CURRENT_TIMESTAMP, result = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute(['approved', 'verified', hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s'), (int) $row['id']]);
            return ['status' => 'approved', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s')];
        }

        $pdo->prepare(
            'INSERT INTO hukum_commit_window (user_id, peran, session_id, status, initiated_at, expires_at, completed_at, result, created_at, updated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([$userId, $peran, $sessionId, 'approved', hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s'), 'verified']);
        return ['status' => 'approved', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s')];
    }

    if ($row) {
        return ['status' => $row['status'], 'peran' => $peran, 'user_id' => $userId, 'expires_at' => $row['expires_at'] ?? null];
    }

    $expiresAt = hukum_now()->modify('+300 seconds')->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO hukum_commit_window (user_id, peran, session_id, status, initiated_at, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    )->execute([$userId, $peran, $sessionId, 'pending', $expiresAt]);

    return ['status' => 'pending', 'peran' => $peran, 'user_id' => $userId, 'expires_at' => $expiresAt];
}

function hukum_commit_snapshot_for_staging(PDO $pdo, int $stagingId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.workspace_id, w.dokumen_id
         FROM hukum_staging s JOIN hukum_workspace w ON w.id = s.workspace_id
         WHERE s.id = ? LIMIT 1'
    );
    $stmt->execute([$stagingId]);
    $staging = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($staging === false || !isset($staging['dokumen_id'])) {
        throw new RuntimeException('Staging tidak ditemukan.', 404);
    }

    $stmt = $pdo->prepare(
        'SELECT sv.pasal_versi_id, pv.pasal_id, pv.hash_konten, pv.isi
         FROM hukum_staging_versi sv
         JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
         WHERE sv.staging_id = ?
         ORDER BY pv.pasal_id ASC'
    );
    $stmt->execute([$stagingId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $stmt = $pdo->prepare(
        'SELECT id, staging_id, parent_commit_id
         FROM hukum_commit
         WHERE id = ? AND dokumen_id = ?
         LIMIT 1'
    );
    $stmt->execute([$commitId, $documentId]);
    $commit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($commit === false || (int) $commit['staging_id'] <= 0) {
        throw new RuntimeException('Commit graph tidak memiliki staging yang valid.', 409);
    }

    $stmt = $pdo->prepare(
        'SELECT pv.pasal_id, pv.id AS pasal_version_id
         FROM hukum_staging_versi sv
         JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
         JOIN hukum_pasal p ON p.id = pv.pasal_id
         WHERE sv.staging_id = ? AND p.dokumen_id = ?'
    );
    $stmt->execute([(int) $commit['staging_id'], $documentId]);
    $stagedVersions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $canonicalVersions = [];
    foreach ($stagedVersions as $version) {
        $canonicalVersions[(int) $version['pasal_id']] = (int) $version['pasal_version_id'];
    }

    if (!empty($commit['parent_commit_id'])) {
        $stmt = $pdo->prepare(
            'SELECT pasal_id, pasal_version_id
             FROM hukum_graph_snapshot
             WHERE commit_id = ? AND dokumen_id = ?',
        );
        $stmt->execute([(int) $commit['parent_commit_id'], $documentId]);
        $parentNodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($parentNodes as $node) {
            $pasalId = (int) $node['pasal_id'];
            if (!array_key_exists($pasalId, $canonicalVersions)) {
                $canonicalVersions[$pasalId] = $node['pasal_version_id'] !== null
                    ? (int) $node['pasal_version_id']
                    : null;
            }
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id AS pasal_id, nomor_label
         FROM hukum_pasal
         WHERE dokumen_id = ?
         ORDER BY id ASC'
    );
    $stmt->execute([$documentId]);
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($nodes as $node) {
        $pasalId = (int) $node['pasal_id'];
        $versionId = $canonicalVersions[$pasalId] ?? null;
        $insert = $pdo->prepare(
            'INSERT INTO hukum_graph_snapshot (commit_id, dokumen_id, pasal_id, pasal_version_id, nomor_label, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $commitId,
            $documentId,
            $pasalId,
            $versionId,
            $node['nomor_label'] ?? null,
            json_encode([
                'pasal_id' => $pasalId,
                'nomor_label' => $node['nomor_label'] ?? null,
                'pasal_version_id' => $versionId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $changedPasalIds = array_fill_keys(
        array_map(static fn (array $version): int => (int) $version['pasal_id'], $stagedVersions),
        true
    );
    $edges = [];
    if (!empty($commit['parent_commit_id'])) {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.source_pasal_id AS pasal_anak_id, e.target_pasal_id AS pasal_induk_id,
                    e.jenis_relasi, e.source_version_id, e.target_version_id
             FROM hukum_graph_snapshot_edge e
             JOIN hukum_graph_snapshot s ON s.id = e.snapshot_id
             WHERE s.commit_id = ? AND s.dokumen_id = ?'
        );
        $stmt->execute([(int) $commit['parent_commit_id'], $documentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
            if (!isset($changedPasalIds[(int) $edge['pasal_anak_id']])
                && !isset($changedPasalIds[(int) $edge['pasal_induk_id']])) {
                $edges[] = $edge;
            }
        }
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.pasal_anak_id, r.pasal_induk_id, r.jenis_relasi, r.source_version_id, r.target_version_id
         FROM hukum_relasi_pasal r
         JOIN hukum_pasal pa ON pa.id = r.pasal_anak_id
         JOIN hukum_pasal pi ON pi.id = r.pasal_induk_id
         WHERE pa.dokumen_id = ? AND pi.dokumen_id = ?
           AND r.source_version_id IS NOT NULL AND r.target_version_id IS NOT NULL'
    );
    $stmt->execute([$documentId, $documentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        if (!empty($commit['parent_commit_id'])
            && !isset($changedPasalIds[(int) $edge['pasal_anak_id']])
            && !isset($changedPasalIds[(int) $edge['pasal_induk_id']])) {
            continue;
        }
        $edges[] = $edge;
    }

    foreach ($edges as $edge) {
        $sourcePasalId = (int) $edge['pasal_anak_id'];
        $targetPasalId = (int) $edge['pasal_induk_id'];
        if (!array_key_exists($sourcePasalId, $canonicalVersions)
            || !array_key_exists($targetPasalId, $canonicalVersions)) {
            continue;
        }

        $sourceVersionId = (int) $edge['source_version_id'];
        $targetVersionId = (int) $edge['target_version_id'];
        if ($sourceVersionId !== $canonicalVersions[$sourcePasalId]
            || $targetVersionId !== $canonicalVersions[$targetPasalId]) {
            continue;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM hukum_graph_snapshot
             WHERE commit_id = ? AND pasal_id = ? LIMIT 1'
        );
        $stmt->execute([$commitId, $sourcePasalId]);
        $snapshotId = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->execute([$commitId, $targetPasalId]);
        $targetSnapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$snapshotId || !$targetSnapshot) {
            continue;
        }

        $pdo->prepare(
            'INSERT INTO hukum_graph_snapshot_edge (snapshot_id, source_pasal_id, target_pasal_id, source_version_id, target_version_id, jenis_relasi, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([
            (int) $snapshotId['id'],
            $sourcePasalId,
            $targetPasalId,
            $sourceVersionId,
            $targetVersionId,
            (string) $edge['jenis_relasi'],
            json_encode([
                'relation_id' => (int) $edge['id'],
                'source_version_id' => $sourceVersionId,
                'target_version_id' => $targetVersionId,
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

function hukum_commit_is_window_approved(PDO $pdo, int $userId, string $peran): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM hukum_commit_window
         WHERE user_id = ? AND peran = ? AND status = ? AND commit_id IS NULL
         ORDER BY id DESC LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$userId, strtolower(trim($peran)), 'approved']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $expiresAt = $row['expires_at'] ?? null;
    if ($expiresAt !== null && strtotime((string) $expiresAt) < hukum_now_timestamp()) {
        $pdo->prepare('UPDATE hukum_commit_window SET status = ?, result = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['expired', 'expired', (int) $row['id']]);
        return null;
    }
    return $row;
}

function hukum_commit_assert_actor_identity(int $actorId): void
{
    $actor = hukum_authenticated_actor();
    if ($actor === null || $actor->id !== $actorId) {
        throw new RuntimeException('Identitas aktor commit tidak konsisten dengan sesi terautentikasi.', 403);
    }
    if (!hukum_technical_role_is_admin($actor->technicalRole)) {
        throw new RuntimeException('Aktor teknis tidak berwenang untuk finalisasi commit.', 403);
    }
}

function hukum_commit_finalize(PDO $pdo, int $stagingId, int $actorId, string $password, ?string $requestId = null, ?string $sessionId = null): array
{
    if ($stagingId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Data commit tidak valid.', 400);
    }
    hukum_commit_assert_actor_identity($actorId);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT s.*, w.dokumen_id, w.status AS workspace_status, d.periode_id
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             WHERE s.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$stagingId]);
        $staging = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($staging === false) {
            throw new RuntimeException('Staging tidak ditemukan.', 404);
        }
        if ((string) $staging['status'] !== 'disetujui'
            || (string) $staging['workspace_status'] !== 'siap_commit') {
            throw new RuntimeException('Staging belum berada pada state siap commit.', 409);
        }

        $workspaceLock = $pdo->prepare('SELECT id FROM hukum_workspace WHERE id = ? FOR UPDATE');
        $workspaceLock->execute([(int) $staging['workspace_id']]);
        if ($workspaceLock->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Workspace tidak ditemukan.', 404);
        }
        $existingCommit = $pdo->prepare(
            'SELECT id FROM hukum_commit WHERE staging_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $existingCommit->execute([$stagingId]);
        if ($existingCommit->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('Staging sudah memiliki commit final.', 409);
        }

        $documentId = (int) $staging['dokumen_id'];
        $periodId = (int) $staging['periode_id'];
        $role = null;
        foreach (['komisi_i', 'ketua_umum'] as $candidate) {
            if (hukum_commit_user_must_be_business_role_on($pdo, $actorId, $periodId, $candidate)) {
                $role = $candidate;
                break;
            }
        }
        if ($role === null) {
            throw new RuntimeException('Aktor tidak berwenang untuk commit dokumen ini.', 403);
        }
        $stmt = $pdo->prepare(
            'SELECT user_id, peran, status
             FROM hukum_staging_approval
             WHERE staging_id = ? ORDER BY peran ASC FOR UPDATE'
        );
        $stmt->execute([$stagingId]);
        $approvalRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $validatedWindows = [];
        foreach (['komisi_i', 'ketua_umum'] as $requiredRole) {
            $memberId = (int) ($approvedRoles[$requiredRole] ?? 0);
            $window = $memberId > 0
                ? hukum_commit_is_window_approved($pdo, $memberId, $requiredRole)
                : null;
            if ($window === null) {
                throw new RuntimeException('Window commit untuk dua pihak belum aktif atau sudah kadaluarsa.', 409);
            }
            $validatedWindows[$requiredRole] = $window;
        }

        $stmt = $pdo->prepare(
            'SELECT id, hash_commit
             FROM hukum_commit
             WHERE dokumen_id = ? AND status = ?
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$documentId, 'aktif']);
        $currentCommit = $stmt->fetch(PDO::FETCH_ASSOC);
        $snapshotData = hukum_commit_snapshot_for_staging($pdo, $stagingId);
        $parentHash = $currentCommit !== false && isset($currentCommit['hash_commit'])
            ? (string) $currentCommit['hash_commit']
            : null;
        $hash = hukum_commit_hash_from_snapshot($parentHash, $snapshotData['snapshot'], [
            'dokumen_id' => $documentId,
            'staging_id' => $stagingId,
            'forum_tipe' => 'finalisasi',
            'tanggal_forum' => hukum_now_string('Y-m-d'),
            'created_at' => hukum_now_string(),
        ]);

        $pdo->prepare(
            'INSERT INTO hukum_commit (dokumen_id, staging_id, parent_commit_id, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, status, dibuat_oleh, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([
            $documentId,
            $stagingId,
            $currentCommit !== false ? (int) $currentCommit['id'] : null,
            $hash,
            json_encode($snapshotData['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finalisasi',
            hukum_now_string('Y-m-d'),
            'aktif',
            $actorId,
        ]);

        $commitId = (int) $pdo->lastInsertId();
        hukum_commit_test_failure_inject('after_commit_insert');

        if ($currentCommit !== false) {
            $pdo->prepare('UPDATE hukum_commit SET status = ?, replaced_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['digantikan', (int) $currentCommit['id']]);
        }

        $pdo->prepare('UPDATE hukum_workspace SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['ditutup', (int) $staging['workspace_id']]);
        $pdo->prepare('UPDATE hukum_dokumen SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(['aktif', $documentId]);
        $pdo->prepare('UPDATE hukum_staging SET status = ? WHERE id = ?')->execute(['disetujui', $stagingId]);

        foreach ($validatedWindows as $requiredRole => $window) {
            $consume = $pdo->prepare(
                'UPDATE hukum_commit_window
                 SET commit_id = ?, status = ?, completed_at = CURRENT_TIMESTAMP, result = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ? AND peran = ? AND status = \'approved\' AND commit_id IS NULL
                   AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)'
            );
            $consume->execute([
                $commitId,
                'approved',
                'finalized',
                (int) $window['id'],
                (int) $window['user_id'],
                (string) $window['peran'],
            ]);
            if ($consume->rowCount() !== 1) {
                throw new RuntimeException('Window commit berubah sebelum dikonsumsi.', 409);
            }
        }

        $pdo->prepare(
            'UPDATE hukum_pasal_versi SET status = ? WHERE id IN (SELECT pasal_versi_id FROM hukum_staging_versi WHERE staging_id = ?)'
        )->execute(['committed', $stagingId]);

        hukum_commit_snapshot_graph($pdo, $commitId, $documentId);
        hukum_commit_test_failure_inject('after_snapshot');

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

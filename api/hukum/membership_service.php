<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/core/hukum-auth.php';

function hukum_membership_audit(PDO $pdo, HukumAuthenticatedActorContext $actor, int $membershipId, string $action, ?array $before, ?array $after, int $periodId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_audit_log
         (entitas, entitas_id, aksi, aktor_id, role_context, periode_id, request_id, result, sebelum_json, sesudah_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        'hukum_keanggotaan',
        $membershipId,
        $action,
        $actor->id,
        $actor->technicalRole,
        $periodId,
        'membership-' . bin2hex(random_bytes(8)),
        'success',
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
    ]);
}

function hukum_membership_require_admin(?HukumAuthenticatedActorContext $actor = null): HukumAuthenticatedActorContext
{
    $actor ??= hukum_authenticated_actor();
    if ($actor === null || !in_array($actor->technicalRole, ['admin', 'superadmin'], true)) {
        throw new RuntimeException('Membership hanya dapat dikelola oleh administrator.', 403);
    }
    return $actor;
}

function hukum_membership_validate_dates(string $start, ?string $end): void
{
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = $end === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if ($startDate === false || ($end !== null && $endDate === false) || ($endDate !== null && $endDate < $startDate)) {
        throw new InvalidArgumentException('Rentang tanggal membership tidak valid.', 400);
    }
}

function hukum_create_membership(
    PDO $pdo,
    HukumAuthenticatedActorContext $actor,
    int $userId,
    int $periodId,
    string $jabatan,
    string $startDate,
    ?string $endDate = null
): array {
    hukum_membership_require_admin($actor);
    if (!in_array($jabatan, ['komisi_i', 'ketua_umum'], true)) {
        throw new InvalidArgumentException('Jabatan membership tidak valid.', 400);
    }
    hukum_membership_validate_dates($startDate, $endDate);

    $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $user->execute([$userId]);
    if (!$user->fetchColumn()) {
        throw new RuntimeException('User membership tidak ditemukan atau tidak aktif.', 404);
    }
    $period = $pdo->prepare('SELECT id FROM periode_kepengurusan WHERE id = ? LIMIT 1');
    $period->execute([$periodId]);
    if (!$period->fetchColumn()) {
        throw new RuntimeException('Periode membership tidak ditemukan.', 404);
    }

    $duplicate = $pdo->prepare('SELECT id FROM hukum_keanggotaan WHERE user_id = ? AND periode_id = ? AND jabatan = ? LIMIT 1');
    $duplicate->execute([$userId, $periodId, $jabatan]);
    if ($duplicate->fetchColumn()) {
        throw new RuntimeException('Membership untuk user, periode, dan jabatan tersebut sudah ada.', 409);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hukum_keanggotaan (user_id, periode_id, jabatan, mulai_pada, selesai_pada, aktif)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$userId, $periodId, $jabatan, $startDate, $endDate]);
        $id = (int) $pdo->lastInsertId();
        hukum_membership_audit($pdo, $actor, $id, 'create', null, [
            'user_id' => $userId,
            'periode_id' => $periodId,
            'jabatan' => $jabatan,
            'mulai_pada' => $startDate,
            'selesai_pada' => $endDate,
            'aktif' => 1,
        ], $periodId);
        $pdo->commit();
        return ['id' => $id, 'user_id' => $userId, 'periode_id' => $periodId, 'jabatan' => $jabatan, 'aktif' => 1];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function hukum_resolve_membership(PDO $pdo, int $userId, int $periodId, string $jabatan): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM hukum_keanggotaan
         WHERE user_id = ? AND periode_id = ? AND jabatan = ? AND aktif = 1
           AND (selesai_pada IS NULL OR selesai_pada >= CURRENT_DATE)
         LIMIT 1'
    );
    $stmt->execute([$userId, $periodId, $jabatan]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function hukum_update_membership(
    PDO $pdo,
    HukumAuthenticatedActorContext $actor,
    int $membershipId,
    string $startDate,
    ?string $endDate
): array {
    hukum_membership_require_admin($actor);
    hukum_membership_validate_dates($startDate, $endDate);
    $stmt = $pdo->prepare('SELECT * FROM hukum_keanggotaan WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$membershipId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new RuntimeException('Membership tidak ditemukan.', 404);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE hukum_keanggotaan
             SET mulai_pada = ?, selesai_pada = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$startDate, $endDate, $membershipId]);
        $after = $before;
        $after['mulai_pada'] = $startDate;
        $after['selesai_pada'] = $endDate;
        hukum_membership_audit($pdo, $actor, $membershipId, 'update', $before, $after, (int) $before['periode_id']);
        $pdo->commit();
        return $after;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function hukum_deactivate_membership(
    PDO $pdo,
    HukumAuthenticatedActorContext $actor,
    int $membershipId,
    string $endDate
): array {
    hukum_membership_require_admin($actor);
    hukum_membership_validate_dates($endDate, null);
    $stmt = $pdo->prepare('SELECT * FROM hukum_keanggotaan WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$membershipId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new RuntimeException('Membership tidak ditemukan.', 404);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE hukum_keanggotaan
             SET aktif = 0, selesai_pada = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$endDate, $membershipId]);
        $after = $before;
        $after['aktif'] = 0;
        $after['selesai_pada'] = $endDate;
        hukum_membership_audit($pdo, $actor, $membershipId, 'deactivate', $before, $after, (int) $before['periode_id']);
        $pdo->commit();
        return $after;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

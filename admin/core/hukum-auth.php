<?php
/**
 * Authentication and authorization contract for the hukum module.
 *
 * The application session remains the single source of truth:
 * admin_id, admin_role, admin_name, admin_username, and admin_periode_id.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/hukum-actor-context.php';

function hukum_current_user_id(): int
{
    return hukum_authenticated_actor()?->id ?? 0;
}

function hukum_current_user_role(): string
{
    return hukum_authenticated_actor()?->technicalRole ?? '';
}

function hukum_current_user_periode_id(): int
{
    return hukum_authenticated_actor()?->periodId ?? 0;
}

function hukum_current_user(): array
{
    $actor = hukum_authenticated_actor();
    return $actor === null ? [
        'id' => 0,
        'role' => '',
        'name' => '',
        'username' => '',
        'periode_id' => 0,
        'can_access_all' => false,
    ] : [
        'id' => $actor->id,
        'role' => $actor->technicalRole,
        'name' => $actor->displayName,
        'username' => $actor->username,
        'periode_id' => $actor->periodId,
        'can_access_all' => $actor->canAccessAll,
    ];
}

function hukum_period_for_document(int $documentId): ?int
{
    $row = dbFetchOne(
        'SELECT periode_id FROM hukum_dokumen WHERE id = ? LIMIT 1',
        [$documentId]
    );

    return $row !== null && isset($row['periode_id']) ? (int) $row['periode_id'] : null;
}

function hukum_business_membership_for_user(int $userId, int $periodeId, string $jabatan): bool
{
    if ($userId <= 0 || $periodeId <= 0) {
        return false;
    }

    $row = dbFetchOne(
        "SELECT id FROM hukum_keanggotaan
         WHERE user_id = ?
           AND periode_id = ?
           AND jabatan = ?
           AND aktif = 1
           AND (selesai_pada IS NULL OR selesai_pada >= CURDATE())
         LIMIT 1",
        [$userId, $periodeId, $jabatan]
    );

    return $row !== null;
}

function hukum_actor_has_technical_role_for_period(string $role, int $userId, int $periodeId): bool
{
    $actor = hukum_authenticated_actor();
    return $actor !== null
        && $userId > 0
        && $actor->id === $userId
        && $periodeId > 0
        && $actor->technicalRole === strtolower(trim($role))
        && ($actor->canAccessAll || $actor->periodId === $periodeId);
}

function hukum_is_komisi_i(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool
{
    if ($userId <= 0) {
        $userId = hukum_current_user_id();
    }

    if ($documentId !== null && $documentId > 0) {
        $periodeId = hukum_period_for_document($documentId) ?? $periodeId;
    }

    if ($periodeId === null) {
        $periodeId = hukum_current_user_periode_id();
    }

    if ($periodeId <= 0) {
        return false;
    }

    return hukum_business_membership_for_user($userId, (int) $periodeId, 'komisi_i');
}

function hukum_is_ketua_umum(int $userId = 0, ?int $periodeId = null, ?int $documentId = null): bool
{
    // Business-membership metadata, not a technical platform role. This function is kept
    // because past governance data stores the organizational title as a membership value.
    if ($userId <= 0) {
        $userId = hukum_current_user_id();
    }

    if ($documentId !== null && $documentId > 0) {
        $periodeId = hukum_period_for_document($documentId) ?? $periodeId;
    }

    if ($periodeId === null) {
        $periodeId = hukum_current_user_periode_id();
    }

    if ($periodeId <= 0) {
        return false;
    }

    return hukum_business_membership_for_user($userId, (int) $periodeId, 'ketua_umum');
}

function hukum_technical_role_is_admin(?string $role = null): bool
{
    $role = strtolower(trim((string) ($role ?? hukum_current_user_role())));

    return in_array($role, ['admin'], true);
}

function hukum_can_review_staging(int $documentId, ?int $userId = null): bool
{
    if ($documentId <= 0) {
        return false;
    }

    if ($userId === null || $userId <= 0) {
        $userId = hukum_current_user_id();
    }

    $periodeId = hukum_period_for_document($documentId);
    if ($periodeId === null || $periodeId <= 0) {
        return false;
    }

    $currentRole = strtolower((string) hukum_current_user_role());
    if ($currentRole === 'komisi_i' && hukum_actor_has_technical_role_for_period('komisi_i', $userId, $periodeId)) {
        return true;
    }

    if ($currentRole === 'admin') {
        return true;
    }

    return false;
}

function hukum_can_commit_as(int $documentId, string $peran, ?int $userId = null): bool
{
    $peran = strtolower(trim($peran));
    if (!in_array($peran, ['komisi_i', 'admin'], true)) {
        return false;
    }

    if ($userId === null || $userId <= 0) {
        $userId = hukum_current_user_id();
    }

    $periodeId = hukum_period_for_document($documentId);
    if ($periodeId === null || $periodeId <= 0) {
        return false;
    }

    $currentRole = strtolower((string) hukum_current_user_role());
    if ($peran === 'komisi_i') {
        return $currentRole === 'komisi_i' && hukum_actor_has_technical_role_for_period('komisi_i', $userId, $periodeId);
    }

    if ($peran === 'admin') {
        return $currentRole === 'admin';
    }

    return false;
}

function hukum_role_permissions(): array
{
    return [
        'superadmin' => ['hukum.view', 'hukum.audit.view'],
        'admin' => [
            'hukum.view',
            'hukum.document.create',
            'hukum.document.update',
            'hukum.document.delete',
            'hukum.pasal.create',
            'hukum.pasal.update',
            'hukum.pasal.delete',
            'hukum.workspace.create',
            'hukum.workspace.close',
            'hukum.workspace.submit',
            'hukum.staging.review',
            'hukum.commit.verify',
            'hukum.commit.create',
            'hukum.commit.approve',
            'hukum.audit.view',
        ],
        'komisi_i' => [
            'hukum.view',
            'hukum.document.create',
            'hukum.document.update',
            'hukum.pasal.create',
            'hukum.pasal.update',
            'hukum.pasal.delete',
            'hukum.workspace.create',
            'hukum.workspace.submit',
            'hukum.staging.review',
            'hukum.commit.verify',
            'hukum.audit.view',
        ],
        'sekretaris' => ['hukum.view', 'hukum.audit.view'],
        'anggota' => ['hukum.view'],
    ];
}

function hukum_has_permission(string $permission): bool
{
    $actor = hukum_authenticated_actor();
    if ($actor === null) {
        return false;
    }

    $legacyPermissions = [
        'view:hukum' => 'hukum.view',
        'create:hukum-dokumen' => 'hukum.document.create',
        'edit:hukum-dokumen' => 'hukum.document.update',
        'delete:hukum-dokumen' => 'hukum.document.delete',
        'edit:hukum-pasal' => 'hukum.pasal.update',
        'create:hukum-meja-kerja' => 'hukum.workspace.create',
        'close:hukum-meja-kerja' => 'hukum.workspace.close',
        'approve:hukum-staging' => 'hukum.staging.review',
    ];
    $permission = $legacyPermissions[$permission] ?? $permission;
    $role = hukum_current_user_role();
    $permissions = hukum_role_permissions()[$role] ?? [];
    return in_array($permission, $permissions, true);
}

function hukum_require_service_permission(string $permission): void
{
    if (hukum_authenticated_actor() === null) {
        throw new RuntimeException('Sesi tidak valid.', 401);
    }
    if (!hukum_has_permission($permission)) {
        throw new RuntimeException('Anda tidak memiliki izin untuk aksi ini.', 403);
    }
}

function hukum_require_service_period(int $periodId): void
{
    $actor = hukum_authenticated_actor();
    if ($periodId <= 0) {
        throw new InvalidArgumentException('Periode dokumen tidak valid.', 400);
    }
    if ($actor === null) {
        throw new RuntimeException('Sesi tidak valid.', 401);
    }
    if (!$actor->canAccessAll && $actor->technicalRole !== 'superadmin' && $actor->periodId !== $periodId) {
        throw new RuntimeException('Anda tidak memiliki akses ke periode dokumen ini.', 403);
    }
}

function hukum_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hukum_require_login(): void
{
    if (hukum_authenticated_actor() === null) {
        hukum_json_response([
            'success' => false,
            'code' => 'UNAUTHENTICATED',
            'message' => 'Sesi habis. Silakan login ulang.',
        ], 401);
    }
}

function hukum_require_permission(string $permission): void
{
    hukum_require_login();

    if (!hukum_has_permission($permission)) {
        hukum_json_response([
            'success' => false,
            'code' => 'FORBIDDEN',
            'message' => 'Anda tidak memiliki izin untuk aksi ini.',
        ], 403);
    }
}

function hukum_require_csrf(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? null;

    if (!csrfVerify($token)) {
        hukum_json_response([
            'success' => false,
            'code' => 'CSRF_INVALID',
            'message' => 'Token CSRF tidak valid atau kadaluarsa.',
        ], 419);
    }
}

function hukum_require_document_period(int $periodeId): void
{
    hukum_require_login();

    $user = hukum_current_user();
    if ($periodeId <= 0) {
        hukum_json_response([
            'success' => false,
            'code' => 'INVALID_PERIOD',
            'message' => 'Periode dokumen tidak valid.',
        ], 400);
    }

    if (!$user['can_access_all'] && $user['role'] !== 'superadmin'
        && $user['periode_id'] !== $periodeId) {
        hukum_json_response([
            'success' => false,
            'code' => 'PERIOD_FORBIDDEN',
            'message' => 'Anda tidak memiliki akses ke periode dokumen ini.',
        ], 403);
    }
}

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

function hukum_current_user_id(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function hukum_current_user_role(): string
{
    return strtolower(trim((string) ($_SESSION['admin_role'] ?? '')));
}

function hukum_current_user_periode_id(): int
{
    return (int) ($_SESSION['admin_periode_id'] ?? 0);
}

function hukum_current_user(): array
{
    return [
        'id' => hukum_current_user_id(),
        'role' => hukum_current_user_role(),
        'name' => (string) ($_SESSION['admin_name'] ?? ''),
        'username' => (string) ($_SESSION['admin_username'] ?? ''),
        'periode_id' => hukum_current_user_periode_id(),
        'can_access_all' => !empty($_SESSION['admin_can_access_all']),
    ];
}

function hukum_role_permissions(): array
{
    return [
        'superadmin' => ['*'],
        'ketua_umum_bpm' => [
            'hukum.view',
            'hukum.document.create',
            'hukum.document.update',
            'hukum.document.delete',
            'hukum.pasal.create',
            'hukum.pasal.update',
            'hukum.pasal.delete',
            'hukum.workspace.create',
            'hukum.staging.review',
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
            'hukum.audit.view',
        ],
        'admin' => ['hukum.view', 'hukum.audit.view'],
        'sekretaris' => ['hukum.view', 'hukum.audit.view'],
        'kominfo' => ['hukum.view'],
        'anggota' => ['hukum.view'],
    ];
}

function hukum_has_permission(string $permission): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    $user = hukum_current_user();
    if ($user['can_access_all'] || $user['role'] === 'superadmin') {
        return true;
    }

    $permissions = hukum_role_permissions()[$user['role']] ?? [];
    return in_array($permission, $permissions, true);
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
    if (!isLoggedIn()) {
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

<?php
/**
 * admin/core/hukum-auth.php
 * Helper otentikasi & otorisasi KHUSUS untuk Modul Produk Hukum (Fase 1).
 *
 * Tujuan:   Memisahkan logic role-check hukum dari auth-check.php
 *           yang dipakai modul lain. Tidak mengganggu sesi/admin lain.
 *
 * Role yang relevan untuk modul hukum:
 *   - superadmin         : semua permission
 *   - ketua_umum_bpm     : buka/tutup meja kerja, finalisasi staging
 *   - komisi_i           : CRUD pasal, submit staging, rekomendasi perubahan
 *   - admin (kompat)     : hanya read (untuk review internal)
 *
 * Permission string yang dipakai:
 *   - view:hukum
 *   - create:hukum-dokumen
 *   - edit:hukum-dokumen
 *   - delete:hukum-dokumen
 *   - create:hukum-pasal
 *   - edit:hukum-pasal
 *   - delete:hukum-pasal
 *   - create:hukum-meja-kerja
 *   - close:hukum-meja-kerja
 *   - submit:hukum-staging
 *   - approve:hukum-staging
 *   - public-commit:hukum
 *
 * @return void  — calls exit() on auth failure
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';

function hukum_require_login(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'code'    => 'UNAUTHENTICATED',
            'message' => 'Sesi habis. Silakan login ulang.'
        ]);
        exit;
    }
}

/**
 * Matriks permission per role.
 *  - key  : role di users.role
 *  - value: daftar permission string
 */
function hukum_role_permissions(): array {
    return [
        'superadmin' => [
            'view:hukum', 'create:hukum-dokumen', 'edit:hukum-dokumen', 'delete:hukum-dokumen',
            'create:hukum-pasal', 'edit:hukum-pasal', 'delete:hukum-pasal',
            'create:hukum-meja-kerja', 'close:hukum-meja-kerja',
            'submit:hukum-staging', 'approve:hukum-staging', 'public-commit:hukum',
        ],
        'ketua_umum_bpm' => [
            'view:hukum', 'create:hukum-dokumen', 'edit:hukum-dokumen', 'delete:hukum-dokumen',
            'create:hukum-pasal', 'edit:hukum-pasal', 'delete:hukum-pasal',
            'create:hukum-meja-kerja', 'close:hukum-meja-kerja',
            'submit:hukum-staging', 'approve:hukum-staging', 'public-commit:hukum',
        ],
        'komisi_i' => [
            'view:hukum',
            'create:hukum-pasal', 'edit:hukum-pasal', 'delete:hukum-pasal',
            'create:hukum-meja-kerja', 'close:hukum-meja-kerja',
            'submit:hukum-staging', 'public-commit:hukum',
        ],
        'admin' => [
            'view:hukum',
        ],
        'sekretaris' => [
            'view:hukum',
        ],
        'kominfo' => [
            'view:hukum',
        ],
        'anggota' => [
            'view:hukum',
        ],
    ];
}

function hukum_current_role(): string {
    return $_SESSION['admin_role'] ?? '';
}

function hukum_has_permission(string $perm): bool {
    $role = hukum_current_role();
    $matrix = hukum_role_permissions();
    return in_array($perm, $matrix[$role] ?? [], true);
}

/**
 * Wajibkan permission tertentu — keluar dengan 403 jika tidak punya.
 */
function hukum_require_permission(string $perm): void {
    hukum_require_login();
    if (!hukum_has_permission($perm)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'code'    => 'FORBIDDEN',
            'message' => "Anda tidak punya izin: {$perm}",
            'role'    => hukum_current_role(),
        ]);
        exit;
    }
}

/**
 * Wajibkan salah satu dari beberapa role.
 */
function hukum_require_any_role(array $roles): void {
    hukum_require_login();
    $current = hukum_current_role();
    if (!in_array($current, $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'code'    => 'FORBIDDEN',
            'message' => 'Role Anda tidak diizinkan untuk aksi ini.',
            'role'    => $current,
            'allowed' => $roles,
        ]);
        exit;
    }
}

/**
 * Wajib CSRF untuk semua POST/PUT/DELETE.
 * Asumsi: csrfToken() / csrfVerify() sudah tersedia di functions.php.
 */
function hukum_verify_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? null;
    if (!csrfVerify($token)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'code'    => 'CSRF_INVALID',
            'message' => 'Token CSRF tidak valid atau kadaluarsa.'
        ]);
        exit;
    }
}

function hukum_current_user_id(): int {
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function hukum_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/staging_service.php';
$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $stagingId = (int) ($_GET['id'] ?? 0);
    if ($stagingId > 0) {
        $row = dbFetchOne(
            'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.periode_id, d.judul
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             WHERE s.id = ?',
            [$stagingId]
        );
        if (!$row) hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
        hukum_require_document_period((int) $row['periode_id']);
        $row['versi'] = dbFetchAll(
            'SELECT pv.id, pv.pasal_id, pv.hash_konten, pv.status, pv.isi
             FROM hukum_staging_versi sv
             JOIN hukum_pasal_versi pv ON pv.id = sv.pasal_versi_id
             WHERE sv.staging_id = ? ORDER BY pv.pasal_id',
            [$stagingId]
        );
        hukum_json_response(['success' => true, 'data' => $row]);
    }

    $user = hukum_current_user();
    $sql = 'SELECT s.*, w.dokumen_id, w.judul_perubahan, d.judul, d.periode_id
            FROM hukum_staging s
            JOIN hukum_workspace w ON w.id = s.workspace_id
            JOIN hukum_dokumen d ON d.id = w.dokumen_id';
    $params = [];
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' WHERE d.periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY s.diajukan_at DESC, s.id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

hukum_require_permission('hukum.workspace.submit');
$input = hukum_input();
$workspaceId = (int) ($input['workspace_id'] ?? 0);
$versionInput = $input['pasal_versi_ids'] ?? [];
if (isRateLimited('login_failed', 5, 15, hukum_current_user()['username'])) {
    hukum_json_response(['success' => false, 'message' => 'Terlalu banyak konfirmasi gagal. Coba lagi dalam 15 menit.'], 429);
}
if (!isset($input['password']) || !is_string($input['password']) || $input['password'] === '') {
    hukum_json_response(['success' => false, 'message' => 'Kata sandi akun wajib untuk mengirim workspace ke staging.'], 400);
}
$actor = hukum_authenticated_actor();
$credentialStmt = $pdo->prepare('SELECT password, totp_enabled, totp_secret, is_active FROM users WHERE id = ? LIMIT 1');
$credentialStmt->execute([$actor?->id ?? 0]);
$credentialUser = $credentialStmt->fetch(PDO::FETCH_ASSOC);
if ($actor === null || $credentialUser === false || (int) $credentialUser['is_active'] !== 1) {
    hukum_json_response(['success' => false, 'message' => 'Sesi akun tidak valid. Silakan masuk kembali.'], 401);
}
if (!password_verify($input['password'], (string) $credentialUser['password'])) {
    recordFailedAttempt('login_failed', $actor->username);
    hukum_json_response(['success' => false, 'message' => 'Kata sandi tidak sesuai. Pengajuan belum dikirim.'], 403);
}
if ((bool) $credentialUser['totp_enabled']) {
    $totpCode = isset($input['totp_code']) && is_string($input['totp_code'])
        ? preg_replace('/\D/', '', $input['totp_code'])
        : '';
    if (empty($credentialUser['totp_secret'])) {
        hukum_json_response(['success' => false, 'message' => 'Konfigurasi 2FA akun tidak lengkap. Hubungi administrator.'], 409);
    }
    if (strlen($totpCode) !== 6 || !totpVerifyWithReplay((string) $credentialUser['totp_secret'], $totpCode, $actor->id)) {
        recordFailedAttempt('login_failed', $actor->username);
        hukum_json_response(['success' => false, 'message' => 'Kode autentikator tidak valid atau sudah digunakan. Pengajuan belum dikirim.'], 403);
    }
}
if (!is_array($versionInput)) {
    hukum_json_response(['success' => false, 'message' => 'pasal_versi_ids harus berupa array.'], 400);
}
$versionIds = hukum_staging_normalize_version_ids($versionInput);

try {
    $result = hukum_submit_staging($pdo, $workspaceId, $versionIds, hukum_current_user_id());
    hukum_json_response(['success' => true] + $result, 201);
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $code = $exception->getCode();
    $status = is_numeric($code) && (int) $code > 0 ? (int) $code : 409;
    hukum_json_response(['success' => false, 'message' => $message], $status);
}

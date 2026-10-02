<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/commit_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    if (($_GET['action'] ?? '') === 'ready_to_finalize') {
        $actor = hukum_current_user();
        $params = [];
        $periodFilter = '';
        if (!$actor['can_access_all'] && $actor['role'] !== 'superadmin') {
            $periodFilter = ' AND d.periode_id = ?';
            $params[] = (int) $actor['periode_id'];
        }
        $rows = dbFetchAll(
            "SELECT s.id AS staging_id, s.workspace_id, w.dokumen_id, d.judul, d.jenis, d.slug,
                    s.diajukan_at,
                    ca.user_id AS komisi_i_user_id, ua.nama AS komisi_i_nama,
                    aa.user_id AS admin_user_id, ub.nama AS admin_nama,
                    EXISTS (
                        SELECT 1 FROM hukum_commit_window cw
                        WHERE cw.staging_id = s.id AND cw.user_id = ca.user_id AND cw.peran = 'komisi_i'
                          AND cw.status = 'approved' AND cw.commit_id IS NULL AND cw.expires_at >= CURRENT_TIMESTAMP
                    ) AS komisi_i_verified,
                    EXISTS (
                        SELECT 1 FROM hukum_commit_window cw
                        WHERE cw.staging_id = s.id AND cw.user_id = aa.user_id AND cw.peran = 'admin'
                          AND cw.status = 'approved' AND cw.commit_id IS NULL AND cw.expires_at >= CURRENT_TIMESTAMP
                    ) AS admin_verified
             FROM hukum_staging s
             JOIN hukum_workspace w ON w.id = s.workspace_id
             JOIN hukum_dokumen d ON d.id = w.dokumen_id
             JOIN hukum_staging_approval ca ON ca.staging_id = s.id AND ca.peran = 'komisi_i' AND ca.status = 'disetujui'
             JOIN users ua ON ua.id = ca.user_id
             JOIN hukum_staging_approval aa ON aa.staging_id = s.id AND aa.peran = 'admin' AND aa.status = 'disetujui'
             JOIN users ub ON ub.id = aa.user_id
             WHERE s.status = 'disetujui' AND w.status = 'siap_commit'
               AND ca.user_id <> aa.user_id
               AND NOT EXISTS (SELECT 1 FROM hukum_commit c WHERE c.staging_id = s.id)
               {$periodFilter}
             ORDER BY s.direview_at DESC, s.id DESC",
            $params
        );
        hukum_json_response(['success' => true, 'data' => $rows]);
    }

    $dokumenId = (int) ($_GET['dokumen_id'] ?? 0);
    if ($dokumenId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'dokumen_id wajib.'], 400);
    }

    $doc = dbFetchOne('SELECT periode_id FROM hukum_dokumen WHERE id = ? LIMIT 1', [$dokumenId]);
    if (!$doc) {
        hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
    }

    hukum_require_document_period((int) $doc['periode_id']);
    hukum_json_response([
        'success' => true,
        'data' => dbFetchAll(
            'SELECT id, dokumen_id, staging_id, parent_commit_id, hash_commit, forum_tipe, tanggal_forum,
                    status, dibuat_oleh, created_at, replaced_at
             FROM hukum_commit WHERE dokumen_id = ? ORDER BY created_at DESC, id DESC',
            [$dokumenId]
        )
    ]);
}

$input = hukum_input();
$action = strtolower(trim((string) ($input['action'] ?? 'finalize')));
$stagingId = (int) ($input['staging_id'] ?? 0);
$actorId = hukum_current_user_id();

if (in_array($action, ['verify_window', 'init_window'], true)) {
    hukum_require_permission('hukum.commit.verify');
    if ($stagingId <= 0) {
        hukum_json_response(['success' => false, 'message' => 'staging_id wajib untuk verifikasi commit.'], 400);
    }
    $role = hukum_current_user_role();
    $peran = match ($role) {
        'komisi_i' => 'komisi_i',
        'admin', 'superadmin' => 'admin',
        default => '',
    };
    if ($peran === '' || (isset($input['peran']) && strtolower(trim((string) $input['peran'])) !== $peran)) {
        hukum_json_response(['success' => false, 'message' => 'Akun ini tidak dapat memverifikasi peran commit tersebut.'], 403);
    }
    $password = (string) ($input['password'] ?? '');
    if ($password === '') {
        hukum_json_response(['success' => false, 'message' => 'Masukkan kata sandi akun Anda untuk verifikasi.'], 400);
    }
    try {
        $result = hukum_commit_create_window(
            $pdo,
            $actorId,
            $peran,
            $password,
            $_SERVER['HTTP_X_SESSION_ID'] ?? session_id(),
            $stagingId
        );
        hukum_json_response(['success' => true, 'data' => $result]);
    } catch (RuntimeException $error) {
        $status = (int) $error->getCode();
        hukum_json_response(
            ['success' => false, 'message' => $error->getMessage()],
            $status > 0 && $status < 600 ? $status : 400
        );
    } catch (Throwable $error) {
        error_log('hukum/commit.php verify_window error: ' . $error->getMessage());
        hukum_json_response(['success' => false, 'message' => 'Verifikasi commit gagal.'], 500);
    }
}

hukum_require_permission('hukum.commit.create');
if ($stagingId <= 0) {
    hukum_json_response(['success' => false, 'message' => 'staging_id wajib.'], 400);
}

$password = (string) ($input['password'] ?? '');
if ($password === '') {
    hukum_json_response(['success' => false, 'message' => 'password wajib untuk finalisasi commit.'], 400);
}

try {
    $result = hukum_commit_finalize($pdo, $stagingId, $actorId, $password, $_SERVER['HTTP_X_REQUEST_ID'] ?? null, $_SERVER['HTTP_X_SESSION_ID'] ?? session_id());
    hukum_json_response(['success' => true, 'data' => $result], 200);
} catch (RuntimeException $e) {
    $code = $e->getCode();
    hukum_json_response(['success' => false, 'message' => $e->getMessage()], is_numeric($code) ? (int) $code : 400);
} catch (Throwable $e) {
    hukum_json_response(['success' => false, 'message' => 'Commit gagal: ' . $e->getMessage()], 500);
}

<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/commit_service.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
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

hukum_require_permission('hukum.commit.create');
$input = hukum_input();
$action = strtolower(trim((string) ($input['action'] ?? 'finalize')));
$stagingId = (int) ($input['staging_id'] ?? 0);
$actorId = hukum_current_user_id();

if ($action === 'init_window') {
    $peran = strtolower(trim((string) ($input['peran'] ?? '')));
    if ($peran === '') {
        $peran = hukum_is_komisi_i($actorId, hukum_current_user_periode_id()) ? 'komisi_i' : 'ketua_umum';
    }
    $password = isset($input['password']) ? (string) $input['password'] : null;
    $result = hukum_commit_create_window($pdo, $actorId, $peran, $password !== null && $password !== '' ? $password : null, $_SERVER['HTTP_X_SESSION_ID'] ?? session_id());
    hukum_json_response(['success' => true, 'data' => $result]);
}

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

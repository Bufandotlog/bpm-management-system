<?php
/**
 * api/hukum/co-commit.php
 * Co-Commit Timer Independen (H-5 Revisi 6).
 *
 * Method & aksi:
 *   POST action=initiate — klik "Inisiasi Commit" (mulai timer independen 5 detik)
 *   POST action=approve  — klik "Setuju Commit" (verifikasi password + timer)
 *   GET  ?commit_id=X     — cek status co-commit (untuk countdown UI)
 *
 * Permission:
 *   POST initiate : komisi_i | ketua_umum_bpm
 *   POST approve  : komisi_i | ketua_umum_bpm (role-specific)
 *   GET           : view:hukum
 */
require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
$method = $_SERVER['REQUEST_METHOD'];
hukum_verify_csrf();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? null;

try {
    $pdo = getConnection();
    $userId = hukum_current_user_id();
    $userRole = hukum_current_role();

    if ($method !== 'POST') {
        hukum_json_response(['success' => false, 'message' => 'Only POST allowed.'], 405);
    }

    if ($action === 'initiate') {
        // === INISIASI COMMIT (Mulai timer independen) ===
        if (empty($input['commit_id'])) {
            hukum_json_response(['success' => false, 'message' => 'commit_id wajib.'], 400);
        }

        $commitId = (int) $input['commit_id'];

        // Validasi commit ada dan status
        $commit = dbFetchOne("SELECT id, dokumen_id, status FROM hukum_commit WHERE id = ?", [$commitId], "i");
        if (!$commit) {
            hukum_json_response(['success' => false, 'message' => 'Commit tidak ditemukan.'], 404);
        }

        if ($commit['status'] !== 'aktif') {
            hukum_json_response([
                'success' => false,
                'message' => 'Commit sudah diproses. Status: ' . $commit['status']
            ], 409);
        }

        // Validasi role: komisi_i atau ketua_umum_bpm
        if (!in_array($userRole, ['komisi_i', 'ketua_umum_bpm'], true)) {
            hukum_json_response([
                'success' => false,
                'message' => 'Role tidak diizinkan untuk co-commit.'
            ], 403);
        }

        // Cek apakah user sudah inisiasi (prevent double-init)
        $existingOtorisasi = dbFetchOne(
            "SELECT id FROM hukum_commit_otorisasi WHERE commit_id = ? AND user_id = ?",
            [$commitId, $userId], "ii"
        );
        if ($existingOtorisasi) {
            hukum_json_response([
                'success' => false,
                'message' => 'Anda sudah menginisiasi commit ini.'
            ], 409);
        }

        // Cek apakah sudah ada 2 inisiasi (jika sudah, status = berlangsung)
        $totalInisiasi = dbFetchOne(
            "SELECT COUNT(*) as cnt FROM hukum_commit_otorisasi WHERE commit_id = ?",
            [$commitId], "i"
        )['cnt'];

        // Tentukan kolom inisiasi berdasarkan role
        $isKomisi = ($userRole === 'komisi_i');
        $colRole = $isKomisi ? 'diinsiasi_oleh_user_id_komisi_i' : 'diinsiasi_oleh_user_id_ketum';
        $colTime = $isKomisi ? 'waktu_inisiasi_komisi_i' : 'waktu_inisiasi_ketum';

        // Insert otorisasi (menunggu inisiasi kedua)
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO hukum_commit_otorisasi
                (commit_id, user_id, role_saat_commit, status, waktu_otorisasi)
            VALUES (?, ?, ?, 'menunggu', NOW())
        ");
        $stmt->execute([$commitId, $userId, $userRole]);

        // Update atau insert ke hukum_commit_window
        $window = dbFetchOne("SELECT id FROM hukum_commit_window WHERE commit_id = ?", [$commitId], "i");

        if ($window) {
            // Window sudah ada (inisiasi pertama sudah terjadi) — update kolom kedua
            $pdo->prepare("
                UPDATE hukum_commit_window
                SET {$colRole} = ?, {$colTime} = NOW(), status = 'berlangsung'
                WHERE commit_id = ?
            ")->execute([$userId, $commitId]);

            $windowId = (int) $window['id'];
            $statusWindow = 'berlangsung';
        } else {
            // Window belum ada — insert (inisiasi pertama)
            $pdo->prepare("
                INSERT INTO hukum_commit_window
                    (commit_id, {$colRole}, {$colTime}, status)
                VALUES (?, ?, NOW(), 'menunggu_inisiasi_kedua')
            ")->execute([$commitId, $userId]);

            $windowId = (int) $pdo->lastInsertId();
            $statusWindow = 'menunggu_inisiasi_kedua';
        }

        // Audit
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_commit_otorisasi', ?, 'initiate', ?, ?, NOW())
        ")->execute([
            $commitId,
            $userId,
            json_encode(['role' => $userRole, 'window_id' => $windowId, 'status' => $statusWindow], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();

        // Hitung deadline per-user (inisiasi + 5 detik)
        $deadline = date('Y-m-d H:i:s', strtotime('+5 seconds'));

        hukum_json_response([
            'success' => true,
            'message' => 'Inisiasi commit berhasil.',
            'data' => [
                'commit_id'        => $commitId,
                'role'             => $userRole,
                'window_id'        => $windowId,
                'status_window'    => $statusWindow,
                'deadline'         => $deadline, // untuk client-side countdown
                'timer_expiry'     => strtotime($deadline) // timestamp untuk JS
            ]
        ], 201);
    }

    if ($action === 'approve') {
        // === SETUJU COMMIT (Verifikasi password + timer) ===
        if (empty($input['commit_id'])) {
            hukum_json_response(['success' => false, 'message' => 'commit_id wajib.'], 400);
        }
        if (empty($input['password'])) {
            hukum_json_response(['success' => false, 'message' => 'password wajib.'], 400);
        }

        $commitId = (int) $input['commit_id'];
        $password = $input['password'];

        // Validasi commit ada
        $commit = dbFetchOne("SELECT id, status FROM hukum_commit WHERE id = ?", [$commitId], "i");
        if (!$commit) {
            hukum_json_response(['success' => false, 'message' => 'Commit tidak ditemukan.'], 404);
        }

        // Validasi window ada dan status berlangsung
        $window = dbFetchOne("SELECT * FROM hukum_commit_window WHERE commit_id = ?", [$commitId], "i");
        if (!$window) {
            hukum_json_response([
                'success' => false,
                'message' => 'Co-commit window belum diinisiasi.'
            ], 409);
        }

        if ($window['status'] !== 'berlangsung') {
            hukum_json_response([
                'success' => false,
                'message' => 'Co-commit belum siap. Tunggu kedua inisiasi.'
            ], 409);
        }

        // Validasi role: user harus salah satu role yang sudah inisiasi
        $isKomisiI = ($userRole === 'komisi_i');
        $isKetum = ($userRole === 'ketua_umum_bpm');
        $isInitiator = false;

        if ($isKomisiI && $window['diinsiasi_oleh_user_id_komisi_i'] === $userId) {
            $isInitiator = true;
        }
        if ($isKetum && $window['diinsiasi_oleh_user_id_ketum'] === $userId) {
            $isInitiator = true;
        }

        if (!$isInitiator) {
            hukum_json_response([
                'success' => false,
                'message' => 'Anda bukan inisiator co-commit ini.'
            ], 403);
        }

        // Cek sudah approve sebelumnya?
        $existingApprove = dbFetchOne(
            "SELECT id FROM hukum_commit_otorisasi WHERE commit_id = ? AND user_id = ? AND status = 'disetujui'",
            [$commitId, $userId], "ii"
        );
        if ($existingApprove) {
            hukum_json_response(['success' => false, 'message' => 'Anda sudah menyetujui commit ini.'], 409);
        }

        // Verify password
        $user = dbFetchOne("SELECT password FROM users WHERE id = ?", [$userId], "i");
        if (!$user || !password_verify($password, $user['password'])) {
            // Rate limit: 3 attempt per 5 min (per-user, per-commit)
            $attempts = dbFetchOne(
                "SELECT COUNT(*) as cnt FROM hukum_commit_otorisasi WHERE commit_id = ? AND user_id = ? AND status = 'ditolak' AND dilakukan_pada > NOW() - INTERVAL 5 MINUTE",
                [$commitId, $userId], "ii"
            )['cnt'];

            if ($attempts >= 3) {
                hukum_json_response([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan gagal. Coba lagi dalam beberapa menit.'
                ], 429);
            }

            // Catat percobaan gagal
            $pdo->prepare("
                INSERT INTO hukum_commit_otorisasi
                    (commit_id, user_id, role_saat_commit, status, alasan_gagal, catatan, ip_address)
                VALUES (?, ?, ?, 'ditolak', 'password_invalid', 'Password salah', ?)
            ")->execute([$commitId, $userId, $userRole, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

            // Cek rate limit gagal commit (3x gagal dalam 1 jam → disable semua)
            $totalFailed = dbFetchOne(
                "SELECT COUNT(*) as cnt FROM hukum_commit_otorisasi WHERE commit_id = ? AND status = 'ditolak' AND dilakukan_pada > NOW() - INTERVAL 1 HOUR",
                [$commitId], "i"
            )['cnt'];

            if ($totalFailed >= 3) {
                // Rate limit: disable commit untuk semua user selama 1 jam
                $pdo->prepare("UPDATE hukum_commit SET status = 'gagal' WHERE id = ?")->execute([$commitId]);
                $pdo->prepare("
                    INSERT INTO hukum_notifikasi_audit
                        (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
                    VALUES ('hukum_commit', ?, 'rate_limited', ?, ?, NOW())
                ")->execute([
                    $commitId,
                    $userId,
                    json_encode(['failed_attempts' => $totalFailed, 'reason' => 'rate_limit_all_users'], JSON_UNESCAPED_UNICODE)
                ]);

                hukum_json_response([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan gagal. Commit dinonaktifkan selama 1 jam untuk semua user.'
                ], 429);
            }

            hukum_json_response([
                'success' => false,
                'message' => 'Password salah.'
            ], 401);
        }

        // === VERIFIKASI TIMER INDEPENDEN ===
        // Cek apakah user masih dalam window (waktu_inisiasi_<role> + 5 detik)
        $waktuInisiasiField = $isKomisiI ? 'waktu_inisiasi_komisi_i' : 'waktu_inisiasi_ketum';
        $waktuInisiasi = $window[$waktuInisiasiField];

        if ($waktuInisiasi) {
            $deadline = strtotime($waktuInisiasi) + 5; // inisiasi + 5 detik
            $now = time();

            if ($now > $deadline) {
                // Window expired untuk user ini
                $gagalReason = $isKomisiI ? 'window_expired_komisi_i' : 'window_expired_ketum';
                $pdo->prepare("UPDATE hukum_commit_window SET status = 'gagal', gagal_reason = ? WHERE commit_id = ?")
                    ->execute([$gagalReason, $commitId]);
                $pdo->prepare("UPDATE hukum_commit SET status = 'gagal' WHERE id = ?")->execute([$commitId]);

                hukum_json_response([
                    'success' => false,
                    'message' => 'Waktu co-commit Anda sudah habis. Commit dibatalkan.'
                ], 408);
            }
        }

        // === APPROVE ===
        // Cek apakah kedua user sudah approve
        $pdo->beginTransaction();

        // Catat approve
        $stmt = $pdo->prepare("
            INSERT INTO hukum_commit_otorisasi
                (commit_id, user_id, role_saat_commit, status, waktu_klik_setuju, ip_address)
            VALUES (?, ?, ?, 'disetujui', NOW(), ?)
        ");
        $stmt->execute([$commitId, $userId, $userRole, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

        // Update window: set waktu_klik_setuju untuk role ini
        $clickTimeCol = $isKomisiI ? 'waktu_klik_setuju_komisi_i' : 'waktu_klik_setuju_ketum';
        // Note: kita pakai waktu_klik_setuju di otorisasi table
        // Update window status jadi sukses jika kedua sudah approve
        $approvedCount = dbFetchOne(
            "SELECT COUNT(*) as cnt FROM hukum_commit_otorisasi WHERE commit_id = ? AND status = 'disetujui'",
            [$commitId], "i"
        )['cnt'];

        if ($approvedCount >= 2) {
            // Kedua user sudah approve — COMMIT EFEKTIF
            $pdo->prepare("UPDATE hukum_commit_window SET status = 'sukses' WHERE commit_id = ?")->execute([$commitId]);
            // Status commit tetap 'aktif' (sudah di-set saat inisiasi)
        }

        // Audit
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_commit_otorisasi', ?, 'approve', ?, ?, NOW())
        ")->execute([
            $commitId,
            $userId,
            json_encode(['approved_count' => $approvedCount], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();

        $allApproved = $approvedCount >= 2;

        hukum_json_response([
            'success' => true,
            'message' => $allApproved ? 'Co-commit berhasil! Commit dieksekusi.' : 'Persetujuan Anda tercatat. Tunggu persetujuan user kedua.',
            'data' => [
                'commit_id'       => $commitId,
                'approved_count'  => $approvedCount,
                'all_approved'    => $allApproved,
                'status'          => $allApproved ? 'sukses' : 'menunggu'
            ]
        ]);

    }

    if ($action === 'status') {
        // === CHECK STATUS CO-COMMIT (untuk countdown UI) ===
        if (empty($input['commit_id'])) {
            hukum_json_response(['success' => false, 'message' => 'commit_id wajib.'], 400);
        }

        $commitId = (int) $input['commit_id'];

        $window = dbFetchOne("SELECT * FROM hukum_commit_window WHERE commit_id = ?", [$commitId], "i");
        if (!$window) {
            hukum_json_response(['success' => false, 'message' => 'Co-commit window tidak ditemukan.'], 404);
        }

        // Hitung remaining time per-user
        $now = time();
        $remaining = [];

        if ($window['waktu_inisiasi_komisi_i']) {
            $deadlineKomisi = strtotime($window['waktu_inisiasi_komisi_i']) + 5;
            $remaining['komisi_i'] = max(0, $deadlineKomisi - $now);
        }
        if ($window['waktu_inisiasi_ketum']) {
            $deadlineKetum = strtotime($window['waktu_inisiasi_ketum']) + 5;
            $remaining['ketum'] = max(0, $deadlineKetum - $now);
        }

        // Cek apakah user sudah inisiasi
        $userInisiasi = false;
        if ($window['diinsiasi_oleh_user_id_komisi_i'] && $window['diinsiasi_oleh_user_id_komisi_i'] == hukum_current_user_id()) {
            $userInisiasi = true;
        }
        if ($window['diinsiasi_oleh_user_id_ketum'] && $window['diinsiasi_oleh_user_id_ketum'] == hukum_current_user_id()) {
            $userInisiasi = true;
        }

        // Cek apakah user sudah approve
        $userApproved = dbFetchOne(
            "SELECT id FROM hukum_commit_otorisasi WHERE commit_id = ? AND user_id = ? AND status = 'disetujui'",
            [$commitId, hukum_current_user_id()], "ii"
        );

        hukum_json_response([
            'success' => true,
            'data' => [
                'commit_id'       => $commitId,
                'status_window'   => $window['status'],
                'inisiasi_komisi' => $window['diinsiasi_oleh_user_id_komisi_i'] ? true : false,
                'inisiasi_ketum'  => $window['diinsiasi_oleh_user_id_ketum'] ? true : false,
                'remaining_seconds' => $remaining,
                'user_inisiasi'   => $userInisiasi,
                'user_approved'   => $userApproved ? true : false,
                'deadline_komisi' => $window['waktu_inisiasi_komisi_i'] ? strtotime($window['waktu_inisiasi_komisi_i']) + 5 : null,
                'deadline_ketum'  => $window['waktu_inisiasi_ketum'] ? strtotime($window['waktu_inisiasi_ketum']) + 5 : null,
                'all_approved'    => $window['status'] === 'sukses'
            ]
        ]);
    }

    hukum_json_response(['success' => false, 'message' => 'Action tidak valid.'], 400);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/co-commit.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

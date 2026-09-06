<?php
/**
 * api/hukum/commit.php
 * Commit Executor (H-4) — hash chaining, snapshot tree, finalisasi.
 *
 * Method & aksi:
 *   POST body: dokumen_id, forum_tipe, tanggal_forum, staging_id?
 *               — eksekusi commit chain. Hash chaining dihitung.
 *               Snapshot tree di-generate dan disimpan.
 *               commit_window_id opsional (untuk co-commit).
 *
 * Permission:
 *   POST : commit:hukum (role: ketua_umum_bpm | superadmin)
 */
require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
$method = $_SERVER['REQUEST_METHOD'];
hukum_verify_csrf();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $pdo = getConnection();

    if ($method !== 'POST') {
        hukum_json_response(['success' => false, 'message' => 'Only POST allowed.'], 405);
    }

    hukum_require_permission('commit:hukum');

    $required = ['dokumen_id', 'forum_tipe', 'tanggal_forum'];
    foreach ($required as $r) {
        if (empty($input[$r])) {
            hukum_json_response(['success' => false, "Field '{$r}' wajib."], 400);
        }
    }

    // Validasi forum_tipe
    if (!in_array($input['forum_tipe'], ['MUBESMA', 'MUSLUB', 'LAINNYA'], true)) {
        hukum_json_response(['success' => false, 'message' => 'forum_tipe tidak valid.'], 400);
    }

    $dokumenId = (int) $input['dokumen_id'];
    $forumTipe = $input['forum_tipe'];
    $tanggalForum = $input['tanggal_forum']; // YYYY-MM-DD
    $stagingId = !empty($input['staging_id']) ? (int) $input['staging_id'] : null;
    $commitWindowId = !empty($input['commit_window_id']) ? (int) $input['commit_window_id'] : null;

    // Validasi dokumen ada
    $dokumen = dbFetchOne("SELECT id, judul, status FROM hukum_dokumen WHERE id = ?", [$dokumenId], "i");
    if (!$dokumen) {
        hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
    }

    // Validasi staging_id jika diberikan
    if ($stagingId) {
        $staging = dbFetchOne("SELECT id, meja_kerja_id, status FROM hukum_staging WHERE id = ?", [$stagingId], "i");
        if (!$staging) {
            hukum_json_response(['success' => false, 'message' => 'Staging tidak ditemukan.'], 404);
        }
        if ($staging['status'] !== 'menunggu_forum') {
            hukum_json_response([
                'success' => false,
                'message' => 'Staging sudah diproses. Status: ' . $staging['status']
            ], 409);
        }
    }

    // ENFORCEMENT: Pastikan ada meja kerja aktif (atau staging terhubung)
    if ($stagingId) {
        $mejaId = dbFetchOne(
            "SELECT meja_kerja_id FROM hukum_staging WHERE id = ?", [$stagingId], "i"
        )['meja_kerja_id'];
    } else {
        $mejaId = dbFetchOne(
            "SELECT id FROM hukum_meja_kerja WHERE dokumen_id = ? AND status = 'aktif' LIMIT 1",
            [$dokumenId], "i"
        )['id'];
    }

    // Cek apakah sudah ada commit aktif untuk dokumen ini
    $existingCommit = dbFetchOne(
        "SELECT id FROM hukum_commit WHERE dokumen_id = ? AND status = 'aktif' LIMIT 1",
        [$dokumenId], "i"
    );
    if ($existingCommit) {
        hukum_json_response([
            'success' => false,
            'message' => 'Dokumen sudah punya commit aktif. Commit berikutnya akan digantikan (digantikan).'
        ], 409);
    }

    // === HASH CHAINING ===
    // Hash parent = hash commit terakhir (status='aktif')
    $parentCommitId = null;
    $parentHash = null;
    $lastCommit = dbFetchOne(
        "SELECT id, hash_commit FROM hukum_commit WHERE dokumen_id = ? ORDER BY created_at DESC LIMIT 1",
        [$dokumenId], "i"
    );
    if ($lastCommit) {
        $parentCommitId = (int) $lastCommit['id'];
        $parentHash = $lastCommit['hash_commit'];
    }

    // Buat snapshot tree: BFS dari pasal_versi yang di-staging
    // Ambil semua pasal_versi yang terkait dengan staging/meja kerja
    $pasalVersiIds = [];
    if ($stagingId) {
        $stagingData = dbFetchOne("SELECT daftar_pasal_versi_id FROM hukum_staging WHERE id = ?", [$stagingId], "i");
        $pasalVersiIds = json_decode($stagingData['daftar_pasal_versi_id'], true);
    }

    $snapshotTree = [];
    if (!empty($pasalVersiIds)) {
        foreach ($pasalVersiIds as $vid) {
            $ver = dbFetchOne(
                "SELECT * FROM hukum_pasal_versi WHERE id = ?", [$vid], "i"
            );
            if ($ver) {
                $snapshotTree[] = [
                    'pasal_id' => (int) $ver['pasal_id'],
                    'versi_id' => (int) $ver['id'],
                    'hash_konten' => $ver['hash_konten'],
                    'status' => $ver['status']
                ];
            }
        }
    }

    // Generate snapshot tree JSON
    $snapshotTreeJson = json_encode($snapshotTree, JSON_UNESCAPED_UNICODE);

    // Hitung hash_commit: SHA256(parent_hash + dokumen_id + timestamp + snapshot_tree)
    $commitContent = ($parentHash ?? '') . '|' . $dokumenId . '|' . time() . '|' . $snapshotTreeJson;
    $hashCommit = hash('sha256', $commitContent);

    // === INSERT COMMIT ===
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO hukum_commit
            (dokumen_id, hash_commit, parent_commit_id, staging_id, commit_window_id,
             forum_tipe, tanggal_forum, snapshot_tree, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif', NOW())
    ");
    $stmt->execute([
        $dokumenId,
        $hashCommit,
        $parentCommitId,
        $stagingId,
        $commitWindowId,
        $forumTipe,
        $tanggalForum,
        $snapshotTreeJson
    ]);
    $commitId = (int) $pdo->lastInsertId();

    // Update status semua pasal_versi jadi 'committed'
    if (!empty($pasalVersiIds)) {
        $placeholders = str_repeat('?,', count($pasalVersiIds) - 1) . '?';
        $pdo->prepare("
            UPDATE hukum_pasal_versi SET status = 'committed'
            WHERE id IN ({$placeholders})
        ")->execute($pasalVersiIds);
    }

    // Update status staging jadi 'disetujui'
    if ($stagingId) {
        $pdo->prepare("UPDATE hukum_staging SET status = 'disetujui' WHERE id = ?")->execute([$stagingId]);
    }

    // Update status meja kerja jadi 'ditutup'
    if ($mejaId) {
        $pdo->prepare("UPDATE hukum_meja_kerja SET status = 'ditutup' WHERE id = ?")->execute([$mejaId]);
    }

    // Update status dokumen jadi 'diterbitkan'
    $pdo->prepare("UPDATE hukum_dokumen SET status = 'diterbitkan' WHERE id = ?")->execute([$dokumenId]);

    // Audit
    $pdo->prepare("
        INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
        VALUES ('hukum_commit', ?, 'create', ?, ?, NOW())
    ")->execute([
        $commitId,
        hukum_current_user_id(),
        json_encode([
            'hash_commit' => $hashCommit,
            'parent_commit_id' => $parentCommitId,
            'forum_tipe' => $forumTipe,
            'tanggal_forum' => $tanggalForum,
            'staging_id' => $stagingId,
            'snapshot_tree' => $snapshotTree
        ], JSON_UNESCAPED_UNICODE)
    ]);

    $pdo->commit();

    hukum_json_response([
        'success' => true,
        'message' => 'Commit berhasil dieksekusi.',
        'data' => [
            'commit_id'    => $commitId,
            'hash_commit'  => $hashCommit,
            'parent_hash'  => $parentHash,
            'forum_tipe'   => $forumTipe,
            'tanggal_forum'=> $tanggalForum,
            'snapshot_tree'=> $snapshotTree
        ]
    ], 201);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/commit.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

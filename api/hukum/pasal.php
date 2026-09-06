<?php
/**
 * api/hukum/pasal.php
 * CRUD untuk entitas hukum_pasal.
 *
 * Method & aksi:
 *   GET    ?dokumen_id=X — list pasal dalam dokumen (urut: urutan ASC)
 *   GET    ?id=X         — detail satu pasal (dengan versi terbaru)
 *   POST   body: dokumen_id, bab_id?, urutan, judul_pasal, isi_json
 *                          — buat pasal baru.
 *                          ENFORCEMENT: hanya bisa buat jika dokumen belum punya commit.
 *   PUT    ?id=X body: judul_pasal, urutan, isi_json
 *                          — update pasal.
 *                          ENFORCEMENT: hanya bisa edit jika tidak ada commit aktif
 *                          (atau kalau sudah ada commit, lewat staging).
 *   DELETE ?id=X         — hapus pasal (jika belum ada commit terkait)
 *
 * Permission:
 *   GET                : view:hukum
 *   POST / PUT / DELETE: create:hukum-dokumen / edit:hukum-dokumen / delete:hukum-dokumen
 */
require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
$method = $_SERVER['REQUEST_METHOD'];
hukum_verify_csrf();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $pdo = getConnection();

    if ($method === 'GET') {
        hukum_require_permission('view:hukum');

        if ($id > 0) {
            // Detail satu pasal + versi terbaru
            $row = dbFetchOne("
                SELECT p.*, 
                       (SELECT COUNT(*) FROM hukum_pasal_versi WHERE pasal_id = p.id) as total_versi
                FROM hukum_pasal p
                WHERE p.id = ?
            ", [$id], "i");
            if (!$row) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);

            // Ambil versi terbaru
            $latest_ver = dbFetchOne("
                SELECT * FROM hukum_pasal_versi 
                WHERE pasal_id = ? AND status = 'committed'
                ORDER BY created_at DESC LIMIT 1
            ", [$id], "i");
            $row['versi_terbaru'] = $latest_ver;

            hukum_json_response(['success' => true, 'data' => $row]);
        } else {
            // List pasal berdasarkan dokumen_id
            $dokumenId = isset($_GET['dokumen_id']) ? (int) $_GET['dokumen_id'] : 0;
            if ($dokumenId <= 0) {
                hukum_json_response(['success' => false, 'message' => 'dokumen_id wajib.'], 400);
            }

            $list = dbFetchAll("
                SELECT p.*, 
                       (SELECT COUNT(*) FROM hukum_pasal_versi WHERE pasal_id = p.id) as total_versi
                FROM hukum_pasal p
                WHERE p.dokumen_id = ?
                ORDER BY p.urutan ASC
            ", [$dokumenId], "i");

            // Tambahkan versi terbaru untuk setiap pasal
            foreach ($list as &$pasal) {
                $latest = dbFetchOne("
                    SELECT * FROM hukum_pasal_versi 
                    WHERE pasal_id = ? AND status = 'committed'
                    ORDER BY created_at DESC LIMIT 1
                ", [$pasal['id']], "i");
                $pasal['versi_terbaru'] = $latest;
            }

            hukum_json_response(['success' => true, 'data' => $list]);
        }
    }

    if ($method === 'POST') {
        hukum_require_permission('create:hukum-dokumen');

        $required = ['dokumen_id', 'urutan', 'judul_pasal'];
        foreach ($required as $r) {
            if (empty($input[$r])) {
                hukum_json_response(['success' => false, 'message' => "Field '{$r}' wajib diisi."], 400);
            }
        }

        // Validasi dokumen ada
        $dok = dbFetchOne("SELECT id, status FROM hukum_dokumen WHERE id = ?", [(int) $input['dokumen_id']], "i");
        if (!$dok) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);

        // ENFORCEMENT: hanya bisa buat pasal jika dokumen belum punya commit
        $hasCommit = dbFetchOne("SELECT id FROM hukum_commit WHERE dokumen_id = ? LIMIT 1", [(int) $input['dokumen_id']], "i");
        if ($hasCommit) {
            hukum_json_response([
                'success' => false,
                'message' => 'Dokumen sudah memiliki commit. Tidak bisa menambah pasal baru. Gunakan meja kerja + staging untuk revisi.'
            ], 409);
        }

        // Validasi bab ada jika diberikan
        if (!empty($input['bab_id'])) {
            $bab = dbFetchOne("SELECT id FROM hukum_bab WHERE id = ?", [(int) $input['bab_id']], "i");
            if (!$bab) {
                hukum_json_response(['success' => false, 'message' => 'Bab tidak ditemukan.'], 404);
            }
        }

        // Insert pasal
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO hukum_pasal (bab_id, dokumen_id, urutan, judul_pasal)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            !empty($input['bab_id']) ? (int) $input['bab_id'] : null,
            (int) $input['dokumen_id'],
            (int) $input['urutan'],
            sanitizeText($input['judul_pasal'], 255),
        ]);
        $newPasalId = (int) $pdo->lastInsertId();

        // Jika ada isi_json, buat versi awal (draft)
        if (!empty($input['isi_json'])) {
            $isiJson = is_array($input['isi_json']) ? json_encode($input['isi_json'], JSON_UNESCAPED_UNICODE) : $input['isi_json'];
            $hash = hash('sha256', $isiJson);

            dbQuery("
                INSERT INTO hukum_pasal_versi (pasal_id, isi, status, hash_konten, dibuat_oleh, meja_kerja_id)
                VALUES (?, ?, 'draft', ?, ?, NULL)
            ", [$newPasalId, $isiJson, $hash, hukum_current_user_id()]);
        }

        // Audit
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_pasal', ?, 'create', ?, ?, NOW())
        ")->execute([
            $newPasalId,
            hukum_current_user_id(),
            json_encode(['dokumen_id' => (int) $input['dokumen_id'], 'judul_pasal' => $input['judul_pasal']], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
        hukum_json_response([
            'success' => true,
            'message' => 'Pasal berhasil dibuat.',
            'id'      => $newPasalId,
        ], 201);
    }

    if ($method === 'PUT') {
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        // Validasi pasal ada
        $existing = dbFetchOne("SELECT id, dokumen_id FROM hukum_pasal WHERE id = ?", [$id], "i");
        if (!$existing) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);

        // ENFORCEMENT: cek apakah dokumen sudah punya commit
        $dokumen = dbFetchOne("SELECT status FROM hukum_dokumen WHERE id = ?", [$existing['dokumen_id']], "i");
        $hasCommit = dbFetchOne("SELECT id FROM hukum_commit WHERE dokumen_id = ? LIMIT 1", [$existing['dokumen_id']], "i");

        if ($hasCommit && $dokumen['status'] !== 'draf') {
            // Sudah commit — hanya bisa edit via staging
            if (!hukum_has_permission('edit:hukum-dokumen')) {
                hukum_json_response([
                    'success' => false,
                    'message' => 'Dokumen sudah dipublikasi. Edit hanya via meja kerja + staging.'
                ], 403);
            }
        } else {
            // Draft — permission biasa
            if (!hukum_has_permission('edit:hukum-dokumen')) {
                hukum_json_response(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
            }
        }

        $allowed = ['judul_pasal', 'urutan', 'bab_id', 'isi_json'];
        $sets = [];
        $params = [];
        $types = '';

        foreach ($allowed as $f) {
            if (!array_key_exists($f, $input)) continue;
            if ($f === 'isi_json' && is_array($input[$f])) {
                $sets[] = "`{$f}` = ?";
                $params[] = json_encode($input[$f], JSON_UNESCAPED_UNICODE);
                $types .= 's';
            } else {
                $sets[] = "`{$f}` = ?";
                $params[] = $input[$f];
                $types .= 's';
            }
        }
        if (empty($sets)) hukum_json_response(['success' => false, 'message' => 'Tidak ada field yang diupdate.'], 400);

        // Buat versi baru jika ada perubahan isi
        $newVersion = false;
        if (isset($input['isi_json']) && !empty($input['isi_json'])) {
            $newVersion = true;
        }

        $pdo->beginTransaction();

        // Update pasal
        $sets[] = "`diperbarui_oleh` = ?"; $params[] = hukum_current_user_id(); $types .= 'i';
        $sets[] = "`waktu_pembaruan` = NOW()";
        $params[] = $id; $types .= 'i';

        $pdo->prepare("UPDATE hukum_pasal SET " . implode(', ', $sets) . " WHERE id = ?")->execute(array_merge($params, [$id]));

        // Buat versi baru jika ada perubahan isi
        if ($newVersion) {
            $isiJson = is_array($input['isi_json']) ? json_encode($input['isi_json'], JSON_UNESCAPED_UNICODE) : $input['isi_json'];
            $hash = hash('sha256', $isiJson);

            dbQuery("
                INSERT INTO hukum_pasal_versi (pasal_id, isi, status, hash_konten, dibuat_oleh, meja_kerja_id, dibuat_dari_versi_id)
                SELECT ?, ?, 'draft', ?, ?, NULL, id FROM (SELECT id FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY created_at DESC LIMIT 1) AS prev
            ", [$id, $isiJson, $hash, hukum_current_user_id(), $id]);
        }

        // Audit
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_pasal', ?, 'update', ?, ?, NOW())
        ")->execute([
            $id,
            hukum_current_user_id(),
            json_encode($input, JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
        hukum_json_response(['success' => true, 'message' => 'Pasal berhasil diupdate.']);
    }

    if ($method === 'DELETE') {
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        $existing = dbFetchOne("SELECT id, dokumen_id FROM hukum_pasal WHERE id = ?", [$id], "i");
        if (!$existing) hukum_json_response(['success' => false, 'message' => 'Pasal tidak ditemukan.'], 404);

        // ENFORCEMENT: hanya bisa hapus jika belum ada commit terkait
        $hasCommit = dbFetchOne("SELECT id FROM hukum_commit WHERE dokumen_id = ? LIMIT 1", [$existing['dokumen_id']], "i");
        if ($hasCommit) {
            hukum_json_response([
                'success' => false,
                'message' => 'Dokumen sudah memiliki commit. Hapus pasal terkait dulu atau gunakan meja kerja.'
            ], 409);
        }

        // Cek apakah pasal punya versi
        $hasVersions = dbFetchOne("SELECT id FROM hukum_pasal_versi WHERE pasal_id = ? LIMIT 1", [$id], "i");
        if ($hasVersions) {
            hukum_json_response([
                'success' => false,
                'message' => 'Pasal sudah punya versi. Tidak bisa dihapus — gunakan soft-delete atau hapus versi dulu.'
            ], 409);
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM hukum_pasal WHERE id = ?")->execute([$id]);
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, waktu)
            VALUES ('hukum_pasal', ?, 'delete', ?, NOW())
        ")->execute([$id, hukum_current_user_id()]);
        $pdo->commit();

        hukum_json_response(['success' => true, 'message' => 'Pasal berhasil dihapus.']);
    }

    hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/pasal.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

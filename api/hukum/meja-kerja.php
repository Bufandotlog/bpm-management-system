<?php
/**
 * api/hukum/meja-kerja.php
 * Lifecycle meja kerja (hukum_meja_kerja).
 *
 * Method & aksi:
 *   GET  ?dokumen_id=X     — list meja kerja untuk dokumen
 *   GET  ?id=X             — detail satu meja kerja
 *   GET  ?dokumen_id=X&status=aktif
 *                          — meja kerja yang sedang aktif untuk dokumen tsb
 *   POST body: dokumen_id, nama, tujuan?, dibuka_hingga?
 *                          — buka meja kerja baru.
 *                          ENFORCEMENT: hanya boleh 1 meja kerja AKTIF per dokumen.
 *   PUT  ?id=X body: nama?, tujuan?, ditutup_pada?, status?
 *                          — update (status: aktif/ditutup/dibatalkan)
 *   DELETE ?id=X            — hapus meja kerja (jika belum ada pasal terlampir)
 *
 * Permission:
 *   GET   : view:hukum
 *   POST  : create:hukum-meja-kerja
 *   PUT   : create:hukum-meja-kerja (komisi_i hanya boleh tutup sendiri),
 *           close:hukum-meja-kerja (khusus ketua_umum_bpm/superadmin)
 *   DELETE: delete:hukum-dokumen
 */
require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
$method = $_SERVER['REQUEST_METHOD'];
hukum_verify_csrf();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$dokumenId = isset($_GET['dokumen_id']) ? (int) $_GET['dokumen_id'] : 0;
$status = $_GET['status'] ?? null;
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $pdo = getConnection();

    if ($method === 'GET') {
        hukum_require_permission('view:hukum');
        if ($id > 0) {
            $row = dbFetchOne("SELECT * FROM hukum_meja_kerja WHERE id = ?", [$id], "i");
            if (!$row) hukum_json_response(['success' => false, 'message' => 'Meja kerja tidak ditemukan.'], 404);
            hukum_json_response(['success' => true, 'data' => $row]);
        } elseif ($dokumenId > 0 && $status) {
            $list = dbFetchAll(
                "SELECT * FROM hukum_meja_kerja WHERE dokumen_id = ? AND status = ? ORDER BY dibuka_pada DESC",
                [$dokumenId, $status], "is"
            );
            hukum_json_response(['success' => true, 'data' => $list]);
        } elseif ($dokumenId > 0) {
            $list = dbFetchAll(
                "SELECT * FROM hukum_meja_kerja WHERE dokumen_id = ? ORDER BY dibuka_pada DESC",
                [$dokumenId], "i"
            );
            hukum_json_response(['success' => true, 'data' => $list]);
        } else {
            hukum_json_response(['success' => false, 'message' => 'id atau dokumen_id wajib.'], 400);
        }
    }

    if ($method === 'POST') {
        hukum_require_permission('create:hukum-meja-kerja');
        if (empty($input['dokumen_id']) || empty($input['nama'])) {
            hukum_json_response(['success' => false, 'message' => 'dokumen_id dan nama wajib.'], 400);
        }

        // Validasi dokumen ada
        $dok = dbFetchOne("SELECT id FROM hukum_dokumen WHERE id = ?", [(int) $input['dokumen_id']], "i");
        if (!$dok) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);

        // ENFORCEMENT: hanya 1 meja kerja AKTIF per dokumen
        $aktif = dbFetchOne(
            "SELECT id, nama FROM hukum_meja_kerja
             WHERE dokumen_id = ? AND status = 'aktif' LIMIT 1",
            [(int) $input['dokumen_id']], "i"
        );
        if ($aktif) {
            hukum_json_response([
                'success' => false,
                'code'    => 'ACTIVE_WORKSPACE_EXISTS',
                'message' => "Dokumen ini sudah punya meja kerja aktif: '{$aktif['nama']}' (id={$aktif['id']}). "
                           . "Tutup dulu sebelum membuka yang baru.",
                'active_id' => (int) $aktif['id'],
            ], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO hukum_meja_kerja
                (dokumen_id, nama, tujuan, dibuka_oleh, dibuka_pada, dibuka_hingga, status)
            VALUES (?, ?, ?, ?, NOW(), ?, 'aktif')
        ");
        $stmt->execute([
            (int) $input['dokumen_id'],
            sanitizeText($input['nama'], 200),
            sanitizeText($input['tujuan'] ?? '', 2000),
            hukum_current_user_id(),
            !empty($input['dibuka_hingga']) ? $input['dibuka_hingga'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_meja_kerja', ?, 'create', ?, ?, NOW())
        ")->execute([
            $newId,
            hukum_current_user_id(),
            json_encode(['dokumen_id' => (int) $input['dokumen_id'], 'nama' => $input['nama']], JSON_UNESCAPED_UNICODE)
        ]);

        hukum_json_response([
            'success' => true,
            'message' => 'Meja kerja dibuka.',
            'id'      => $newId,
        ], 201);
    }

    if ($method === 'PUT') {
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        $existing = dbFetchOne("SELECT * FROM hukum_meja_kerja WHERE id = ?", [$id], "i");
        if (!$existing) hukum_json_response(['success' => false, 'message' => 'Meja kerja tidak ditemukan.'], 404);

        $role = hukum_current_role();
        $isOwner = (int) $existing['dibuka_oleh'] === hukum_current_user_id();

        // Khusus close: butuh permission close atau role ketua/superadmin
        $isCloseAction = isset($input['status']) && in_array($input['status'], ['ditutup', 'dibatalkan'], true);

        if ($isCloseAction) {
            if (!hukum_has_permission('close:hukum-meja-kerja') && !($isOwner && $role === 'komisi_i')) {
                hukum_json_response([
                    'success' => false,
                    'message' => 'Anda tidak punya izin menutup meja kerja.'
                ], 403);
            }
        } else {
            // Update biasa
            if (!hukum_has_permission('create:hukum-meja-kerja')) {
                hukum_json_response(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
            }
        }

        $allowed = ['nama', 'tujuan', 'dibuka_hingga', 'status'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $input)) continue;
            if ($f === 'status' && !in_array($input[$f], ['aktif', 'ditutup', 'dibatalkan'], true)) {
                hukum_json_response(['success' => false, 'message' => "status tidak valid."], 400);
            }
            $sets[] = "`{$f}` = ?";
            $params[] = $input[$f];
        }
        if (empty($sets)) hukum_json_response(['success' => false, 'message' => 'Tidak ada field yang diupdate.'], 400);

        // Jika menutup, set ditutup_pada + ditutup_oleh
        if (isset($input['status']) && in_array($input['status'], ['ditutup', 'dibatalkan'], true)) {
            $sets[] = '`ditutup_pada` = NOW()';
            $sets[] = '`ditutup_oleh` = ?';
            $params[] = hukum_current_user_id();
        }
        $params[] = $id;

        $pdo->prepare("UPDATE hukum_meja_kerja SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_meja_kerja', ?, 'update', ?, ?, NOW())
        ")->execute([
            $id,
            hukum_current_user_id(),
            json_encode($input, JSON_UNESCAPED_UNICODE)
        ]);

        hukum_json_response(['success' => true, 'message' => 'Meja kerja diupdate.']);
    }

    if ($method === 'DELETE') {
        hukum_require_permission('delete:hukum-dokumen');
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        $existing = dbFetchOne("SELECT id, status FROM hukum_meja_kerja WHERE id = ?", [$id], "i");
        if (!$existing) hukum_json_response(['success' => false, 'message' => 'Meja kerja tidak ditemukan.'], 404);
        if ($existing['status'] === 'aktif') {
            hukum_json_response([
                'success' => false,
                'message' => 'Meja kerja masih aktif. Tutup dulu sebelum hapus.'
            ], 409);
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM hukum_meja_kerja WHERE id = ?")->execute([$id]);
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi, aktor_id, waktu)
            VALUES ('hukum_meja_kerja', ?, 'delete', ?, NOW())
        ")->execute([$id, hukum_current_user_id()]);
        $pdo->commit();

        hukum_json_response(['success' => true, 'message' => 'Meja kerja dihapus.']);
    }

    hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/meja-kerja.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

<?php
/**
 * api/hukum/bab.php
 * CRUD untuk entitas hukum_bab (chapter/section dalam dokumen hukum).
 *
 * Method & aksi:
 *   GET    ?dokumen_id=X — list bab dalam dokumen
 *   GET    ?id=X         — detail satu bab
 *   POST   body: dokumen_id, nomor_bab, judul_bab, urutan, deskripsi
 *   PUT    ?id=X body: field yang diupdate
 *   DELETE ?id=X         — hapus bab (jika belum ada pasal terkait)
 *
 * Permission: sama dengan dokumen.
 */
require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
$method = $_SERVER['REQUEST_METHOD'];
hukum_verify_csrf();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$dokumenId = isset($_GET['dokumen_id']) ? (int) $_GET['dokumen_id'] : 0;
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $pdo = getConnection();

    if ($method === 'GET') {
        hukum_require_permission('view:hukum');
        if ($id > 0) {
            $row = dbFetchOne("SELECT * FROM hukum_bab WHERE id = ?", [$id], "i");
            if (!$row) hukum_json_response(['success' => false, 'message' => 'Bab tidak ditemukan.'], 404);
            hukum_json_response(['success' => true, 'data' => $row]);
        } elseif ($dokumenId > 0) {
            $list = dbFetchAll(
                "SELECT * FROM hukum_bab WHERE dokumen_id = ? ORDER BY urutan ASC, id ASC",
                [$dokumenId], "i"
            );
            hukum_json_response(['success' => true, 'data' => $list]);
        } else {
            hukum_json_response(['success' => false, 'message' => 'Parameter id atau dokumen_id wajib.'], 400);
        }
    }

    if ($method === 'POST') {
        hukum_require_permission('create:hukum-dokumen'); // level izin sama dgn dokumen
        $required = ['dokumen_id', 'nomor_bab', 'judul_bab'];
        foreach ($required as $r) {
            if (empty($input[$r])) {
                hukum_json_response(['success' => false, 'message' => "Field '{$r}' wajib."], 400);
            }
        }
        // Validasi dokumen ada
        $dok = dbFetchOne("SELECT id FROM hukum_dokumen WHERE id = ?", [(int) $input['dokumen_id']], "i");
        if (!$dok) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);

        $stmt = $pdo->prepare("
            INSERT INTO hukum_bab
                (dokumen_id, nomor_bab, judul_bab, urutan, deskripsi)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) $input['dokumen_id'],
            sanitizeText($input['nomor_bab'], 50),
            sanitizeText($input['judul_bab'], 500),
            (int) ($input['urutan'] ?? 0),
            sanitizeText($input['deskripsi'] ?? '', 2000),
        ]);
        $newId = (int) $pdo->lastInsertId();
        hukum_json_response(['success' => true, 'message' => 'Bab berhasil dibuat.', 'id' => $newId], 201);
    }

    if ($method === 'PUT') {
        hukum_require_permission('edit:hukum-dokumen');
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        $allowed = ['nomor_bab', 'judul_bab', 'urutan', 'deskripsi'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $sets[] = "`{$f}` = ?";
                $params[] = $input[$f];
            }
        }
        if (empty($sets)) hukum_json_response(['success' => false, 'message' => 'Tidak ada field yang diupdate.'], 400);
        $params[] = $id;
        $pdo->prepare("UPDATE hukum_bab SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        hukum_json_response(['success' => true, 'message' => 'Bab berhasil diupdate.']);
    }

    if ($method === 'DELETE') {
        hukum_require_permission('delete:hukum-dokumen');
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        $hasPasal = dbFetchOne("SELECT id FROM hukum_pasal WHERE bab_id = ? LIMIT 1", [$id], "i");
        if ($hasPasal) {
            hukum_json_response([
                'success' => false,
                'message' => 'Bab sudah memiliki pasal. Hapus pasal terkait dulu.'
            ], 409);
        }
        $pdo->prepare("DELETE FROM hukum_bab WHERE id = ?")->execute([$id]);
        hukum_json_response(['success' => true, 'message' => 'Bab berhasil dihapus.']);
    }

    hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
} catch (Exception $e) {
    error_log("hukum/bab.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

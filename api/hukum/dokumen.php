<?php
/**
 * api/hukum/dokumen.php
 * CRUD untuk entitas master hukum_dokumen.
 *
 * Method & aksi:
 *   GET    ?id=X        — detail satu dokumen
 *   GET    (no id)      — list semua dokumen (urut: created_at DESC)
 *   POST   body: judul, jenis, deskripsi, format_mukadimah, mukadimah_legacy, mukadimah_json
 *                       — buat dokumen baru
 *   PUT    ?id=X body: field-field yang ingin diupdate
 *   DELETE ?id=X        — hapus dokumen (hanya jika belum ada commit terkait)
 *
 * Permission:
 *   GET                : view:hukum
 *   POST / PUT / DELETE: sesuai field yang diubah (lihat bawah)
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
            $row = dbFetchOne(
                "SELECT * FROM hukum_dokumen WHERE id = ?",
                [$id], "i"
            );
            if (!$row) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
            hukum_json_response(['success' => true, 'data' => $row]);
        } else {
            $list = dbFetchAll(
                "SELECT * FROM hukum_dokumen ORDER BY created_at DESC, id DESC"
            );
            hukum_json_response(['success' => true, 'data' => $list]);
        }
    }

    if ($method === 'POST') {
        hukum_require_permission('create:hukum-dokumen');

        $required = ['judul', 'jenis', 'format_mukadimah'];
        foreach ($required as $r) {
            if (empty($input[$r])) {
                hukum_json_response(['success' => false, 'message' => "Field '{$r}' wajib diisi."], 400);
            }
        }
        if (!in_array($input['format_mukadimah'], ['legacy', 'json'], true)) {
            hukum_json_response(['success' => false, 'message' => "format_mukadimah harus 'legacy' atau 'json'."], 400);
        }
        if (!in_array($input['jenis'], ['AD', 'ART', 'PB', 'PERATURAN', 'KEPUTUSAN'], true)) {
            hukum_json_response(['success' => false, 'message' => "jenis tidak valid."], 400);
        }

        // Slug dari judul (opsional, hanya untuk display)
        $slug = createSlug($input['judul']);

        // Validasi mukadimah sesuai format
        if ($input['format_mukadimah'] === 'legacy') {
            if (empty(trim($input['mukadimah_legacy'] ?? ''))) {
                hukum_json_response(['success' => false, 'message' => "Mukadimah legacy tidak boleh kosong."], 400);
            }
            $mukadimah_json = null;
        } else {
            if (empty($input['mukadimah_json']) || !is_array($input['mukadimah_json'])) {
                hukum_json_response(['success' => false, 'message' => "mukadimah_json harus berupa object/array."], 400);
            }
            $mukadimah_json = json_encode($input['mukadimah_json'], JSON_UNESCAPED_UNICODE);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO hukum_dokumen
                (judul, slug, jenis, deskripsi, format_mukadimah,
                 mukadimah_legacy, mukadimah_json, status, dibuat_oleh, waktu_dibuat)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draf', ?, NOW())
        ");
        $stmt->execute([
            sanitizeText($input['judul'], 500),
            $slug,
            $input['jenis'],
            sanitizeText($input['deskripsi'] ?? '', 2000),
            $input['format_mukadimah'],
            $input['mukadimah_legacy'] ?? null,
            $mukadimah_json,
            hukum_current_user_id(),
        ]);
        $newId = (int) $pdo->lastInsertId();

        // Audit: tulis ke hukum_notifikasi_audit
        $audit = $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi,aktor_id, detail_json, waktu)
            VALUES ('hukum_dokumen', ?, 'create', ?, ?, NOW())
        ");
        $audit->execute([
            $newId,
            hukum_current_user_id(),
            json_encode(['judul' => $input['judul'], 'jenis' => $input['jenis']], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
        hukum_json_response([
            'success' => true,
            'message' => 'Dokumen berhasil dibuat.',
            'id'      => $newId,
        ], 201);
    }

    if ($method === 'PUT') {
        hukum_require_permission('edit:hukum-dokumen');
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        // Tolak edit jika sudah ada commit (kecuali status draf)
        $existing = dbFetchOne("SELECT id, status FROM hukum_dokumen WHERE id = ?", [$id], "i");
        if (!$existing) hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        if ($existing['status'] !== 'draf' && !hukum_has_permission('delete:hukum-dokumen')) {
            hukum_json_response([
                'success' => false,
                'message' => 'Dokumen sudah dipublikasi. Hanya superadmin/ketua_umum_bpm yang boleh edit.'
            ], 403);
        }

        $allowed = ['judul', 'jenis', 'deskripsi', 'format_mukadimah',
                    'mukadimah_legacy', 'mukadimah_json', 'status'];
        $sets = [];
        $params = [];
        $types = '';

        foreach ($allowed as $f) {
            if (!array_key_exists($f, $input)) continue;
            if ($f === 'mukadimah_json' && is_array($input[$f])) {
                $sets[] = "`mukadimah_json` = ?";
                $params[] = json_encode($input[$f], JSON_UNESCAPED_UNICODE);
                $types .= 's';
            } else {
                $sets[] = "`{$f}` = ?";
                $params[] = $input[$f];
                $types .= 's';
            }
        }
        if (empty($sets)) hukum_json_response(['success' => false, 'message' => 'Tidak ada field yang diupdate.'], 400);

        $sets[] = '`diperbarui_oleh` = ?';   $params[] = hukum_current_user_id();  $types .= 'i';
        $sets[] = '`waktu_pembaruan` = NOW()';
        $params[] = $id;  $types .= 'i';

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE hukum_dokumen SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);

        $audit = $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
            VALUES ('hukum_dokumen', ?, 'update', ?, ?, NOW())
        ");
        $audit->execute([
            $id,
            hukum_current_user_id(),
            json_encode($input, JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
        hukum_json_response(['success' => true, 'message' => 'Dokumen berhasil diupdate.']);
    }

    if ($method === 'DELETE') {
        hukum_require_permission('delete:hukum-dokumen');
        if ($id <= 0) hukum_json_response(['success' => false, 'message' => 'id wajib.'], 400);

        // Tolak hapus jika sudah ada pasal atau commit terkait
        $hasPasal = dbFetchOne("SELECT id FROM hukum_pasal WHERE dokumen_id = ? LIMIT 1", [$id], "i");
        $hasCommit = dbFetchOne("SELECT id FROM hukum_commit WHERE dokumen_id = ? LIMIT 1", [$id], "i");
        if ($hasPasal || $hasCommit) {
            hukum_json_response([
                'success' => false,
                'message' => 'Dokumen sudah memiliki pasal/commit. Hapus pasal terkait dulu.'
            ], 409);
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM hukum_dokumen WHERE id = ?")->execute([$id]);
        $pdo->prepare("
            INSERT INTO hukum_notifikasi_audit
                (entitas, entitas_id, aksi, aktor_id, waktu)
            VALUES ('hukum_dokumen', ?, 'delete', ?, NOW())
        ")->execute([$id, hukum_current_user_id()]);
        $pdo->commit();
        hukum_json_response(['success' => true, 'message' => 'Dokumen berhasil dihapus.']);
    }

    hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/dokumen.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

<?php
/**
 * api/hukum/staging.php
 * Staging Engine (H-3) — submit draft pasal ke staging.
 *
 * Method & aksi:
 *   POST body: meja_kerja_id, daftar_pasal_versi_id[]
 *               — submit draft pasal ke staging untuk ditinjau forum.
 *               Atomic: semua draft masuk atau tidak sama sekali.
 *
 * Permission:
 *   POST : submit:staging (role: komisi_i)
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

    hukum_require_permission('submit:staging');

    $required = ['meja_kerja_id'];
    foreach ($required as $r) {
        if (empty($input[$r])) {
            hukum_json_response(['success' => false, "Field '{$r}' wajib."], 400);
        }
    }

    $mejaKerjaId = (int) $input['meja_kerja_id'];

    // Validasi meja kerja ada dan status aktif
    $meja = dbFetchOne(
        "SELECT id, dokumen_id, status FROM hukum_meja_kerja WHERE id = ? AND status = 'aktif'",
        [$mejaKerjaId], "i"
    );
    if (!$meja) {
        hukum_json_response([
            'success' => false,
            'message' => 'Meja kerja tidak ditemukan atau tidak aktif.'
        ], 404);
    }

    // Validasi dokumen tidak punya commit
    $hasCommit = dbFetchOne(
        "SELECT id FROM hukum_commit WHERE dokumen_id = ? LIMIT 1",
        [$meja['dokumen_id']], "i"
    );
    if ($hasCommit) {
        hukum_json_response([
            'success' => false,
            'message' => 'Dokumen sudah punya commit. Tidak bisa submit staging.'
        ], 409);
    }

    // Validasi daftar pasal_versi_id
    $daftarPasalVersi = $input['daftar_pasal_versi_id'] ?? [];
    if (empty($daftarPasalVersi) || !is_array($daftarPasalVersi)) {
        hukum_json_response(['success' => false, 'message' => 'daftar_pasal_versi_id wajib (array).'], 400);
    }

    // Validasi semua pasal_versi_id valid dan milik dokumen ini
    foreach ($daftarPasalVersi as $vid) {
        $ver = dbFetchOne(
            "SELECT pv.id, pv.pasal_id, p.dokumen_id 
             FROM hukum_pasal_versi pv
             JOIN hukum_pasal p ON p.id = pv.pasal_id
             WHERE pv.id = ?",
            [(int) $vid], "i"
        );
        if (!$ver) {
            hukum_json_response([
                'success' => false,
                'message' => "Versi pasal id={$vid} tidak ditemukan."
            ], 404);
        }
        if ($ver['dokumen_id'] != $meja['dokumen_id']) {
            hukum_json_response([
                'success' => false,
                'message' => "Versi pasal id={$vid} bukan milik dokumen ini."
            ], 409);
        }
    }

    // BEGIN: Atomic submit
    $pdo->beginTransaction();

    // Insert hukum_staging
    $stmt = $pdo->prepare("
        INSERT INTO hukum_staging (meja_kerja_id, daftar_pasal_versi_id, status, diajukan_pada)
        VALUES (?, ?, 'menunggu_forum', NOW())
    ");
    $stmt->execute([$mejaKerjaId, json_encode($daftarPasalVersi, JSON_UNESCAPED_UNICODE)]);
    $stagingId = (int) $pdo->lastInsertId();

    // Update status pasal_versi terkait jadi 'staged'
    $placeholders = str_repeat('?,', count($daftarPasalVersi) - 1) . '?';
    $stmt = $pdo->prepare("
        UPDATE hukum_pasal_versi SET status = 'staged'
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($daftarPasalVersi);

    // Buat notifikasi peninjauan untuk setiap pasal yang punya relasi
    // (Cross-reference chain reaction — detail di H-6, tapi kita catat akar-nya di sini)
    foreach ($daftarPasalVersi as $vid) {
        // Cek relasi pasal ini (apakah punya pasal anak yang perlu ditinjau)
        $relasi = dbFetchAll(
            "SELECT pasal_anak_id FROM hukum_relasi_pasal WHERE pasal_induk_id = ?",
            [(int) $vid], "i"
        );
        // Notifikasi detail akan ditangani oleh H-6 logic
        // Di sini kita hanya catat bahwa staging diajukan
    }

    // Audit
    $pdo->prepare("
        INSERT INTO hukum_notifikasi_audit (entitas, entitas_id, aksi, aktor_id, detail_json, waktu)
        VALUES ('hukum_staging', ?, 'submit', ?, ?, NOW())
    ")->execute([
        $stagingId,
        hukum_current_user_id(),
        json_encode([
            'meja_kerja_id' => $mejaKerjaId,
            'daftar_pasal_versi_id' => $daftarPasalVersi,
            'dokumen_id' => $meja['dokumen_id']
        ], JSON_UNESCAPED_UNICODE)
    ]);

    // Update meja kerja status jadi 'diajukan'
    $pdo->prepare("UPDATE hukum_meja_kerja SET status = 'diajukan' WHERE id = ?")->execute([$mejaKerjaId]);

    $pdo->commit();

    hukum_json_response([
        'success' => true,
        'message' => 'Staging berhasil diajukan.',
        'id'      => $stagingId,
        'data'    => [
            'meja_kerja_id' => $mejaKerjaId,
            'daftar_pasal_versi_id' => $daftarPasalVersi,
            'status' => 'menunggu_forum'
        ]
    ], 201);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("hukum/staging.php error: " . $e->getMessage());
    hukum_json_response(['success' => false, 'message' => 'Server error.'], 500);
}

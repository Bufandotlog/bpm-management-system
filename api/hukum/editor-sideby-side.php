<?php
/**
 * api/hukum/editor-sideby-side.php
 * Side-by-Side Editor (H-8)
 *
 * Menampilkan view draft vs live per pasal.
 * Membangun form JSON dinamis untuk edit konten pasal.
 *
 * Method & aksi:
 *   GET    /api/hukum/editor-sideby-side?pasal_id=xxx  — tampilkan pasal draft vs live
 *   POST   /api/hukum/editor-sideby-side                — simpan versi draft (buat hukum_pasal_versi)
 *
 * Request body POST:
 *   { "pasal_id": xxx,
 *     "versi": "draft",
 *     "konten_json": "{...}",  // JSON kanonik dari form dinamis
 *     "hash_konten": "sha256_string"
 *   }
 *
 * Response: JSON dengan struktur pasal (live + draft diff), validasi hash, status update.
 */

require __DIR__.'/../config/database.php';
$pdo = getConnection();

// Auth check: hanya admin, ketua_umum_bpm yang bisa edit
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','ketua_umum_bpm'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

$pasalId = (int)($_GET['pasal_id'] ?? 0);
if (!$pasalId) {
    header('HTTP/1.1 400 Bad Request');
    exit('Pasal ID wajib');
}

// Helper: buat hash SHA256 dari konten JSON
function hashKonten($jsonStr) {
    return hash('sha256', $jsonStr);
}

// === GET: ambil pasal draft vs live ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Ambil data pasal terlive (committed)
    $stmt = $pdo->prepare('
        SELECT id, judul_perubahan, nomor_label, hash_konten, format_mukadimah, created_at, updated_at
        FROM hukum_pasal WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$pasalId]);
    $live = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ambil versi draft terbaru dari hukum_pasal_versi
    $stmt = $pdo->prepare('
        SELECT id, status, hash_konten, dibuat_oleh, dibuat_pada, catatan
        FROM hukum_pasal_versi
        WHERE pasal_id = ? AND status = "draft"
        ORDER BY dibuat_pana DESC LIMIT 1
    ');
    $stmt->execute([$pasalId]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ambil format_mukadimah dari dokumen
    $stmt = $pdo->prepare('
        SELECT format_mukadimah, diperbarui_oleh, waktu_pembaruan
        FROM hukum_dokumen WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$live['dokumen_id'] ?? 0]);
    $dokumen = $stmt->fetch(PDO::FETCH_ASSOC);

    // Bangun comparison: apakah ada perbedaan hash
    $hasDifference = false;
    if ($live && $draft) {
        $hasDifference = ($live['hash_konten'] !== $draft['hash_konten']);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'pasal_id' => $pasalId,
            'live' => $live ?: ['id'=>$pasalId,'judul_perubahan'=>'','nomor_label'=>'','hash_konten'=>'','format_mukadimah'=>'','created_at'=>'','updated_at'=>''],
            'draft' => $draft ?: ['id'=>null,'status'=>'draft','hash_konten'=>'','dibuat_oleh'=>0,'dibuat_pada'=>'','catatan'=>''],
            'dokumen_format' => $dokumen ?: ['format_mukadimah'=>'legacy','diperbarui_oleh'=>0,'waktu_pembaruan'=>''],
            'has_difference' => $hasDifference,
            'can_edit' => in_array($_SESSION['role'], ['admin','ketua_umum_bpm'])
        ]
    ]);
    exit;
}

// === POST: simpan versi draft ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $kontenJson = $data['konten_json'] ?? '';
    $hashKontenInput = $data['hash_konten'] ?? '';
    $versi = $data['versi'] ?? 'draft';

    // validasi hash
    $hashValid = (hashKonten($kontenJson) === $hashKontenInput);
    if (!$hashValid) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success'=>false,'error'=>'Hash konten tidak cocok']);
        exit;
    }

    // cek pasal ada
    $stmt = $pdo->prepare('SELECT id, judul_perubahan FROM hukum_pasal WHERE id = ? LIMIT 1');
    $stmt->execute([$pasalId]);
    if (!$stmt->fetchColumn()) {
        header('HTTP/1.1 404 Not Found');
        exit('Pasal tidak ditemukan');
    }

    // Cek apakah sudah ada draft versi untuk pasal ini
    $stmt = $pdo->prepare('
        SELECT id FROM hukum_pasal_versi
        WHERE pasal_id = ? AND status = "draft"
        LIMIT 1
    ');
    $stmt->execute([$pasalId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // Update draft existente
        $stmt = $pdo->prepare('
            UPDATE hukum_pasal_versi
            SET hash_konten = ?, dibuat_oleh = ?, catatan = "editor-side-by-side", dibuat_pana = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$hashKontenInput, $user_id ?? 0, $existing]);
    } else {
        // Buat versi baru
        $stmt = $pdo->prepare('
            INSERT INTO hukum_pasal_versi
            (pasal_id, status, hash_konten, dibuat_oleh)
            VALUES (?, "draft", ?, ?)
        ');
        $stmt->execute([$pasalId, $hashKontenInput, $user_id ?? 0]);
    }

    // Update hash_konten di header pasal kalau perlu (untuk tracking)
    $stmt = $pdo->prepare('
        UPDATE hukum_pasal SET hash_konten = ?, updated_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([$hashKontenInput, $pasalId]);

    echo json_encode([
        'success' => true,
        'data' => [
            'saved' => true,
            'versi' => $versi,
            'hash' => $hashKontenInput,
            'new' => ($existing ? false : true)
        ]
    ]);
    exit;
}

header('HTTP/1.1 405 Method Not Allowed');
echo json_encode(['success'=>false,'error'=>'Method tidak didukung']);
exit;
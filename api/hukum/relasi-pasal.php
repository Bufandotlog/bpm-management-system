<?php
/**
 * api/hukum/relasi-pasal.php
 * Cross-Reference Relasi Pasal (H-6.1~H-6.9)
 *
 * Method & aksi:
 *   GET    /api/hukum/relasi-pasal?anak_id=xxx    — ambil semua relasi pasal anak
 *   GET    /api/hukum/relasi-pasal?induk_id=xxx   — ambil relasi pasal induk
 *   POST   /api/hukum/relasi-pasal                — buat relasi baru (hanya admin/ketua_umum_bpm)
 *   PUT    /api/hukum/relasi-pasal/:id           — update status relasi (setelah divalidasi)
 *   DELETE /api/hukum/relasi-pasal/:id          — hapus relasi (karena blok hard E1)
 *
 * Request body POST/PUT:
 *   { "jenis_relasi": "mengacu|berhubungan|sequensial|induk_anak",
 *     "dibuat_oleh": "auto|manual",
 *     "catatan": "opsional"
 *   }
 *
 * Response: JSON dengan {id, pasal_anak_id, pasal_induk_id, jenis_relasi,
 *               dibuat_oleh, dibuat_oleh_user_id, waktu_dibuat,
 *               pasal_anak_judul, pasal_induk_judul}
 */

require __DIR__.'/../config/database.php';
$pdo = getConnection();

// Auth check: hanya admin, komisi_i, atau ketua_umum_bpm
$isAuthed = false;
foreach ([
    'role' => 'guest',
    'user_id' => null,
    'username' => null
] as $key => $default) {
    if (isset($_SESSION[$key])) { $$key = $_SESSION[$key]; }
}
if (!isset($role)) { header('HTTP/1.1 401 Unauthorized'); exit('Unauthorized'); }
if (!in_array($role, ['admin','komisi_i','ketua_umum_bpm']) && !isset($_GET['public'])) {
    header('HTTP/1.1 403 Forbidden'); exit('Akses ditolak: role tidak diizinkan');
}

// Helper: ambil judul pasal
function getPasalJudul($pdo, $id) {
    $stmt = $pdo->prepare('SELECT judul_perubahan FROM hukum_pasal WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: 'Tanpa judul';
}

// === GET: ambil relasi pasal anak ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['anak_id'])) {
    $stmt = $pdo->prepare('
        SELECT r.*, p.judul_perubahan as pasal_anak_judul, p2.judul_perubahan as pasal_induk_judul
        FROM hukum_relasi_pasal r
        LEFT JOIN hukum_pasal p ON r.pasal_anak_id = p.id
        LEFT JOIN hukum_pasal p2 ON r.pasal_induk_id = p2.id
        WHERE r.pasal_anak_id = ?
        ORDER BY r.waktu_dibuat DESC
    ');
    $stmt->execute([(int)$_GET['anak_id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$results]);
    exit;
}

// === GET: ambil relasi pasal induk ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['induk_id'])) {
    $stmt = $pdo->prepare('
        SELECT r.*, p.judul_perubahan as pasal_anak_judul, p2.judul_perubahan as pasal_induk_judul
        FROM hukum_relasi_pasal r
        LEFT JOIN hukum_pasal p ON r.pasal_anak_id = p.id
        LEFT JOIN hukum_pasal p2 ON r.pasal_induk_id = p2.id
        WHERE r.pasal_induk_id = ?
        ORDER BY r.waktu_dibuat DESC
    ');
    $stmt->execute([(int)$_GET['induk_id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$results]);
    exit;
}

// === POST: buat relasi baru ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'guest') {
    $data = json_decode(file_get_contents('php://input'), true);
    $jenis = $data['jenis_relasi'] ?? '';
    $dibuat_oleh = $data['dibuat_oleh'] ?? 'auto';
    $pasal_anak_id = (int)($data['pasal_anak_id'] ?? 0);
    $pasal_induk_id = (int)($data['pasal_induk_id'] ?? 0);

    // validasi: pasal_anak dan pasal_induk harus ada
    $stmt = $pdo->prepare('SELECT id FROM hukum_pasal WHERE id = ? LIMIT 1');
    $stmt->execute([$pasal_anak_id]); $anak_exists = $stmt->fetchColumn();
    $stmt->execute([$pasal_induk_id]); $induk_exists = $stmt->fetchColumn();
    if (!$anak_exists || !$induk_exists) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success'=>false,'error'=>'Pasal anak atau induk tidak ditemukan']);
        exit;
    }

    // cek relasi sudah ada (unique constraint)
    $stmt = $pdo->prepare('
        SELECT id FROM hukum_relasi_pasal
        WHERE pasal_anak_id = ? AND pasal_induk_id = ?
    ');
    $stmt->execute([$pasal_anak_id, $pasal_induk_id]);
    if ($stmt->fetchColumn()) {
        header('HTTP/1.1 409 Conflict');
        echo json_encode(['success'=>false,'error'=>'Relasi sudah pernah dibuat']);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO hukum_relasi_pasal
        (pasal_anak_id, pasal_induk_id, jenis_relasi, dibuat_oleh, dibuat_oleh_user_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$pasal_anak_id, $pasal_induk_id, $jenis, $dibuat_oleh, $user_id ?? 0]);
    $relasi_id = $pdo->lastInsertId();

    // buat notifikasi peninjauan jika jenis_relasi = 'induk_anak' atau 'sequensial'
    if (in_array($jenis, ['induk_anak','sequensial'])) {
        $stmt = $pdo->prepare('
            INSERT INTO hukum_notifikasi_peninjauan
            (pasal_anak_id, pasal_induk_id, dipicu_oleh_pasal_versi_id, status)
            VALUES (?, ?, NULL, "perlu_ditinjau")
        ');
        $stmt->execute([$pasal_anak_id, $pasal_induk_id]);
    }

    echo json_encode(['success'=>true,'data'=>['id'=>$relasi_id],'notifikasi_buat'=>($jenis==='induk_anak'||$jenis==='sequensial')]);
    exit;
}

// === PUT: update status relasi ===
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $role !== 'guest') {
    $data = json_decode(file_get_contents('php://input'), true);
    $status = $data['status'] ?? ''; // perlu_ditinjau / sudah_diselaraskan / diabaikan_dengan_alasan
    $relasi_id = (int)($data['id'] ?? 0);
    $alasan = $data['alasan'] ?? '';

    if (!$relasi_id) {
        header('HTTP/1.1 400 Bad Request'); echo json_encode(['success'=>false,'error'=>'ID relasi wajib']); exit;
    }

    // validate status enum
    $allowed = ['perlu_ditinjau','sudah_diselaraskan','diabaikan_dengan_alasan'];
    if (!in_array($status, $allowed)) {
        header('HTTP/1.1 400 Bad Request'); echo json_encode(['success'=>false,'error'=>'Status tidak valid']); exit;
    }

    // jika diabaikan, perlu alasan min 30 char (CARA 2)
    if ($status === 'diabaikan_dengan_alasan' && (empty($alasan) || strlen($alasan) < 30)) {
        header('HTTP/1.1 400 Bad Request'); echo json_encode(['success'=>false,'error'=>'Alasan minimal 30 karakter']); exit;
    }

    // rate limit cek session counter
    $key = 'abaikan_count_'.$relasi_id;
    if (!isset($_SESSION[$key])) $_SESSION[$key] = 0;
    if ($_SESSION[$key] >= 3) {
        header('HTTP/1.1 429 Too Many Requests'); echo json_encode(['success'=>false,'error'=>'3 gagal coba 5 menit']); exit;
    }

    $stmt = $pdo->prepare('
        UPDATE hukum_relasi_pasal SET status = ?, alasan = ?, diperbarui_oleh = ?, waktu_diperbarui = NOW()
        WHERE id = ?
    ');
    $stmt->execute([$status, $alasan, $user_id ?? 0, $relasi_id]);

    // auto-resolve jika status sudah_diselarasan + jenis relasi tertentu
    if ($status === 'sudah_diselarasan') {
        $stmt = $pdo->prepare('
            UPDATE hukum_notifikasi_peninjauan SET status = "selesai", diselesaikan_oleh = ?, diselesaikan_pana = NOW()
            WHERE pasal_anak_id IN (SELECT pasal_anak_id FROM hukum_relasi_pasal WHERE id = ?)
              AND pasal_induk_id IN (SELECT pasal_induk_id FROM hukum_relasi_pasal WHERE id = ?)
        ');
        $stmt->execute([$user_id ?? 0, $relasi_id, $relasi_id]);
    }

    echo json_encode(['success'=>true,'data'=>['status'=>$status,'alasan'=>$alasan]]);
    exit;
}

// === DELETE: hapus relasi (hard block: karna single active workspace, hapus disallowed) ===
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success'=>false,'error'=>'Hapus relasi dilarang (hard block: single active workspace policy) — gunakan update status ke diabaikan_dengan_alasan']);
    exit;
}

header('HTTP/1.1 405 Method Not Allowed'); echo json_encode(['success'=>false,'error'=>'Method tidak didukung']); exit;
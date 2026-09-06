<?php
/**
 * api/hukum/public-render.php
 * Public Rendering (H-9)
 *
 * Method & aksi:
 *   GET    /api/hukum/public-render?commit_id=xxx  — ambil commit terbaru + render HTML
 *   GET    /api/hukum/public-render?pasal_id=xxx  — render halaman pasal publik (html)
 *
 * Response: JSON dengan {success, data: {html, version, hash, commit_tanggal, footer}}
 *
 * Logika:
 *   1. Ambil commit_id terbaru dari hukum_commit (status=aktif atau terbaru)
 *   2. Ambil hash_konten + format_mukadimah dari hukum_pasal + hukum_dokumen
 *   3. Jika format_mukadimah = 'json' → render JSON structure ke HTML
 *   4. Jika format_mukadimah = 'legacy' → render plain TEXT
 *   5. Tambah footer versi & hash_konten
 */

require __DIR__.'/../config/database.php';
$pdo = getConnection();

// Public rendering: boleh diakses tanpa auth (hanya read), tapi verifikasi commit
$commitId = (int)($_GET['commit_id'] ?? 0);
$pasalId = (int)($_GET['pasal_id'] ?? 0);

// Helper: ambil commit terbaru
function getTerbaruCommit($pdo, $commitId = 0) {
    if ($commitId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM hukum_commit WHERE id = ? LIMIT 1');
        $stmt->execute([$commitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Ambil commit aktif terbaru
    $stmt = $pdo->query("SELECT * FROM hukum_commit WHERE status='aktif' ORDER BY created_at DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper: render konten berdasarkan format
function renderKonten($pdo, $pasalId, $formatMukadimah) {
    // Ambil data pasal
    $stmt = $pdo->prepare('
        SELECT p.hash_konten, p.judul_perubahan, p.nomor_label,
               d.format_mukadimah, d.diperbarui_oleh, d.waktu_pembaruan
        FROM hukum_pasal p
        LEFT JOIN hukum_dokumen d ON p.dokumen_id = d.id
        WHERE p.id = ? LIMIT 1
    ');
    $stmt->execute([$pasalId]);
    $pasal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pasal) return '<p>Pasal tidak ditemukan</p>';

    $hash = $pasal['hash_konten'] ?? '';
    $format = $pasal['format_mukadimah'] ?? 'legacy';
    $judul = $pasal['judul_perubahan'] ?? 'Tanpa Judul';
    $nomor = $pasal['nomor_label'] ?? '';

    if ($format === 'json') {
        // Render JSON terstruktur ke HTML ringkas
        $data = json_decode($hash, true);
        $output = '<div class="pasal-render">';
        $output .= '<h2>' . htmlspecialchars($judul) . '</h2>';
        $output .= '<p><strong>Nomor:</strong> ' . htmlspecialchars($nomor) . '</p>';
        $output .= '<pre class="json-content">' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        $output .= '</div>';
    } else {
        // Legacy: plain TEXT (ambil isi dari hash_konten atau tampilkan info)
        $output = '<div class="pasal-render">';
        $output .= '<h2>' . htmlspecialchars($judul) . '</h2>';
        $output .= '<p><strong>Nomor:</strong> ' . htmlspecialchars($nomor) . '</p>';
        $output .= '<p><em>Format legacy - konten TEXT (diload dari database)</em></p>';
        $output .= '<p><strong>Hash:</strong> ' . htmlspecialchars(substr($hash, 0, 16)) . '...</p>';
        $output .= '</div>';
    }
    return $output;
}

// === GET: render publik berdasarkan commit_id ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $commit = getTerbaruCommit($pdo, $commitId);

    if (!$commit) {
        // Ambil commit terbaruapun
        $commit = getTerbaruCommit($pdo);
    }

    if (!$commit) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success'=>false,'error'=>'Tidak ada commit ditemukan']);
        exit;
    }

    // Ambil pasal terkait dari commit (dokumen_id)
    $stmt = $pdo->prepare('SELECT dokumen_id FROM hukum_commit WHERE id = ? LIMIT 1');
    $stmt->execute([$commit['id']]);
    $commitDoc = $stmt->fetchColumn();

    // Ambil data pasal + format
    $pasalData = renderKonten($pdo, $pasalId ?: $commitDoc ?: 0, '');

    // Footer versi & hash
    $footer = 'Versi ' . ($commit['tanggal_ekspirasi'] ? substr($commit['tanggal_ekspirasi'], 0, 10) : 'tidak known') .
              ' | Hash: ' . substr($commit['hash_commit'] ?? 'unknown', 0, 16) . '...';

    echo json_encode([
        'success' => true,
        'data' => [
            'commit' => [
                'id' => $commit['id'],
                'tanggal_forum' => $commit['tanggal_forum'],
                'forum_tipe' => $commit['forum_tipe'],
                'status' => $commit['status'],
                'hash_commit' => $commit['hash_commit']
            ],
            'pasal' => $pasalData,
            'footer' => $footer,
            'rendered_at' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}

// === GET: render halaman pasal publik per pasal_id ===
if (!isset($commit) || !isset($pasalData)) {
    // Ambil data pasal
    $stmt = $pdo->prepare('SELECT p.id, p.hash_konten, p.judul_perubahan, p.nomor_label, d.format_mukadimah FROM hukum_pasal p LEFT JOIN hukum_dokumen d ON p.dokumen_id = d.id WHERE p.id = ? LIMIT 1');
    $stmt->execute([$pasalId]);
    $pasal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pasal) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success'=>false,'error'=>'Pasal tidak ditemukan']);
        exit;
    }

    $rendered = renderKonten($pdo, $pasalId, $pasal['format_mukadimah'] ?? 'legacy');
    $footer = 'Versi Publik | Hash: ' . substr($pasal['hash_konten'] ?? '', 0, 16) . '...';

    echo json_encode([
        'success' => true,
        'data' => [
            'pasal_id' => $pasalId,
            'judul' => $pasal['judul_perubahan'] ?? 'Tanpa Judul',
            'nomor' => $pasal['nomor_label'] ?? '',
            'rendered_html' => $rendered,
            'format' => $pasal['format_mukadimah'] ?? 'legacy',
            'footer' => $footer,
            'rendered_at' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}

header('HTTP/1.1 405 Method Not Allowed');
echo json_encode(['success'=>false,'error'=>'Method tidak didukung']);
exit;
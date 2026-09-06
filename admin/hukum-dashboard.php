<?php
/**
 * admin/hukum-dashboard.php
 * Dashboard Admin Modul Hukum (H-7)
 *
 * Tabs:
 *   Tab 1: Meja Kerja Saya — cek single active workspace enforcement
 *   Tab 2: Menunggu Forum — daftar hukum_staging berstatus menunggu_forum
 *   Tab 3: Riwayat Ketetapan — daftar hukum_commit (aktif & digantikan) + link ke meja kerja archived
 *   Tab 4: Notifikasi Peninjauan — badge merah jika ada relasi yang berubah
 *   Tab 5: Audit Trail — read-only log dari hukum_notifikasi_audit
 *
 * Dependencies:
 *   - config/database.php (PDO)
 *   - admin/core/auth-check.php (auth middleware)
 *   - Session: role, user_id, username
 */

require __DIR__.'/../config/database.php';
$pdo = getConnection();

// Auth check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','komisi_i','ketua_umum_bpm'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

// Helper: count meja kerja aktif
function countMejaKerjaAktif($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM hukum_meja_kerja WHERE status='aktif'");
    return (int)$stmt->fetchColumn();
}

// Helper: count staging menunggu forum
function countStagingMenunggu($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM hukum_staging WHERE status='menunggu_forum'");
    return (int)$stmt->fetchColumn();
}

// Helper: commit count
function countCommitAktif($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM hukum_commit WHERE status='aktif'");
    return (int)$stmt->fetchColumn();
}

// Helper: notifikasi count merah
function countNotifikasiAktif($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM hukum_notifikasi_peninjauan WHERE status='perlu_ditinjau'");
    return (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id" dir="ltr">
<head>
<meta charset="utf-8">
<title>Dashboard — Modul Hukum</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<h1>Dashboard — Modul Hukum</h1>

<!-- Tab 1: Meja Kerja Saya -->
<div class="tab">
<h2>Meja Kerja Saya</h2>
<p>Status aktif di sistem: <strong><?php echo countMejaKerjaAktif($pdo); ?></strong> meja kerja</p>
<?php if (countMejaKerjaAktif($pdo) == 0): ?>
<p><a href="meja-kerja-edit.php" class="btn">Buat Meja Kerja Baru</a></p>
<?php else: ?>
<p><span class="disabled">Meja kerja aktif sudah ada — tombol disabled</span></p>
<?php endif; ?>
</div>

<!-- Tab 2: Menunggu Forum -->
<div class="tab">
<h2>Menunggu Forum</h2>
<p>Daftar <code>hukum_staging</code> status <em>menunggu_forum</em>: <strong><?php echo countStagingMenunggu($pdo); ?></strong> item</p>
<table>
<thead><tr><th>ID</th><th>Dokumen ID</th><th>Judul</th><th>Dibuat Oleh</th><th>Tanggal</th></tr></thead>
<tbody>
<?php
$stmt = $pdo->query("
    SELECT hs.id, hs.dokumen_id, d.judul_perubahan, u.username, hs.created_at
    FROM hukum_staging hs
    LEFT JOIN hukum_dokumen d ON hs.dokumen_id = d.id
    LEFT JOIN users u ON hs.dibuat_oleh = u.id
    WHERE hs.status = 'menunggu_forum'
    ORDER BY hs.created_at DESC
    LIMIT 20
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>{$row['id']}</td><td>{$row['dokumen_id']}</td><td>{$row['judul_perubahan']}</td><td>{$row['username']}</td><td>{$row['created_at']}</td></tr>";
}
?>
</tbody>
</table>
</div>

<!-- Tab 3: Riwayat Ketetapan -->
<div class="tab">
<h2>Riwayat Ketetapan</h2>
<p>Daftar <code>hukum_commit</code> (aktif & digantikan): <strong><?php echo countCommitAktif($pdo); ?></strong> item aktif</p>
<table>
<thead><tr><th>ID</th><th>Dokumen ID</th><th>Hash Commit</th><th>Tanggal Forum</th><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php
$stmt = $pdo->query("
    SELECT c.id, c.dokumen_id, c.hash_commit, c.tanggal_forum, c.status,
           GROUP_CONCAT(DISTINCT r.jenis_relasi SEPARATOR ', ') as relasi_aktif
    FROM hukum_commit c
    LEFT JOIN hukum_relasi_pasal r ON r.pasal_induk_id IN (
        SELECT pasal_induk_id FROM hukum_relasi_pasal WHERE relasi_id = c.id OR relasi_id = c.id
    )
    WHERE c.status IN ('aktif','diganti')
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 20
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>{$row['id']}</td><td>{$row['dokumen_id']}</td><td>{$row['hash_commit']}</td><td>{$row['tanggal_forum']}</td><td>{$row['status']}</td><td>{$row['relasi_aktif']}</td></tr>";
}
?>
</tbody>
</table>
</div>

<!-- Tab 4: Notifikasi Peninjauan -->
<div class="tab">
<h2>Notifikasi Peninjauan</h2>
<p>Badge merah: <strong><?php echo countNotifikasiAktif($pdo); ?></strong> relasi perlu ditinjau</p>
<table>
<thead><tr><th>ID</th><th>Pasal Anak</th><th>Pasal Induk</th><th>Jenis Relasi</th><th>Status</th></tr></thead>
<tbody>
<?php
$stmt = $pdo->query("
    SELECT n.id, n.pasal_anak_id, n.pasal_induk_id, r.jenis_relasi, n.status,
           p.judul_perubahan as anak_judul, p2.judul_perubahan as induk_judul
    FROM hukum_notifikasi_peninjauan n
    LEFT JOIN hukum_relasi_pasal r ON r.pasal_anak_id = n.pasal_anak_id AND r.pasal_induk_id = n.pasal_induk_id
    LEFT JOIN hukum_pasal p ON n.pasal_anak_id = p.id
    LEFT JOIN hukum_pasal p2 ON n.pasal_induk_id = p2.id
    WHERE n.status = 'perlu_ditinjau'
    ORDER BY n.dilakukan_pana DESC
    LIMIT 20
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['pasal_anak_id']} ({$row['anak_judul']})</td>";
    echo "<td>{$row['pasal_induk_id']} ({$row['induk_judul']})</td>";
    echo "<td>{$row['jenis_relasi']}</td>";
    echo "<td>{$row['status']}</td>";
    echo "</tr>";
}
?>
</tbody>
</table>
</div>

<!-- Tab 5: Audit Trail -->
<div class="tab">
<h2>Audit Trail</h2>
<p>Log semua aksi pada <code>hukum_notifikasi_audit</code></p>
<table>
<thead><tr><th>ID</th><th>Aksi</th><th>Dilakukan Oleh</th><th>Waktu</th><th>Catatan</th></tr></thead>
<tbody>
<?php
$stmt = $pdo->query("
    SELECT id, axsi, dilakukan_oleh, dilakukan_pada, catatan
    FROM hukum_notifikasi_audit
    ORDER BY dilakukan_pada DESC
    LIMIT 50
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>{$row['id']}</td><td>{$row['axsi']}</td><td>{$row['dilakukan_oleh']}</td><td>{$row['dilakukan_pada']}</td><td>{$row['catatan']}</td></tr>";
}
?>
</tbody>
</table>
</div>

<style>
.tab { display:none; }
.tab.active { display:block; }
.btn { display:inline-block;padding:6px 12px;border:1px solid #ccc;border-radius:4px;background:#f5f5f5;text-decoration:none;color:#333;margin-top:8px; }
.btn:focus { outline:2px solid #0066cc; }
table { width:100%; border-collapse:collapse; margin-top:10px; }
th, td { border:1px solid #ddd;padding:6px;text-align:left; }
th { background:#f8f8f8; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show first tab by default
    document.querySelectorAll('.tab')[0]?.classList.add('active');
    
    // Tab switching
    const tabs = document.querySelectorAll('.tab');
    const tabLinks = document.querySelectorAll('a.btn');
    
    tabs.forEach((tab, idx) => {
        tab.style.display = 'block';
    });
    
    if (tabLinks.length > 0) {
        tabLinks[0]?.click();
    }
});
</script>
</body>
</html>
<?php
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Produk Hukum';
$filters = [
    'jenis' => trim((string) ($_GET['jenis'] ?? '')),
    'lingkup' => trim((string) ($_GET['lingkup'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$allowedJenis = ['AD', 'ART', 'GBHO', 'GBMO', 'PERATURAN', 'KEPUTUSAN'];
$allowedLingkup = ['induk', 'BEM', 'BPM', 'UKM'];

$sql = 'SELECT id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, published_at
        FROM hukum_dokumen WHERE status = \'aktif\'';
$params = [];
if (in_array($filters['jenis'], $allowedJenis, true)) {
    $sql .= ' AND jenis = ?';
    $params[] = $filters['jenis'];
}
if (in_array($filters['lingkup'], $allowedLingkup, true)) {
    $sql .= ' AND lingkup = ?';
    $params[] = $filters['lingkup'];
}
if ($filters['q'] !== '') {
    $sql .= ' AND (judul LIKE ? OR deskripsi LIKE ? OR nama_ormawa LIKE ?)';
    $term = '%' . $filters['q'] . '%';
    array_push($params, $term, $term, $term);
}
$sql .= ' ORDER BY jenis, judul';
$documents = dbFetchAll($sql, $params);
?>
<?php include __DIR__ . '/header.php'; ?>
<?php $hukum_css_ver = file_exists(__DIR__ . '/assets/css/hukum.css') ? filemtime(__DIR__ . '/assets/css/hukum.css') : '1'; ?>
<link rel="stylesheet" href="<?php echo assetUrl('css/hukum.css'); ?>?v=<?php echo $hukum_css_ver; ?>">

<div class="hukum-public">
    <div class="hero-caption">
        <div class="caption-content">
            <h1 class="caption-title"><span>PRODUK HUKUM</span></h1>
            <p class="caption-narasi">Dokumen hukum resmi BPM yang telah disahkan dan dipublikasikan.</p>
        </div>
    </div>

    <div class="container hukum-public-content">
        <form class="hukum-public-filters" method="get">
            <label>
                <span>Kata kunci</span>
                <input type="search" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Cari dokumen...">
            </label>
            <label>
                <span>Jenis</span>
                <select name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach ($allowedJenis as $jenis): ?>
                        <option value="<?php echo $jenis; ?>" <?php echo $filters['jenis'] === $jenis ? 'selected' : ''; ?>><?php echo $jenis; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Lingkup</span>
                <select name="lingkup">
                    <option value="">Semua lingkup</option>
                    <?php foreach ($allowedLingkup as $lingkup): ?>
                        <option value="<?php echo $lingkup; ?>" <?php echo $filters['lingkup'] === $lingkup ? 'selected' : ''; ?>><?php echo htmlspecialchars($lingkup); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-small" type="submit">Cari</button>
        </form>

        <?php if (!$documents): ?>
            <div class="hukum-public-empty">
                <i class="fas fa-scale-balanced"></i>
                <p>Belum ada produk hukum yang dipublikasikan.</p>
            </div>
        <?php else: ?>
            <div class="hukum-public-grid">
                <?php foreach ($documents as $document): ?>
                    <article class="hukum-public-card">
                        <div class="hukum-public-card-top">
                            <span class="hukum-public-badge"><?php echo htmlspecialchars($document['jenis']); ?></span>
                            <span><?php echo htmlspecialchars($document['lingkup']); ?></span>
                        </div>
                        <h2><?php echo htmlspecialchars($document['judul']); ?></h2>
                        <?php if (!empty($document['nama_ormawa'])): ?>
                            <p class="hukum-public-muted"><?php echo htmlspecialchars($document['nama_ormawa']); ?></p>
                        <?php endif; ?>
                        <p><?php echo htmlspecialchars($document['deskripsi'] ?: 'Dokumen hukum resmi BPM.'); ?></p>
                        <a class="btn btn-small" href="<?php echo baseUrl('hukum-detail.php?slug=' . urlencode($document['slug'])); ?>">Baca Dokumen</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

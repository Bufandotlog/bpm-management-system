<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hukum-public.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$document = hukum_public_document_by_slug($slug);
if (!$document) {
    http_response_code(404);
    $page_title = 'Peta Relasi Tidak Ditemukan';
    include __DIR__ . '/header.php';
    echo '<div class="container hukum-public-empty"><h1>Peta relasi tidak ditemukan</h1><p>Dokumen publik yang diminta tidak tersedia atau sudah tidak aktif.</p><a class="btn btn-small" href="' . htmlspecialchars(baseUrl('hukum.php')) . '">Kembali ke Produk Hukum</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$commit = hukum_public_active_commit_by_document((int) $document['id']);
$graph = $commit ? hukum_public_relation_map_for_commit((int) $commit['id']) : ['nodes' => [], 'edges' => []];

$page_title = 'Peta Relasi ' . $document['judul'];
include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="<?php echo assetUrl('css/hukum.css'); ?>">
<div class="container hukum-public-detail">
    <a class="hukum-public-back" href="<?php echo baseUrl('hukum-detail.php?slug=' . urlencode($document['slug'])); ?>">&larr; Kembali ke dokumen</a>
    <header class="hukum-document-header">
        <div class="hukum-public-card-top">
            <span class="hukum-public-badge"><?php echo htmlspecialchars($document['jenis']); ?></span>
            <span><?php echo htmlspecialchars($document['lingkup']); ?></span>
        </div>
        <h1>Peta relasi publik: <?php echo htmlspecialchars($document['judul']); ?></h1>
        <p class="hukum-public-muted">Snapshot aktif · <?php echo $commit ? htmlspecialchars((string) $commit['forum_tipe']) : 'tidak tersedia'; ?></p>
    </header>

    <section class="hukum-chapter">
        <h2>Legenda</h2>
        <div class="hukum-summary-grid">
            <div class="hukum-public-card"><span class="hukum-status-tag status-added">Node</span> <span>Pasal aktif pada snapshot publik.</span></div>
            <div class="hukum-public-card"><span class="hukum-status-tag status-modified">Edge</span> <span>Hubungan antar pasal.</span></div>
        </div>
    </section>

    <section class="hukum-chapter">
        <h2>Node</h2>
        <?php if (!$graph['nodes']): ?>
            <div class="hukum-public-empty"><p>Belum ada node pada snapshot publik.</p></div>
        <?php else: ?>
            <ul>
                <?php foreach ($graph['nodes'] as $node): ?>
                    <li><?php echo htmlspecialchars((string) ($node['nomor_label'] ?? 'Pasal ' . (int) ($node['pasal_id'] ?? 0))); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="hukum-chapter">
        <h2>Edge</h2>
        <?php if (!$graph['edges']): ?>
            <div class="hukum-public-empty"><p>Belum ada edge pada snapshot publik.</p></div>
        <?php else: ?>
            <ul>
                <?php foreach ($graph['edges'] as $edge): ?>
                    <li><?php echo htmlspecialchars((string) ($edge['jenis_relasi'] ?? 'mengacu')); ?>: pasal <?php echo htmlspecialchars((string) ((int) $edge['source_pasal_id'])); ?> → pasal <?php echo htmlspecialchars((string) ((int) $edge['target_pasal_id'])); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/functions.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$document = $slug === '' ? null : dbFetchOne(
    'SELECT id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, mukadimah_json, published_at
     FROM hukum_dokumen WHERE slug = ? AND status = \'aktif\' LIMIT 1',
    [$slug]
);
if (!$document) {
    http_response_code(404);
    $page_title = 'Dokumen Tidak Ditemukan';
    include __DIR__ . '/header.php';
    echo '<div class="container hukum-public-empty"><h1>Dokumen tidak ditemukan</h1><p>Dokumen belum dipublikasikan atau tautannya tidak valid.</p><a class="btn btn-small" href="' . htmlspecialchars(baseUrl('hukum.php')) . '">Kembali ke Produk Hukum</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$commit = dbFetchOne(
    'SELECT id, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, created_at
     FROM hukum_commit WHERE dokumen_id = ? AND status = \'aktif\' ORDER BY id DESC LIMIT 1',
    [$document['id']]
);
$chapters = dbFetchAll(
    'SELECT id, nomor_label, judul_bab FROM hukum_bab WHERE dokumen_id = ? ORDER BY urutan, id',
    [$document['id']]
);
$snapshot = $commit ? json_decode($commit['snapshot_tree'], true) : [];
$snapshot = is_array($snapshot) ? $snapshot : [];
$pasalById = [];
foreach ($snapshot as $pasal) {
    if (isset($pasal['pasal_id'])) {
        $pasalById[(int) $pasal['pasal_id']] = $pasal;
    }
}
$pasalRows = dbFetchAll(
    'SELECT p.id, p.bab_id, p.nomor_label, p.judul_pasal
     FROM hukum_pasal p WHERE p.dokumen_id = ? ORDER BY p.urutan, p.id',
    [$document['id']]
);

function hukum_public_content($value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $key => $item) {
            $label = is_string($key) && !is_numeric($key) ? '<strong>' . htmlspecialchars($key) . ':</strong> ' : '';
            $parts[] = '<li>' . $label . hukum_public_content($item) . '</li>';
        }
        return '<ul>' . implode('', $parts) . '</ul>';
    }
    return nl2br(htmlspecialchars((string) $value));
}

$page_title = $document['judul'];
include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="<?php echo assetUrl('css/hukum.css'); ?>">
<div class="container hukum-public-detail">
    <a class="hukum-public-back" href="<?php echo baseUrl('hukum.php'); ?>">&larr; Kembali ke Produk Hukum</a>
    <header class="hukum-document-header">
        <div class="hukum-public-card-top">
            <span class="hukum-public-badge"><?php echo htmlspecialchars($document['jenis']); ?></span>
            <span><?php echo htmlspecialchars($document['lingkup']); ?></span>
        </div>
        <h1><?php echo htmlspecialchars($document['judul']); ?></h1>
        <?php if (!empty($document['nama_ormawa'])): ?><p class="hukum-public-muted"><?php echo htmlspecialchars($document['nama_ormawa']); ?></p><?php endif; ?>
        <p><?php echo htmlspecialchars($document['deskripsi'] ?: 'Dokumen hukum resmi BPM.'); ?></p>
        <?php if ($commit): ?><small class="hukum-public-muted">Disahkan melalui <?php echo htmlspecialchars($commit['forum_tipe']); ?> pada <?php echo htmlspecialchars($commit['tanggal_forum']); ?> · Integritas: <?php echo htmlspecialchars(substr($commit['hash_commit'], 0, 16)); ?>…</small><?php endif; ?>
    </header>

    <?php if (!$commit): ?>
        <div class="hukum-public-empty"><p>Isi dokumen belum tersedia untuk publik.</p></div>
    <?php else: ?>
        <?php foreach ($chapters as $chapter): ?>
            <section class="hukum-chapter">
                <h2><?php echo htmlspecialchars($chapter['nomor_label'] . ' — ' . $chapter['judul_bab']); ?></h2>
                <?php foreach ($pasalRows as $pasal): ?>
                    <?php if ((int) $pasal['bab_id'] !== (int) $chapter['id'] || !isset($pasalById[(int) $pasal['id']])) continue; ?>
                    <article class="hukum-pasal">
                        <h3><?php echo htmlspecialchars($pasal['nomor_label'] . (!empty($pasal['judul_pasal']) ? ' — ' . $pasal['judul_pasal'] : '')); ?></h3>
                        <div><?php echo hukum_public_content($pasalById[(int) $pasal['id']]['isi'] ?? []); ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>

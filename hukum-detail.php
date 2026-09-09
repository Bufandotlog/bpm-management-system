<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hukum-public.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$document = hukum_public_document_by_slug($slug);
if (!$document) {
    http_response_code(404);
    $page_title = 'Dokumen Tidak Ditemukan';
    include __DIR__ . '/header.php';
    echo '<div class="container hukum-public-empty"><h1>Dokumen tidak ditemukan</h1><p>Dokumen belum dipublikasikan atau tautannya tidak valid.</p><a class="btn btn-small" href="' . htmlspecialchars(baseUrl('hukum.php')) . '">Kembali ke Produk Hukum</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$latestCommit = hukum_public_active_commit_by_document((int) $document['id']);
$history = hukum_public_history_by_document((int) $document['id']);
$previousCommit = count($history) > 1 ? $history[1] : null;
$diffItems = $previousCommit && $latestCommit ? hukum_public_diff_snapshot($previousCommit, $latestCommit) : [];
$references = $latestCommit ? hukum_public_extract_references_from_snapshot(hukum_public_snapshot_items($latestCommit)) : [];
$snapshot = $latestCommit ? hukum_public_snapshot_items($latestCommit) : [];
$snapshotMap = $latestCommit ? hukum_public_snapshot_map($latestCommit) : [];

$chapters = dbFetchAll(
    'SELECT id, nomor_label, judul_bab FROM hukum_bab WHERE dokumen_id = ? ORDER BY urutan, id',
    [(int) $document['id']]
);
$pasalRows = dbFetchAll(
    'SELECT p.id, p.bab_id, p.nomor_label, p.judul_pasal
     FROM hukum_pasal p
     WHERE p.dokumen_id = ?
     ORDER BY p.urutan, p.id',
    [(int) $document['id']]
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
        <?php if ($latestCommit): ?><small class="hukum-public-muted">Disahkan melalui <?php echo htmlspecialchars($latestCommit['forum_tipe']); ?> pada <?php echo htmlspecialchars($latestCommit['tanggal_forum']); ?> · Integritas: <?php echo htmlspecialchars(substr((string) $latestCommit['hash_commit'], 0, 16)); ?>…</small><?php endif; ?>
    </header>

    <?php if (!$latestCommit): ?>
        <div class="hukum-public-empty"><p>Isi dokumen belum tersedia untuk publik.</p></div>
    <?php else: ?>
        <div class="hukum-summary-grid">
            <div class="hukum-public-card">
                <h3>Versi aktif</h3>
                <p class="hukum-public-muted">Hash: <?php echo htmlspecialchars(substr((string) $latestCommit['hash_commit'], 0, 32)); ?></p>
                <p class="hukum-public-muted">Forum: <?php echo htmlspecialchars((string) $latestCommit['forum_tipe']); ?> · <?php echo htmlspecialchars((string) $latestCommit['tanggal_forum']); ?></p>
            </div>
            <div class="hukum-public-card">
                <h3>Riwayat</h3>
                <p class="hukum-public-muted"><?php echo count($history); ?> komit publik tercatat</p>
                <p><a href="<?php echo baseUrl('hukum-history.php?slug=' . urlencode($document['slug'])); ?>">Lihat riwayat lengkap</a></p>
            </div>
            <div class="hukum-public-card">
                <h3>Hubungan</h3>
                <p class="hukum-public-muted">Peta relasi snapshot yang dipublikasikan</p>
                <p><a href="<?php echo baseUrl('hukum-graf.php?slug=' . urlencode($document['slug'])); ?>">Lihat peta relasi</a></p>
            </div>
        </div>

        <?php if ($previousCommit && $diffItems): ?>
            <section class="hukum-chapter">
                <h2>Perubahan struktural</h2>
                <div class="hukum-diff-table">
                    <?php foreach ($diffItems as $item): ?>
                        <?php $pasalId = (int) ($item['pasal_id'] ?? 0); ?>
                        <div class="hukum-diff-row">
                            <span class="hukum-status-tag status-<?php echo htmlspecialchars((string) $item['status']); ?>"><?php echo htmlspecialchars(hukum_public_diff_status_label((string) $item['status'])); ?></span>
                            <strong>Pasal <?php echo htmlspecialchars((string) $pasalId); ?></strong>
                            <?php if ($item['status'] === 'modified'): ?>
                                <span>Struktur pasal berubah dari versi sebelumnya.</span>
                            <?php elseif ($item['status'] === 'added'): ?>
                                <span>Pasal baru ditambahkan ke snapshot aktif.</span>
                            <?php elseif ($item['status'] === 'removed'): ?>
                                <span>Pasal dihapus dari snapshot baru.</span>
                            <?php else: ?>
                                <span>Tidak ada perubahan pada struktur pasal.</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($references): ?>
            <section class="hukum-chapter">
                <h2>Referensi publik</h2>
                <ul>
                    <?php foreach ($references as $ref): ?>
                        <li><?php echo htmlspecialchars($ref['raw']); ?> → <strong><?php echo htmlspecialchars((string) $ref['pasal']); ?></strong><?php echo $ref['ayat'] !== null ? ' / ayat ' . htmlspecialchars((string) $ref['ayat']) : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php foreach ($chapters as $chapter): ?>
            <section class="hukum-chapter">
                <h2><?php echo htmlspecialchars($chapter['nomor_label'] . ' — ' . $chapter['judul_bab']); ?></h2>
                <?php foreach ($pasalRows as $pasal): ?>
                    <?php if ((int) $pasal['bab_id'] !== (int) $chapter['id']) continue; ?>
                    <?php $snapshotItem = $snapshotMap[(int) $pasal['id']] ?? null; ?>
                    <?php if (!$snapshotItem) continue; ?>
                    <article class="hukum-pasal">
                        <h3><?php echo htmlspecialchars($pasal['nomor_label'] . (!empty($pasal['judul_pasal']) ? ' — ' . $pasal['judul_pasal'] : '')); ?></h3>
                        <div><?php echo hukum_public_content($snapshotItem['isi'] ?? []); ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>

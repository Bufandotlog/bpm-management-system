<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hukum-public.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$document = hukum_public_document_by_slug($slug);
if (!$document) {
    http_response_code(404);
    $page_title = 'Riwayat Dokumen Tidak Ditemukan';
    include __DIR__ . '/header.php';
    echo '<div class="container hukum-public-empty"><h1>Riwayat dokumen tidak ditemukan</h1><p>Dokumen publik yang diminta tidak tersedia atau sudah tidak aktif.</p><a class="btn btn-small" href="' . htmlspecialchars(baseUrl('hukum.php')) . '">Kembali ke Produk Hukum</a></div>';
    include __DIR__ . '/footer.php';
    exit;
}

$history = hukum_public_history_by_document((int) $document['id']);
$compareFrom = isset($_GET['from']) ? (int) $_GET['from'] : 0;
$compareTo = isset($_GET['to']) ? (int) $_GET['to'] : 0;
$selectedFrom = null;
$selectedTo = null;
if ($compareFrom > 0) {
    foreach ($history as $commit) {
        if ((int) $commit['id'] === $compareFrom) {
            $selectedFrom = $commit;
            break;
        }
    }
}
if ($compareTo > 0) {
    foreach ($history as $commit) {
        if ((int) $commit['id'] === $compareTo) {
            $selectedTo = $commit;
            break;
        }
    }
}
if ($selectedTo === null && !empty($history)) {
    $selectedTo = $history[0];
}
if ($selectedFrom === null && count($history) > 1) {
    $selectedFrom = $history[1];
}

$diffItems = [];
if ($selectedFrom && $selectedTo) {
    $diffItems = hukum_public_diff_snapshot($selectedFrom, $selectedTo);
}

$page_title = 'Riwayat ' . $document['judul'];
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
        <h1>Riwayat publik: <?php echo htmlspecialchars($document['judul']); ?></h1>
        <p class="hukum-public-muted"><?php echo htmlspecialchars($document['slug']); ?></p>
    </header>

    <section class="hukum-chapter">
        <h2>Bandingkan snapshot</h2>
        <form method="get" class="hukum-public-filters">
            <input type="hidden" name="slug" value="<?php echo htmlspecialchars($document['slug']); ?>">
            <label>
                <span>Versi awal</span>
                <select name="from">
                    <option value="">Pilih versi</option>
                    <?php foreach ($history as $item): ?>
                        <option value="<?php echo (int) $item['id']; ?>" <?php echo $selectedFrom && (int) $item['id'] === (int) $selectedFrom['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $item['tanggal_forum'] ?: (string) $item['created_at']); ?> · <?php echo htmlspecialchars(substr((string) $item['hash_commit'], 0, 16)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Versi akhir</span>
                <select name="to">
                    <option value="">Pilih versi</option>
                    <?php foreach ($history as $item): ?>
                        <option value="<?php echo (int) $item['id']; ?>" <?php echo $selectedTo && (int) $item['id'] === (int) $selectedTo['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $item['tanggal_forum'] ?: (string) $item['created_at']); ?> · <?php echo htmlspecialchars(substr((string) $item['hash_commit'], 0, 16)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-small" type="submit">Bandingkan</button>
        </form>
    </section>

    <?php if ($diffItems): ?>
        <section class="hukum-chapter">
            <h2>Perubahan struktur</h2>
            <div class="hukum-diff-table">
                <?php foreach ($diffItems as $item): ?>
                    <div class="hukum-diff-row">
                        <span class="hukum-status-tag status-<?php echo htmlspecialchars((string) $item['status']); ?>"><?php echo htmlspecialchars(hukum_public_diff_status_label((string) $item['status'])); ?></span>
                        <strong>Pasal <?php echo htmlspecialchars((string) ((int) ($item['pasal_id'] ?? 0))); ?></strong>
                        <span>
                            <?php
                            if ($item['status'] === 'added') { echo 'Pasal baru ditambahkan ke snapshot publik.'; }
                            elseif ($item['status'] === 'removed') { echo 'Pasal dihapus dari snapshot publik.'; }
                            elseif ($item['status'] === 'modified') { echo 'Pasal berubah dari versi sebelumnya.'; }
                            else { echo 'Tidak ada perubahan pada struktur pasal.'; }
                            ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="hukum-chapter">
        <h2>Daftar komit publik</h2>
        <?php if (!$history): ?>
            <div class="hukum-public-empty"><p>Belum ada riwayat komit publik.</p></div>
        <?php else: ?>
            <ul>
                <?php foreach ($history as $commit): ?>
                    <?php $dateLabel = (string) ($commit['tanggal_forum'] ?: $commit['created_at']); ?>
                    <li>
                        <strong><?php echo htmlspecialchars($dateLabel); ?></strong>
                        <span class="hukum-public-muted"> · <?php echo htmlspecialchars($commit['forum_tipe']); ?></span>
                        <span class="hukum-public-muted"> · <?php echo htmlspecialchars(substr((string) $commit['hash_commit'], 0, 16)); ?>…</span>
                        <a href="<?php echo baseUrl('hukum-history.php?slug=' . urlencode($document['slug']) . '&from=' . (int) $commit['id'] . '&to=' . (int) ($history[0]['id'] ?? 0)); ?>">Bandingkan</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/footer.php'; ?>

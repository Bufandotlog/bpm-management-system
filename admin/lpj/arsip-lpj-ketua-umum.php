<?php
$page_css = 'lpj-ketua-umum';
require_once __DIR__ . '/../core/header.php';
requireKetuaUmum();

$periode_id = (int)getUserPeriode();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $id = (int)$_POST['delete_id'];
        $lpj = dbFetchOne('SELECT id, file_path FROM lpj_ketua_umum WHERE id = ? AND periode_id = ?', [$id, $periode_id], 'ii');
        if (!$lpj) {
            $error = 'LPJ Ketua Umum tidak ditemukan pada periode aktif.';
        } else {
            dbQuery('DELETE FROM lpj_ketua_umum WHERE id = ? AND periode_id = ?', [$id, $periode_id], 'ii');
            if (!empty($lpj['file_path'])) deleteFile($lpj['file_path']);
            $success = 'LPJ Ketua Umum berhasil dihapus.';
        }
    }
}

$items = dbFetchAll(
    "SELECT id, triwulan, status, file_path, created_at, updated_at
     FROM lpj_ketua_umum WHERE periode_id = ?
     ORDER BY FIELD(triwulan, 'I', 'II', 'MUBESMA'), updated_at DESC",
    [$periode_id], 'i'
);
?>
<?php
$statusCounts = ['submitted' => 0, 'draft' => 0];
foreach ($items as $item) {
    $status = strtolower((string)($item['status'] ?? ''));
    if (isset($statusCounts[$status])) $statusCounts[$status]++;
}
?>
<div class="ketua-lpj">
    <header class="lpj-page-header">
        <div class="lpj-page-heading"><span><i class="fas fa-file-signature"></i> LPJ Kepemimpinan</span><h1>Arsip LPJ Ketua Umum</h1><p>Kelola laporan untuk Triwulan I, Triwulan II, dan MUBESMA.</p></div>
        <div class="lpj-create-actions">
            <?php foreach (['I' => 'Triwulan I', 'II' => 'Triwulan II', 'MUBESMA' => 'MUBESMA'] as $type => $label): ?>
            <a href="buat-lpj-ketua-umum.php?triwulan=<?php echo urlencode($type); ?>"><i class="fas fa-plus"></i><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>
    </header>
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="lpj-archive-summary" aria-label="Ringkasan LPJ">
        <div class="lpj-summary-card"><i class="fas fa-file-alt"></i><div><span>Total LPJ</span><strong><?php echo count($items); ?></strong></div></div>
        <div class="lpj-summary-card"><i class="fas fa-check"></i><div><span>Diserahkan</span><strong><?php echo $statusCounts['submitted']; ?></strong></div></div>
        <div class="lpj-summary-card"><i class="fas fa-pen"></i><div><span>Draft</span><strong><?php echo $statusCounts['draft']; ?></strong></div></div>
    </section>

    <section class="lpj-archive-card">
        <?php if (!$items): ?>
        <div class="lpj-empty"><i class="far fa-folder-open"></i><h2>Belum ada LPJ Ketua Umum</h2><p>Pilih periode dan buat laporan pertama.</p></div>
        <?php else: ?>
        <div class="lpj-table-wrap archive-table-wrap"><table class="lpj-table responsive-card-table"><thead><tr><th>No.</th><th>Jenis LPJ</th><th>Periode</th><th>Status</th><th>Terakhir diperbarui</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($items as $index => $item):
            $status = strtolower((string)$item['status']);
            $time = strtotime((string)$item['updated_at']);
        ?>
        <tr>
            <td data-label="No."><?php echo $index + 1; ?></td>
            <td data-label="Jenis LPJ">LPJ Ketua Umum</td>
            <td data-label="Periode"><span class="lpj-quarter"><?php echo htmlspecialchars((string)$item['triwulan']); ?></span></td>
            <td data-label="Status"><span class="lpj-status lpj-status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
            <td data-label="Terakhir diperbarui"><?php echo $time ? date('d M Y, H:i', $time) : '—'; ?></td>
            <td data-label="Aksi"><div class="lpj-actions">
                <a href="buat-lpj-ketua-umum.php?triwulan=<?php echo urlencode((string)$item['triwulan']); ?>"><i class="fas fa-pen"></i> Edit</a>
                <?php if (!empty($item['file_path'])): ?><a href="<?php echo htmlspecialchars(uploadUrl($item['file_path'])); ?>" download><i class="fas fa-download"></i> Unduh DOCX</a><?php endif; ?>
                <form method="POST" onsubmit="return confirm('Hapus LPJ Ketua Umum ini?')"><?php echo csrfField(); ?><input type="hidden" name="delete_id" value="<?php echo (int)$item['id']; ?>"><button type="submit"><i class="fas fa-trash-alt"></i> Hapus</button></form>
            </div></td>
        </tr>
        <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

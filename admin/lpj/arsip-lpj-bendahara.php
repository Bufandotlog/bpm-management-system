<?php
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';
requireBendahara();

$periode_id = (int)getUserPeriode();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $id = (int)$_POST['delete_id'];
        $lpj = dbFetchOne('SELECT id, file_path FROM lpj_bendahara WHERE id = ? AND periode_id = ?', [$id, $periode_id], 'ii');
        if (!$lpj) {
            $error = 'LPJ Bendahara tidak ditemukan pada periode aktif.';
        } else {
            dbQuery('DELETE FROM lpj_bendahara WHERE id = ? AND periode_id = ?', [$id, $periode_id], 'ii');
            if (!empty($lpj['file_path'])) {
                deleteFile($lpj['file_path']);
            }
            $success = 'LPJ Bendahara berhasil dihapus.';
        }
    }
}
$items = dbFetchAll(
    "SELECT id, triwulan, status, file_path, created_at, updated_at
     FROM lpj_bendahara WHERE periode_id = ?
     ORDER BY FIELD(triwulan, 'I', 'II', 'MUBESMA'), updated_at DESC, id DESC",
    [$periode_id],
    'i'
);
$statusCounts = ['submitted' => 0, 'draft' => 0];
foreach ($items as $item) {
    $status = strtolower((string)($item['status'] ?? ''));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><h1><i class="fas fa-coins"></i> Arsip LPJ Bendahara</h1><p>Kelola laporan keuangan Bendahara pada periode aktif.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach (['I' => 'Triwulan I', 'II' => 'Triwulan II', 'MUBESMA' => 'MUBESMA'] as $type => $label): ?>
            <a class="btn-primary" href="buat-lpj-bendahara.php?triwulan=<?php echo urlencode($type); ?>"><i class="fas fa-plus"></i> <?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php if ($error !== ''): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<section class="admin-card" aria-label="Ringkasan arsip LPJ Bendahara">
    <p>Total: <strong><?php echo count($items); ?></strong> &middot; Diserahkan: <strong><?php echo $statusCounts['submitted']; ?></strong> &middot; Draft: <strong><?php echo $statusCounts['draft']; ?></strong></p>
    <?php if (!$items): ?>
        <p>Belum ada LPJ Bendahara pada periode aktif.</p>
    <?php else: ?>
        <div class="table-responsive"><table class="admin-table responsive-card-table">
            <thead><tr><th>No.</th><th>Periode</th><th>Status</th><th>Terakhir diperbarui</th><th>Aksi</th></tr></thead>
            <tbody><?php foreach ($items as $index => $item):
                $updated = strtotime((string)$item['updated_at']);
                $status = strtolower((string)$item['status']);
            ?>
                <tr>
                    <td data-label="No."><?php echo $index + 1; ?></td>
                    <td data-label="Periode"><?php echo htmlspecialchars((string)$item['triwulan']); ?></td>
                    <td data-label="Status"><span class="status-badge status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                    <td data-label="Terakhir diperbarui"><?php echo $updated ? date('d M Y, H:i', $updated) : '—'; ?></td>
                    <td data-label="Aksi"><div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a class="btn-secondary" href="buat-lpj-bendahara.php?id=<?php echo (int)$item['id']; ?>">Edit</a>
                        <?php if (!empty($item['file_path'])): ?><a class="btn-primary" href="<?php echo htmlspecialchars(uploadUrl($item['file_path'])); ?>" download>Unduh DOCX</a><?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Hapus LPJ Bendahara ini?')"><?php echo csrfField(); ?><input type="hidden" name="delete_id" value="<?php echo (int)$item['id']; ?>"><button class="btn-danger" type="submit">Hapus</button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

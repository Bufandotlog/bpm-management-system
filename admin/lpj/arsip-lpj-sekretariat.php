<?php
$page_css = 'lpj-sekretariat-arsip';
require_once __DIR__ . '/../core/header.php';
requireSekretaris();

$periode_id = (int)getUserPeriode();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $id = (int)$_POST['delete_id'];
        $lpj = dbFetchOne("SELECT id, file_path FROM lpj_sekretariat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
        if (!$lpj) {
            $error = 'LPJ tidak ditemukan pada periode aktif.';
        } else {
            dbQuery("DELETE FROM lpj_sekretariat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
            if (!empty($lpj['file_path'])) {
                deleteFile($lpj['file_path']);
            }
            $success = 'LPJ Sekretariat berhasil dihapus.';
        }
    }
}
$items = dbFetchAll(
    "SELECT id, triwulan, status, file_path, created_at, updated_at
     FROM lpj_sekretariat
     WHERE periode_id = ?
     ORDER BY FIELD(triwulan, 'I', 'II', 'MUBESMA'), updated_at DESC",
    [$periode_id],
    "i"
);
$statusCounts = ['submitted' => 0, 'draft' => 0];
foreach ($items as $item) {
    $status = strtolower((string)($item['status'] ?? ''));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}
?>
<div class="secretariat-archive">
    <header class="secretariat-archive-header">
        <div class="secretariat-archive-heading">
            <span class="secretariat-archive-eyebrow"><i class="fas fa-folder-open"></i> Dokumen periode aktif</span>
            <h1>Arsip LPJ Sekretariat</h1>
            <p>Kelola, perbarui, dan unduh laporan pertanggungjawaban sekretariat.</p>
        </div>
        <div class="secretariat-archive-create" aria-label="Buat LPJ baru">
            <?php foreach (['I' => 'Triwulan I', 'II' => 'Triwulan II', 'MUBESMA' => 'MUBESMA'] as $type => $label): ?>
                <a href="buat-lpj-sekretariat.php?triwulan=<?php echo urlencode($type); ?>">
                    <i class="fas fa-plus" aria-hidden="true"></i><span><?php echo htmlspecialchars($label); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </header>
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="secretariat-archive-summary" aria-label="Ringkasan arsip">
        <div class="archive-summary-card">
            <span class="archive-summary-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></span>
            <span class="archive-summary-label">Total LPJ</span>
            <strong><?php echo count($items); ?></strong>
        </div>
        <div class="archive-summary-card">
            <span class="archive-summary-icon is-submitted"><i class="fas fa-check" aria-hidden="true"></i></span>
            <span class="archive-summary-label">Diserahkan</span>
            <strong><?php echo $statusCounts['submitted']; ?></strong>
        </div>
        <div class="archive-summary-card">
            <span class="archive-summary-icon is-draft"><i class="fas fa-pen" aria-hidden="true"></i></span>
            <span class="archive-summary-label">Draft</span>
            <strong><?php echo $statusCounts['draft']; ?></strong>
        </div>
    </section>

    <section class="secretariat-archive-list" aria-labelledby="archive-list-title">
        <div class="archive-list-heading">
            <div>
                <h2 id="archive-list-title">Daftar laporan</h2>
                <p><?php echo count($items); ?> dokumen pada periode aktif</p>
            </div>
        </div>
        <?php if (!$items): ?>
            <div class="archive-empty-state">
                <span><i class="far fa-folder-open" aria-hidden="true"></i></span>
                <h3>Belum ada LPJ Sekretariat</h3>
                <p>Buat laporan Triwulan I, Triwulan II, atau MUBESMA untuk memulai pengarsipan.</p>
            </div>
        <?php else: ?>
            <div class="archive-table-wrap">
                <table class="admin-table responsive-card-table secretariat-archive-table">
                    <thead>
                        <tr><th>No.</th><th>Jenis LPJ</th><th>Triwulan</th><th>Status</th><th>Terakhir diperbarui</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $index => $item):
                        $status = strtolower((string)($item['status'] ?? ''));
                        $statusClass = $status === 'submitted' ? 'is-submitted' : ($status === 'draft' ? 'is-draft' : 'is-other');
                        $statusLabel = $status !== '' ? ucfirst($status) : 'Tidak diketahui';
                        $updatedAt = (string)($item['updated_at'] ?? '');
                        $updatedTimestamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
                        $updatedLabel = $updatedTimestamp !== false
                            ? date('d M Y, H:i', $updatedTimestamp)
                            : ($updatedAt !== '' ? $updatedAt : '—');
                    ?>
                        <tr>
                            <td data-label="No."><span class="archive-row-number"><?php echo $index + 1; ?></span></td>
                            <td data-label="Jenis LPJ"><span class="archive-report-name"><i class="fas fa-file-word" aria-hidden="true"></i>LPJ Sekretariat</span></td>
                            <td data-label="Triwulan"><span class="archive-quarter"><?php echo htmlspecialchars((string)$item['triwulan']); ?></span></td>
                            <td data-label="Status"><span class="archive-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                            <td data-label="Terakhir diperbarui"><span class="archive-updated"><?php echo htmlspecialchars($updatedLabel); ?></span></td>
                            <td data-label="Aksi" class="archive-actions-cell">
                                <div class="archive-actions">
                                    <a class="archive-action archive-action-edit" href="buat-lpj-sekretariat.php?triwulan=<?php echo urlencode((string)$item['triwulan']); ?>">
                                        <i class="fas fa-pen" aria-hidden="true"></i><span>Edit</span>
                                    </a>
                                    <?php if (!empty($item['file_path'])): ?>
                                        <a class="archive-action archive-action-download" href="<?php echo htmlspecialchars(uploadUrl($item['file_path'])); ?>" download>
                                            <i class="fas fa-download" aria-hidden="true"></i><span>Unduh DOCX</span>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('Hapus LPJ Sekretariat ini?')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$item['id']; ?>">
                                        <button class="archive-action archive-action-delete" type="submit">
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i><span>Hapus</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

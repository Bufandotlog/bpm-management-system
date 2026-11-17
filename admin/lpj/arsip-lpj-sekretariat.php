<?php
$page_css = 'arsip-surat';
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
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <h1><i class="fas fa-archive"></i> Arsip LPJ Sekretariat</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach (['I' => 'Triwulan I', 'II' => 'Triwulan II', 'MUBESMA' => 'MUBESMA'] as $type => $label): ?>
            <a class="btn-primary" href="buat-lpj-sekretariat.php?triwulan=<?php echo urlencode($type); ?>"><i class="fas fa-plus"></i> <?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<div style="overflow-x:auto;">
<table class="admin-table responsive-card-table">
    <thead><tr><th>No.</th><th>Jenis LPJ</th><th>Triwulan</th><th>Status</th><th>Terakhir diperbarui</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$items): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;">Belum ada LPJ Sekretariat untuk periode ini.</td></tr>
    <?php else: foreach ($items as $index => $item): ?>
        <tr>
            <td><?php echo $index + 1; ?></td>
            <td>LPJ Sekretaris Umum</td>
            <td><?php echo htmlspecialchars($item['triwulan']); ?></td>
            <td><?php echo htmlspecialchars(ucfirst($item['status'])); ?></td>
            <td><?php echo htmlspecialchars($item['updated_at'] ?? '—'); ?></td>
            <td style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn-edit" href="buat-lpj-sekretariat.php?triwulan=<?php echo urlencode($item['triwulan']); ?>">Edit</a>
                <?php if (!empty($item['file_path'])): ?>
                    <a class="btn-buat" href="<?php echo htmlspecialchars(uploadUrl($item['file_path'])); ?>" download>Unduh DOCX</a>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Hapus LPJ Sekretariat ini?')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="delete_id" value="<?php echo (int)$item['id']; ?>">
                    <button class="btn-delete" type="submit">Hapus</button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

<?php
declare(strict_types=1);

$page_css = 'galeri';
require_once __DIR__ . '/../core/header.php';

$allowedRoles = ['superadmin', 'admin', 'kominfo'];
$adminRole = strtolower((string) ($_SESSION['admin_role'] ?? ''));
if (!in_array($adminRole, $allowedRoles, true)) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Anda tidak memiliki akses ke halaman ini.</p></body></html>';
    exit();
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$isSuperadmin = $adminRole === 'superadmin';
$userScope = dbFetchOne(
    'SELECT periode_id, can_access_all FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
    [$adminId],
    'i'
);

if (!$userScope) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Akun tidak aktif atau tidak ditemukan.</p></body></html>';
    exit();
}

$periods = $isSuperadmin
    ? dbFetchAll('SELECT id, nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan ORDER BY tahun_mulai DESC, id DESC')
    : [];

$requestedPeriod = isset($_GET['period_id']) ? (int) $_GET['period_id'] : 0;
$scopedPeriodId = $isSuperadmin
    ? ($requestedPeriod > 0 ? $requestedPeriod : (int) ($periods[0]['id'] ?? 0))
    : (int) ($userScope['periode_id'] ?? 0);

$selectedPeriod = $scopedPeriodId > 0
    ? dbFetchOne(
        'SELECT id, nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan WHERE id = ? LIMIT 1',
        [$scopedPeriodId],
        'i'
    )
    : null;

if (!$selectedPeriod || (!$isSuperadmin && $scopedPeriodId !== (int) ($userScope['periode_id'] ?? 0))) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Periode tidak tersedia untuk akun ini.</p></body></html>';
    exit();
}

$redirectUrl = 'admin/konten/galeri.php?period_id=' . $scopedPeriodId;
$resolvePeriod = static function (int $submittedPeriodId) use ($isSuperadmin, $scopedPeriodId): int {
    return $isSuperadmin && $submittedPeriodId > 0 ? $submittedPeriodId : $scopedPeriodId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect($redirectUrl, 'Request tidak valid.', 'error');
    }

    $action = (string) ($_POST['action'] ?? '');
    $submittedPeriodId = (int) ($_POST['period_id'] ?? 0);
    $operationPeriodId = $resolvePeriod($submittedPeriodId);
    $periodExists = dbFetchOne(
        'SELECT id FROM periode_kepengurusan WHERE id = ? LIMIT 1',
        [$operationPeriodId],
        'i'
    );

    if (!$periodExists || (!$isSuperadmin && $operationPeriodId !== $scopedPeriodId)) {
        http_response_code(403);
        exit('403 Forbidden');
    }

    if ($action === 'create' || $action === 'update') {
        $title = sanitizeText((string) ($_POST['title'] ?? ''), 255);
        $subtitle = sanitizeText((string) ($_POST['subtitle'] ?? ''), 500);
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $existing = $cardId > 0
            ? dbFetchOne(
                'SELECT id, image_path FROM gallery_cards WHERE id = ? AND periode_id = ? LIMIT 1',
                [$cardId, $operationPeriodId],
                'ii'
            )
            : null;

        if ($action === 'update' && !$existing) {
            http_response_code(403);
            exit('403 Forbidden');
        }
        if ($title === '') {
            redirect($redirectUrl, 'Judul card wajib diisi.', 'error');
        }

        $newImage = null;
        if (isset($_FILES['image']) && (int) $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $newImage = uploadFile($_FILES['image'], 'gallery');
            if (!$newImage) {
                redirect($redirectUrl, 'Foto gagal diunggah.', 'error');
            }
        }

        if ($action === 'create') {
            if (!$newImage) {
                redirect($redirectUrl, 'Foto card wajib diunggah.', 'error');
            }
            $lastOrder = dbFetchOne(
                'SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM gallery_cards WHERE periode_id = ?',
                [$operationPeriodId],
                'i'
            );
            dbQuery(
                'INSERT INTO gallery_cards (periode_id, sort_order, image_path, title, subtitle, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$operationPeriodId, (int) ($lastOrder['max_order'] ?? -1) + 1, $newImage, $title, $subtitle, $adminId, $adminId],
                'iisssii'
            );
            auditLog('CREATE', 'gallery_cards', dbLastId(), 'Tambah card galeri');
            redirect($redirectUrl, 'Card berhasil ditambahkan.', 'success');
        }

        $imagePath = $existing['image_path'];
        if ($newImage) {
            if (!empty($existing['image_path']) && !deleteFile($existing['image_path'])) {
                deleteFile($newImage);
                redirect($redirectUrl, 'Foto lama gagal dihapus sehingga perubahan dibatalkan.', 'error');
            }
            $imagePath = $newImage;
        }
        dbQuery(
            'UPDATE gallery_cards
             SET image_path = ?, title = ?, subtitle = ?, updated_by = ?
             WHERE id = ? AND periode_id = ?',
            [$imagePath, $title, $subtitle, $adminId, $cardId, $operationPeriodId],
            'sssiii'
        );
        auditLog('UPDATE', 'gallery_cards', $cardId, 'Ubah card galeri');
        redirect($redirectUrl, 'Card berhasil diperbarui.', 'success');
    }

    if ($action === 'delete') {
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $card = dbFetchOne(
            'SELECT id, image_path FROM gallery_cards WHERE id = ? AND periode_id = ? LIMIT 1',
            [$cardId, $operationPeriodId],
            'ii'
        );
        if (!$card) {
            http_response_code(403);
            exit('403 Forbidden');
        }
        if (!empty($card['image_path']) && !deleteFile($card['image_path'])) {
            redirect($redirectUrl, 'Foto card gagal dihapus sehingga card tidak dihapus.', 'error');
        }
        dbQuery('DELETE FROM gallery_cards WHERE id = ? AND periode_id = ?', [$cardId, $operationPeriodId], 'ii');
        auditLog('DELETE', 'gallery_cards', $cardId, 'Hapus card galeri');
        redirect($redirectUrl, 'Card berhasil dihapus permanen.', 'success');
    }

    if ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) {
            redirect($redirectUrl, 'Urutan card tidak valid.', 'error');
        }
        $submittedIds = array_map('intval', array_values($order));
        $scopedIds = array_map(
            'intval',
            array_column(
                dbFetchAll('SELECT id FROM gallery_cards WHERE periode_id = ?', [$operationPeriodId], 'i'),
                'id'
            )
        );
        sort($submittedIds);
        sort($scopedIds);
        if ($submittedIds !== $scopedIds) {
            http_response_code(403);
            exit('403 Forbidden');
        }
        dbBeginTransaction();
        try {
            foreach (array_values($order) as $position => $rawCardId) {
                $cardId = (int) $rawCardId;
                if ($cardId <= 0) {
                    throw new RuntimeException('ID card tidak valid.');
                }
                dbQuery(
                    'UPDATE gallery_cards SET sort_order = ?, updated_by = ? WHERE id = ? AND periode_id = ?',
                    [$position, $adminId, $cardId, $operationPeriodId],
                    'iiii'
                );
            }
            dbCommit();
        } catch (Throwable $error) {
            dbRollback();
            error_log('Gallery reorder failed: ' . $error->getMessage());
            redirect($redirectUrl, 'Urutan card gagal disimpan.', 'error');
        }
        auditLog('UPDATE', 'gallery_cards', null, 'Atur urutan card galeri');
        redirect($redirectUrl, 'Urutan card berhasil disimpan.', 'success');
    }

    redirect($redirectUrl, 'Aksi tidak dikenali.', 'error');
}

$cards = dbFetchAll(
    'SELECT id, periode_id, sort_order, image_path, title, subtitle
     FROM gallery_cards
     WHERE periode_id = ?
     ORDER BY sort_order ASC, id ASC',
    [$scopedPeriodId],
    'i'
);

$page_title = 'Galeri';
?>
<div class="page-header gallery-page-header">
    <div>
        <span class="gallery-kicker"><i class="fas fa-images"></i> Informasi BPM</span>
        <h1>Galeri</h1>
        <p>Kelola foto, judul, subjudul, dan urutan card galeri.</p>
    </div>
    <div class="gallery-period-badge">
        <span>Periode aktif</span>
        <strong><?php echo htmlspecialchars($selectedPeriod['nama'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <small><?php echo htmlspecialchars($selectedPeriod['tahun_mulai'] . '/' . $selectedPeriod['tahun_selesai'], ENT_QUOTES, 'UTF-8'); ?></small>
    </div>
</div>

<?php flashMessage(); ?>

<?php if ($isSuperadmin): ?>
<form method="get" class="gallery-period-form">
    <label for="period_id"><i class="fas fa-calendar-alt"></i> Pilih periode kerja</label>
    <select id="period_id" name="period_id" onchange="this.form.submit()">
        <?php foreach ($periods as $period): ?>
            <option value="<?php echo (int) $period['id']; ?>" <?php echo (int) $period['id'] === $scopedPeriodId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($period['nama'] . ' (' . $period['tahun_mulai'] . '/' . $period['tahun_selesai'] . ')', ENT_QUOTES, 'UTF-8'); ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="gallery-create-card">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="period_id" value="<?php echo $scopedPeriodId; ?>">
    <div class="gallery-section-heading">
        <div class="gallery-section-icon"><i class="fas fa-plus"></i></div>
        <div><h2>Tambah card baru</h2><p>Gunakan foto yang tajam agar tampil optimal di galeri.</p></div>
    </div>
    <div class="gallery-form-grid">
        <label>Judul<input type="text" name="title" maxlength="255" placeholder="Contoh: Golden" required></label>
        <label>Subjudul<input type="text" name="subtitle" maxlength="500" placeholder="Contoh: Card 01"></label>
        <label class="gallery-file-field">Foto card<input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required></label>
    </div>
    <button class="gallery-button gallery-button-primary" type="submit"><i class="fas fa-plus"></i> Tambah card</button>
</form>

<form method="post" id="gallery-reorder-form" class="gallery-reorder-form">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="reorder">
    <input type="hidden" name="period_id" value="<?php echo $scopedPeriodId; ?>">
    <button class="gallery-button gallery-button-secondary" type="submit"><i class="fas fa-sort"></i> Simpan urutan</button>
</form>

<div id="gallery-card-list">
    <?php foreach ($cards as $card): ?>
        <article class="gallery-card-item" data-card-id="<?php echo (int) $card['id']; ?>">
            <div class="gallery-card-preview">
                <img src="<?php echo htmlspecialchars(uploadUrl($card['image_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?>">
                <span class="gallery-card-order">#<?php echo (int) $card['sort_order'] + 1; ?></span>
            </div>
            <div class="gallery-card-body">
                <div class="gallery-card-title">
                    <div><strong><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars($card['subtitle'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="gallery-move-actions"><button type="button" data-move="up" aria-label="Naikkan card">↑</button><button type="button" data-move="down" aria-label="Turunkan card">↓</button></div>
                </div>
                <form method="post" enctype="multipart/form-data" class="gallery-edit-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="card_id" value="<?php echo (int) $card['id']; ?>">
                <input type="hidden" name="period_id" value="<?php echo $scopedPeriodId; ?>">
                <label>Judul<input type="text" name="title" value="<?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" required></label>
                <label>Subjudul<input type="text" name="subtitle" value="<?php echo htmlspecialchars($card['subtitle'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="500"></label>
                <label>Ganti foto<input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></label>
                <button class="gallery-button gallery-button-primary" type="submit"><i class="fas fa-save"></i> Simpan perubahan</button>
            </form>
            <form method="post" class="gallery-delete-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="card_id" value="<?php echo (int) $card['id']; ?>">
                <input type="hidden" name="period_id" value="<?php echo $scopedPeriodId; ?>">
                <button class="gallery-button gallery-button-danger" type="submit" onclick="return confirm('Hapus card dan file secara permanen?')"><i class="fas fa-trash"></i> Hapus permanen</button>
            </form>
        </div>
        </article>
    <?php endforeach; ?>
</div>

<script>
document.getElementById('gallery-reorder-form')?.addEventListener('submit', function () {
    this.querySelectorAll('input[name="order[]"]').forEach((input) => input.remove());
    document.querySelectorAll('#gallery-card-list [data-card-id]').forEach((card) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'order[]';
        input.value = card.dataset.cardId;
        this.appendChild(input);
    });
});
document.querySelectorAll('[data-move]').forEach((button) => {
    button.addEventListener('click', () => {
        const card = button.closest('[data-card-id]');
        const sibling = button.dataset.move === 'up'
            ? card.previousElementSibling
            : card.nextElementSibling;
        if (sibling) {
            if (button.dataset.move === 'up') {
                card.parentNode.insertBefore(card, sibling);
            } else {
                card.parentNode.insertBefore(card, sibling.nextElementSibling);
            }
        }
    });
});
</script>

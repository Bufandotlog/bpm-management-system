<?php
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';
requireSekretaris();

$periode_id = (int)getUserPeriode();
$error = '';
$success = '';
$lpj_id = (int)($_GET['id'] ?? 0);
$sanitizeLpjText = static function (string $value, int $maxLength): string {
    $value = strip_tags($value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;
    return mb_substr(trim($value), 0, $maxLength);
};
$lpj = $lpj_id > 0
    ? dbFetchOne("SELECT * FROM lpj_sekretariat WHERE id = ? AND periode_id = ?", [$lpj_id, $periode_id], "ii")
    : dbFetchOne("SELECT * FROM lpj_sekretariat WHERE periode_id = ?", [$periode_id], "i");
if ($lpj && $lpj_id === 0) {
    $lpj_id = (int)$lpj['id'];
}
$formKeadaanObjektif = $lpj['keadaan_objektif'] ?? '';
$formPenutup = $lpj['penutup'] ?? '';

if ($lpj_id > 0 && !$lpj) {
    http_response_code(404);
    $error = 'LPJ Sekretariat tidak ditemukan pada periode aktif.';
}

$categories = [
    'L' => ['title' => 'Surat Keluar (Eksternal)', 'date' => 'Tanggal', 'party' => 'Dituju Kepada'],
    'D' => ['title' => 'Surat Keluar (Internal)', 'date' => 'Tanggal', 'party' => 'Dituju Kepada'],
    'M' => ['title' => 'Surat Masuk (Eksternal)', 'date' => 'Tanggal Diterima', 'party' => 'Asal Instansi'],
    'I' => ['title' => 'Surat Masuk (Internal)', 'date' => 'Tanggal Diterima', 'party' => 'Asal Instansi'],
];
$lettersByType = array_fill_keys(array_keys($categories), []);
$letters = dbFetchAll(
    "SELECT id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan
     FROM arsip_surat
     WHERE periode_id = ? AND jenis_surat IN ('L', 'D', 'M', 'I')
       AND (status_arsip = 'archived' OR status_arsip IS NULL)
     ORDER BY jenis_surat, tanggal_dikirim ASC, id ASC",
    [$periode_id],
    "i"
);
foreach ($letters as $letter) {
    $lettersByType[$letter['jenis_surat']][] = $letter;
}

$membership = json_decode($lpj['keanggotaan'] ?? '', true);
if (!is_array($membership)) {
    $membership = dbFetchAll(
        "SELECT DISTINCT ab.nama, ab.jabatan
         FROM anggota_bph ab
         LEFT JOIN struktur_bph sb ON sb.id = ab.bph_id
         WHERE ab.periode_id = ?
           AND (LOWER(ab.jabatan) LIKE '%sekretaris%'
             OR LOWER(COALESCE(sb.jabatan, '')) LIKE '%sekretaris%'
             OR LOWER(COALESCE(sb.posisi, '')) LIKE '%sekretariat%')
         ORDER BY ab.urutan ASC, ab.id ASC",
        [$periode_id],
        "i"
    );
    $membership = array_map(static fn($member) => [
        'nama' => $member['nama'],
        'jabatan' => $member['jabatan'],
    ], $membership);
}
if (!$membership) {
    $membership = [['nama' => '', 'jabatan' => '']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($lpj_id === 0 || $lpj !== null)) {
    $formKeadaanObjektif = $sanitizeLpjText((string)($_POST['keadaan_objektif'] ?? ''), 10000);
    $formPenutup = $sanitizeLpjText((string)($_POST['penutup'] ?? ''), 10000);
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $keadaan_objektif = $formKeadaanObjektif;
        $penutup = $formPenutup;
        $status = ($_POST['status'] ?? 'draft') === 'submitted' ? 'submitted' : 'draft';
        $membership = [];
        $memberNames = (array)($_POST['anggota_nama'] ?? []);
        $memberRoles = (array)($_POST['anggota_jabatan'] ?? []);
        foreach ($memberNames as $index => $name) {
            $name = sanitizeText((string)$name, 150);
            $role = sanitizeText((string)($memberRoles[$index] ?? ''), 150);
            if ($name !== '' || $role !== '') {
                if ($name === '' || $role === '') {
                    $membership[] = ['nama' => $name, 'jabatan' => $role];
                    $error = 'Nama dan jabatan setiap anggota harus diisi.';
                    break;
                }
                $membership[] = ['nama' => $name, 'jabatan' => $role];
            }
        }

        if ($error === '' && $status === 'submitted' && ($keadaan_objektif === '' || !$membership)) {
            $error = 'LPJ yang disubmit harus memiliki keadaan objektif dan minimal satu anggota sekretariat.';
        }

        if ($error === '') {
            $membershipJson = json_encode($membership, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $contentSaved = false;
            $oldFilePath = $lpj['file_path'] ?? '';
            $outputPath = null;
            try {
                if ($lpj) {
                    dbQuery(
                        "UPDATE lpj_sekretariat
                         SET status = ?, keadaan_objektif = ?, keanggotaan = ?, penutup = ?, file_path = NULL
                         WHERE id = ? AND periode_id = ?",
                        [$status, $keadaan_objektif, $membershipJson, $penutup, $lpj_id, $periode_id],
                        "ssssii"
                    );
                } else {
                    dbQuery(
                        "INSERT INTO lpj_sekretariat (periode_id, status, keadaan_objektif, keanggotaan, penutup)
                         VALUES (?, ?, ?, ?, ?)",
                        [$periode_id, $status, $keadaan_objektif, $membershipJson, $penutup],
                        "issss"
                    );
                    $lpj_id = dbLastId();
                }
                $contentSaved = true;

                $periode = getPeriodeData($periode_id);
                $config = [
                    'periode' => $periode ? [
                        'nama' => $periode['nama'],
                        'tahun_mulai' => $periode['tahun_mulai'],
                        'tahun_selesai' => $periode['tahun_selesai'],
                    ] : [],
                    'keadaan_objektif' => $keadaan_objektif,
                    'keanggotaan' => $membership,
                    'surat' => $lettersByType,
                    'penutup' => $penutup,
                ];
                $jsonPath = tempnam(sys_get_temp_dir(), 'lpj-sekretariat-');
                if ($jsonPath === false) {
                    throw new RuntimeException('Tidak dapat membuat file sementara untuk generator LPJ.');
                }
                $outputDirectory = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'lpj';
                if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
                    throw new RuntimeException('Folder penyimpanan LPJ tidak dapat dibuat.');
                }
                $filename = 'LPJ_Sekretariat_' . $periode_id . '_' . date('Ymd_His') . '.docx';
                $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . $filename;
                try {
                    file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $script = escapeshellarg(__DIR__ . '/../../scratch/bpm_lpj_manager.py');
                    $command = 'python3 ' . $script . ' generate-secretary '
                        . escapeshellarg($outputPath) . ' ' . escapeshellarg($jsonPath) . ' 2>&1';
                    exec($command, $generatorOutput, $generatorStatus);
                } finally {
                    if (is_file($jsonPath)) {
                        unlink($jsonPath);
                    }
                }
                if ($generatorStatus !== 0 || !is_file($outputPath)) {
                    throw new RuntimeException('Generator DOCX gagal: ' . implode("\n", $generatorOutput));
                }
                $relativePath = 'lpj/' . $filename;
                dbQuery(
                    "UPDATE lpj_sekretariat SET file_path = ? WHERE id = ? AND periode_id = ?",
                    [$relativePath, $lpj_id, $periode_id],
                    "sii"
                );
                if ($oldFilePath !== '' && $oldFilePath !== $relativePath) {
                    deleteFile($oldFilePath);
                }
                redirect('admin/lpj/arsip-lpj-sekretariat.php', 'LPJ Sekretariat berhasil disimpan dan DOCX dibuat.', 'success');
            } catch (Throwable $exception) {
                error_log('[LPJ Sekretariat] ' . $exception->getMessage());
                if ($outputPath !== null && is_file($outputPath)) {
                    unlink($outputPath);
                }
                if ($contentSaved) {
                    dbQuery(
                        "UPDATE lpj_sekretariat SET file_path = NULL WHERE id = ? AND periode_id = ?",
                        [$lpj_id, $periode_id],
                        "ii"
                    );
                    if ($oldFilePath !== '') {
                        deleteFile($oldFilePath);
                    }
                }
                $error = 'LPJ tersimpan sebagai data, tetapi proses DOCX gagal. Detail: ' . $exception->getMessage();
            }
        }
    }
}

$letters = dbFetchAll(
    "SELECT id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan
     FROM arsip_surat
     WHERE periode_id = ? AND jenis_surat IN ('L', 'D', 'M', 'I')
       AND (status_arsip = 'archived' OR status_arsip IS NULL)
     ORDER BY jenis_surat, tanggal_dikirim ASC, id ASC",
    [$periode_id],
    "i"
);
$lettersByType = array_fill_keys(array_keys($categories), []);
foreach ($letters as $letter) {
    $lettersByType[$letter['jenis_surat']][] = $letter;
}
?>

<style>
    .lpj-step-nav { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 20px; }
    .lpj-step-nav button { flex:1; min-width:155px; padding:12px; border:1px solid #2a3545; border-radius:8px; background:#121620; color:#aab5c5; cursor:pointer; }
    .lpj-step-nav button.active { color:#fff; border-color:#4A90E2; background:rgba(74,144,226,.14); }
    .lpj-panel { display:none; }
    .lpj-panel.active { display:block; }
    .member-row { display:grid; grid-template-columns:1fr 1fr auto; gap:10px; margin:10px 0; align-items:center; }
    .member-row input { width:100%; padding:10px; color:#fff; background:#080c14; border:1px solid #35445b; border-radius:6px; }
    .archive-category { border:1px solid #2a3545; border-radius:9px; margin:12px 0; overflow:hidden; }
    .archive-category > button { width:100%; display:flex; justify-content:space-between; padding:14px; border:0; color:#fff; background:#121a27; cursor:pointer; font-weight:700; text-align:left; }
    .archive-category-content { display:none; padding:12px; overflow-x:auto; }
    .archive-category.open .archive-category-content { display:block; }
    .archive-category table { width:100%; border-collapse:collapse; min-width:650px; color:#e7edf5; }
    .archive-category th,.archive-category td { border:1px solid #35445b; padding:8px; text-align:left; vertical-align:top; }
    .archive-category th { background:#263c55; }
    @media(max-width:650px) { .member-row { grid-template-columns:1fr; } }
</style>

<div class="page-header">
    <h1><i class="fas fa-file-signature"></i> <?php echo $lpj ? 'Edit' : 'Buat'; ?> LPJ Sekretariat</h1>
    <a class="btn-secondary" href="arsip-lpj-sekretariat.php"><i class="fas fa-archive"></i> Arsip LPJ Sekretariat</a>
</div>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="lpj-step-nav">
    <button type="button" class="active" data-step-target="1">1. Informasi Dasar</button>
    <button type="button" data-step-target="2">2. Keanggotaan</button>
    <button type="button" data-step-target="3">3. Arsip Surat</button>
</div>
<form method="POST" id="secretariatLpjForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="status" id="lpjStatus" value="<?php echo htmlspecialchars($lpj['status'] ?? 'draft'); ?>">
    <section class="lpj-panel active" data-step-panel="1">
        <div class="card"><div class="card-body">
            <p><strong>Periode:</strong> <?php echo htmlspecialchars(($periode_data['nama'] ?? 'Periode aktif') . ' (' . ($periode_data['tahun_mulai'] ?? '') . '-' . ($periode_data['tahun_selesai'] ?? '') . ')'); ?></p>
            <label for="keadaan_objektif">A. Keadaan Objektif Sekretariat</label>
            <textarea id="keadaan_objektif" name="keadaan_objektif" class="form-control" rows="8" maxlength="10000"><?php echo htmlspecialchars($formKeadaanObjektif); ?></textarea>
            <label for="penutup" style="display:block;margin-top:18px;">D. Penutup</label>
            <textarea id="penutup" name="penutup" class="form-control" rows="6" maxlength="10000"><?php echo htmlspecialchars($formPenutup); ?></textarea>
        </div></div>
    </section>
    <section class="lpj-panel" data-step-panel="2">
        <div class="card"><div class="card-body">
            <p>Data awal diambil dari struktur BPH bila tersedia. Nama dan jabatan dapat disesuaikan untuk laporan.</p>
            <div id="members">
            <?php foreach ($membership as $member): ?>
                <div class="member-row">
                    <input name="anggota_nama[]" maxlength="150" placeholder="Nama" value="<?php echo htmlspecialchars($member['nama'] ?? ''); ?>">
                    <input name="anggota_jabatan[]" maxlength="150" placeholder="Jabatan" value="<?php echo htmlspecialchars($member['jabatan'] ?? ''); ?>">
                    <button class="btn-delete" type="button" onclick="this.closest('.member-row').remove()">Hapus</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="btn-secondary" id="addMember"><i class="fas fa-plus"></i> Tambah Anggota</button>
        </div></div>
    </section>
    <section class="lpj-panel" data-step-panel="3">
        <div class="card"><div class="card-body">
            <p>Data diambil langsung dari arsip surat periode aktif. Surat staging dan surat periode lain tidak dimasukkan.</p>
            <?php foreach ($categories as $type => $category): $items = $lettersByType[$type]; ?>
                <div class="archive-category">
                    <button type="button" aria-expanded="false">
                        <span><?php echo htmlspecialchars($category['title']); ?> (<?php echo count($items); ?>)</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="archive-category-content">
                        <?php if (!$items): ?>
                            <p>Belum ada surat dalam kategori ini.</p>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>No.</th><th><?php echo htmlspecialchars($category['date']); ?></th><th>Nomor Surat</th><th>Perihal</th><th><?php echo htmlspecialchars($category['party']); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($item['tanggal_dikirim'] ? date('d/m/Y', strtotime($item['tanggal_dikirim'])) : '—'); ?></td>
                                        <td><?php echo htmlspecialchars($item['nomor_surat']); ?></td>
                                        <td><?php echo htmlspecialchars($item['perihal']); ?></td>
                                        <td><?php echo htmlspecialchars($item['tujuan']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div></div>
    </section>
    <div style="display:flex;justify-content:space-between;gap:12px;margin:18px 0;">
        <button type="button" id="previousStep" class="btn-secondary">Sebelumnya</button>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn-secondary" onclick="document.getElementById('lpjStatus').value='draft'">Simpan Draft &amp; Generate DOCX</button>
            <button type="submit" class="btn-primary" onclick="document.getElementById('lpjStatus').value='submitted'">Submit &amp; Generate DOCX</button>
            <button type="button" id="nextStep" class="btn-primary">Berikutnya</button>
        </div>
    </div>
</form>
<script>
(() => {
    const buttons = [...document.querySelectorAll('[data-step-target]')];
    const panels = [...document.querySelectorAll('[data-step-panel]')];
    let active = 1;
    const show = step => {
        active = Math.max(1, Math.min(3, step));
        buttons.forEach(button => button.classList.toggle('active', Number(button.dataset.stepTarget) === active));
        panels.forEach(panel => panel.classList.toggle('active', Number(panel.dataset.stepPanel) === active));
        document.getElementById('previousStep').disabled = active === 1;
        document.getElementById('nextStep').style.display = active === 3 ? 'none' : '';
    };
    buttons.forEach(button => button.addEventListener('click', () => show(Number(button.dataset.stepTarget))));
    document.getElementById('previousStep').addEventListener('click', () => show(active - 1));
    document.getElementById('nextStep').addEventListener('click', () => show(active + 1));
    document.getElementById('addMember').addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'member-row';
        row.innerHTML = '<input name="anggota_nama[]" maxlength="150" placeholder="Nama"><input name="anggota_jabatan[]" maxlength="150" placeholder="Jabatan"><button class="btn-delete" type="button">Hapus</button>';
        row.querySelector('button').addEventListener('click', () => row.remove());
        document.getElementById('members').appendChild(row);
    });
    document.querySelectorAll('.archive-category > button').forEach(button => button.addEventListener('click', () => {
        const category = button.closest('.archive-category');
        category.classList.toggle('open');
        button.setAttribute('aria-expanded', category.classList.contains('open') ? 'true' : 'false');
    }));
    show(1);
})();
</script>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

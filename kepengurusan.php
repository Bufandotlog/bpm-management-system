<?php
// kepengurusan.php - Halaman Kepengurusan
// VERSI: 2.2 - Added format=json support for LPJ auto-fill

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    require_once __DIR__ . '/includes/functions.php';
    header('Content-Type: application/json');
    
    $kementerian_id = (int)($_GET['kementerian_id'] ?? 0);
    $selected_periode = isset($_GET['periode']) ? (int)$_GET['periode'] : 0;

    if (!$selected_periode) {
        $periode_aktif    = dbFetchOne("SELECT id FROM periode_kepengurusan WHERE is_active = 1");
        $selected_periode = (int)($periode_aktif['id'] ?? 0);
    }
    
    if ($kementerian_id <= 0) {
        echo json_encode(['error' => 'Kementerian ID tidak valid.']);
        exit();
    }

    // IDOR Prevention: Validasi kementerian milik periode yang diminta
    $kemCheck = dbFetchOne(
        "SELECT id FROM kementerian WHERE id = ? AND periode_id = ?",
        [$kementerian_id, $selected_periode], "ii"
    );
    if (!$kemCheck) {
        echo json_encode(['error' => 'Kementerian tidak ditemukan.']);
        exit();
    }

    $members = dbFetchAll(
        "SELECT nama, jabatan FROM anggota_kementerian 
         WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan",
        [$kementerian_id, $selected_periode], "ii"
    );
    
    if (empty($members)) {
        echo json_encode([
            'ketua' => '',
            'sekretaris' => '',
            'bendahara' => '',
            'anggota' => [],
            'found' => false
        ]);
        exit();
    }
    
    $ketua = '';
    $sekretaris = '';
    $bendahara = '';
    $anggota = [];
    
    foreach ($members as $m) {
        $jab = strtolower($m['jabatan']);
        if (strpos($jab, 'anggota') !== false) {
            $anggota[] = $m['nama'];
        } elseif (strpos($jab, 'sekretaris') !== false || strpos($jab, 'sekertaris') !== false || strpos($jab, 'sekre') !== false) {
            $sekretaris = $m['nama'];
        } elseif (strpos($jab, 'bendahara') !== false || strpos($jab, 'bendum') !== false) {
            $bendahara = $m['nama'];
        } elseif (strpos($jab, 'menteri') !== false || strpos($jab, 'mentri') !== false || strpos($jab, 'ketua') !== false || strpos($jab, 'kepala') !== false) {
            $ketua = $m['nama'];
        } else {
            $anggota[] = $m['nama'];
        }
    }
    
    echo json_encode([
        'ketua' => $ketua,
        'sekretaris' => $sekretaris,
        'bendahara' => $bendahara,
        'anggota' => $anggota,
        'found' => true
    ]);
    exit();
}

include 'header.php';
$page_title = 'Kepengurusan';

// ===========================================
// PERIODE: AMBIL SEMUA & TENTUKAN YANG AKTIF
// ===========================================
$semua_periode = dbFetchAll(
    "SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC"
);

// Periode dipilih user via GET, fallback ke periode aktif DB
$selected_periode = isset($_GET['periode']) ? (int)$_GET['periode'] : 0;

if (!$selected_periode) {
    $periode_aktif    = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");
    $selected_periode = (int)($periode_aktif['id'] ?? 0);

    // Jika tidak ada yang aktif, pakai yang pertama
    if (!$selected_periode && !empty($semua_periode)) {
        $selected_periode = (int)$semua_periode[0]['id'];
    }
}

// Validasi: pastikan periode yang dipilih benar-benar ada di DB
$periode_terpilih = dbFetchOne(
    "SELECT * FROM periode_kepengurusan WHERE id = ?",
    [$selected_periode],
    "i"
);

// Jika ID tidak valid, fallback ke periode pertama
if (!$periode_terpilih && !empty($semua_periode)) {
    $selected_periode = (int)$semua_periode[0]['id'];
    $periode_terpilih = $semua_periode[0];
}

// Gallery cards are scoped to the same public period as the page.
$gallery_cards = dbFetchAll(
    "SELECT id, image_path, title, subtitle
     FROM gallery_cards
     WHERE periode_id = ?
     ORDER BY sort_order ASC, id ASC",
    [$selected_periode],
    "i"
);
$gallery_runtime_data = array_map(static function (array $card): array {
    return [
        'id' => (int) $card['id'],
        'image' => uploadUrl($card['image_path']),
        'title' => (string) $card['title'],
        'subtitle' => (string) $card['subtitle'],
    ];
}, $gallery_cards);

// ===========================================
// AMBIL DATA BPH UNTUK PERIODE TERPILIH
// ===========================================
$ketua = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?",
    [$selected_periode], "i"
);

$wakil = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = 'wakil_ketua' AND periode_id = ?",
    [$selected_periode], "i"
);

$sekum = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = 'sekretaris_umum' AND periode_id = ?",
    [$selected_periode], "i"
);
$sekum_anggota = [];
$sekum_nama    = [];
if ($sekum) {
    $sekum_anggota = dbFetchAll(
        "SELECT nama, jabatan, foto FROM anggota_bph
         WHERE bph_id = ? AND periode_id = ? ORDER BY urutan",
        [$sekum['id'], $selected_periode], "ii"
    );
    $sekum_nama = array_column($sekum_anggota, 'nama');
}

$bendum = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = 'bendahara_umum' AND periode_id = ?",
    [$selected_periode], "i"
);
$bendum_anggota = [];
$bendum_nama    = [];
if ($bendum) {
    $bendum_anggota = dbFetchAll(
        "SELECT nama, jabatan, foto FROM anggota_bph
         WHERE bph_id = ? AND periode_id = ? ORDER BY urutan",
        [$bendum['id'], $selected_periode], "ii"
    );
    $bendum_nama = array_column($bendum_anggota, 'nama');
}

// ===========================================
// AMBIL KEMENTERIAN + ANGGOTA
// ===========================================
$kementerian_list = dbFetchAll(
    "SELECT * FROM kementerian WHERE periode_id = ? ORDER BY urutan",
    [$selected_periode], "i"
);

foreach ($kementerian_list as &$k) {
    $k['anggota'] = dbFetchAll(
        "SELECT nama, jabatan FROM anggota_kementerian
         WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan",
        [$k['id'], $selected_periode], "ii"
    );
}
unset($k);

// ===========================================
// AMBIL STRUKTUR ORGANISASI
// ===========================================
$struktur = dbFetchOne(
    "SELECT * FROM struktur_organisasi WHERE periode_id = ? AND is_active = 1",
    [$selected_periode], "i"
);

// ===========================================
// HELPER: TEKS DROPDOWN PERIODE
// ===========================================
function getPeriodeText($p, $withBadge = false) {
    $text = htmlspecialchars($p['nama'])
          . ' (' . $p['tahun_mulai'] . '/' . $p['tahun_selesai'] . ')';
    if ($withBadge && $p['is_active']) {
        $text .= ' • Periode Aktif';
    }
    return $text;
}

// ===========================================
// HELPER: URL GAMBAR DENGAN FALLBACK
// Mengembalikan URL gambar atau URL fallback jika kosong
// ===========================================
function fotoUrl($filename, $fallback = 'images/default-avatar.jpg') {
    return !empty($filename)
        ? uploadUrl($filename)
        : assetUrl($fallback);
}
?>

<?php $css_dropdown_ver = file_exists(__DIR__ . '/assets/css/kepengurusan-dropdown.css') ? filemtime(__DIR__ . '/assets/css/kepengurusan-dropdown.css') : '1'; ?>
<link rel="stylesheet" href="<?php echo baseUrl('assets/css/kepengurusan-dropdown.css'); ?>?v=<?php echo $css_dropdown_ver; ?>">
<link rel="stylesheet" href="<?php echo assetUrl('css/depth-gallery.css'); ?>">

<!-- ========================================= -->
<!-- HERO CAPTION                              -->
<!-- ========================================= -->
<div class="hero-caption">
    <div class="caption-content">
        <h1 class="caption-title"><span>KEPENGURUSAN</span></h1>

        <!-- DROPDOWN PILIH PERIODE (tampil jika lebih dari 1) -->
        <?php if (count($semua_periode) > 1): ?>
        <label for="custom-periode-trigger" class="periode-label">
            <i class="fas fa-calendar-alt"></i> Lihat Periode:
        </label>
        <div class="periode-selector custom-dropdown-container">
            <div class="custom-dropdown" id="customPeriodeDropdown">

                <button type="button"
                        class="custom-dropdown-trigger"
                        id="custom-periode-trigger"
                        aria-haspopup="listbox"
                        aria-expanded="false">
                    <span class="trigger-text">
                        <?php echo getPeriodeText($periode_terpilih, true); ?>
                    </span>
                    <i class="fas fa-chevron-down dropdown-arrow"></i>
                </button>

                <div class="custom-dropdown-menu"
                     role="listbox"
                     aria-labelledby="custom-periode-trigger"
                     tabindex="-1">
                    <?php foreach ($semua_periode as $p):
                        $isSelected = ((int)$p['id'] === $selected_periode);
                    ?>
                    <div class="custom-dropdown-item <?php echo $isSelected ? 'selected' : ''; ?>"
                         data-value="<?php echo $p['id']; ?>"
                         data-text="<?php echo getPeriodeText($p, true); ?>"
                         data-aktif="<?php echo $p['is_active'] ? '1' : '0'; ?>"
                         role="option"
                         aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>"
                         tabindex="-1">
                        <div class="item-content">
                            <span class="item-nama"><?php echo htmlspecialchars($p['nama']); ?></span>
                            <span class="item-tahun">(<?php echo $p['tahun_mulai']; ?>/<?php echo $p['tahun_selesai']; ?>)</span>
                            <?php if ($p['is_active']): ?>
                                <span class="item-badge">Periode Aktif</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isSelected): ?>
                            <i class="fas fa-check selected-icon"></i>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="GET" action="" id="customPeriodeForm" style="display:none;">
                <input type="hidden" name="periode" id="customPeriodeInput"
                       value="<?php echo $selected_periode; ?>">
            </form>
        </div>

        <?php if ($periode_terpilih && !$periode_terpilih['is_active']): ?>
        <div class="periode-badge arsip">
            <i class="fas fa-archive"></i>
            Menampilkan Arsip Periode
            <?php echo $periode_terpilih['tahun_mulai']; ?>/<?php echo $periode_terpilih['tahun_selesai']; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Gambar Struktur Organisasi -->
        <div class="caption-image-container">
            <?php if ($struktur && !empty($struktur['gambar'])): ?>
                <!-- ✅ FIX: uploadUrl() bukan UPLOAD_URL . $path -->
                <img src="<?php echo uploadUrl($struktur['gambar']); ?>"
                     alt="<?php echo htmlspecialchars($struktur['judul'] ?? 'Struktur Organisasi BPM'); ?>"
                     class="caption-image"
                     loading="lazy">
                <?php if (!empty($struktur['deskripsi'])): ?>
                <div class="image-caption">
                    <i class="fas fa-info-circle"></i>
                    <?php echo htmlspecialchars($struktur['deskripsi']); ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-image-placeholder">
                    <div class="placeholder-content">
                        <i class="fas fa-image"></i>
                        <p>Gambar Struktur Organisasi</p>
                        <span>
                            Periode
                            <?php echo $periode_terpilih['tahun_mulai'] ?? ''; ?>/<?php echo $periode_terpilih['tahun_selesai'] ?? ''; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <p class="caption-narasi">
            Struktur organisasi BPM Institut Budi Utomo Nasional
            <?php if ($periode_terpilih): ?>
                periode <?php echo $periode_terpilih['tahun_mulai']; ?>/<?php echo $periode_terpilih['tahun_selesai']; ?>
            <?php else: ?>
                periode kepengurusan
            <?php endif; ?>
            yang terdiri dari Badan Pengurus Harian (BPH) dan jajaran Komisi.
        </p>

        <div class="caption-scroll">
            <span class="scroll-text">jelajahi struktur</span>
            <i class="fas fa-chevron-down"></i>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- KONTEN UTAMA KEPENGURUSAN                 -->
<!-- ========================================= -->
<div class="kepengurusan-content-wrapper">
    <div class="kepengurusan-container">

        <!-- ===== CODEDROPS DEPTH GALLERY (STATIC FIXTURE) ===== -->
        <section class="kepengurusan-depth-gallery" data-depth-gallery aria-labelledby="depth-gallery-title">
            <div class="depth-gallery-viewport" data-depth-gallery-viewport>
                <canvas class="depth-gallery-canvas" data-depth-gallery-canvas aria-hidden="true"></canvas>
                <h2 id="depth-gallery-title" class="visually-hidden">Galeri atmosferik</h2>
            </div>
        </section>

        <div class="static-org-chart-wrapper">
        <!-- ===== BPH PRESENTATION LAYER ===== -->
        <section class="bph-section kepengurusan-bph" data-group="bph" aria-label="Badan Pengurus Harian">

            <?php if (!$ketua && !$wakil && !$sekum && !$bendum): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>Belum ada data BPH untuk periode ini.</p>
            </div>
            <?php else: ?>
            <div class="bph-grid static-org-chart">
                <!-- Ketua -->
                <?php if ($ketua): ?>
                <a href="detail-menteri.php?type=bph&id=<?php echo $ketua['id']; ?>&periode=<?php echo $selected_periode; ?>"
                   class="org-card organization-card leader-card">
                    <div class="card-photo-container">
                        <i class="fas fa-user org-person-icon" aria-hidden="true"></i>
                        <img src="<?php echo fotoUrl($ketua['foto']); ?>"
                             alt="<?php echo htmlspecialchars($ketua['nama']); ?>"
                             loading="lazy"
                             onerror="this.src='<?php echo assetUrl('images/default-avatar.jpg'); ?>'">
                    </div>
                    <div class="org-card-info">
                        <h3><?php echo htmlspecialchars($ketua['nama']); ?></h3>
                        <p class="org-jabatan">Ketua Umum</p>
                    </div>
                </a>
                <?php endif; ?>

                <!-- Wakil Ketua -->
                <?php if ($wakil): ?>
                <a href="detail-menteri.php?type=bph&id=<?php echo $wakil['id']; ?>&periode=<?php echo $selected_periode; ?>"
                   class="org-card organization-card vice-card">
                    <div class="card-photo-container">
                        <img src="<?php echo fotoUrl($wakil['foto']); ?>"
                             alt="<?php echo htmlspecialchars($wakil['nama']); ?>"
                             loading="lazy"
                             onerror="this.src='<?php echo assetUrl('images/default-avatar.jpg'); ?>'">
                    </div>
                    <div class="org-card-info">
                        <h3><?php echo htmlspecialchars($wakil['nama']); ?></h3>
                        <p class="org-jabatan"><?php echo htmlspecialchars($wakil['jabatan']); ?></p>
                    </div>
                </a>
                <?php endif; ?>

                <!-- Sekretaris Umum -->
                <?php if ($sekum): ?>
                <a href="detail-menteri.php?type=bph&id=<?php echo $sekum['id']; ?>&periode=<?php echo $selected_periode; ?>"
                   class="org-card organization-card dept-card logo-card">
                    <div class="card-logo-container">
                        <i class="fas fa-user org-person-icon" aria-hidden="true"></i>
                        <img src="<?php echo fotoUrl($sekum['logo'], 'images/default-logo.png'); ?>"
                             alt="Logo Sekretaris"
                             class="org-logo"
                             loading="lazy"
                             onerror="this.src='<?php echo assetUrl('images/default-logo.png'); ?>'">
                    </div>
                    <div class="org-card-info">
                        <h3>Sekretaris</h3>
                        <p class="org-jabatan"><?php echo count($sekum_anggota); ?> Anggota</p>
                        <?php if (!empty($sekum_nama)): ?>
                        <div class="org-preview">
                            <?php echo htmlspecialchars(implode(' & ', $sekum_nama)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endif; ?>

                <!-- Bendahara Umum -->
                <?php if ($bendum): ?>
                <a href="detail-menteri.php?type=bph&id=<?php echo $bendum['id']; ?>&periode=<?php echo $selected_periode; ?>"
                   class="org-card organization-card dept-card logo-card">
                    <div class="card-logo-container">
                        <i class="fas fa-user org-person-icon" aria-hidden="true"></i>
                        <img src="<?php echo fotoUrl($bendum['logo'], 'images/default-logo.png'); ?>"
                             alt="Logo Bendahara Umum"
                             class="org-logo"
                             loading="lazy"
                             onerror="this.src='<?php echo assetUrl('images/default-logo.png'); ?>'">
                    </div>
                    <div class="org-card-info">
                        <h3>Bendahara</h3>
                        <p class="org-jabatan"><?php echo count($bendum_anggota); ?> Anggota</p>
                        <?php if (!empty($bendum_nama)): ?>
                        <div class="org-preview">
                            <?php echo htmlspecialchars(implode(' & ', $bendum_nama)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endif; ?>

            </div>
            <?php endif; ?>
        </section>

        <section class="commission-section kepengurusan-komisi" data-group="kementerian" aria-labelledby="commission-title">
            <h2 id="commission-title" class="visually-hidden">Komisi</h2>
            <?php if (empty($kementerian_list)): ?>
            <div class="empty-state">
                <i class="fas fa-sitemap"></i>
                <p>Belum ada data Komisi untuk periode ini.</p>
            </div>
            <?php else: ?>
            <div class="commission-grid static-commission-chart">
                <?php foreach ($kementerian_list as $komisi): ?>
                <a class="org-card organization-card commission-card"
                   href="detail-menteri.php?type=kementerian&id=<?php echo (int)$komisi['id']; ?>&periode=<?php echo $selected_periode; ?>"
                   data-commission-id="<?php echo (int)$komisi['id']; ?>"
                   data-period-id="<?php echo $selected_periode; ?>">
                    <div class="commission-card__content">
                        <?php if (!empty($komisi['logo'])): ?>
                        <img class="commission-card__logo"
                             src="<?php echo uploadUrl($komisi['logo']); ?>"
                             alt="Logo <?php echo htmlspecialchars($komisi['nama']); ?>"
                             loading="lazy"
                             onerror="this.src='<?php echo assetUrl('images/default-logo.png'); ?>'">
                        <?php endif; ?>
                        <span class="commission-card__eyebrow">Komisi</span>
                        <h2 class="commission-card__title"><?php echo htmlspecialchars($komisi['nama']); ?></h2>
                        <span class="commission-card__action">Lihat detail</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        </div>
    </div>
</div>

<script>
window.__DEPTH_GALLERY_CARDS__ = <?php echo json_encode($gallery_runtime_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script type="module" src="<?php echo assetUrl('js/depth-gallery-dist/depth-gallery-entry.js'); ?>" defer></script>

<?php include 'footer.php'; ?>
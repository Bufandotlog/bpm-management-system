<?php
// admin/cetak-panitia.php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../core/config.php';

requireLogin();
requireSekretaris();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$periode_id = getUserPeriode();

$panitia = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
if (!$panitia) {
    die("Susunan panitia tidak ditemukan.");
}

$data = json_decode($panitia['panitia_json'], true) ?: [];
$sc_members = dbFetchAll(
    "SELECT id, nama, posisi FROM struktur_bph WHERE periode_id = ? AND posisi IN ('ketua', 'wakil_ketua')",
    [$periode_id],
    "i"
);
$sc_members_by_id = [];
$sc_member_by_position = [];
foreach ($sc_members as $member) {
    $sc_members_by_id[(int)$member['id']] = $member;
    $sc_member_by_position[$member['posisi']] = $member;
}

$sync_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_sc') {
    if (!csrfVerify()) {
        $sync_error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (empty($sc_member_by_position['ketua']) || empty($sc_member_by_position['wakil_ketua'])) {
        $sync_error = 'Data Ketua atau Wakil Ketua BPH periode aktif belum lengkap.';
    } else {
        $current_sc = [
            $sc_member_by_position['ketua'],
            $sc_member_by_position['wakil_ketua']
        ];
        $data['sc'] = array_column($current_sc, 'nama');
        $data['sc_ids'] = array_map('intval', array_column($current_sc, 'id'));
        $data['sc_roles'] = array_column($current_sc, 'posisi');
        try {
            dbQuery(
                "UPDATE arsip_panitia SET panitia_json = ? WHERE id = ? AND periode_id = ?",
                [json_encode($data, JSON_UNESCAPED_UNICODE), $id, $periode_id],
                "sii"
            );
            header('Location: cetak-panitia.php?id=' . $id . '&sc_updated=1');
            exit;
        } catch (RuntimeException $exception) {
            $sync_error = 'Gagal menyimpan perubahan Steering Committee.';
        }
    }
}

$sc_names = $data['sc'] ?? [];
foreach ($data['sc_roles'] ?? [] as $index => $role) {
    if (is_string($role) && isset($sc_member_by_position[$role])) {
        $sc_names[$index] = $sc_member_by_position[$role]['nama'];
    }
}
if (empty($data['sc_roles'])) {
    foreach ($data['sc_ids'] ?? [] as $index => $member_id) {
        $member_id = (int)$member_id;
        if (isset($sc_members_by_id[$member_id])) {
            $sc_names[$index] = $sc_members_by_id[$member_id]['nama'];
        }
    }
}
$sc_names = array_values(array_filter($sc_names, static function ($name) {
    return trim((string)$name) !== '';
}));
$sc_can_sync = !empty($sc_member_by_position['ketua']) && !empty($sc_member_by_position['wakil_ketua']);

// Ambil tahun periode
$periode_data = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE id = ?", [$periode_id], "i");
$tahun_mulai = $periode_data['tahun_mulai'] ?? date('Y');
$tahun_selesai = $periode_data['tahun_selesai'] ?? (date('Y') + 1);
$tahun_periode_str = $tahun_mulai . '/' . $tahun_selesai;

$nama_kegiatan = strtoupper($data['nama_kegiatan'] ?? 'KEGIATAN');
$download_name = "PANITIA - " . ($data['nama_kegiatan'] ?? 'KEGIATAN') . " - " . $tahun_periode_str;
$valid_seksi_list = array_values(array_filter($data['seksi_seksi'] ?? [], static function ($seksi) {
    $valid_anggota = array_filter($seksi['anggota'] ?? [], static function ($item) {
        return trim((string)$item) !== '';
    });
    return !empty($valid_anggota);
}));
$first_page_seksi = array_slice($valid_seksi_list, 0, 4);
$follow_up_seksi_pages = array_chunk(array_slice($valid_seksi_list, 4), 10);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($download_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; line-height: 1.4; }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 20mm;
            margin: 10mm auto;
            border: 1px solid #D3D3D3;
            border-radius: 5px;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .no-print {
            text-align: center;
            padding: 15px;
            background: #222;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .btn {
            background: #4A90E2; color: #fff; border: none; padding: 10px 20px; font-size: 16px;
            border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-warning { background: #f39c12; }

        .header-pdf {
            text-align: center;
            margin-bottom: 30px;
            text-transform: uppercase;
        }
        .header-pdf h1, .header-pdf h2, .header-pdf h3 {
            font-size: 14pt;
            font-weight: bold;
            margin: 3px 0;
        }

        .table-panitia {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .table-panitia tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .table-panitia th, .table-panitia td {
            border: 1px solid #000;
            padding: 8px 12px;
            vertical-align: top;
        }
        
        .table-panitia td.role-title {
            width: 35%;
        }

        .table-panitia td.role-title.italic-title {
            font-style: italic;
        }

        .table-panitia td.names-list {
            width: 65%;
        }

        .table-panitia td.names-list ol {
            margin: 0;
            padding-left: 15px;
        }

        .table-panitia td.names-list ol li {
            margin-bottom: 3px;
        }

        .table-panitia .section-heading {
            text-align: center;
            font-weight: bold;
            background-color: transparent;
            padding: 8px 12px;
            text-transform: uppercase;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            body { background: white; margin: 0; padding: 0; -webkit-print-color-adjust: exact; }
            .page { 
                margin: 0 !important; 
                padding: 15mm 20mm !important;
                border: none !important; 
                border-radius: 0 !important; 
                width: 210mm; 
                min-height: 297mm;
                box-shadow: none !important; 
                background: white !important; 
            }
            .page:not(:last-child) {
                break-after: page;
                page-break-after: always;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak Dokumen</button>
        <a href="arsip-panitia.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali</a>
        <?php if ($sc_can_sync): ?>
            <form method="post" onsubmit="return confirm('Perbarui Steering Committee arsip ini sesuai Ketua dan Wakil Ketua BPH saat ini?');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="sync_sc">
                <button type="submit" class="btn"><i class="fas fa-sync-alt"></i> Sinkronkan SC</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($sync_error !== ''): ?>
        <div class="no-print" style="background:#8b1e1e;justify-content:center;">
            <?php echo htmlspecialchars($sync_error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php elseif (isset($_GET['sc_updated'])): ?>
        <div class="no-print" style="background:#1e6b3c;justify-content:center;">
            Steering Committee berhasil disinkronkan dengan BPH periode ini.
        </div>
    <?php endif; ?>

    <div class="page">
        
        <div class="header-pdf">
            <h1>SUSUNAN PANITIA</h1>
            <h2><?php echo htmlspecialchars($nama_kegiatan); ?></h2>
            <h3>PERIODE <?php echo htmlspecialchars($tahun_periode_str); ?></h3>
        </div>

        <table class="table-panitia">
            <tbody>
                <!-- Penanggung Jawab -->
                <tr>
                    <td class="role-title">Penanggung Jawab</td>
                    <td class="names-list"><?php echo htmlspecialchars($data['penanggung_jawab'] ?? '-'); ?></td>
                </tr>

                <!-- Steering Committee -->
                <tr>
                    <td class="role-title"><span style="font-style: italic;">Steering Committee</span> (SC)</td>
                    <td class="names-list">
                        <?php if (!empty($sc_names)): ?>
                            <ol>
                                <?php foreach ($sc_names as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Ketua Pelaksana -->
                <tr>
                    <td class="role-title">Ketua Pelaksana</td>
                    <td class="names-list">
                        <?php if (!empty($data['ketua_pelaksana'])): ?>
                            <ol>
                                <li><?php echo htmlspecialchars($data['ketua_pelaksana']); ?></li>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Sekretaris -->
                <tr>
                    <td class="role-title">Sekretaris</td>
                    <td class="names-list">
                        <?php if (!empty($data['sekretaris'])): ?>
                            <ol>
                                <?php foreach ($data['sekretaris'] as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Bendahara -->
                <tr>
                    <td class="role-title">Bendahara</td>
                    <td class="names-list">
                        <?php if (!empty($data['bendahara'])): ?>
                            <ol>
                                <?php foreach ($data['bendahara'] as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Seksi-Seksi Heading & Details -->
                <?php if (!empty($first_page_seksi)): ?>
                    <tr>
                        <td colspan="2" class="section-heading">Seksi - Seksi</td>
                    </tr>
                    <?php foreach ($first_page_seksi as $seksi):
                        $valid_anggota = array_filter($seksi['anggota'] ?? [], static function ($item) { return trim((string)$item) !== ''; });
                    ?>
                        <tr>
                            <td class="role-title"><?php echo htmlspecialchars($seksi['nama_seksi'] ?? ''); ?></td>
                            <td class="names-list">
                                <ol>
                                    <?php foreach ($valid_anggota as $name): ?>
                                        <li><?php echo htmlspecialchars($name); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($follow_up_seksi_pages as $seksi_page): ?>
    <div class="page">
        <table class="table-panitia">
            <tbody>
                <tr>
                    <td colspan="2" class="section-heading">Seksi - Seksi (Lanjutan)</td>
                </tr>
                <?php foreach ($seksi_page as $seksi):
                    $valid_anggota = array_filter($seksi['anggota'] ?? [], static function ($item) { return trim((string)$item) !== ''; });
                ?>
                    <tr>
                        <td class="role-title"><?php echo htmlspecialchars($seksi['nama_seksi'] ?? ''); ?></td>
                        <td class="names-list">
                            <ol>
                                <?php foreach ($valid_anggota as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

</body>
</html>

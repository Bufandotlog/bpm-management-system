<?php
$page_css = 'lpj-ketua-umum';
require_once __DIR__ . '/../core/header.php';
requireKetuaUmum();

$periode_id = (int)getUserPeriode();
$validTriwulan = ['I', 'II', 'MUBESMA'];
$triwulan = strtoupper(trim((string)($_GET['triwulan'] ?? 'I')));
if (!in_array($triwulan, $validTriwulan, true)) $triwulan = 'I';
$error = '';
$success = '';

$lpj = dbFetchOne('SELECT * FROM lpj_ketua_umum WHERE periode_id = ? AND triwulan = ?', [$periode_id, $triwulan], 'is');

$visiRow = dbFetchOne('SELECT visi, misi FROM visi_misi WHERE id = 1');
$visi = trim((string)($visiRow['visi'] ?? '')) ?: 'Visi organisasi belum dikonfigurasi.';
$misi = is_array(json_decode($visiRow['misi'] ?? '[]', true)) ? array_values(json_decode($visiRow['misi'] ?? '[]', true)) : [];
if (!$misi) $misi = ['Misi organisasi belum dikonfigurasi.'];

$defaultPendahuluan = "Laporan ini merupakan bentuk pertanggungjawaban kepemimpinan Ketua Umum atas pelaksanaan visi, misi, kebijakan, dan program kerja organisasi selama periode kepengurusan yang berjalan. Penyusunan laporan mencakup kondisi objektif kepengurusan, susunan pengurus, pencapaian visi dan misi, serta realisasi program kerja Badan Pengurus Harian dan seluruh komisi.";
$defaultPenutup = 'Demikian laporan pertanggungjawaban ini disusun sebagai bentuk evaluasi dan pertanggungjawaban atas pelaksanaan kepemimpinan periode ini. Semoga capaian yang telah dicapai menjadi dasar penguatan organisasi pada periode berikutnya.';

$savedMembership = json_decode($lpj['keanggotaan'] ?? '', true);
$savedMembership = is_array($savedMembership) ? $savedMembership : [];

$membership = [];
$bphStructures = dbFetchAll('SELECT id, nama, posisi, jabatan, urutan FROM struktur_bph WHERE periode_id = ? ORDER BY urutan ASC, id ASC', [$periode_id], 'i');
foreach ($bphStructures as $structure) {
    $position = strtolower((string)$structure['posisi']);
    $group = 'Badan Pengurus Harian';
    $jabatan = (string)($structure['jabatan'] ?: $structure['nama']);
    $row = ['nama' => (string)$structure['nama'], 'jabatan' => $jabatan, 'nim' => '', 'kelas' => '', 'gender' => '', 'group' => $group, 'source' => 'bph:'.$structure['id']];
    $key = 'bph:' . (int)$structure['id'];
    if (isset($savedMembership[$key])) $row = array_merge($row, $savedMembership[$key]);
    $membership[$key] = $row;
    $members = dbFetchAll('SELECT id, nama, jabatan, urutan FROM anggota_bph WHERE bph_id = ? AND periode_id = ? ORDER BY urutan ASC, id ASC', [(int)$structure['id'], $periode_id], 'ii');
    foreach ($members as $member) {
        $key = 'bph_member:' . (int)$member['id'];
        $row = ['nama' => (string)$member['nama'], 'jabatan' => (string)$member['jabatan'], 'nim' => '', 'kelas' => '', 'gender' => '', 'group' => $group, 'source' => $key];
        if (isset($savedMembership[$key])) $row = array_merge($row, $savedMembership[$key]);
        $membership[$key] = $row;
    }
}

$commissions = dbFetchAll('SELECT id, nama FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC, id ASC', [$periode_id], 'i');
foreach ($commissions as $commission) {
    $group = (string)$commission['nama'];
    $members = dbFetchAll('SELECT id, nama, jabatan, urutan FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan ASC, id ASC', [(int)$commission['id'], $periode_id], 'ii');
    foreach ($members as $member) {
        $key = 'commission_member:' . (int)$member['id'];
        $row = ['nama' => (string)$member['nama'], 'jabatan' => (string)$member['jabatan'], 'nim' => '', 'kelas' => '', 'gender' => '', 'group' => $group, 'source' => $key];
        if (isset($savedMembership[$key])) $row = array_merge($row, $savedMembership[$key]);
        $membership[$key] = $row;
    }
}

$savedMission = json_decode($lpj['pencapaian_visi_misi'] ?? '', true);
$savedMission = is_array($savedMission) ? $savedMission : [];

$fetchRekap = static function(int $periode_id, string $triwulan): array {
    $quarterValues = $triwulan === 'MUBESMA' ? ['I', 'II'] : [$triwulan];
    $placeholders = implode(',', array_fill(0, count($quarterValues), '?'));
    $params = array_merge([$periode_id], $quarterValues);
    $types = 'i' . str_repeat('s', count($quarterValues));
    $rows = dbFetchAll(
        "SELECT l.triwulan, l.proker_terlaksana, l.proker_belum_terlaksana, k.nama AS komisi
         FROM lpj_dokumen l JOIN kementerian k ON k.id = l.kementerian_id
         WHERE l.periode_id = ? AND l.triwulan IN ($placeholders)
         ORDER BY k.urutan ASC, k.id ASC, l.triwulan ASC",
        $params, $types
    );
    $rekap = [];
    foreach ($rows as $row) {
        $komisi = (string)$row['komisi'];
        if (!isset($rekap[$komisi])) $rekap[$komisi] = ['terlaksana' => [], 'belum' => []];
        $executed = json_decode($row['proker_terlaksana'] ?? '[]', true);
        foreach (is_array($executed) ? $executed : [] as $item) {
            $rekap[$komisi]['terlaksana'][] = [
                'waktu' => (string)($item['Tanggal Kegiatan'] ?? $item['Waktu Pelaksanaan'] ?? $item['tanggal'] ?? ''),
                'nama' => (string)($item['Nama Kegiatan'] ?? $item['Nama Program Kerja'] ?? ''),
                'tempat' => (string)($item['Tempat Kegiatan'] ?? $item['Tempat'] ?? ''),
                'peserta' => (string)($item['Peserta'] ?? ''),
                'keterangan' => 'Terlaksana Dengan Baik',
                'triwulan' => (string)$row['triwulan'],
            ];
        }
        $unexecuted = json_decode($row['proker_belum_terlaksana'] ?? '[]', true);
        foreach (is_array($unexecuted) ? $unexecuted : [] as $item) {
            $rekap[$komisi]['belum'][] = [
                'nama' => (string)($item['Nama Kegiatan'] ?? $item['Nama Program Kerja'] ?? ''),
                'keterangan' => (string)($item['Alasan'] ?? $item['Keterangan'] ?? 'Belum terlaksana'),
                'triwulan' => (string)$row['triwulan'],
            ];
        }
    }
    return $rekap;
};
$rekap = $fetchRekap($periode_id, $triwulan);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) $error = 'Token CSRF tidak valid.';
    else {
        $sanitize = static function(string $value, int $limit): string { $value = strip_tags(str_replace(["\r\n", "\r"], "\n", $value)); $value = preg_replace('/[^-]+/u', '', $value) ?? $value; return mb_substr(trim($value), 0, $limit); };
        // Keep Indonesian Unicode: use normal strip_tags + whitespace cleanup.
        $sanitize = static function(string $value, int $limit): string { $value = strip_tags(str_replace(["\r\n", "\r"], "\n", $value)); $value = trim(preg_replace('/[\t ]+/u', ' ', $value) ?? $value); return mb_substr($value, 0, $limit); };
        $pendahuluan = trim((string)($_POST['keadaan_objektif'] ?? '')) ?: $defaultPendahuluan;
        $penutup = trim((string)($_POST['penutup'] ?? '')) ?: $defaultPenutup;
        $membershipPost = $_POST['membership'] ?? [];
        foreach ($membership as $key => $row) {
            $post = is_array($membershipPost[$key] ?? null) ? $membershipPost[$key] : [];
            $membership[$key]['nim'] = $sanitize((string)($post['nim'] ?? ''), 30);
            $membership[$key]['kelas'] = $sanitize((string)($post['kelas'] ?? ''), 30);
            $membership[$key]['gender'] = in_array((string)($post['gender'] ?? ''), ['L', 'P'], true) ? (string)$post['gender'] : '';
        }
        $missionPost = $_POST['misi'] ?? [];
        $missionRows = [];
        foreach ($misi as $index => $missionText) {
            $rowPost = is_array($missionPost[$index] ?? null) ? $missionPost[$index] : [];
            $missionRows[] = ['misi' => $missionText, 'indikator' => $sanitize((string)($rowPost['indikator'] ?? ''), 3000), 'implementasi' => $sanitize((string)($rowPost['implementasi'] ?? ''), 3000), 'hasil' => $sanitize((string)($rowPost['hasil'] ?? ''), 3000)];
        }
        $status = ($_POST['action'] ?? 'save') === 'submit' ? 'submitted' : 'draft';
        if ($status === 'submitted' && trim($pendahuluan) === '') $error = 'Pendahuluan wajib diisi.';
        if (!$error) {
            $membershipJson = json_encode($membership, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $missionJson = json_encode($missionRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $rekapJson = json_encode($rekap, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            try {
                if ($lpj) dbQuery('UPDATE lpj_ketua_umum SET status=?, keadaan_objektif=?, keanggotaan=?, visi=?, misi=?, pencapaian_visi_misi=?, realisasi_program_kerja=?, penutup=?, file_path=NULL WHERE id=? AND periode_id=?', [$status,$pendahuluan,$membershipJson,$visi,json_encode($misi, JSON_UNESCAPED_UNICODE),$missionJson,$rekapJson,$penutup,$lpj['id'],$periode_id], 'ssssssssii');
                else { dbQuery('INSERT INTO lpj_ketua_umum (periode_id,triwulan,status,keadaan_objektif,keanggotaan,visi,misi,pencapaian_visi_misi,realisasi_program_kerja,penutup) VALUES (?,?,?,?,?,?,?,?,?,?)', [$periode_id,$triwulan,$status,$pendahuluan,$membershipJson,$visi,json_encode($misi, JSON_UNESCAPED_UNICODE),$missionJson,$rekapJson,$penutup], 'isssssssss'); $lpjId = dbLastId(); }
                $lpjId = (int)($lpj['id'] ?? $lpjId);
                $periode = getPeriodeData($periode_id);
                $config = ['periode' => $periode ? ['nama'=>$periode['nama'],'tahun_mulai'=>$periode['tahun_mulai'],'tahun_selesai'=>$periode['tahun_selesai']] : [], 'cover'=>['triwulan'=>$triwulan,'kementerian'=>'Ketua Umum'], 'keadaan_objektif'=>$pendahuluan, 'keanggotaan'=>$membership, 'visi'=>$visi, 'misi'=>$misi, 'pencapaian_visi_misi'=>$missionRows, 'realisasi_program_kerja'=>$rekap, 'penutup'=>$penutup];
                $jsonPath = tempnam(sys_get_temp_dir(), 'lpj-ketua-'); $outputDirectory = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'lpj';
                if (!is_dir($outputDirectory) && !mkdir($outputDirectory,0775,true) && !is_dir($outputDirectory)) throw new RuntimeException('Folder LPJ tidak dapat dibuat.');
                $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . 'LPJ_Ketua_Umum_' . $triwulan . '_' . $periode_id . '_' . date('Ymd_His') . '.docx';
                file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                exec('python3 ' . escapeshellarg(__DIR__.'/../../scratch/bpm_lpj_manager.py') . ' generate-chairperson ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($jsonPath) . ' 2>&1', $output, $statusCode);
                if (is_file($jsonPath)) unlink($jsonPath);
                if ($statusCode !== 0 || !is_file($outputPath)) throw new RuntimeException('Generator DOCX gagal: '.implode("\n",$output));
                dbQuery('UPDATE lpj_ketua_umum SET file_path=? WHERE id=? AND periode_id=?', ['lpj/'.basename($outputPath),$lpjId,$periode_id], 'sii');
                redirect('admin/lpj/arsip-lpj-ketua-umum.php','LPJ Ketua Umum berhasil disimpan dan DOCX dibuat.','success');
            } catch (Throwable $exception) { error_log('[LPJ Ketua Umum] '.$exception->getMessage()); $error='LPJ tersimpan sebagai data, tetapi DOCX gagal dibuat: '.$exception->getMessage(); }
        }
    }
}

$formPendahuluan = trim((string)($lpj['keadaan_objektif'] ?? '')) ?: $defaultPendahuluan;
$formPenutup = trim((string)($lpj['penutup'] ?? '')) ?: $defaultPenutup;
?>
<style>
.ketua-lpj{max-width:1100px;margin:0 auto 36px}.lpj-page-header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:20px}.lpj-eyebrow{color:#5f83ad;font-weight:700;text-transform:uppercase;font-size:.78rem}.lpj-page-header h1{margin:6px 0}.lpj-create-actions{display:flex;gap:8px;flex-wrap:wrap}.lpj-create-actions a{padding:9px 12px;border:1px solid #324052;border-radius:8px;text-decoration:none}.lpj-steps{display:flex;gap:8px;margin-bottom:18px}.lpj-step{flex:1;padding:11px;text-align:center;border-bottom:2px solid #334155}.lpj-step.active{border-color:#4A90E2;color:#86b7ef}.lpj-section{background:#151922;border:1px solid #303a48;border-radius:12px;padding:18px;margin-bottom:16px}.lpj-section h2{margin-top:0}.lpj-field{width:100%;min-height:110px}.lpj-table-wrap{overflow-x:auto}.lpj-table{width:100%;border-collapse:collapse}.lpj-table th,.lpj-table td{border:1px solid #3a4554;padding:8px;vertical-align:top}.lpj-table th{background:#a31516;color:#fff}.lpj-input,.lpj-table input,.lpj-table select,.mission-card textarea{width:100%;box-sizing:border-box;border:1px solid #3c4757;border-radius:6px;padding:8px}.mission-card{border:1px solid #303a48;border-radius:10px;padding:14px;margin-bottom:12px}.mission-card h3{margin-top:0}.mission-card label{display:block;margin:10px 0 5px}.lpj-actions{display:flex;gap:8px;justify-content:flex-end}.lpj-actions button,.lpj-actions a{padding:10px 14px;border-radius:8px;border:1px solid #3c4757}.lpj-rekap-komisi{margin-bottom:20px}.lpj-rekap-komisi h3{margin-bottom:8px}@media(max-width:720px){.lpj-page-header{flex-direction:column}.lpj-steps{display:grid;grid-template-columns:repeat(2,1fr)}.lpj-table.responsive-card-table,.lpj-table.responsive-card-table tbody,.lpj-table.responsive-card-table tr,.lpj-table.responsive-card-table td{display:block;width:100%}.lpj-table.responsive-card-table thead{display:none}.lpj-table.responsive-card-table tr{border:1px solid #3a4554;margin-bottom:10px}.lpj-table.responsive-card-table td::before{content:attr(data-label);display:block;font-weight:bold;margin-bottom:4px}.lpj-actions{flex-direction:column}}
</style>
<div class="ketua-lpj">
<header class="lpj-page-header"><div><span class="lpj-eyebrow">LPJ Ketua Umum</span><h1>Laporan Pertanggungjawaban <?php echo $triwulan === 'MUBESMA' ? 'MUBESMA' : 'Triwulan '.$triwulan; ?></h1></div><div class="lpj-create-actions"><a href="arsip-lpj-ketua-umum.php"><i class="fas fa-archive"></i> Arsip</a></div></header>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="POST">
<?php echo csrfField(); ?>
<div class="lpj-steps"><div class="lpj-step active">A. Pendahuluan</div><div class="lpj-step">B. Kepengurusan</div><div class="lpj-step">C. Visi & Misi</div><div class="lpj-step">D. Proker</div><div class="lpj-step">E. Penutup</div></div>
<section class="lpj-section"><h2>A. Pendahuluan</h2><textarea class="lpj-field lpj-input" name="keadaan_objektif"><?php echo htmlspecialchars($formPendahuluan); ?></textarea></section>
<section class="lpj-section"><h2>B. Susunan Kepengurusan</h2><div class="lpj-table-wrap"><table class="lpj-table responsive-card-table"><thead><tr><th>No.</th><th>Nama</th><th>NIM</th><th>Kelas</th><th>L/P</th><th>Jabatan</th><th>Kelompok</th></tr></thead><tbody>
<?php $no=1; foreach ($membership as $key=>$row): ?><tr><td data-label="No."><?php echo $no++; ?></td><td data-label="Nama"><?php echo htmlspecialchars($row['nama']); ?></td><td data-label="NIM"><input name="membership[<?php echo htmlspecialchars($key); ?>][nim]" value="<?php echo htmlspecialchars((string)$row['nim']); ?>"></td><td data-label="Kelas"><input name="membership[<?php echo htmlspecialchars($key); ?>][kelas]" value="<?php echo htmlspecialchars((string)$row['kelas']); ?>"></td><td data-label="L/P"><select name="membership[<?php echo htmlspecialchars($key); ?>][gender]"><option value="">-</option><option value="L" <?php echo $row['gender']==='L'?'selected':''; ?>>L</option><option value="P" <?php echo $row['gender']==='P'?'selected':''; ?>>P</option></select></td><td data-label="Jabatan"><?php echo htmlspecialchars($row['jabatan']); ?></td><td data-label="Kelompok"><?php echo htmlspecialchars($row['group']); ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="lpj-section"><h2>C. Pencapaian Visi dan Misi</h2><h3>Visi</h3><p><?php echo nl2br(htmlspecialchars($visi)); ?></p><h3>Misi</h3>
<?php foreach ($misi as $index=>$mission): $saved = $savedMission[$index] ?? []; ?><div class="mission-card"><h3>Misi <?php echo $index+1; ?>: <?php echo htmlspecialchars((string)$mission); ?></h3><label>Indikator pencapaian</label><textarea name="misi[<?php echo $index; ?>][indikator]" rows="3"><?php echo htmlspecialchars((string)($saved['indikator'] ?? '')); ?></textarea><label>Implementasi</label><textarea name="misi[<?php echo $index; ?>][implementasi]" rows="3"><?php echo htmlspecialchars((string)($saved['implementasi'] ?? '')); ?></textarea><label>Hasil/capaian</label><textarea name="misi[<?php echo $index; ?>][hasil]" rows="3"><?php echo htmlspecialchars((string)($saved['hasil'] ?? '')); ?></textarea></div><?php endforeach; ?></section>
<section class="lpj-section"><h2>D. Realisasi Program Kerja</h2><p>Data berikut diambil otomatis dari LPJ seluruh komisi. Tidak perlu diinput ulang.</p>
<?php if (!$rekap): ?><div class="alert alert-warning">Belum ada LPJ komisi yang dapat dirangkum pada periode ini.</div><?php endif; ?>
<?php foreach ($rekap as $komisi=>$data): ?><div class="lpj-rekap-komisi"><h3><?php echo htmlspecialchars($komisi); ?></h3><div class="lpj-table-wrap"><table class="lpj-table responsive-card-table"><thead><tr><th>No.</th><th>Waktu Pelaksanaan</th><th>Nama Kegiatan</th><th>Tempat</th><th>Peserta</th><th>Keterangan</th></tr></thead><tbody>
<?php $rowNo=1; foreach ($data['terlaksana'] as $item): ?><tr><td data-label="No."><?php echo $rowNo++; ?></td><td data-label="Waktu"><?php echo htmlspecialchars($item['waktu']); ?></td><td data-label="Nama"><?php echo htmlspecialchars($item['nama']); ?></td><td data-label="Tempat"><?php echo htmlspecialchars($item['tempat']); ?></td><td data-label="Peserta"><?php echo htmlspecialchars($item['peserta']); ?></td><td data-label="Keterangan"><?php echo htmlspecialchars($item['keterangan']); ?></td></tr><?php endforeach; ?>
<?php foreach ($data['belum'] as $item): ?><tr><td data-label="No."><?php echo $rowNo++; ?></td><td data-label="Waktu">—</td><td data-label="Nama"><?php echo htmlspecialchars($item['nama']); ?></td><td data-label="Tempat">—</td><td data-label="Peserta">—</td><td data-label="Keterangan"><?php echo htmlspecialchars($item['keterangan']); ?></td></tr><?php endforeach; ?>
<?php if (!$data['terlaksana']&&!$data['belum']): ?><tr><td colspan="6">Belum ada data program kerja pada periode ini.</td></tr><?php endif; ?></tbody></table></div></div><?php endforeach; ?>
</section>
<section class="lpj-section"><h2>E. Penutup</h2><textarea class="lpj-field lpj-input" name="penutup"><?php echo htmlspecialchars($formPenutup); ?></textarea></section>
<div class="lpj-actions"><button type="submit" name="action" value="save">Simpan Draft</button><button type="submit" name="action" value="submit">Simpan & Serahkan</button></div>
</form></div>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

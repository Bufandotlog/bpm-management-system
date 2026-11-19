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
    $position = strtolower(trim((string)$structure['posisi']));
    $group = 'Badan Pengurus Harian';

    // struktur_bph for Sekretaris Umum and Bendahara Umum is a header/office record,
    // while real people are stored in anggota_bph. Including the header here creates
    // placeholder rows such as "Sekretaris Umum" and "Bendahara Umum".
    $placeholderPositions = ['sekretaris_umum', 'bendahara_umum'];
    if (!in_array($position, $placeholderPositions, true)) {
        $jabatan = (string)($structure['jabatan'] ?: $structure['nama']);
        $row = ['nama' => (string)$structure['nama'], 'jabatan' => $jabatan, 'nim' => '', 'kelas' => '', 'gender' => '', 'group' => $group, 'source' => 'bph:'.$structure['id']];
        $key = 'bph:' . (int)$structure['id'];
        if (isset($savedMembership[$key])) $row = array_merge($row, $savedMembership[$key]);
        $membership[$key] = $row;
    }

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
                'peserta' => (string)($item['Peserta Kegiatan'] ?? $item['Peserta'] ?? ''),
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
<div class="ketua-lpj">
<header class="lpj-page-header">
    <div class="lpj-page-heading"><span><i class="fas fa-file-signature"></i> LPJ Kepemimpinan</span><h1>Laporan Pertanggungjawaban <?php echo $triwulan === 'MUBESMA' ? 'MUBESMA' : 'Triwulan '.$triwulan; ?></h1><p>Lengkapi laporan kepemimpinan Ketua Umum untuk periode aktif.</p></div>
    <div class="lpj-create-actions"><a href="arsip-lpj-ketua-umum.php"><i class="fas fa-archive"></i> Arsip LPJ</a></div>
</header>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<div class="lpj-step-progress" id="lpjStepProgress">
    <button type="button" class="active" data-step="1" aria-current="step"><span class="step-number">1</span><span class="step-label">Pendahuluan</span></button>
    <button type="button" data-step="2"><span class="step-number">2</span><span class="step-label">Kepengurusan</span></button>
    <button type="button" data-step="3"><span class="step-number">3</span><span class="step-label">Visi & Misi</span></button>
    <button type="button" data-step="4"><span class="step-number">4</span><span class="step-label">Realisasi</span></button>
    <button type="button" data-step="5"><span class="step-number">5</span><span class="step-label">Penutup</span></button>
</div>
<form method="POST" id="chairpersonLpjForm">
<?php echo csrfField(); ?>
<section class="lpj-panel active" data-panel="1"><div class="lpj-panel-card"><div class="lpj-panel-heading"><h2><i class="fas fa-info-circle"></i> A. Pendahuluan</h2><p>Pilih periode dan sesuaikan gambaran objektif kepemimpinan.</p></div><div class="lpj-panel-content">
<div class="period-summary"><i class="fas fa-calendar-alt"></i><div class="period-summary-text"><small>Periode Kepengurusan</small><strong><?php echo htmlspecialchars((string)(getPeriodeData($periode_id)['nama'] ?? 'Periode aktif')); ?></strong></div><label class="period-select-label">Jenis LPJ<select id="chairpersonTriwulan"><option value="I" <?php echo $triwulan==='I'?'selected':''; ?>>Triwulan I</option><option value="II" <?php echo $triwulan==='II'?'selected':''; ?>>Triwulan II</option><option value="MUBESMA" <?php echo $triwulan==='MUBESMA'?'selected':''; ?>>MUBESMA</option></select></label></div>
<div class="lpj-field"><label for="keadaan_objektif"><span class="field-kicker">A.</span> Keadaan Objektif Ketua Umum</label><textarea id="keadaan_objektif" name="keadaan_objektif" rows="8"><?php echo htmlspecialchars($formPendahuluan); ?></textarea><small class="field-hint">Template awal dapat disesuaikan dengan kondisi kepemimpinan periode ini.</small></div>
</div></div></section>
<section class="lpj-panel" data-panel="2"><div class="lpj-panel-card"><div class="lpj-panel-heading"><h2><i class="fas fa-users"></i> B. Susunan Kepengurusan</h2><p>Lengkapi NIM, kelas, dan gender untuk anggota yang tercantum dalam LPJ.</p></div><div class="lpj-panel-content"><div class="lpj-table-wrap"><table class="lpj-table responsive-card-table"><thead><tr><th>No.</th><th>Nama</th><th>NIM</th><th>Kelas</th><th>L/P</th><th>Jabatan</th><th>Kelompok</th></tr></thead><tbody>
<?php $no=1; foreach ($membership as $key=>$row): ?><tr><td data-label="No."><?php echo $no++; ?></td><td data-label="Nama"><?php echo htmlspecialchars($row['nama']); ?></td><td data-label="NIM"><input name="membership[<?php echo htmlspecialchars($key); ?>][nim]" value="<?php echo htmlspecialchars((string)$row['nim']); ?>" placeholder="NIM"></td><td data-label="Kelas"><input name="membership[<?php echo htmlspecialchars($key); ?>][kelas]" value="<?php echo htmlspecialchars((string)$row['kelas']); ?>" placeholder="Kelas"></td><td data-label="L/P"><select name="membership[<?php echo htmlspecialchars($key); ?>][gender]"><option value="">-</option><option value="L" <?php echo $row['gender']==='L'?'selected':''; ?>>L</option><option value="P" <?php echo $row['gender']==='P'?'selected':''; ?>>P</option></select></td><td data-label="Jabatan"><?php echo htmlspecialchars($row['jabatan']); ?></td><td data-label="Kelompok"><?php echo htmlspecialchars($row['group']); ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div></section>
<section class="lpj-panel" data-panel="3"><div class="lpj-panel-card"><div class="lpj-panel-heading"><h2><i class="fas fa-bullseye"></i> C. Pencapaian Visi dan Misi</h2><p>Isi indikator, implementasi, dan hasil untuk setiap misi.</p></div><div class="lpj-panel-content"><div class="visi-box"><h3>Visi Organisasi</h3><p><?php echo nl2br(htmlspecialchars($visi)); ?></p></div>
<?php foreach ($misi as $index=>$mission): $saved = $savedMission[$index] ?? []; ?><div class="mission-card"><h3>Misi <?php echo $index+1; ?> — <?php echo htmlspecialchars((string)$mission); ?></h3><label>Indikator pencapaian</label><textarea name="misi[<?php echo $index; ?>][indikator]" rows="3" placeholder="Tuliskan indikator pencapaian..."><?php echo htmlspecialchars((string)($saved['indikator'] ?? '')); ?></textarea><label>Implementasi</label><textarea name="misi[<?php echo $index; ?>][implementasi]" rows="3" placeholder="Tuliskan kebijakan atau program yang diimplementasikan..."><?php echo htmlspecialchars((string)($saved['implementasi'] ?? '')); ?></textarea><label>Hasil/capaian</label><textarea name="misi[<?php echo $index; ?>][hasil]" rows="3" placeholder="Tuliskan hasil atau dampak yang diperoleh..."><?php echo htmlspecialchars((string)($saved['hasil'] ?? '')); ?></textarea></div><?php endforeach; ?>
</div></div></section>
<section class="lpj-panel" data-panel="4"><div class="lpj-panel-card"><div class="lpj-panel-heading"><h2><i class="fas fa-tasks"></i> D. Realisasi Program Kerja</h2><p>Rekap otomatis dari LPJ seluruh komisi. Tidak perlu diinput ulang.</p></div><div class="lpj-panel-content">
<?php if (!$rekap): ?><div class="lpj-empty-rekap"><i class="fas fa-info-circle"></i> Belum ada LPJ komisi yang dapat dirangkum pada periode ini.</div><?php endif; ?>
<?php foreach ($rekap as $komisi=>$data): ?><div class="lpj-rekap-komisi"><h3><i class="fas fa-building"></i> <?php echo htmlspecialchars($komisi); ?></h3><div class="lpj-table-wrap"><table class="lpj-table responsive-card-table"><thead><tr><th>No.</th><th>Waktu Pelaksanaan</th><th>Nama Kegiatan</th><th>Tempat</th><th>Peserta</th><th>Keterangan</th></tr></thead><tbody>
<?php $rowNo=1; foreach ($data['terlaksana'] as $item): ?><tr><td data-label="No."><?php echo $rowNo++; ?></td><td data-label="Waktu"><?php echo htmlspecialchars($item['waktu']); ?></td><td data-label="Nama"><?php echo htmlspecialchars($item['nama']); ?></td><td data-label="Tempat"><?php echo htmlspecialchars($item['tempat']); ?></td><td data-label="Peserta"><?php echo htmlspecialchars($item['peserta']); ?></td><td data-label="Keterangan" class="lpj-status-ok"><?php echo htmlspecialchars($item['keterangan']); ?></td></tr><?php endforeach; ?>
<?php foreach ($data['belum'] as $item): ?><tr><td data-label="No."><?php echo $rowNo++; ?></td><td data-label="Waktu">—</td><td data-label="Nama"><?php echo htmlspecialchars($item['nama']); ?></td><td data-label="Tempat">—</td><td data-label="Peserta">—</td><td data-label="Keterangan" class="lpj-status-pending"><?php echo htmlspecialchars($item['keterangan']); ?></td></tr><?php endforeach; ?>
<?php if (!$data['terlaksana']&&!$data['belum']): ?><tr><td colspan="6">Belum ada data program kerja pada periode ini.</td></tr><?php endif; ?></tbody></table></div></div><?php endforeach; ?>
</div></div></section>
<section class="lpj-panel" data-panel="5"><div class="lpj-panel-card"><div class="lpj-panel-heading"><h2><i class="fas fa-flag-checkered"></i> E. Penutup</h2><p>Tuliskan kesimpulan dan penutup pertanggungjawaban.</p></div><div class="lpj-panel-content"><div class="lpj-field"><label for="penutup"><span class="field-kicker">E.</span> Penutup LPJ</label><textarea id="penutup" name="penutup" rows="7"><?php echo htmlspecialchars($formPenutup); ?></textarea></div></div></div></section>
<div class="lpj-form-actions"><button type="button" id="prevChairpersonStep">Sebelumnya</button><button type="submit" name="action" value="save">Simpan Draft</button><button type="submit" name="action" value="submit" class="submit-button">Simpan & Serahkan</button></div>
</form></div>
<script>
(function(){var progress=document.getElementById('lpjStepProgress');var panels=[].slice.call(document.querySelectorAll('[data-panel]'));var buttons=[].slice.call(progress.querySelectorAll('button'));var current=1;function show(step){current=Math.max(1,Math.min(buttons.length,step));panels.forEach(function(p){p.classList.toggle('active',Number(p.dataset.panel)==current)});buttons.forEach(function(b,i){b.classList.toggle('active',i+1==current);b.classList.toggle('completed',i+1<current);if(i+1==current)b.setAttribute('aria-current','step');else b.removeAttribute('aria-current')});var card=panels[current-1];if(card&&window.innerWidth<700)card.scrollIntoView({behavior:'smooth',block:'start'});}buttons.forEach(function(b){b.addEventListener('click',function(){show(Number(b.dataset.step))})});document.getElementById('prevChairpersonStep').addEventListener('click',function(){show(current-1)});document.getElementById('chairpersonTriwulan').addEventListener('change',function(){window.location.href='buat-lpj-ketua-umum.php?triwulan='+encodeURIComponent(this.value)});})();
</script>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

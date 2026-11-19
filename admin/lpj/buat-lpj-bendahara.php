<?php
$page_css = 'lpj-bendahara';
require_once __DIR__ . '/../core/header.php';
requireBendahara();

$periode_id = (int)getUserPeriode();
$validTriwulan = ['I', 'II', 'MUBESMA'];
$requestedTriwulan = strtoupper(trim((string)($_GET['triwulan'] ?? 'I')));
if (!in_array($requestedTriwulan, $validTriwulan, true)) {
    $requestedTriwulan = 'I';
}
$requestedId = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';
$lpj = $requestedId > 0
    ? dbFetchOne('SELECT * FROM lpj_bendahara WHERE id = ? AND periode_id = ?', [$requestedId, $periode_id], 'ii')
    : dbFetchOne('SELECT * FROM lpj_bendahara WHERE periode_id = ? AND triwulan = ?', [$periode_id, $requestedTriwulan], 'is');
if ($requestedId > 0 && !$lpj) {
    http_response_code(404);
    $error = 'LPJ Bendahara tidak ditemukan pada periode aktif.';
}
$triwulan = strtoupper((string)($lpj['triwulan'] ?? $requestedTriwulan));
$keadaanObjektif = (string)($lpj['keadaan_objektif'] ?? '');
$penutup = (string)($lpj['penutup'] ?? '');
$penggunaan = [];
$transaksi = [];
$rekapKas = [];
if ($lpj) {
    $lpj_id = (int)$lpj['id'];
    $penggunaan = dbFetchAll('SELECT * FROM lpj_bendahara_penggunaan_dana WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
    $transaksi = dbFetchAll('SELECT * FROM lpj_bendahara_transaksi_dana WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
    $rekapKas = dbFetchAll('SELECT * FROM lpj_bendahara_rekap_kas WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
}

$keanggotaan = json_decode($lpj['keanggotaan'] ?? '', true);
if (!is_array($keanggotaan)) {
    $keanggotaan = [];
}
if (!$keanggotaan) {
    $bphMembers = dbFetchAll(
        "SELECT ab.nama, ab.jabatan
         FROM anggota_bph ab
         JOIN struktur_bph sb ON sb.id = ab.bph_id
         WHERE ab.periode_id = ?
           AND (LOWER(sb.posisi) LIKE '%bendahara%' OR LOWER(ab.jabatan) LIKE '%bendahara%')
         ORDER BY ab.urutan ASC, ab.id ASC",
        [$periode_id],
        'i'
    );
    $keanggotaan = array_map(static fn(array $member): array => [
        'nama' => $member['nama'],
        'jabatan' => $member['jabatan'],
    ], $bphMembers);
}

if (!$lpj) {
    $commissionTriwulan = $triwulan === 'MUBESMA' ? ['I', 'II'] : [$triwulan];
    $placeholders = implode(',', array_fill(0, count($commissionTriwulan), '?'));
    $commissionLpjs = dbFetchAll(
        "SELECT l.*, k.nama AS nama_kementerian
         FROM lpj_dokumen l
         JOIN kementerian k ON k.id = l.kementerian_id
         WHERE l.periode_id = ? AND l.status = 'submitted'
           AND l.triwulan IN ($placeholders)
         ORDER BY k.urutan ASC, k.id ASC, l.triwulan ASC",
        array_merge([$periode_id], $commissionTriwulan),
        'i' . str_repeat('s', count($commissionTriwulan))
    );
    $usedKeys = [];
    foreach ($commissionLpjs as $commissionLpj) {
        $prokers = json_decode($commissionLpj['proker_terlaksana'] ?? '', true);
        if (!is_array($prokers)) {
            continue;
        }
        foreach ($prokers as $index => $proker) {
            if (!is_array($proker) || !empty($proker['tidak_menggunakan_anggaran'])) {
                continue;
            }
            $anggaran = is_array($proker['anggaran'] ?? null) ? $proker['anggaran'] : [];
            if (!$anggaran) {
                continue;
            }
            $activityName = trim((string)($proker['Nama Kegiatan'] ?? $proker['Nama Program Kerja'] ?? ''));
            $activityKey = trim((string)($proker['source_activity_key'] ?? ''));
            if ($activityKey === '') {
                $activityKey = 'komisi-' . (int)$commissionLpj['kementerian_id'] . '-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($activityName ?: 'kegiatan')) . '-' . ($index + 1);
            }
            if (isset($usedKeys[$activityKey])) {
                $activityKey .= '-' . (int)$commissionLpj['id'];
            }
            $usedKeys[$activityKey] = true;
            $activityDate = null;
            $rawDate = trim((string)($proker['Tanggal Kegiatan'] ?? ''));
            if ($rawDate !== '') {
                foreach ([ '!Y-m-d', '!d/m/Y', '!d-m-Y', '!Y/m/d' ] as $format) {
                    $parsedDate = DateTimeImmutable::createFromFormat($format, $rawDate);
                    if ($parsedDate) {
                        $activityDate = $parsedDate->format('Y-m-d');
                        break;
                    }
                }
            }
            $totalAmount = 0.0;
            foreach ($anggaran as $tx) {
                $totalAmount += (float)($tx['debet'] ?? 0) + (float)($tx['kredit'] ?? 0);
            }
            $penggunaan[] = [
                'tanggal' => $activityDate,
                'source_activity_key' => $activityKey,
                'nama_kegiatan' => $activityName,
                'sumber_dana' => trim((string)($proker['sumber_dana'] ?? '')),
                'uraian' => $activityName ?: 'Penggunaan dana kegiatan komisi',
                'jumlah' => $totalAmount,
                'keterangan' => (string)$commissionLpj['nama_kementerian'],
            ];
            foreach ($anggaran as $tx) {
                $debet = (float)($tx['debet'] ?? 0);
                $kredit = (float)($tx['kredit'] ?? 0);
                $txDate = trim((string)($tx['tanggal'] ?? ''));
                $txDateNormalized = null;
                if ($txDate !== '') {
                    foreach ([ '!Y-m-d', '!d/m/Y', '!d-m-Y', '!Y/m/d' ] as $format) {
                        $parsedTxDate = DateTimeImmutable::createFromFormat($format, $txDate);
                        if ($parsedTxDate) {
                            $txDateNormalized = $parsedTxDate->format('Y-m-d');
                            break;
                        }
                    }
                }
                if ($debet > 0) {
                    $transaksi[] = ['tanggal' => $txDateNormalized ?: $activityDate, 'source_activity_key' => $activityKey, 'jenis' => 'pemasukan', 'sumber_dana' => trim((string)($proker['sumber_dana'] ?? '')), 'uraian' => trim((string)($tx['uraian'] ?? $tx['keterangan'] ?? $activityName)), 'jumlah' => $debet];
                }
                if ($kredit > 0) {
                    $transaksi[] = ['tanggal' => $txDateNormalized ?: $activityDate, 'source_activity_key' => $activityKey, 'jenis' => 'pengeluaran', 'sumber_dana' => trim((string)($proker['sumber_dana'] ?? '')), 'uraian' => trim((string)($tx['uraian'] ?? $tx['keterangan'] ?? $activityName)), 'jumlah' => $kredit];
                }
            }
        }
    }
    $penggunaan = array_values($penggunaan);
    usort($penggunaan, static function (array $a, array $b): int {
        if (empty($a['tanggal']) && !empty($b['tanggal'])) return 1;
        if (!empty($a['tanggal']) && empty($b['tanggal'])) return -1;
        return strcmp((string)($a['tanggal'] ?? ''), (string)($b['tanggal'] ?? ''));
    });
}

$clean = static function ($value, int $limit = 2000): string {
    return mb_substr(trim(strip_tags((string)$value)), 0, $limit);
};
$parseRows = static function (string $key, array $fields, array $moneyFields = []) use ($clean, &$error): array {
    $posted = is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
    $rowCount = 0;
    foreach ($fields as $field) {
        $rowCount = max($rowCount, count(is_array($posted[$field] ?? null) ? $posted[$field] : []));
    }
    $result = [];
    for ($index = 0; $index < $rowCount; $index++) {
        $row = [];
        $hasContent = false;
        foreach ($fields as $field) {
            $values = is_array($posted[$field] ?? null) ? $posted[$field] : [];
            $value = $values[$index] ?? '';
            if (in_array($field, $moneyFields, true)) {
                $value = trim((string)$value);
                if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) {
                    $error = 'Nilai nominal harus berupa angka nol atau lebih.';
                }
                $row[$field] = $value === '' ? null : (float)$value;
            } elseif ($field === 'tanggal') {
                $value = trim((string)$value);
                if ($value !== '') {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        $error = 'Tanggal pada rincian keuangan tidak valid.';
                    }
                }
                $row[$field] = $value === '' ? null : $value;
            } else {
                $row[$field] = $clean($value, $field === 'source_activity_key' ? 100 : ($field === 'sumber_dana' ? 255 : 2000));
            }
            if ($row[$field] !== null && $row[$field] !== '') {
                $hasContent = true;
            }
        }
        if ($hasContent) {
            $result[] = $row;
        }
    }
    return $result;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($requestedId === 0 || $lpj !== null)) {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $postedTriwulan = strtoupper(trim((string)($_POST['triwulan'] ?? '')));
        $status = (string)($_POST['status'] ?? 'draft');
        $keadaanObjektif = $clean($_POST['keadaan_objektif'] ?? '', 10000);
        $penutup = $clean($_POST['penutup'] ?? '', 10000);
        $membershipNames = is_array($_POST['anggota_nama'] ?? null) ? $_POST['anggota_nama'] : [];
        $membershipJobs = is_array($_POST['anggota_jabatan'] ?? null) ? $_POST['anggota_jabatan'] : [];
        $keanggotaan = [];
        $membershipCount = max(count($membershipNames), count($membershipJobs));
        for ($membershipIndex = 0; $membershipIndex < $membershipCount; $membershipIndex++) {
            $memberName = $clean($membershipNames[$membershipIndex] ?? '', 100);
            $memberJob = $clean($membershipJobs[$membershipIndex] ?? '', 100);
            if ($memberName !== '' || $memberJob !== '') {
                $keanggotaan[] = ['nama' => $memberName, 'jabatan' => $memberJob];
            }
        }
        if ($lpj && $postedTriwulan !== $triwulan) {
            $error = 'Jenis triwulan tidak boleh diubah saat mengedit LPJ yang sudah ada.';
        } elseif (!in_array($postedTriwulan, $validTriwulan, true)) {
            $error = 'Jenis triwulan LPJ tidak valid.';
        } elseif (!in_array($status, ['draft', 'submitted'], true)) {
            $error = 'Status LPJ tidak valid.';
        } else {
            $triwulan = $postedTriwulan;
            $penggunaan = $parseRows('penggunaan', ['tanggal', 'source_activity_key', 'nama_kegiatan', 'sumber_dana', 'uraian', 'jumlah', 'keterangan'], ['jumlah']);
            $transaksi = $parseRows('transaksi', ['tanggal', 'source_activity_key', 'jenis', 'sumber_dana', 'uraian', 'jumlah'], ['jumlah']);
            $rekapKas = $parseRows('rekap', ['tanggal', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir', 'keterangan'], ['saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir']);
            if ($status === 'submitted' && ($keadaanObjektif === '' || $penutup === '' || !$keanggotaan || !$transaksi)) {
                $error = 'LPJ yang diserahkan wajib memiliki keadaan objektif, penutup, keanggotaan, dan minimal satu transaksi.';
            }
            foreach ($transaksi as $row) {
                if (!in_array($row['jenis'], ['', 'pemasukan', 'pengeluaran'], true)) {
                    $error = 'Jenis transaksi tidak valid.';
                    break;
                }
            }
            foreach ($status === 'submitted' ? $penggunaan : [] as $row) {
                if (empty($row['tanggal']) || empty($row['sumber_dana']) || empty($row['uraian']) || $row['jumlah'] === null) {
                    $error = 'Setiap penggunaan dana yang diserahkan harus memiliki tanggal, sumber dana, uraian, dan nominal.';
                    break;
                }
            }
            foreach ($status === 'submitted' ? $transaksi : [] as $row) {
                if (empty($row['tanggal']) || !in_array($row['jenis'], ['pemasukan', 'pengeluaran'], true) || empty($row['sumber_dana']) || empty($row['uraian']) || $row['jumlah'] === null) {
                    $error = 'Setiap transaksi yang diserahkan harus memiliki tanggal, jenis, sumber dana, uraian, dan nominal.';
                    break;
                }
            }
            foreach ($status === 'submitted' ? $rekapKas : [] as $row) {
                if (empty($row['tanggal']) || $row['saldo_awal'] === null || $row['total_pemasukan'] === null || $row['total_pengeluaran'] === null || $row['saldo_akhir'] === null) {
                    $error = 'Setiap rekap kas yang diserahkan harus memiliki tanggal dan seluruh nilai saldo.';
                    break;
                }
                $expectedBalance = (float)$row['saldo_awal'] + (float)$row['total_pemasukan'] - (float)$row['total_pengeluaran'];
                if (abs($expectedBalance - (float)$row['saldo_akhir']) > 0.01) {
                    $error = 'Saldo akhir rekap kas harus sama dengan saldo awal ditambah pemasukan dikurangi pengeluaran.';
                    break;
                }
            }
        }

        if ($error === '') {
            $pdo = getConnection();
            $oldFilePath = (string)($lpj['file_path'] ?? '');
            try {
                $pdo->beginTransaction();
                if ($lpj) {
                    dbQuery('UPDATE lpj_bendahara SET status = ?, keadaan_objektif = ?, keanggotaan = ?, penutup = ?, file_path = NULL WHERE id = ? AND periode_id = ?', [$status, $keadaanObjektif, json_encode($keanggotaan, JSON_UNESCAPED_UNICODE), $penutup, (int)$lpj['id'], $periode_id], 'ssssii');
                    $lpj_id = (int)$lpj['id'];
                } else {
                    dbQuery('INSERT INTO lpj_bendahara (periode_id, triwulan, status, keadaan_objektif, keanggotaan, penutup) VALUES (?, ?, ?, ?, ?, ?)', [$periode_id, $triwulan, $status, $keadaanObjektif, json_encode($keanggotaan, JSON_UNESCAPED_UNICODE), $penutup], 'isssss');
                    $lpj_id = dbLastId();
                }
                foreach ([
                    'lpj_bendahara_penggunaan_dana' => $penggunaan,
                    'lpj_bendahara_transaksi_dana' => $transaksi,
                    'lpj_bendahara_rekap_kas' => $rekapKas,
                ] as $table => $rows) {
                    dbQuery("DELETE FROM {$table} WHERE lpj_bendahara_id = ?", [$lpj_id], 'i');
                    foreach ($rows as $row) {
                        if ($table === 'lpj_bendahara_penggunaan_dana') {
                            dbQuery('INSERT INTO lpj_bendahara_penggunaan_dana (lpj_bendahara_id, tanggal, source_activity_key, nama_kegiatan, sumber_dana, uraian, jumlah, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$lpj_id, $row['tanggal'], $row['source_activity_key'], $row['nama_kegiatan'] ?? $row['uraian'], $row['sumber_dana'], $row['uraian'], $row['jumlah'], $row['keterangan']], 'isssssds');
                        } elseif ($table === 'lpj_bendahara_transaksi_dana') {
                            dbQuery('INSERT INTO lpj_bendahara_transaksi_dana (lpj_bendahara_id, tanggal, source_activity_key, jenis, sumber_dana, uraian, jumlah) VALUES (?, ?, ?, ?, ?, ?, ?)', [$lpj_id, $row['tanggal'], $row['source_activity_key'] ?? null, $row['jenis'] ?: null, $row['sumber_dana'], $row['uraian'], $row['jumlah']], 'isssssd');
                        } else {
                            dbQuery('INSERT INTO lpj_bendahara_rekap_kas (lpj_bendahara_id, tanggal, saldo_awal, total_pemasukan, total_pengeluaran, saldo_akhir, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)', [$lpj_id, $row['tanggal'], $row['saldo_awal'], $row['total_pemasukan'], $row['total_pengeluaran'], $row['saldo_akhir'], $row['keterangan']], 'isdddds');
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[LPJ Bendahara] ' . $exception->getMessage());
                $error = 'LPJ Bendahara gagal disimpan: ' . $exception->getMessage();
            }

            if ($error === '') {
                try {
                    $periode = getPeriodeData($periode_id);
                    $config = [
                        'periode' => $periode ? ['nama' => $periode['nama'], 'tahun_mulai' => $periode['tahun_mulai'], 'tahun_selesai' => $periode['tahun_selesai']] : [],
                        'cover' => ['triwulan' => $triwulan, 'kementerian' => 'Bendahara Umum'],
                        'keadaan_objektif' => $keadaanObjektif,
                        'keanggotaan' => $keanggotaan,
                        'penggunaan_dana' => $penggunaan,
                        'transaksi_dana' => $transaksi,
                        'rekap_kas' => $rekapKas,
                        'penutup' => $penutup,
                    ];
                    $jsonPath = tempnam(sys_get_temp_dir(), 'lpj-bendahara-');
                    if ($jsonPath === false) {
                        throw new RuntimeException('File sementara generator tidak dapat dibuat.');
                    }
                    $directory = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'lpj';
                    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                        throw new RuntimeException('Folder penyimpanan LPJ tidak dapat dibuat.');
                    }
                    $filename = 'LPJ_Bendahara_' . $triwulan . '_' . $periode_id . '_' . date('Ymd_His') . '.docx';
                    $outputPath = $directory . DIRECTORY_SEPARATOR . $filename;
                    try {
                        file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                        $command = 'python3 ' . escapeshellarg(__DIR__ . '/../../scratch/bpm_lpj_manager.py') . ' generate-treasurer ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($jsonPath) . ' 2>&1';
                        exec($command, $generatorOutput, $generatorStatus);
                    } finally {
                        if (is_file($jsonPath)) {
                            unlink($jsonPath);
                        }
                    }
                    if ($generatorStatus !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
                        throw new RuntimeException('Generator DOCX gagal: ' . implode("\n", $generatorOutput));
                    }
                    dbQuery('UPDATE lpj_bendahara SET file_path = ? WHERE id = ? AND periode_id = ?', ['lpj/' . $filename, $lpj_id, $periode_id], 'sii');
                    if ($oldFilePath !== '') {
                        deleteFile($oldFilePath);
                    }
                    redirect('admin/lpj/arsip-lpj-bendahara.php', 'LPJ Bendahara berhasil disimpan dan DOCX dibuat.', 'success');
                } catch (Throwable $exception) {
                    error_log('[LPJ Bendahara DOCX] ' . $exception->getMessage());
                    $error = 'Data LPJ tersimpan, tetapi DOCX gagal dibuat: ' . $exception->getMessage();
                    $lpj = dbFetchOne('SELECT * FROM lpj_bendahara WHERE id = ? AND periode_id = ?', [$lpj_id, $periode_id], 'ii');
                    $penggunaan = dbFetchAll('SELECT * FROM lpj_bendahara_penggunaan_dana WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
                    $transaksi = dbFetchAll('SELECT * FROM lpj_bendahara_transaksi_dana WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
                    $rekapKas = dbFetchAll('SELECT * FROM lpj_bendahara_rekap_kas WHERE lpj_bendahara_id = ? ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC', [$lpj_id], 'i');
                }
            }
        }
    }
}

$renderRows = static function (array $rows, array $fields, string $prefix): void {
    foreach ($rows as $row): ?>
        <tr><?php foreach ($fields as $field => $label): ?><td data-label="<?php echo htmlspecialchars($label); ?>">
            <?php if ($field === 'jenis'): ?><select name="transaksi[jenis][]"><option value="">Pilih</option><option value="pemasukan" <?php echo ($row[$field] ?? '') === 'pemasukan' ? 'selected' : ''; ?>>Pemasukan</option><option value="pengeluaran" <?php echo ($row[$field] ?? '') === 'pengeluaran' ? 'selected' : ''; ?>>Pengeluaran</option></select>
            <?php else: ?><input type="<?php echo $field === 'tanggal' ? 'date' : (in_array($field, ['jumlah', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir'], true) ? 'number' : 'text'); ?>" <?php echo in_array($field, ['jumlah', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir'], true) ? 'min="0" step="0.01"' : ''; ?> name="<?php echo htmlspecialchars($prefix); ?>[<?php echo htmlspecialchars($field); ?>][]" value="<?php echo htmlspecialchars((string)($row[$field] ?? '')); ?>">
            <?php endif; ?>
        </td><?php endforeach; ?><td><button type="button" class="btn-secondary" onclick="this.closest('tr').remove()">Hapus</button></td></tr>
    <?php endforeach;
};
?>
<div class="page-header treasurer-page-header"><div><h1><i class="fas fa-coins"></i> <?php echo $lpj ? 'Edit' : 'Buat'; ?> LPJ Bendahara</h1><p>Triwulan <?php echo htmlspecialchars($triwulan); ?> · Laporan keuangan Badan Pengurus Harian</p></div><a class="btn-secondary" href="arsip-lpj-bendahara.php"><i class="fas fa-archive"></i> Arsip LPJ</a></div>
<?php if ($error !== ''): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<div class="step-progress" id="treasurerStepProgress" aria-label="Tahapan pengisian LPJ">
    <button type="button" class="step active" data-step="1"><span class="step-num">1</span><span class="step-text">Informasi Dasar</span></button>
    <button type="button" class="step" data-step="2"><span class="step-num">2</span><span class="step-text">Keanggotaan</span></button>
    <button type="button" class="step" data-step="3"><span class="step-num">3</span><span class="step-text">Penggunaan Dana</span></button>
    <button type="button" class="step" data-step="4"><span class="step-num">4</span><span class="step-text">Rekapitulasi Kas</span></button>
</div>
<form method="POST" id="treasurerLpjForm" class="treasurer-form">
    <?php echo csrfField(); ?>
    <section class="wizard-panel active" data-step="1">
        <div class="treasurer-card">
            <div class="section-heading"><span class="section-icon"><i class="fas fa-clipboard-list"></i></span><div><h2>Informasi Dasar</h2><p>Lengkapi periode laporan dan gambaran kondisi keuangan bendahara.</p></div></div>
            <div class="form-grid">
                <label class="treasurer-field">Jenis LPJ<select name="triwulan" <?php echo $lpj ? 'disabled' : ''; ?> required><?php foreach ($validTriwulan as $period): ?><option value="<?php echo $period; ?>" <?php echo $triwulan === $period ? 'selected' : ''; ?>><?php echo $period === 'MUBESMA' ? 'MUBESMA' : 'Triwulan ' . $period; ?></option><?php endforeach; ?></select><?php if ($lpj): ?><input type="hidden" name="triwulan" value="<?php echo htmlspecialchars($triwulan); ?>"><?php endif; ?></label>
            </div>
            <label class="treasurer-field">Keadaan Objektif Bendahara<textarea name="keadaan_objektif" rows="6" placeholder="Jelaskan keadaan dan pengelolaan keuangan selama periode ini..."><?php echo htmlspecialchars($keadaanObjektif); ?></textarea></label>
            <label class="treasurer-field">Penutup<textarea name="penutup" rows="5" placeholder="Tuliskan penutup laporan..."><?php echo htmlspecialchars($penutup); ?></textarea></label>
        </div>
    </section>
    <section class="wizard-panel" data-step="2">
        <div class="treasurer-card">
            <div class="section-heading"><span class="section-icon"><i class="fas fa-users"></i></span><div><h2>Keanggotaan Bendahara</h2><p>Data anggota terisi dari database kepengurusan dan dapat disesuaikan.</p></div></div>
            <div class="table-responsive"><table class="admin-table"><thead><tr><th>Nama</th><th>Jabatan</th><th class="action-column"></th></tr></thead><tbody id="membershipRows"><?php foreach ($keanggotaan as $member): ?><tr><td><input type="text" name="anggota_nama[]" value="<?php echo htmlspecialchars((string)($member['nama'] ?? '')); ?>" placeholder="Nama anggota"></td><td><input type="text" name="anggota_jabatan[]" value="<?php echo htmlspecialchars((string)($member['jabatan'] ?? '')); ?>" placeholder="Jabatan"></td><td><button type="button" class="btn-row-delete" onclick="this.closest('tr').remove()"><i class="fas fa-trash-alt"></i><span>Hapus</span></button></td></tr><?php endforeach; ?></tbody></table></div>
            <button type="button" class="btn-add-row" onclick="addMembershipRow()"><i class="fas fa-plus"></i> Tambah Anggota</button>
        </div>
    </section>
    <section class="wizard-panel" data-step="3">
        <div class="treasurer-card">
            <div class="section-heading"><span class="section-icon"><i class="fas fa-money-bill-wave"></i></span><div><h2>Laporan Penggunaan Dana</h2><p>Kegiatan beranggaran dari LPJ komisi ditampilkan otomatis berdasarkan tanggal. Anda juga dapat menambahkan laporan manual.</p></div></div>
            <div class="table-responsive"><table class="admin-table"><thead><tr><th>Tanggal</th><th>Kunci Kegiatan</th><th>Nama Kegiatan</th><th>Sumber Dana</th><th>Uraian</th><th>Jumlah</th><th>Keterangan</th><th class="action-column"></th></tr></thead><tbody id="usageRows">
        <?php $renderRows($penggunaan, ['tanggal' => 'Tanggal', 'source_activity_key' => 'Kunci Kegiatan', 'nama_kegiatan' => 'Nama Kegiatan', 'sumber_dana' => 'Sumber Dana', 'uraian' => 'Uraian', 'jumlah' => 'Jumlah', 'keterangan' => 'Keterangan'], 'penggunaan'); ?>
    </tbody></table></div><button type="button" class="btn-add-row" onclick="addFinancialRow('usageRows','penggunaan',['tanggal','source_activity_key','nama_kegiatan','sumber_dana','uraian','jumlah','keterangan'])"><i class="fas fa-plus"></i> Tambah Penggunaan Dana</button>
        </div>
        <div class="treasurer-card">
            <div class="section-heading"><span class="section-icon"><i class="fas fa-exchange-alt"></i></span><div><h2>Rincian Transaksi</h2><p>Catat aliran dana masuk dan keluar untuk tiap kegiatan.</p></div></div>
            <div class="table-responsive"><table class="admin-table"><thead><tr><th>Tanggal</th><th>Kunci Kegiatan</th><th>Jenis</th><th>Sumber Dana</th><th>Uraian</th><th>Jumlah</th><th class="action-column"></th></tr></thead><tbody id="transactionRows">
        <?php $renderRows($transaksi, ['tanggal' => 'Tanggal', 'source_activity_key' => 'Kunci Kegiatan', 'jenis' => 'Jenis', 'sumber_dana' => 'Sumber Dana', 'uraian' => 'Uraian', 'jumlah' => 'Jumlah'], 'transaksi'); ?>
    </tbody></table></div><button type="button" class="btn-add-row" onclick="addFinancialRow('transactionRows','transaksi',['tanggal','source_activity_key','jenis','sumber_dana','uraian','jumlah'])"><i class="fas fa-plus"></i> Tambah Transaksi</button>
        </div>
    </section>
    <section class="wizard-panel" data-step="4">
        <div class="treasurer-card">
            <div class="section-heading"><span class="section-icon"><i class="fas fa-cash-register"></i></span><div><h2>Rekapitulasi Kas</h2><p>Isi rekap kas BPM secara manual. Saldo akhir harus sesuai saldo awal ditambah pemasukan dikurangi pengeluaran.</p></div></div>
            <div class="table-responsive"><table class="admin-table"><thead><tr><th>Tanggal</th><th>Saldo Awal</th><th>Pemasukan</th><th>Pengeluaran</th><th>Saldo Akhir</th><th>Keterangan</th><th class="action-column"></th></tr></thead><tbody id="cashRows">
        <?php $renderRows($rekapKas, ['tanggal' => 'Tanggal', 'saldo_awal' => 'Saldo Awal', 'total_pemasukan' => 'Pemasukan', 'total_pengeluaran' => 'Pengeluaran', 'saldo_akhir' => 'Saldo Akhir', 'keterangan' => 'Keterangan'], 'rekap'); ?>
            </tbody></table></div><button type="button" class="btn-add-row" onclick="addFinancialRow('cashRows','rekap',['tanggal','saldo_awal','total_pemasukan','total_pengeluaran','saldo_akhir','keterangan'])"><i class="fas fa-plus"></i> Tambah Baris Kas</button>
        </div>
        <div class="form-actions treasurer-actions"><button class="btn-secondary" name="status" value="draft"><i class="fas fa-save"></i> Simpan Draft</button><button class="btn-primary" name="status" value="submitted"><i class="fas fa-paper-plane"></i> Submit LPJ</button></div>
    </section>
    <div class="wizard-navigation"><button type="button" class="btn-secondary" id="previousStep"><i class="fas fa-arrow-left"></i> Sebelumnya</button><span id="stepCounter">Langkah 1 dari 4</span><button type="button" class="btn-primary" id="nextStep">Selanjutnya <i class="fas fa-arrow-right"></i></button></div>
</form>
<script>
(() => {
    const progress = document.getElementById('treasurerStepProgress');
    const panels = Array.from(document.querySelectorAll('.treasurer-form .wizard-panel'));
    const steps = Array.from(progress.querySelectorAll('.step'));
    const previous = document.getElementById('previousStep');
    const next = document.getElementById('nextStep');
    const counter = document.getElementById('stepCounter');
    let activeStep = 1;
    const showStep = (step) => {
        activeStep = Math.min(Math.max(step, 1), panels.length);
        panels.forEach((panel) => panel.classList.toggle('active', Number(panel.dataset.step) === activeStep));
        steps.forEach((button) => {
            const number = Number(button.dataset.step);
            button.classList.toggle('active', number === activeStep);
            button.classList.toggle('completed', number < activeStep);
            button.setAttribute('aria-current', number === activeStep ? 'step' : 'false');
        });
        previous.disabled = activeStep === 1;
        next.hidden = activeStep === panels.length;
        counter.textContent = `Langkah ${activeStep} dari ${panels.length}`;
    };
    previous.addEventListener('click', () => showStep(activeStep - 1));
    next.addEventListener('click', () => showStep(activeStep + 1));
    steps.forEach((button) => button.addEventListener('click', () => showStep(Number(button.dataset.step))));
    showStep(activeStep);
})();

function addMembershipRow() {
    const body = document.getElementById('membershipRows');
    const row = document.createElement('tr');
    ['anggota_nama[]', 'anggota_jabatan[]'].forEach((name) => {
        const cell = document.createElement('td');
        const input = document.createElement('input'); input.type = 'text'; input.name = name;
        cell.appendChild(input); row.appendChild(cell);
    });
    const action = document.createElement('td');
    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Hapus'; remove.className = 'btn-secondary';
    remove.addEventListener('click', () => row.remove()); action.appendChild(remove); row.appendChild(action); body.appendChild(row);
}
function addFinancialRow(bodyId, prefix, fields) {
    const body = document.getElementById(bodyId);
    const row = document.createElement('tr');
    fields.forEach((field) => {
        const cell = document.createElement('td');
        let input;
        if (field === 'jenis') {
            input = document.createElement('select');
            [['', 'Pilih'], ['pemasukan', 'Pemasukan'], ['pengeluaran', 'Pengeluaran']].forEach(([value, label]) => {
                const option = document.createElement('option'); option.value = value; option.textContent = label; input.appendChild(option);
            });
        } else {
            input = document.createElement('input');
            input.type = field === 'tanggal' ? 'date' : ['jumlah', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir'].includes(field) ? 'number' : 'text';
            if (input.type === 'number') { input.min = '0'; input.step = '0.01'; }
        }
        input.name = `${prefix}[${field}][]`;
        cell.appendChild(input); row.appendChild(cell);
    });
    const action = document.createElement('td');
    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Hapus'; remove.className = 'btn-secondary';
    remove.addEventListener('click', () => row.remove()); action.appendChild(remove); row.appendChild(action); body.appendChild(row);
}
</script>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

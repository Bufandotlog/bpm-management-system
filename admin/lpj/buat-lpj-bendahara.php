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
$rekapKas = [];
$normalizeDate = static function ($value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!Y/m/d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }
    if (preg_match('/(?:^|,\\s*)(\\d{1,2})(?:\\s*[-–]\\s*\\d{1,2})?\\s+([[:alpha:]]+)\\s+(\\d{4})/u', $value, $matches)) {
        $months = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        ];
        $day = (int)$matches[1];
        $month = $months[mb_strtolower($matches[2], 'UTF-8')] ?? null;
        $year = (int)$matches[3];
        if ($month !== null && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    return null;
};
$loadActivities = static function (int $lpjId): array {
    $activities = dbFetchAll(
        'SELECT * FROM lpj_bendahara_penggunaan_dana
         WHERE lpj_bendahara_id = ?
         ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC',
        [$lpjId],
        'i'
    );
    $byId = [];
    $bySourceKey = [];
    $ambiguousSourceKeys = [];
    foreach ($activities as $index => &$activity) {
        $activity['transactions'] = [];
        $byId[(int)$activity['id']] = $index;
        $sourceKey = (string)($activity['source_activity_key'] ?? '');
        if ($sourceKey !== '') {
            if (isset($bySourceKey[$sourceKey])) {
                unset($bySourceKey[$sourceKey]);
                $ambiguousSourceKeys[$sourceKey] = true;
            } elseif (!isset($ambiguousSourceKeys[$sourceKey])) {
                $bySourceKey[$sourceKey] = $index;
            }
        }
    }
    unset($activity);

    $transactions = dbFetchAll(
        'SELECT * FROM lpj_bendahara_transaksi_dana
         WHERE lpj_bendahara_id = ?
         ORDER BY tanggal IS NULL ASC, tanggal ASC, id ASC',
        [$lpjId],
        'i'
    );
    foreach ($transactions as $transaction) {
        $activityIndex = !empty($transaction['penggunaan_dana_id'])
            ? ($byId[(int)$transaction['penggunaan_dana_id']] ?? null)
            : null;
        $sourceKey = trim((string)($transaction['source_activity_key'] ?? ''));
        if ($activityIndex === null && $sourceKey !== '' && !isset($ambiguousSourceKeys[$sourceKey])) {
            $activityIndex = $bySourceKey[$sourceKey] ?? null;
        }
        if ($activityIndex === null) {
            $legacyKey = $sourceKey !== ''
                ? 'legacy-' . $sourceKey . (isset($ambiguousSourceKeys[$sourceKey]) ? '-transaction-' . (int)$transaction['id'] : '')
                : 'legacy-transaction-' . (int)$transaction['id'];
            $activities[] = [
                'id' => null,
                'source_type' => 'manual',
                'source_lpj_id' => null,
                'source_activity_key' => $legacyKey,
                'tanggal' => $transaction['tanggal'] ?? null,
                'nama_kegiatan' => trim((string)($transaction['uraian'] ?? '')) ?: 'Kegiatan (data transaksi lama)',
                'sumber_dana' => $transaction['sumber_dana'] ?? '',
                'keterangan' => '',
                'transactions' => [],
            ];
            $activityIndex = array_key_last($activities);
            if ($sourceKey !== '' && !isset($ambiguousSourceKeys[$sourceKey])) {
                $bySourceKey[$sourceKey] = $activityIndex;
            }
        }
        $debet = $transaction['debet'] !== null
            ? (float)$transaction['debet']
            : ((($transaction['jenis'] ?? '') === 'pemasukan') ? (float)($transaction['jumlah'] ?? 0) : 0.0);
        $kredit = $transaction['kredit'] !== null
            ? (float)$transaction['kredit']
            : ((($transaction['jenis'] ?? '') === 'pengeluaran') ? (float)($transaction['jumlah'] ?? 0) : 0.0);
        $activities[$activityIndex]['transactions'][] = [
            'tanggal' => $transaction['tanggal'] ?? null,
            'keterangan' => $transaction['keterangan'] ?? '',
            'uraian' => $transaction['uraian'] ?? '',
            'debet' => $debet,
            'kredit' => $kredit,
        ];
    }
    foreach ($activities as &$activity) {
        usort($activity['transactions'], static function (array $a, array $b): int {
            if (empty($a['tanggal']) && !empty($b['tanggal'])) return 1;
            if (!empty($a['tanggal']) && empty($b['tanggal'])) return -1;
            return strcmp((string)($a['tanggal'] ?? ''), (string)($b['tanggal'] ?? ''));
        });
    }
    unset($activity);
    usort($activities, static function (array $a, array $b): int {
        if (empty($a['tanggal']) && !empty($b['tanggal'])) return 1;
        if (!empty($a['tanggal']) && empty($b['tanggal'])) return -1;
        return strcmp((string)($a['tanggal'] ?? ''), (string)($b['tanggal'] ?? ''));
    });
    return $activities;
};
if ($lpj) {
    $lpj_id = (int)$lpj['id'];
    $penggunaan = $loadActivities($lpj_id);
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
            $activityKey = 'komisi-lpj-' . (int)$commissionLpj['id'] . '-proker-' . ($index + 1);
            $usedKeys[$activityKey] = true;
            $activityDate = $normalizeDate($proker['Tanggal Kegiatan'] ?? '');
            $activityTransactions = [];
            foreach ($anggaran as $tx) {
                $debet = (float)($tx['debet'] ?? 0);
                $kredit = (float)($tx['kredit'] ?? 0);
                $activityTransactions[] = [
                    'tanggal' => $normalizeDate($tx['tanggal'] ?? '') ?? $activityDate,
                    'keterangan' => trim((string)($tx['keterangan'] ?? '')),
                    'uraian' => trim((string)($tx['uraian'] ?? '')),
                    'debet' => $debet,
                    'kredit' => $kredit,
                ];
            }
            usort($activityTransactions, static function (array $a, array $b): int {
                if (empty($a['tanggal']) && !empty($b['tanggal'])) return 1;
                if (!empty($a['tanggal']) && empty($b['tanggal'])) return -1;
                return strcmp((string)($a['tanggal'] ?? ''), (string)($b['tanggal'] ?? ''));
            });
            $penggunaan[] = [
                'id' => null,
                'source_type' => 'komisi',
                'source_lpj_id' => (int)$commissionLpj['id'],
                'source_activity_key' => $activityKey,
                'tanggal' => $activityDate,
                'nama_kegiatan' => $activityName,
                'sumber_dana' => trim((string)($proker['sumber_dana'] ?? '')),
                'keterangan' => (string)$commissionLpj['nama_kementerian'],
                'transactions' => $activityTransactions,
            ];
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
$parseActivities = static function () use ($clean, $normalizeDate, &$error): array {
    $postedActivities = is_array($_POST['penggunaan'] ?? null) ? $_POST['penggunaan'] : [];
    $activities = [];
    foreach ($postedActivities as $activityInput) {
        if (!is_array($activityInput)) {
            continue;
        }
        $activity = [
            'source_type' => ($activityInput['source_type'] ?? '') === 'komisi' ? 'komisi' : 'manual',
            'source_lpj_id' => max(0, (int)($activityInput['source_lpj_id'] ?? 0)) ?: null,
            'source_activity_key' => $clean($activityInput['source_activity_key'] ?? '', 100),
            'nama_kegiatan' => $clean($activityInput['nama_kegiatan'] ?? '', 255),
            'tanggal' => $normalizeDate($activityInput['tanggal'] ?? ''),
            'sumber_dana' => $clean($activityInput['sumber_dana'] ?? '', 255),
            'keterangan' => $clean($activityInput['keterangan'] ?? '', 2000),
            'transactions' => [],
        ];
        if (trim((string)($activityInput['tanggal'] ?? '')) !== '' && $activity['tanggal'] === null) {
            $error = 'Tanggal kegiatan penggunaan dana tidak valid.';
        }
        $transactionInput = is_array($activityInput['transaksi'] ?? null) ? $activityInput['transaksi'] : [];
        $transactionFields = ['tanggal', 'keterangan', 'uraian', 'debet', 'kredit'];
        $rowCount = 0;
        foreach ($transactionFields as $field) {
            $rowCount = max($rowCount, count(is_array($transactionInput[$field] ?? null) ? $transactionInput[$field] : []));
        }
        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            $transaction = [];
            $hasContent = false;
            foreach ($transactionFields as $field) {
                $values = is_array($transactionInput[$field] ?? null) ? $transactionInput[$field] : [];
                $rawValue = trim((string)($values[$rowIndex] ?? ''));
                if ($field === 'tanggal') {
                    $transaction[$field] = $normalizeDate($rawValue);
                    if ($rawValue !== '' && $transaction[$field] === null) {
                        $error = 'Tanggal transaksi penggunaan dana tidak valid.';
                    }
                } elseif (in_array($field, ['debet', 'kredit'], true)) {
                    if ($rawValue !== '' && (!is_numeric($rawValue) || (float)$rawValue < 0)) {
                        $error = 'Nilai debet dan kredit harus berupa angka nol atau lebih.';
                    }
                    $transaction[$field] = $rawValue === '' ? null : (float)$rawValue;
                } else {
                    $transaction[$field] = $clean($rawValue, 2000);
                }
                if ($transaction[$field] !== null && $transaction[$field] !== '') {
                    $hasContent = true;
                }
            }
            if ($hasContent) {
                $activity['transactions'][] = $transaction;
            }
        }
        $hasActivityContent = $activity['nama_kegiatan'] !== ''
            || $activity['tanggal'] !== null
            || $activity['sumber_dana'] !== ''
            || $activity['transactions'] !== [];
        if ($hasActivityContent) {
            $activities[] = $activity;
        }
    }
    return $activities;
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
            $penggunaan = $parseActivities();
            $rekapKas = $parseRows('rekap', ['tanggal', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir', 'keterangan'], ['saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir']);
            $transactionCount = 0;
            foreach ($penggunaan as $activity) {
                $transactionCount += count($activity['transactions']);
            }
            if ($status === 'submitted' && ($keadaanObjektif === '' || $penutup === '' || !$keanggotaan || $transactionCount === 0)) {
                $error = 'LPJ yang diserahkan wajib memiliki keadaan objektif, penutup, keanggotaan, dan minimal satu transaksi penggunaan dana.';
            }
            foreach ($status === 'submitted' ? $penggunaan : [] as $activity) {
                if (empty($activity['nama_kegiatan']) || empty($activity['tanggal']) || empty($activity['sumber_dana']) || !$activity['transactions']) {
                    $error = 'Setiap kegiatan penggunaan dana yang diserahkan wajib memiliki nama kegiatan, tanggal, sumber dana, dan minimal satu transaksi.';
                    break;
                }
                foreach ($activity['transactions'] as $transaction) {
                    if (empty($transaction['tanggal']) || $transaction['uraian'] === '' || ($transaction['debet'] === null && $transaction['kredit'] === null)) {
                        $error = 'Setiap transaksi penggunaan dana wajib memiliki tanggal, uraian, dan nilai debet atau kredit.';
                        break 2;
                    }
                    if ((float)($transaction['debet'] ?? 0) > 0 && (float)($transaction['kredit'] ?? 0) > 0) {
                        $error = 'Satu baris transaksi tidak boleh memiliki debet dan kredit sekaligus.';
                        break 2;
                    }
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
                dbQuery('DELETE FROM lpj_bendahara_transaksi_dana WHERE lpj_bendahara_id = ?', [$lpj_id], 'i');
                dbQuery('DELETE FROM lpj_bendahara_penggunaan_dana WHERE lpj_bendahara_id = ?', [$lpj_id], 'i');
                foreach ($penggunaan as $activity) {
                    $totalKredit = array_sum(array_map(
                        static fn(array $transaction): float => (float)($transaction['kredit'] ?? 0),
                        $activity['transactions']
                    ));
                    dbQuery(
                        'INSERT INTO lpj_bendahara_penggunaan_dana
                         (lpj_bendahara_id, source_type, source_lpj_id, source_activity_key, tanggal, nama_kegiatan, sumber_dana, uraian, jumlah, keterangan)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $lpj_id,
                            $activity['source_type'],
                            $activity['source_lpj_id'],
                            $activity['source_activity_key'] ?: null,
                            $activity['tanggal'],
                            $activity['nama_kegiatan'],
                            $activity['sumber_dana'],
                            $activity['nama_kegiatan'],
                            $totalKredit,
                            $activity['keterangan'],
                        ],
                        'isisssssds'
                    );
                    $activityId = dbLastId();
                    foreach ($activity['transactions'] as $transaction) {
                        $debet = (float)($transaction['debet'] ?? 0);
                        $kredit = (float)($transaction['kredit'] ?? 0);
                        $legacyType = $debet > 0 ? 'pemasukan' : ($kredit > 0 ? 'pengeluaran' : null);
                        $legacyAmount = $debet > 0 ? $debet : $kredit;
                        dbQuery(
                            'INSERT INTO lpj_bendahara_transaksi_dana
                             (lpj_bendahara_id, penggunaan_dana_id, tanggal, keterangan, source_activity_key, jenis, sumber_dana, uraian, jumlah, debet, kredit)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $lpj_id,
                                $activityId,
                                $transaction['tanggal'],
                                $transaction['keterangan'],
                                $activity['source_activity_key'] ?: null,
                                $legacyType,
                                $activity['sumber_dana'],
                                $transaction['uraian'],
                                $legacyAmount,
                                $debet,
                                $kredit,
                            ],
                            'iissssssddd'
                        );
                    }
                }
                dbQuery('DELETE FROM lpj_bendahara_rekap_kas WHERE lpj_bendahara_id = ?', [$lpj_id], 'i');
                foreach ($rekapKas as $row) {
                    dbQuery('INSERT INTO lpj_bendahara_rekap_kas (lpj_bendahara_id, tanggal, saldo_awal, total_pemasukan, total_pengeluaran, saldo_akhir, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)', [$lpj_id, $row['tanggal'], $row['saldo_awal'], $row['total_pemasukan'], $row['total_pengeluaran'], $row['saldo_akhir'], $row['keterangan']], 'isdddds');
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
                    $penggunaan = $loadActivities($lpj_id);
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
            <div class="section-heading"><span class="section-icon"><i class="fas fa-money-bill-wave"></i></span><div><h2>Laporan Penggunaan Dana</h2><p>Setiap kegiatan memiliki kartu dan tabel realisasi anggaran sendiri. Laporan beranggaran dari LPJ komisi ditarik otomatis dan diurutkan menurut tanggal.</p></div></div>
            <div id="activityCards">
                <?php foreach ($penggunaan as $activityIndex => $activity): ?>
                    <?php $activityTransactions = is_array($activity['transactions'] ?? null) ? $activity['transactions'] : []; ?>
                    <article class="activity-card" data-activity-card data-index="<?php echo (int)$activityIndex; ?>">
                        <div class="activity-card-header">
                            <span class="activity-number">Kegiatan <?php echo (int)$activityIndex + 1; ?></span>
                            <span class="activity-source-badge <?php echo ($activity['source_type'] ?? '') === 'komisi' ? 'is-commission' : 'is-manual'; ?>"><?php echo ($activity['source_type'] ?? '') === 'komisi' ? 'Dari Komisi' : 'Manual'; ?></span>
                            <button type="button" class="activity-toggle" aria-expanded="true" title="Minimalkan kegiatan"><i class="fas fa-chevron-up"></i></button>
                            <button type="button" class="btn-row-delete activity-remove" title="Hapus kegiatan"><i class="fas fa-trash-alt"></i><span>Hapus</span></button>
                        </div>
                        <div class="activity-card-body">
                            <input type="hidden" name="penggunaan[<?php echo (int)$activityIndex; ?>][source_type]" value="<?php echo htmlspecialchars(($activity['source_type'] ?? '') === 'komisi' ? 'komisi' : 'manual'); ?>">
                            <input type="hidden" name="penggunaan[<?php echo (int)$activityIndex; ?>][source_lpj_id]" value="<?php echo (int)($activity['source_lpj_id'] ?? 0); ?>">
                            <input type="hidden" name="penggunaan[<?php echo (int)$activityIndex; ?>][source_activity_key]" value="<?php echo htmlspecialchars((string)($activity['source_activity_key'] ?? '')); ?>">
                            <input type="hidden" name="penggunaan[<?php echo (int)$activityIndex; ?>][keterangan]" value="<?php echo htmlspecialchars((string)($activity['keterangan'] ?? '')); ?>">
                            <div class="activity-meta-grid">
                                <label class="treasurer-field">Nama Kegiatan<input type="text" name="penggunaan[<?php echo (int)$activityIndex; ?>][nama_kegiatan]" value="<?php echo htmlspecialchars((string)($activity['nama_kegiatan'] ?? '')); ?>" placeholder="Nama kegiatan"></label>
                                <label class="treasurer-field">Hari / Tanggal<input type="date" name="penggunaan[<?php echo (int)$activityIndex; ?>][tanggal]" value="<?php echo htmlspecialchars(substr((string)($activity['tanggal'] ?? ''), 0, 10)); ?>"></label>
                                <label class="treasurer-field">Sumber Dana<input type="text" name="penggunaan[<?php echo (int)$activityIndex; ?>][sumber_dana]" value="<?php echo htmlspecialchars((string)($activity['sumber_dana'] ?? '')); ?>" placeholder="Contoh: Kas BPM, iuran, sponsor"></label>
                            </div>
                            <div class="table-responsive activity-budget-wrap">
                                <table class="admin-table proker-budget-table activity-budget-table">
                                    <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Uraian Transaksi</th><th>Debet (Pemasukan)</th><th>Kredit (Pengeluaran)</th><th>Saldo</th><th class="action-column">Aksi</th></tr></thead>
                                    <tbody class="activity-transactions">
                                        <?php foreach ($activityTransactions as $transactionIndex => $transaction): ?>
                                            <tr class="activity-transaction-row">
                                                <td><input type="date" name="penggunaan[<?php echo (int)$activityIndex; ?>][transaksi][tanggal][]" value="<?php echo htmlspecialchars(substr((string)($transaction['tanggal'] ?? ''), 0, 10)); ?>"></td>
                                                <td><input type="text" name="penggunaan[<?php echo (int)$activityIndex; ?>][transaksi][keterangan][]" value="<?php echo htmlspecialchars((string)($transaction['keterangan'] ?? '')); ?>" placeholder="Keterangan"></td>
                                                <td><input type="text" name="penggunaan[<?php echo (int)$activityIndex; ?>][transaksi][uraian][]" value="<?php echo htmlspecialchars((string)($transaction['uraian'] ?? '')); ?>" placeholder="Uraian transaksi"></td>
                                                <td><input type="number" min="0" step="1" class="budget-debet" name="penggunaan[<?php echo (int)$activityIndex; ?>][transaksi][debet][]" value="<?php echo htmlspecialchars(($transaction['debet'] ?? null) === null ? '' : (string)$transaction['debet']); ?>" placeholder="0"></td>
                                                <td><input type="number" min="0" step="1" class="budget-kredit" name="penggunaan[<?php echo (int)$activityIndex; ?>][transaksi][kredit][]" value="<?php echo htmlspecialchars(($transaction['kredit'] ?? null) === null ? '' : (string)$transaction['kredit']); ?>" placeholder="0"></td>
                                                <td class="activity-balance">Rp 0</td>
                                                <td><button type="button" class="btn-row-delete transaction-remove" title="Hapus transaksi"><i class="fas fa-trash-alt"></i></button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr><th colspan="5">TOTAL PENGELUARAN</th><th class="activity-total-expense">Rp 0</th><th></th></tr></tfoot>
                                </table>
                            </div>
                            <button type="button" class="btn-add-row activity-add-transaction"><i class="fas fa-plus"></i> Tambah Transaksi</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-row" id="addActivity"><i class="fas fa-plus"></i> Tambah Penggunaan Dana</button>
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

const activityContainer = document.getElementById('activityCards');
let nextActivityIndex = activityContainer.querySelectorAll('[data-activity-card]').length;
const formatRupiah = (value) => `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value || 0)}`;

function updateActivityBalance(card) {
    let balance = 0;
    let totalExpense = 0;
    card.querySelectorAll('.activity-transaction-row').forEach((row) => {
        const debet = Number(row.querySelector('.budget-debet').value) || 0;
        const kredit = Number(row.querySelector('.budget-kredit').value) || 0;
        balance += debet - kredit;
        totalExpense += kredit;
        row.querySelector('.activity-balance').textContent = formatRupiah(balance);
    });
    card.querySelector('.activity-total-expense').textContent = formatRupiah(totalExpense);
}

function addActivityTransaction(card, values = {}) {
    const body = card.querySelector('.activity-transactions');
    const index = card.dataset.index;
    const row = document.createElement('tr');
    row.className = 'activity-transaction-row';
    const fields = [
        ['tanggal', 'date', values.tanggal || '', ''],
        ['keterangan', 'text', values.keterangan || '', 'Keterangan'],
        ['uraian', 'text', values.uraian || '', 'Uraian transaksi'],
        ['debet', 'number', values.debet ?? '', '0'],
        ['kredit', 'number', values.kredit ?? '', '0']
    ];
    fields.forEach(([field, type, value, placeholder]) => {
        const cell = document.createElement('td');
        const input = document.createElement('input');
        input.type = type;
        input.name = `penggunaan[${index}][transaksi][${field}][]`;
        input.value = value;
        if (placeholder) input.placeholder = placeholder;
        if (type === 'number') {
            input.min = '0';
            input.step = '1';
            input.className = field === 'debet' ? 'budget-debet' : 'budget-kredit';
        }
        cell.appendChild(input);
        row.appendChild(cell);
    });
    const balanceCell = document.createElement('td');
    balanceCell.className = 'activity-balance';
    balanceCell.textContent = 'Rp 0';
    row.appendChild(balanceCell);
    const actionCell = document.createElement('td');
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'btn-row-delete transaction-remove';
    removeButton.title = 'Hapus transaksi';
    removeButton.innerHTML = '<i class="fas fa-trash-alt"></i>';
    actionCell.appendChild(removeButton);
    row.appendChild(actionCell);
    body.appendChild(row);
    updateActivityBalance(card);
}

function addActivityCard() {
    const index = nextActivityIndex++;
    const card = document.createElement('article');
    card.className = 'activity-card';
    card.dataset.activityCard = '';
    card.dataset.index = String(index);
    const key = `manual-${Date.now()}-${index}`;
    card.innerHTML = `
        <div class="activity-card-header">
            <span class="activity-number"></span><span class="activity-source-badge is-manual">Manual</span>
            <button type="button" class="activity-toggle" aria-expanded="true" title="Minimalkan kegiatan"><i class="fas fa-chevron-up"></i></button>
            <button type="button" class="btn-row-delete activity-remove" title="Hapus kegiatan"><i class="fas fa-trash-alt"></i><span>Hapus</span></button>
        </div>
        <div class="activity-card-body">
            <input type="hidden" name="penggunaan[${index}][source_type]" value="manual">
            <input type="hidden" name="penggunaan[${index}][source_lpj_id]" value="">
            <input type="hidden" name="penggunaan[${index}][source_activity_key]" value="${key}">
            <input type="hidden" name="penggunaan[${index}][keterangan]" value="">
            <div class="activity-meta-grid">
                <label class="treasurer-field">Nama Kegiatan<input type="text" name="penggunaan[${index}][nama_kegiatan]" placeholder="Nama kegiatan"></label>
                <label class="treasurer-field">Hari / Tanggal<input type="date" name="penggunaan[${index}][tanggal]"></label>
                <label class="treasurer-field">Sumber Dana<input type="text" name="penggunaan[${index}][sumber_dana]" placeholder="Contoh: Kas BPM, iuran, sponsor"></label>
            </div>
            <div class="table-responsive activity-budget-wrap">
                <table class="admin-table proker-budget-table activity-budget-table">
                    <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Uraian Transaksi</th><th>Debet (Pemasukan)</th><th>Kredit (Pengeluaran)</th><th>Saldo</th><th class="action-column">Aksi</th></tr></thead>
                    <tbody class="activity-transactions"></tbody>
                    <tfoot><tr><th colspan="5">TOTAL PENGELUARAN</th><th class="activity-total-expense">Rp 0</th><th></th></tr></tfoot>
                </table>
            </div>
            <button type="button" class="btn-add-row activity-add-transaction"><i class="fas fa-plus"></i> Tambah Transaksi</button>
        </div>`;
    activityContainer.appendChild(card);
    bindActivityCard(card);
    addActivityTransaction(card);
    updateActivityNumbers();
    return card;
}

function updateActivityNumbers() {
    activityContainer.querySelectorAll('[data-activity-card]').forEach((card, index) => {
        const label = card.querySelector('.activity-number');
        if (label) label.textContent = `Kegiatan ${index + 1}`;
    });
}

function bindActivityCard(card) {
    card.querySelector('.activity-toggle').addEventListener('click', (event) => {
        const button = event.currentTarget;
        const collapsed = card.classList.toggle('is-collapsed');
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.title = collapsed ? 'Buka kegiatan' : 'Minimalkan kegiatan';
        button.innerHTML = `<i class="fas fa-chevron-${collapsed ? 'down' : 'up'}"></i>`;
    });
    card.querySelector('.activity-remove').addEventListener('click', () => {
        card.remove();
        updateActivityNumbers();
    });
    card.querySelector('.activity-add-transaction').addEventListener('click', () => addActivityTransaction(card));
    card.querySelector('.activity-transactions').addEventListener('input', () => updateActivityBalance(card));
    card.querySelector('.activity-transactions').addEventListener('click', (event) => {
        const remove = event.target.closest('.transaction-remove');
        if (remove) {
            remove.closest('tr').remove();
            updateActivityBalance(card);
        }
    });
    const dateInput = card.querySelector('.activity-meta-grid input[type="date"]');
    dateInput.addEventListener('change', () => {
        const cards = Array.from(activityContainer.querySelectorAll('[data-activity-card]'));
        cards.sort((a, b) => {
            const first = a.querySelector('.activity-meta-grid input[type="date"]').value;
            const second = b.querySelector('.activity-meta-grid input[type="date"]').value;
            if (!first && second) return 1;
            if (first && !second) return -1;
            return first.localeCompare(second);
        });
        cards.forEach((item) => activityContainer.appendChild(item));
        updateActivityNumbers();
    });
    card.querySelector('.activity-transactions').addEventListener('change', (event) => {
        if (event.target.matches('input[type="date"]')) {
            const body = card.querySelector('.activity-transactions');
            const rows = Array.from(body.querySelectorAll('.activity-transaction-row'));
            rows.sort((a, b) => {
                const first = a.querySelector('input[type="date"]').value;
                const second = b.querySelector('input[type="date"]').value;
                if (!first && second) return 1;
                if (first && !second) return -1;
                return first.localeCompare(second);
            });
            rows.forEach((row) => body.appendChild(row));
            updateActivityBalance(card);
        }
    });
}

activityContainer.querySelectorAll('[data-activity-card]').forEach((card) => {
    bindActivityCard(card);
    if (!card.querySelector('.activity-transaction-row')) addActivityTransaction(card);
    else updateActivityBalance(card);
});
document.getElementById('addActivity').addEventListener('click', () => addActivityCard());

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

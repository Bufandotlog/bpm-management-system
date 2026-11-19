<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
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
        return null;
    };

    $lpjRows = $pdo->query(
        'SELECT id, periode_id, triwulan FROM lpj_bendahara ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $commissionRows = $pdo->query(
        "SELECT l.id, l.periode_id, l.kementerian_id, l.triwulan, l.proker_terlaksana
         FROM lpj_dokumen l
         JOIN kementerian k ON k.id = l.kementerian_id
         WHERE l.status = 'submitted'
         ORDER BY l.periode_id, k.urutan, k.id, l.triwulan, l.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $commissionRowsByPeriodQuarter = [];
    foreach ($commissionRows as $commission) {
        $key = (int)$commission['periode_id'] . ':' . strtoupper((string)$commission['triwulan']);
        $commissionRowsByPeriodQuarter[$key][] = $commission;
    }

    $findUsage = $pdo->prepare(
        "SELECT id, source_lpj_id, source_activity_key, keterangan
         FROM lpj_bendahara_penggunaan_dana
         WHERE lpj_bendahara_id = ?
           AND source_type = 'komisi'
           AND source_activity_key LIKE 'komisi-%'
           AND source_activity_key NOT LIKE 'komisi-lpj-%'"
    );
    $findTransactions = $pdo->prepare(
        'SELECT id, tanggal, jenis, uraian, jumlah, debet, kredit
         FROM lpj_bendahara_transaksi_dana
         WHERE penggunaan_dana_id = ?
           AND (keterangan IS NULL OR keterangan = \'\')
         ORDER BY id'
    );
    $restoreRemark = $pdo->prepare(
        'UPDATE lpj_bendahara_transaksi_dana
         SET keterangan = ?
         WHERE id = ? AND (keterangan IS NULL OR keterangan = \'\')'
    );

    foreach ($lpjRows as $treasurerLpj) {
        $quarter = strtoupper((string)$treasurerLpj['triwulan']);
        $commissionQuarters = $quarter === 'MUBESMA' ? ['I', 'II'] : [$quarter];
        $commissions = [];
        $usedKeys = [];
        foreach ($commissionQuarters as $commissionQuarter) {
            $periodQuarterKey = (int)$treasurerLpj['periode_id'] . ':' . $commissionQuarter;
            foreach ($commissionRowsByPeriodQuarter[$periodQuarterKey] ?? [] as $commission) {
                $prokers = json_decode((string)($commission['proker_terlaksana'] ?? ''), true);
                if (!is_array($prokers)) {
                    continue;
                }
                foreach ($prokers as $index => $proker) {
                    if (!is_array($proker) || !empty($proker['tidak_menggunakan_anggaran'])) {
                        continue;
                    }
                    $budget = $proker['anggaran'] ?? [];
                    if (!is_array($budget) || !$budget) {
                        continue;
                    }
                    $activityName = trim((string)($proker['Nama Kegiatan'] ?? $proker['Nama Program Kerja'] ?? ''));
                    $activityKey = trim((string)($proker['source_activity_key'] ?? ''));
                    if ($activityKey === '') {
                        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($activityName ?: 'kegiatan'));
                        $activityKey = 'komisi-' . (int)$commission['kementerian_id'] . '-' . trim((string)$slug, '-') . '-' . ($index + 1);
                    }
                    if (isset($usedKeys[$activityKey])) {
                        $activityKey .= '-' . (int)$commission['id'];
                    }
                    $usedKeys[$activityKey] = true;

                    $activityDate = $normalizeDate($proker['Tanggal Kegiatan'] ?? '');
                    $expected = [];
                    foreach ($budget as $transaction) {
                        if (!is_array($transaction)) {
                            continue;
                        }
                        $date = $normalizeDate($transaction['tanggal'] ?? '') ?? $activityDate;
                        $description = trim((string)($transaction['uraian'] ?? $transaction['keterangan'] ?? $activityName));
                        $remark = trim((string)($transaction['keterangan'] ?? ''));
                        foreach ([
                            'pemasukan' => (float)($transaction['debet'] ?? 0),
                            'pengeluaran' => (float)($transaction['kredit'] ?? 0),
                        ] as $type => $amount) {
                            if ($amount > 0) {
                                $expected[] = [
                                    'tanggal' => $date,
                                    'jenis' => $type,
                                    'uraian' => $description,
                                    'jumlah' => $amount,
                                    'keterangan' => $remark,
                                ];
                            }
                        }
                    }
                    $commissions[$activityKey] = [
                        'lpj_id' => (int)$commission['id'],
                        'expected' => $expected,
                    ];
                }
            }
        }

        $findUsage->execute([(int)$treasurerLpj['id']]);
        foreach ($findUsage->fetchAll(PDO::FETCH_ASSOC) as $usage) {
            $activityKey = (string)$usage['source_activity_key'];
            $commission = $commissions[$activityKey] ?? null;
            if (!$commission || ($usage['source_lpj_id'] !== null && (int)$usage['source_lpj_id'] !== $commission['lpj_id'])) {
                continue;
            }

            $expectedBySignature = [];
            foreach ($commission['expected'] as $expected) {
                $signature = implode("\0", [
                    (string)($expected['tanggal'] ?? ''),
                    $expected['jenis'],
                    number_format($expected['jumlah'], 2, '.', ''),
                    $expected['uraian'],
                ]);
                $expectedBySignature[$signature][] = $expected['keterangan'];
            }

            $findTransactions->execute([(int)$usage['id']]);
            $transactionRows = $findTransactions->fetchAll(PDO::FETCH_ASSOC);
            $actualSignatures = [];
            foreach ($transactionRows as $transaction) {
                $debet = $transaction['debet'] !== null
                    ? (float)$transaction['debet']
                    : (($transaction['jenis'] ?? '') === 'pemasukan' ? (float)($transaction['jumlah'] ?? 0) : 0.0);
                $kredit = $transaction['kredit'] !== null
                    ? (float)$transaction['kredit']
                    : (($transaction['jenis'] ?? '') === 'pengeluaran' ? (float)($transaction['jumlah'] ?? 0) : 0.0);
                $type = $debet > 0 ? 'pemasukan' : 'pengeluaran';
                $amount = $debet > 0 ? $debet : $kredit;
                $signature = implode("\0", [
                    (string)($transaction['tanggal'] ?? ''),
                    $type,
                    number_format($amount, 2, '.', ''),
                    trim((string)($transaction['uraian'] ?? '')),
                ]);
                $actualSignatures[$signature][] = (int)$transaction['id'];
            }

            foreach ($expectedBySignature as $signature => $remarks) {
                $transactionIds = $actualSignatures[$signature] ?? [];
                if (count($remarks) !== count($transactionIds) || count(array_unique($remarks)) !== 1) {
                    continue;
                }
                foreach ($transactionIds as $transactionId) {
                    $restoreRemark->execute([$remarks[0], $transactionId]);
                }
            }
        }
    }
};

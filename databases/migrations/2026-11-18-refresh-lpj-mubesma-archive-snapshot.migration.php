<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $reports = $pdo->query(
        "SELECT id, periode_id, surat_arsip
         FROM lpj_sekretariat
         WHERE triwulan = 'MUBESMA'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $fetchLetters = $pdo->prepare(
        "SELECT id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan
         FROM arsip_surat
         WHERE periode_id = ? AND jenis_surat IN ('L', 'D', 'M', 'I')
           AND (status_arsip = 'archived' OR status_arsip IS NULL)
         ORDER BY jenis_surat, tanggal_dikirim ASC, id ASC"
    );
    $saveSnapshot = $pdo->prepare(
        'UPDATE lpj_sekretariat SET surat_arsip = ?, file_path = NULL WHERE id = ?'
    );

    foreach ($reports as $report) {
        $snapshot = ['L' => [], 'D' => [], 'M' => [], 'I' => []];
        if (!empty($report['surat_arsip'])) {
            $existingSnapshot = json_decode($report['surat_arsip'], true);
            if (!is_array($existingSnapshot) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Unable to refresh invalid MUBESMA letter snapshot for report ' . (int)$report['id']
                );
            }
            foreach (array_keys($snapshot) as $type) {
                foreach ((array)($existingSnapshot[$type] ?? []) as $letter) {
                    if (is_array($letter) && (int)($letter['archive_id'] ?? 0) <= 0) {
                        $snapshot[$type][] = $letter;
                    }
                }
            }
        }

        $fetchLetters->execute([(int)$report['periode_id']]);
        foreach ($fetchLetters->fetchAll(PDO::FETCH_ASSOC) as $letter) {
            $snapshot[$letter['jenis_surat']][] = [
                'archive_id' => (int)$letter['id'],
                'tanggal_dikirim' => $letter['tanggal_dikirim'],
                'nomor_surat' => $letter['nomor_surat'],
                'perihal' => $letter['perihal'],
                'tujuan' => $letter['tujuan'],
            ];
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode refreshed MUBESMA letter snapshot for report ' . (int)$report['id']
            );
        }
        $saveSnapshot->execute([$json, (int)$report['id']]);
    }
};

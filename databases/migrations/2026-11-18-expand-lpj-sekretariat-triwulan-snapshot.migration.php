<?php
declare(strict_types=1);

// Expand the base LPJ Sekretariat table after its creation migration.
return static function (PDO $pdo): void {
    $columnExists = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute(['lpj_sekretariat', $column]);
        return (int)$statement->fetchColumn() === 1;
    };

    $table = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $table->execute(['lpj_sekretariat']);
    if ((int)$table->fetchColumn() !== 1) {
        throw new RuntimeException('Required table lpj_sekretariat is missing.');
    }

    if (!$columnExists('triwulan')) {
        $pdo->exec(
            "ALTER TABLE `lpj_sekretariat`
             ADD COLUMN `triwulan` varchar(50) NOT NULL DEFAULT 'MUBESMA' AFTER `periode_id`"
        );
    }
    if (!$columnExists('surat_arsip')) {
        $pdo->exec(
            'ALTER TABLE `lpj_sekretariat`
             ADD COLUMN `surat_arsip` longtext DEFAULT NULL AFTER `keanggotaan`'
        );
    }

    $indexes = $pdo->prepare(
        'SELECT index_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name IN (?, ?)
         GROUP BY index_name'
    );
    $indexes->execute([
        'lpj_sekretariat',
        'uq_lpj_sekretariat_periode',
        'uq_lpj_sekretariat_periode_triwulan',
    ]);
    $existingIndexes = $indexes->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('uq_lpj_sekretariat_periode', $existingIndexes, true)) {
        $pdo->exec('ALTER TABLE `lpj_sekretariat` DROP INDEX `uq_lpj_sekretariat_periode`');
    }
    if (!in_array('uq_lpj_sekretariat_periode_triwulan', $existingIndexes, true)) {
        $pdo->exec(
            'ALTER TABLE `lpj_sekretariat`
             ADD UNIQUE KEY `uq_lpj_sekretariat_periode_triwulan` (`periode_id`, `triwulan`)'
        );
    }

    $existingReports = $pdo->query(
        'SELECT id, periode_id FROM lpj_sekretariat WHERE surat_arsip IS NULL'
    )->fetchAll(PDO::FETCH_ASSOC);
    $fetchLetters = $pdo->prepare(
        "SELECT id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan
         FROM arsip_surat
         WHERE periode_id = ? AND jenis_surat IN ('L', 'D', 'M', 'I')
           AND (status_arsip = 'archived' OR status_arsip IS NULL)
         ORDER BY jenis_surat, tanggal_dikirim ASC, id ASC"
    );
    $saveSnapshot = $pdo->prepare('UPDATE lpj_sekretariat SET surat_arsip = ? WHERE id = ?');
    foreach ($existingReports as $report) {
        $snapshot = ['L' => [], 'D' => [], 'M' => [], 'I' => []];
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
            throw new RuntimeException('Unable to backfill LPJ sekretariat letter snapshot.');
        }
        $saveSnapshot->execute([$json, (int)$report['id']]);
    }

    $index = $pdo->prepare(
        'SELECT column_name, non_unique FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?
         ORDER BY seq_in_index'
    );
    $index->execute(['lpj_sekretariat', 'uq_lpj_sekretariat_periode_triwulan']);
    $indexRows = $index->fetchAll(PDO::FETCH_ASSOC);
    if (
        count($indexRows) !== 2
        || array_column($indexRows, 'column_name') !== ['periode_id', 'triwulan']
        || (int)$indexRows[0]['non_unique'] !== 0
        || (int)$indexRows[1]['non_unique'] !== 0
    ) {
        throw new RuntimeException('Unique LPJ sekretariat periode/triwulan index is missing or invalid.');
    }
};

<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() === 1;
    };

    $constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?'
        );
        $statement->execute([$table, $constraint]);
        return (int)$statement->fetchColumn() === 1;
    };

    $indexExists = static function (string $table, string $index) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $statement->execute([$table, $index]);
        return (int)$statement->fetchColumn() > 0;
    };

    foreach ([
        'source_type' => "enum('komisi','manual') NOT NULL DEFAULT 'manual' AFTER `lpj_bendahara_id`",
        'source_lpj_id' => 'int(11) DEFAULT NULL AFTER `source_type`',
    ] as $column => $definition) {
        if (!$columnExists('lpj_bendahara_penggunaan_dana', $column)) {
            $pdo->exec("ALTER TABLE `lpj_bendahara_penggunaan_dana` ADD COLUMN `{$column}` {$definition}");
        }
    }
    if (!$indexExists('lpj_bendahara_penggunaan_dana', 'idx_lpj_bendahara_penggunaan_source')) {
        $pdo->exec(
            'ALTER TABLE `lpj_bendahara_penggunaan_dana`
             ADD KEY `idx_lpj_bendahara_penggunaan_source` (`lpj_bendahara_id`, `source_type`, `source_lpj_id`)'
        );
    }

    foreach ([
        'penggunaan_dana_id' => 'int(11) DEFAULT NULL AFTER `lpj_bendahara_id`',
        'keterangan' => 'text DEFAULT NULL AFTER `tanggal`',
        'debet' => 'decimal(15,2) DEFAULT NULL AFTER `jumlah`',
        'kredit' => 'decimal(15,2) DEFAULT NULL AFTER `debet`',
    ] as $column => $definition) {
        if (!$columnExists('lpj_bendahara_transaksi_dana', $column)) {
            $pdo->exec("ALTER TABLE `lpj_bendahara_transaksi_dana` ADD COLUMN `{$column}` {$definition}");
        }
    }

    $pdo->exec(
        "UPDATE lpj_bendahara_penggunaan_dana
         SET source_type = 'komisi'
         WHERE source_type = 'manual' AND source_activity_key LIKE 'komisi-%'"
    );
    $pdo->exec(
        "UPDATE lpj_bendahara_transaksi_dana t
         JOIN (
             SELECT lpj_bendahara_id, source_activity_key, MIN(id) AS usage_id
             FROM lpj_bendahara_penggunaan_dana
             WHERE source_activity_key IS NOT NULL
             GROUP BY lpj_bendahara_id, source_activity_key
             HAVING COUNT(*) = 1
         ) unique_usage
           ON unique_usage.lpj_bendahara_id = t.lpj_bendahara_id
          AND unique_usage.source_activity_key = t.source_activity_key
         JOIN lpj_bendahara_penggunaan_dana u ON u.id = unique_usage.usage_id
         SET t.penggunaan_dana_id = u.id,
             t.keterangan = COALESCE(t.keterangan, u.keterangan),
             t.debet = CASE WHEN t.jenis = 'pemasukan' THEN COALESCE(t.jumlah, 0) ELSE 0 END,
             t.kredit = CASE WHEN t.jenis = 'pengeluaran' THEN COALESCE(t.jumlah, 0) ELSE 0 END
         WHERE t.penggunaan_dana_id IS NULL"
    );
    $pdo->exec(
        "UPDATE lpj_bendahara_transaksi_dana
         SET debet = CASE WHEN jenis = 'pemasukan' THEN COALESCE(jumlah, 0) ELSE 0 END,
             kredit = CASE WHEN jenis = 'pengeluaran' THEN COALESCE(jumlah, 0) ELSE 0 END
         WHERE debet IS NULL OR kredit IS NULL"
    );

    $pdo->exec(
        'UPDATE lpj_bendahara_transaksi_dana t
         LEFT JOIN lpj_bendahara_penggunaan_dana u ON u.id = t.penggunaan_dana_id
         SET t.penggunaan_dana_id = NULL
         WHERE t.penggunaan_dana_id IS NOT NULL AND u.id IS NULL'
    );

    if (!$constraintExists('lpj_bendahara_transaksi_dana', 'fk_lpj_bendahara_transaksi_penggunaan')) {
        if (!$indexExists('lpj_bendahara_transaksi_dana', 'idx_lpj_bendahara_transaksi_penggunaan')) {
            $pdo->exec(
                'ALTER TABLE `lpj_bendahara_transaksi_dana`
                 ADD KEY `idx_lpj_bendahara_transaksi_penggunaan` (`penggunaan_dana_id`)'
            );
        }
        $pdo->exec(
            'ALTER TABLE `lpj_bendahara_transaksi_dana`
             ADD CONSTRAINT `fk_lpj_bendahara_transaksi_penggunaan`
                 FOREIGN KEY (`penggunaan_dana_id`)
                 REFERENCES `lpj_bendahara_penggunaan_dana` (`id`) ON DELETE CASCADE'
        );
    }

    foreach ([
        'lpj_bendahara_penggunaan_dana' => [
            'source_type', 'source_lpj_id',
        ],
        'lpj_bendahara_transaksi_dana' => [
            'penggunaan_dana_id', 'keterangan', 'debet', 'kredit',
        ],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            if (!$columnExists($table, $column)) {
                throw new RuntimeException("Required column {$table}.{$column} is missing.");
            }
        }
    }
};

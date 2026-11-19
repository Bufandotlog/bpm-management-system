<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS `lpj_bendahara` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `periode_id` int(11) NOT NULL,
            `triwulan` varchar(50) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'draft',
            `keadaan_objektif` text DEFAULT NULL,
            `keanggotaan` longtext DEFAULT NULL,
            `penutup` text DEFAULT NULL,
            `file_path` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_lpj_bendahara_periode_triwulan` (`periode_id`, `triwulan`),
            CONSTRAINT `fk_lpj_bendahara_periode`
                FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci",
        "CREATE TABLE IF NOT EXISTS `lpj_bendahara_penggunaan_dana` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lpj_bendahara_id` int(11) NOT NULL,
            `tanggal` date DEFAULT NULL,
            `source_activity_key` varchar(100) DEFAULT NULL,
            `nama_kegiatan` varchar(255) DEFAULT NULL,
            `sumber_dana` varchar(255) DEFAULT NULL,
            `uraian` text DEFAULT NULL,
            `jumlah` decimal(15,2) DEFAULT NULL,
            `keterangan` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lpj_bendahara_penggunaan_date` (`lpj_bendahara_id`, `tanggal`, `id`),
            CONSTRAINT `fk_lpj_bendahara_penggunaan_lpj`
                FOREIGN KEY (`lpj_bendahara_id`) REFERENCES `lpj_bendahara` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci",
        "CREATE TABLE IF NOT EXISTS `lpj_bendahara_transaksi_dana` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lpj_bendahara_id` int(11) NOT NULL,
            `tanggal` date DEFAULT NULL,
            `source_activity_key` varchar(100) DEFAULT NULL,
            `jenis` enum('pemasukan','pengeluaran') DEFAULT NULL,
            `sumber_dana` varchar(255) DEFAULT NULL,
            `uraian` text DEFAULT NULL,
            `jumlah` decimal(15,2) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lpj_bendahara_transaksi_date` (`lpj_bendahara_id`, `tanggal`, `id`),
            CONSTRAINT `fk_lpj_bendahara_transaksi_lpj`
                FOREIGN KEY (`lpj_bendahara_id`) REFERENCES `lpj_bendahara` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci",
        "CREATE TABLE IF NOT EXISTS `lpj_bendahara_rekap_kas` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lpj_bendahara_id` int(11) NOT NULL,
            `tanggal` date DEFAULT NULL,
            `saldo_awal` decimal(15,2) DEFAULT NULL,
            `total_pemasukan` decimal(15,2) DEFAULT NULL,
            `total_pengeluaran` decimal(15,2) DEFAULT NULL,
            `saldo_akhir` decimal(15,2) DEFAULT NULL,
            `keterangan` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lpj_bendahara_rekap_date` (`lpj_bendahara_id`, `tanggal`, `id`),
            CONSTRAINT `fk_lpj_bendahara_rekap_lpj`
                FOREIGN KEY (`lpj_bendahara_id`) REFERENCES `lpj_bendahara` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $requiredColumns = [
        'lpj_bendahara' => ['id', 'periode_id', 'triwulan', 'status', 'keadaan_objektif', 'keanggotaan', 'penutup', 'file_path'],
        'lpj_bendahara_penggunaan_dana' => ['id', 'lpj_bendahara_id', 'tanggal', 'source_activity_key', 'nama_kegiatan', 'sumber_dana', 'uraian', 'jumlah'],
        'lpj_bendahara_transaksi_dana' => ['id', 'lpj_bendahara_id', 'tanggal', 'source_activity_key', 'jenis', 'sumber_dana', 'uraian', 'jumlah'],
        'lpj_bendahara_rekap_kas' => ['id', 'lpj_bendahara_id', 'tanggal', 'saldo_awal', 'total_pemasukan', 'total_pengeluaran', 'saldo_akhir'],
    ];
    $columns = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach ($requiredColumns as $tableName => $expectedColumns) {
        $columns->execute([$tableName]);
        $foundColumns = $columns->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expectedColumns as $columnName) {
            if (!in_array($columnName, $foundColumns, true)) {
                throw new RuntimeException("Required column {$tableName}.{$columnName} is missing.");
            }
        }
    }
};

<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lpj_sekretariat` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `periode_id` int(11) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'draft',
            `keadaan_objektif` text DEFAULT NULL,
            `keanggotaan` longtext DEFAULT NULL,
            `penutup` text DEFAULT NULL,
            `file_path` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_lpj_sekretariat_periode` (`periode_id`),
            CONSTRAINT `fk_lpj_sekretariat_periode`
                FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
    );

    $table = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $table->execute(['lpj_sekretariat']);
    if ((int)$table->fetchColumn() !== 1) {
        throw new RuntimeException('Required table lpj_sekretariat was not created.');
    }

    $columns = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $columns->execute(['lpj_sekretariat']);
    $columnNames = $columns->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'periode_id', 'status', 'keadaan_objektif', 'keanggotaan', 'penutup', 'file_path'] as $requiredColumn) {
        if (!in_array($requiredColumn, $columnNames, true)) {
            throw new RuntimeException("Required column lpj_sekretariat.{$requiredColumn} is missing.");
        }
    }

    $index = $pdo->prepare(
        'SELECT column_name, non_unique FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?
         ORDER BY seq_in_index'
    );
    $index->execute(['lpj_sekretariat', 'uq_lpj_sekretariat_periode']);
    $indexRows = $index->fetchAll(PDO::FETCH_ASSOC);
    if (
        count($indexRows) !== 1
        || (string)$indexRows[0]['column_name'] !== 'periode_id'
        || (int)$indexRows[0]['non_unique'] !== 0
    ) {
        throw new RuntimeException('Required unique index uq_lpj_sekretariat_periode is missing or invalid.');
    }
};

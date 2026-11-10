<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $table = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $table->execute(['lampiran_pinjam']);
    if ((int)$table->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Required table lampiran_pinjam is missing; initialize the baseline MySQL schema first.'
        );
    }

    $columns = $pdo->prepare(
        'SELECT data_type, is_nullable FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $columns->execute(['lampiran_pinjam', 'kegiatan_id']);
    $column = $columns->fetch(PDO::FETCH_ASSOC);
    if ($column === false) {
        $pdo->exec(
            'ALTER TABLE `lampiran_pinjam`
             ADD COLUMN `kegiatan_id` int(11) DEFAULT NULL AFTER `id`'
        );
    } elseif (
        strtolower((string)$column['data_type']) !== 'int'
        || strtoupper((string)$column['is_nullable']) !== 'YES'
    ) {
        throw new RuntimeException(
            'Column lampiran_pinjam.kegiatan_id exists with an unexpected definition.'
        );
    }

    $index = $pdo->prepare(
        'SELECT column_name, non_unique
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?
         ORDER BY seq_in_index'
    );
    $index->execute(['lampiran_pinjam', 'idx_lampiran_pinjam_kegiatan_periode']);
    $indexRows = $index->fetchAll(PDO::FETCH_ASSOC);
    $indexColumns = array_map(
        static fn(array $row): string => (string)$row['column_name'],
        $indexRows
    );

    if ($indexColumns === []) {
        $pdo->exec(
            'ALTER TABLE `lampiran_pinjam`
             ADD KEY `idx_lampiran_pinjam_kegiatan_periode` (`kegiatan_id`, `periode_id`)'
        );
    } elseif (
        $indexColumns !== ['kegiatan_id', 'periode_id']
        || array_reduce(
            $indexRows,
            static fn(bool $isNonUnique, array $row): bool =>
                $isNonUnique && (int)$row['non_unique'] === 1,
            true
        ) === false
    ) {
        throw new RuntimeException(
            'Index idx_lampiran_pinjam_kegiatan_periode exists with an unexpected definition.'
        );
    }

    $pdo->exec(
        'UPDATE `lampiran_pinjam` lp
         JOIN (
            SELECT `periode_id`,
                   LOWER(TRIM(`nama_kegiatan`)) COLLATE utf8mb4_bin AS `nama_normal`,
                   MIN(`id`) AS `kegiatan_id`
            FROM `kegiatan`
            GROUP BY `periode_id`,
                     LOWER(TRIM(`nama_kegiatan`)) COLLATE utf8mb4_bin
            HAVING COUNT(*) = 1
        ) k
          ON k.`periode_id` = lp.`periode_id`
         AND k.`nama_normal` = LOWER(TRIM(lp.`nama_acara`)) COLLATE utf8mb4_bin
        SET lp.`kegiatan_id` = k.`kegiatan_id`
        WHERE lp.`kegiatan_id` IS NULL'
    );
};

<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lpj_ketua_umum` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `periode_id` int(11) NOT NULL,
            `triwulan` varchar(50) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'draft',
            `keadaan_objektif` text DEFAULT NULL,
            `keanggotaan` longtext DEFAULT NULL,
            `visi` text DEFAULT NULL,
            `misi` longtext DEFAULT NULL,
            `pencapaian_visi_misi` longtext DEFAULT NULL,
            `realisasi_program_kerja` longtext DEFAULT NULL,
            `penutup` text DEFAULT NULL,
            `file_path` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_lpj_ketua_umum_periode_triwulan` (`periode_id`, `triwulan`),
            CONSTRAINT `fk_lpj_ketua_umum_periode`
                FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci"
    );
};

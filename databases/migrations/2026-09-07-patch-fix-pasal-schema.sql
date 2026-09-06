-- Patch schema: hukum_pasal + hukum_pasal_versi ke Revisi 8 spec
-- Drop & recreate karena kolom salah total (ORMawa-flavored masih tersisa)

DROP TABLE IF EXISTS hukum_pasal_versi;
DROP TABLE IF EXISTS hukum_pasal;

CREATE TABLE `hukum_pasal` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bab_id` INT NULL,
    `dokumen_id` INT NOT NULL,
    `urutan` INT NOT NULL,
    `judul_pasal` VARCHAR(255) NULL COMMENT 'Revisi 7 Sep 2026: Judul/nama pasal untuk tooltip & search result publik.',
    FOREIGN KEY (`bab_id`) REFERENCES `hukum_bab`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`dokumen_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `hukum_pasal_versi` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pasal_id` INT NOT NULL,
    `isi` JSON NOT NULL COMMENT 'Schema JSON final: {teks_utama, ayat[], bagian[], penjelasan, poin, format}',
    `status` ENUM('draft', 'staged', 'committed', 'digantikan') DEFAULT 'draft',
    `hash_konten` VARCHAR(64) NOT NULL COMMENT 'SHA-256 canonical hash (sort keys, minify)',
    `dibuat_oleh` INT NOT NULL COMMENT 'FK ke users.id',
    `meja_kerja_id` INT NOT NULL COMMENT 'FK ke hukum_meja_kerja.id',
    `dibuat_dari_versi_id` INT NULL COMMENT 'FK ke hukum_pasal_versi.id (parent version)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`pasal_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`meja_kerja_id`) REFERENCES `hukum_meja_kerja`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dibuat_dari_versi_id`) REFERENCES `hukum_pasal_versi`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

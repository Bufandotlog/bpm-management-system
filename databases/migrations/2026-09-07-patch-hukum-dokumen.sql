-- ============================================
-- Migration Patch: hukum_dokumen lama (skeleton ormawa) → schema baru (AD/ART)
-- Tanggal: 2026-09-07
-- Reason:  Tabel hukum_dokumen sudah ada (dari skeleton awal)
--          dengan kolom ormawa (nama_ormawa, gap_pasal, periode_id)
--          tapi belum pernah dipakai. Schema baru AD/ART membutuhkan
--          slug, deskripsi, status, dibuat_oleh, waktu_dibuat, dll.
--          Karena tabel KOSONG (verified via smoke test), DROP aman.
-- ============================================

DROP TABLE IF EXISTS `hukum_dokumen`;

CREATE TABLE `hukum_dokumen` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `judul` VARCHAR(500) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `jenis` ENUM('AD','ART','PB','PERATURAN','KEPUTUSAN') NOT NULL,
  `deskripsi` TEXT NULL,
  `format_mukadimah` ENUM('legacy','json') NOT NULL DEFAULT 'legacy',
  `mukadimah_legacy` LONGTEXT NULL,
  `mukadimah_json` LONGTEXT NULL,
  `status` ENUM('draf','publikasi','arsip') NOT NULL DEFAULT 'draf',
  `dibuat_oleh` INT NULL,
  `waktu_dibuat` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `diperbarui_oleh` INT NULL,
  `waktu_pembaruan` DATETIME NULL,
  INDEX `idx_dokumen_jenis_status` (`jenis`, `status`),
  INDEX `idx_dokumen_status_dibuat` (`status`, `waktu_dibuat`),
  INDEX `idx_dokumen_dibuat_oleh` (`dibuat_oleh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

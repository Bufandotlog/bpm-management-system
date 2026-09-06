-- ============================================
-- Migration Patch: DROP & RECREATE 13 hukum_* tables (schema baru AD/ART)
-- Tanggal: 2026-09-07
-- Reason:  Tabel hukum_* sudah ada (dari skeleton awal/attempt sebelumnya)
--          dengan kolom yang tidak sesuai spec AD/ART Revisi 8.
--          Semua 13 tabel KOSONG (verified via smoke test count).
--          DROP aman, lalu CREATE dari migration utama.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `hukum_bab`;
DROP TABLE IF EXISTS `hukum_commit_otorisasi`;
DROP TABLE IF EXISTS `hukum_commit_window`;
DROP TABLE IF EXISTS `hukum_commit`;
DROP TABLE IF EXISTS `hukum_dokumen`;
DROP TABLE IF EXISTS `hukum_meja_kerja`;
DROP TABLE IF EXISTS `hukum_notifikasi_audit`;
DROP TABLE IF EXISTS `hukum_notifikasi_peninjauan`;
DROP TABLE IF EXISTS `hukum_pasal_versi`;
DROP TABLE IF EXISTS `hukum_pasal`;
DROP TABLE IF EXISTS `hukum_referensi_inline`;
DROP TABLE IF EXISTS `hukum_relasi_pasal`;
DROP TABLE IF EXISTS `hukum_staging`;
SET FOREIGN_KEY_CHECKS = 1;

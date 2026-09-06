-- ============================================
-- Migration: Modul Produk Hukum (AD/ART) — Fase 1 (H-0 ~ H-2)
-- Tanggal: 2026-09-07
-- Sumber: proyek/rencana-implementasi-ad-art-bpm/05-skema-db-baru.md
--         (Revisi 8, 7 Sep 2026)
-- Tabel: 10 tabel hukum_* + patch users.role
-- Catatan:
--   - Idempotent: pakai CREATE TABLE IF NOT EXISTS
--   - Tabel cross-reference (hukum_relasi_pasal, notifikasi, audit) dideploy
--     sekarang juga (Fase 1+H6 schema sekaligus) untuk konsistensi FK lintas fase
--   - ENUM 'legacy'/'json' format_mukadimah sudah include sejak patch E revisi 8
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PATCH 1: users.role — tambah 'komisi_i' & 'ketua_umum_bpm'
-- Untuk H-1 (registrasi role baru).
-- ============================================================
ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'superadmin',
    'admin',
    'kominfo',
    'sekretaris',
    'anggota',
    'komisi_i',
    'ketua_umum_bpm'
  ) NOT NULL DEFAULT 'anggota';

-- ============================================================
-- TABEL 1: Identitas Dokumen & BAB (Permanen)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_dokumen` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `jenis` ENUM('induk_ormawa', 'anak_ormawa', 'gbho_ormawa', 'gbmo_ormawa') NOT NULL
    COMMENT 'induk_ormawa=AD/ART ORMAWA; anak_ormawa=AD/ART BEM/BPM/UKM; gbho_ormawa=haluan per-ormawa anak; gbmo_ormawa=pedoman manajemen per-ormawa anak',
  `nama_ormawa` VARCHAR(100) NULL
    COMMENT 'NULL untuk induk_ormawa. WAJIB untuk anak_ormawa/gbho_ormawa/gbmo_ormawa: BEM|BPM|UKM (nama ormawa)',
  `judul` VARCHAR(255) NOT NULL,
  `mukadimah` JSON NULL
    COMMENT 'Format data: JSON (Format 2.0) {paragraf: [{nomor, teks}]} dengan inline formatting **bold**, *italic*, [[PASAL:N]]',
  `format_mukadimah` ENUM('legacy', 'json') NOT NULL DEFAULT 'legacy'
    COMMENT 'Penanda format data mukadimah. legacy=TEXT lawas, json=Format 2.0. Sistem render dual-mode.',
  `gap_pasal` JSON NULL
    COMMENT 'Array integer nomor Pasal yang sengaja dikosongkan. Mis. [9,15].',
  `periode_id` INT NOT NULL,
  `diperbarui_oleh` INT NULL
    COMMENT 'FK ke users.id — admin yang terakhir memperbarui mukadimah via editor',
  `waktu_pembaruan` DATETIME NULL
    COMMENT 'Timestamp terakhir mukadimah diperbarui. NULL = masih original (legacy)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_jenis` (`jenis`),
  INDEX `idx_nama_ormawa` (`nama_ormawa`),
  INDEX `idx_periode` (`periode_id`),
  CONSTRAINT `fk_hukum_dokumen_periode`
    FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_dokumen_diperbarui_oleh`
    FOREIGN KEY (`diperbarui_oleh`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `hukum_bab` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dokumen_id` INT NOT NULL,
  `nomor_label` VARCHAR(50) NOT NULL,
  `judul_bab` VARCHAR(255) NULL,
  `bagian_label` VARCHAR(255) NULL
    COMMENT 'Judul Bagian (mis. "Bagian Kesatu: Hak BPM"). NULL jika BAB tidak punya Bagian',
  `urutan` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_dokumen` (`dokumen_id`),
  INDEX `idx_urutan` (`dokumen_id`, `urutan`),
  CONSTRAINT `fk_hukum_bab_dokumen`
    FOREIGN KEY (`dokumen_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 2: Identitas Pasal (Permanen)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_pasal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bab_id` INT NULL,
  `dokumen_id` INT NOT NULL,
  `urutan` INT NOT NULL,
  `judul_pasal` VARCHAR(255) NULL
    COMMENT 'Judul/nama pasal untuk tooltip & search result publik',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_dokumen` (`dokumen_id`),
  INDEX `idx_bab` (`bab_id`),
  INDEX `idx_urutan` (`dokumen_id`, `urutan`),
  CONSTRAINT `fk_hukum_pasal_bab`
    FOREIGN KEY (`bab_id`) REFERENCES `hukum_bab`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hukum_pasal_dokumen`
    FOREIGN KEY (`dokumen_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 3: Meja Kerja (Workspace)
-- Single Active Workspace: invariant COUNT(status='aktif') = 1
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_meja_kerja` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dokumen_id` INT NOT NULL,
  `judul_perubahan` VARCHAR(255) NOT NULL,
  `dibuat_oleh` INT NOT NULL,
  `status` ENUM('aktif', 'diajukan', 'ditarik') DEFAULT 'aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_dokumen` (`dokumen_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_single_active` (`status`, `dokumen_id`),
  CONSTRAINT `fk_hukum_meja_kerja_dokumen`
    FOREIGN KEY (`dokumen_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_meja_kerja_user`
    FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 4: Staging (Pengajuan ke Forum)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_staging` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `meja_kerja_id` INT NOT NULL,
  `daftar_pasal_versi_id` JSON NOT NULL,
  `status` ENUM('menunggu_forum', 'disetujui', 'ditolak') DEFAULT 'menunggu_forum',
  `diajukan_pada` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_meja_kerja` (`meja_kerja_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_hukum_staging_meja_kerja`
    FOREIGN KEY (`meja_kerja_id`) REFERENCES `hukum_meja_kerja`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 5: Commit (Ketetapan Resmi)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_commit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dokumen_id` INT NOT NULL,
  `hash_commit` VARCHAR(64) NOT NULL UNIQUE,
  `parent_commit_id` INT NULL,
  `staging_id` INT NULL,
  `commit_window_id` INT NULL
    COMMENT 'FK ke hukum_commit_window (Revisi 6). NULL untuk auto-resolve atau bypass.',
  `tanggal_ekspirasi` DATETIME NULL
    COMMENT 'DEPRECATED di Revisi 6: expiry sekarang per-user',
  `forum_tipe` ENUM('MUBESMA', 'MUSLUB', 'LAINNYA') NOT NULL,
  `tanggal_forum` DATE NOT NULL
    COMMENT 'Revisi 7 (6 Sep 2026): cukup tanggal_forum, tidak perlu nomor_sk',
  `snapshot_tree` JSON NOT NULL,
  `status` ENUM('aktif', 'digantikan', 'gagal') DEFAULT 'aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_dokumen` (`dokumen_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_parent` (`parent_commit_id`),
  CONSTRAINT `fk_hukum_commit_dokumen`
    FOREIGN KEY (`dokumen_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_commit_parent`
    FOREIGN KEY (`parent_commit_id`) REFERENCES `hukum_commit`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hukum_commit_staging`
    FOREIGN KEY (`staging_id`) REFERENCES `hukum_staging`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 6: Versi Pasal (Isi Konten) — Immutable setelah committed
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_pasal_versi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pasal_id` INT NOT NULL,
  `isi` JSON NOT NULL,
  `status` ENUM('draft', 'staged', 'committed', 'digantikan') DEFAULT 'draft',
  `hash_konten` VARCHAR(64) NOT NULL,
  `dibuat_oleh` INT NOT NULL,
  `meja_kerja_id` INT NOT NULL,
  `dibuat_dari_versi_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pasal` (`pasal_id`),
  INDEX `idx_meja_kerja` (`meja_kerja_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_hash` (`hash_konten`),
  CONSTRAINT `fk_hukum_pasal_versi_pasal`
    FOREIGN KEY (`pasal_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_pasal_versi_meja_kerja`
    FOREIGN KEY (`meja_kerja_id`) REFERENCES `hukum_meja_kerja`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_pasal_versi_dari`
    FOREIGN KEY (`dibuat_dari_versi_id`) REFERENCES `hukum_pasal_versi`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hukum_pasal_versi_user`
    FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 7: Relasi & Notifikasi Peninjauan (Cross-Reference)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_relasi_pasal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pasal_anak_id` INT NOT NULL,
  `pasal_induk_id` INT NOT NULL,
  `jenis_relasi` ENUM('mengacu', 'berhubungan', 'sequensial', 'induk_anak') NOT NULL DEFAULT 'mengacu',
  `dibuat_oleh` ENUM('auto', 'manual') NOT NULL DEFAULT 'manual'
    COMMENT 'auto=sistem auto-generate; manual=admin buat via UI editor',
  `dibuat_oleh_user_id` INT NULL
    COMMENT 'FK ke users.id — admin yang membuat relasi manual. NULL jika dibuat_oleh=auto',
  `waktu_dibuat` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_anak` (`pasal_anak_id`),
  INDEX `idx_induk` (`pasal_induk_id`),
  INDEX `idx_jenis` (`jenis_relasi`),
  CONSTRAINT `fk_hukum_relasi_anak`
    FOREIGN KEY (`pasal_anak_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_relasi_induk`
    FOREIGN KEY (`pasal_induk_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hukum_relasi_user`
    FOREIGN KEY (`dibuat_oleh_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `hukum_notifikasi_peninjauan` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pasal_anak_id` INT NOT NULL,
  `pasal_induk_id` INT NOT NULL,
  `relasi_id` INT NOT NULL,
  `dipicu_oleh_pasal_versi_id` INT NOT NULL,
  `status` ENUM('perlu_ditinjau', 'sudah_diselaraskan', 'diabaikan_dengan_alasan') DEFAULT 'perlu_ditinjau',
  `catatan` TEXT NULL,
  `diselesaikan_oleh` INT NULL,
  `diselesaikan_pada` DATETIME NULL,
  `pasal_induk_versi_sebelum_id` INT NULL,
  `pasal_induk_versi_sesudah_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_anak` (`pasal_anak_id`),
  INDEX `idx_induk` (`pasal_induk_id`),
  INDEX `idx_relasi` (`relasi_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_notif_anak`
    FOREIGN KEY (`pasal_anak_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_induk`
    FOREIGN KEY (`pasal_induk_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_relasi`
    FOREIGN KEY (`relasi_id`) REFERENCES `hukum_relasi_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_dipicu`
    FOREIGN KEY (`dipicu_oleh_pasal_versi_id`) REFERENCES `hukum_pasal_versi`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_penyelesai`
    FOREIGN KEY (`diselesaikan_oleh`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_sebelum`
    FOREIGN KEY (`pasal_induk_versi_sebelum_id`) REFERENCES `hukum_pasal_versi`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_sesudah`
    FOREIGN KEY (`pasal_induk_versi_sesudah_id`) REFERENCES `hukum_pasal_versi`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 8: Audit Trail Notifikasi
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_notifikasi_audit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `notifikasi_id` INT NOT NULL,
  `aksi` ENUM('dibuat', 'diselesaikan', 'diabaikan', 'diubah_status', 'percobaan_abaikan_gagal', 'auto_resolve_commit_induk') NOT NULL,
  `dilakukan_oleh` INT NOT NULL,
  `catatan` TEXT NULL,
  `dilakukan_pada` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif` (`notifikasi_id`),
  INDEX `idx_aksi` (`aksi`),
  INDEX `idx_notif_waktu` (`notifikasi_id`, `dilakukan_pada`),
  CONSTRAINT `fk_notif_audit_notif`
    FOREIGN KEY (`notifikasi_id`) REFERENCES `hukum_notifikasi_peninjauan`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_audit_user`
    FOREIGN KEY (`dilakukan_oleh`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 9: Referensi Inline (parser [[PASAL:N...]] → broken-ref report)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_referensi_inline` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pasal_asal_id` INT NOT NULL
    COMMENT 'FK ke hukum_pasal.id (pasal yang MEMUAT [[PASAL:N]] di teksnya)',
  `dokumen_tujuan_id` INT NOT NULL
    COMMENT 'FK ke hukum_dokumen.id — bisa sama (internal) atau beda (lintas-dokumen)',
  `pasal_tujuan_nomor` INT NOT NULL
    COMMENT 'Nomor urut pasal tujuan (hukum_pasal.urutan), bukan id',
  `ayat_tujuan_nomor` INT NULL,
  `huruf_tujuan_nomor` VARCHAR(5) NULL,
  `konteks_field` ENUM('teks_utama', 'ayat_teks', 'ayat_penjelasan', 'huruf_teks',
                       'poin_teks', 'penjelasan_pasal', 'bagian_deskripsi', 'mukadimah_paragraf')
    NOT NULL DEFAULT 'teks_utama',
  `status_validasi` ENUM('valid', 'dicabut', 'tidak_ditemukan') NOT NULL DEFAULT 'valid',
  `terakhir_divalidasi` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status_validasi`, `terakhir_divalidasi`),
  INDEX `idx_target` (`dokumen_tujuan_id`, `pasal_tujuan_nomor`),
  INDEX `idx_asal` (`pasal_asal_id`),
  INDEX `idx_asal_field` (`pasal_asal_id`, `konteks_field`),
  CONSTRAINT `fk_ref_inline_asal`
    FOREIGN KEY (`pasal_asal_id`) REFERENCES `hukum_pasal`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_inline_tujuan`
    FOREIGN KEY (`dokumen_tujuan_id`) REFERENCES `hukum_dokumen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 10: Otorisasi Commit (Co-Commit Gate, password verify)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_commit_otorisasi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `commit_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role_saat_commit` ENUM('komisi_i', 'ketua_umum_bpm', 'admin') NOT NULL,
  `password_hash_input` VARCHAR(255) NULL
    COMMENT 'Opsional: hash dari password yg diinput saat commit (untuk audit)',
  `waktu_klik_setuju` DATETIME NULL
    COMMENT 'Timestamp klik tombol "Setuju Commit". NULL kalau belum diklik',
  `alasan_gagal` ENUM('window_expired', 'password_invalid', 'role_salah', 'sudah_dinonaktifkan', 'bukan_user_yang_dimaksud') NULL,
  `waktu_otorisasi` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) NULL,
  `status` ENUM('menunggu', 'disetujui', 'ditolak', 'expired') DEFAULT 'menunggu',
  `catatan` TEXT NULL,
  UNIQUE KEY `uniq_commit_user` (`commit_id`, `user_id`),
  INDEX `idx_commit` (`commit_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_otorisasi_commit`
    FOREIGN KEY (`commit_id`) REFERENCES `hukum_commit`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_otorisasi_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- TABEL 10b: Commit Window Tracker (timer independen per user)
-- ============================================================
CREATE TABLE IF NOT EXISTS `hukum_commit_window` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `commit_id` INT NOT NULL,
  `diinsiasi_oleh_user_id_komisi_i` INT NULL,
  `waktu_inisiasi_komisi_i` DATETIME NULL
    COMMENT 'Waktu user Komisi I klik "Inisiasi Commit". +5s = expiry miliknya',
  `diinsiasi_oleh_user_id_ketum` INT NULL,
  `waktu_inisiasi_ketum` DATETIME NULL
    COMMENT 'Waktu user Ketum BPM klik "Inisiasi Commit". +5s = expiry miliknya',
  `status` ENUM('menunggu_inisiasi_kedua', 'berlangsung', 'sukses', 'gagal') DEFAULT 'menunggu_inisiasi_kedua',
  `gagal_reason` ENUM('window_expired_komisi_i', 'window_expired_ketum', 'password_invalid', 'role_salah', 'sudah_dinonaktifkan') NULL,
  `waktu_dibuat` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_commit` (`commit_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_window_commit`
    FOREIGN KEY (`commit_id`) REFERENCES `hukum_commit`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_window_komisi_i_user`
    FOREIGN KEY (`diinsiasi_oleh_user_id_komisi_i`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_window_ketum_user`
    FOREIGN KEY (`diinsiasi_oleh_user_id_ketum`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- Sekarang FK hukum_commit.commit_window_id bisa resolve (10b dibuat)
-- ============================================================
ALTER TABLE `hukum_commit`
  ADD CONSTRAINT `fk_hukum_commit_window`
    FOREIGN KEY (`commit_window_id`) REFERENCES `hukum_commit_window`(`id`) ON DELETE SET NULL;

-- ============================================================
-- INDEX UNIQUE untuk hash_commit & hash_konten (per verification plan §1)
-- ============================================================
-- hash_commit sudah UNIQUE di CREATE TABLE
-- hash_konten butuh composite unique (pasal_id, hash_konten) agar bisa
-- re-detect konten identik per pasal tanpa memicu duplikasi global
CREATE UNIQUE INDEX `uniq_pasal_hash`
  ON `hukum_pasal_versi` (`pasal_id`, `hash_konten`);

SET FOREIGN_KEY_CHECKS = 1;

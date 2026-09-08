-- Fase 1: Konsolidasi schema modul hukum (MySQL/MariaDB)
-- Migration ini hanya membuat objek yang belum ada. Jangan drop/rename tabel
-- existing otomatis; lakukan inspeksi dan migrasi data terpisah bila tabel lama
-- sudah berisi data.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hukum_dokumen (
  id INT NOT NULL AUTO_INCREMENT,
  periode_id INT NOT NULL,
  jenis ENUM('AD','ART','GBHO','GBMO','PERATURAN','KEPUTUSAN') NOT NULL,
  lingkup ENUM('induk','BEM','BPM','UKM') NOT NULL DEFAULT 'induk',
  nama_ormawa VARCHAR(100) NULL,
  judul VARCHAR(500) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  deskripsi TEXT NULL,
  format_mukadimah ENUM('legacy','json') NOT NULL DEFAULT 'json',
  mukadimah_json JSON NULL,
  mukadimah_legacy LONGTEXT NULL,
  status ENUM('draft','aktif','diarsipkan') NOT NULL DEFAULT 'draft',
  dibuat_oleh INT NOT NULL,
  diperbarui_oleh INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_dokumen_slug (slug),
  KEY idx_hukum_dokumen_periode_status (periode_id, status),
  KEY idx_hukum_dokumen_jenis_lingkup (jenis, lingkup),
  CONSTRAINT fk_hukum_dokumen_periode
    FOREIGN KEY (periode_id) REFERENCES periode_kepengurusan (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_dokumen_dibuat_oleh
    FOREIGN KEY (dibuat_oleh) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_dokumen_diperbarui_oleh
    FOREIGN KEY (diperbarui_oleh) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_bab (
  id INT NOT NULL AUTO_INCREMENT,
  dokumen_id INT NOT NULL,
  nomor_label VARCHAR(50) NOT NULL,
  judul_bab VARCHAR(255) NOT NULL,
  bagian_label VARCHAR(255) NULL,
  urutan INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_bab_urutan (dokumen_id, urutan),
  KEY idx_hukum_bab_dokumen (dokumen_id),
  CONSTRAINT fk_hukum_bab_dokumen
    FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_pasal (
  id INT NOT NULL AUTO_INCREMENT,
  dokumen_id INT NOT NULL,
  bab_id INT NULL,
  nomor_label VARCHAR(50) NOT NULL,
  judul_pasal VARCHAR(255) NULL,
  urutan INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_pasal_nomor (dokumen_id, nomor_label),
  UNIQUE KEY uq_hukum_pasal_urutan (dokumen_id, urutan),
  KEY idx_hukum_pasal_bab (bab_id),
  CONSTRAINT fk_hukum_pasal_dokumen
    FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_pasal_bab
    FOREIGN KEY (bab_id) REFERENCES hukum_bab (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_workspace (
  id INT NOT NULL AUTO_INCREMENT,
  dokumen_id INT NOT NULL,
  judul_perubahan VARCHAR(255) NOT NULL,
  tujuan TEXT NULL,
  status ENUM('aktif','diajukan','ditutup','dibatalkan') NOT NULL DEFAULT 'aktif',
  dibuat_oleh INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closed_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_hukum_workspace_dokumen_status (dokumen_id, status),
  CONSTRAINT fk_hukum_workspace_dokumen
    FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_workspace_dibuat_oleh
    FOREIGN KEY (dibuat_oleh) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_workspace_closed_by
    FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_pasal_versi (
  id INT NOT NULL AUTO_INCREMENT,
  pasal_id INT NOT NULL,
  workspace_id INT NOT NULL,
  isi JSON NOT NULL,
  hash_konten CHAR(64) NOT NULL,
  status ENUM('draft','staged','committed','replaced','rejected') NOT NULL DEFAULT 'draft',
  dibuat_oleh INT NOT NULL,
  dibuat_dari_versi_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  rejected_at DATETIME NULL,
  rejected_by INT NULL,
  rejection_reason TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_versi_pasal_hash (pasal_id, hash_konten),
  KEY idx_hukum_versi_pasal_status (pasal_id, status),
  KEY idx_hukum_versi_workspace (workspace_id),
  CONSTRAINT fk_hukum_versi_pasal
    FOREIGN KEY (pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_versi_workspace
    FOREIGN KEY (workspace_id) REFERENCES hukum_workspace (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_versi_parent
    FOREIGN KEY (dibuat_dari_versi_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL,
  CONSTRAINT fk_hukum_versi_dibuat_oleh
    FOREIGN KEY (dibuat_oleh) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_versi_rejected_by
    FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_staging (
  id INT NOT NULL AUTO_INCREMENT,
  workspace_id INT NOT NULL,
  status ENUM('menunggu_review','disetujui','ditolak','dibatalkan') NOT NULL DEFAULT 'menunggu_review',
  diajukan_oleh INT NOT NULL,
  diajukan_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  direview_oleh INT NULL,
  direview_at DATETIME NULL,
  review_note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_hukum_staging_workspace_status (workspace_id, status),
  CONSTRAINT fk_hukum_staging_workspace
    FOREIGN KEY (workspace_id) REFERENCES hukum_workspace (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_staging_diajukan_oleh
    FOREIGN KEY (diajukan_oleh) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_staging_direview_oleh
    FOREIGN KEY (direview_oleh) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_staging_versi (
  staging_id INT NOT NULL,
  pasal_versi_id INT NOT NULL,
  PRIMARY KEY (staging_id, pasal_versi_id),
  CONSTRAINT fk_hukum_staging_versi_staging
    FOREIGN KEY (staging_id) REFERENCES hukum_staging (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_staging_versi_versi
    FOREIGN KEY (pasal_versi_id) REFERENCES hukum_pasal_versi (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_commit (
  id INT NOT NULL AUTO_INCREMENT,
  dokumen_id INT NOT NULL,
  staging_id INT NOT NULL,
  parent_commit_id INT NULL,
  hash_commit CHAR(64) NOT NULL,
  snapshot_tree JSON NOT NULL,
  forum_tipe VARCHAR(50) NOT NULL,
  tanggal_forum DATE NOT NULL,
  status ENUM('aktif','digantikan','gagal') NOT NULL DEFAULT 'aktif',
  dibuat_oleh INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  replaced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_commit_hash (hash_commit),
  KEY idx_hukum_commit_dokumen_status (dokumen_id, status),
  CONSTRAINT fk_hukum_commit_dokumen
    FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_commit_staging
    FOREIGN KEY (staging_id) REFERENCES hukum_staging (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_commit_parent
    FOREIGN KEY (parent_commit_id) REFERENCES hukum_commit (id) ON DELETE SET NULL,
  CONSTRAINT fk_hukum_commit_dibuat_oleh
    FOREIGN KEY (dibuat_oleh) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_commit_approval (
  id INT NOT NULL AUTO_INCREMENT,
  commit_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(50) NOT NULL,
  status ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  note TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_commit_approval_user (commit_id, user_id),
  KEY idx_hukum_commit_approval_status (commit_id, status),
  CONSTRAINT fk_hukum_commit_approval_commit
    FOREIGN KEY (commit_id) REFERENCES hukum_commit (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_commit_approval_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_relasi_pasal (
  id INT NOT NULL AUTO_INCREMENT,
  pasal_anak_id INT NOT NULL,
  pasal_induk_id INT NOT NULL,
  jenis_relasi ENUM('mengacu','berhubungan','induk_anak') NOT NULL DEFAULT 'mengacu',
  dibuat_oleh ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  dibuat_oleh_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_relasi (pasal_anak_id, pasal_induk_id, jenis_relasi),
  KEY idx_hukum_relasi_induk (pasal_induk_id),
  CONSTRAINT fk_hukum_relasi_anak
    FOREIGN KEY (pasal_anak_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_relasi_induk
    FOREIGN KEY (pasal_induk_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_relasi_user
    FOREIGN KEY (dibuat_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_notifikasi (
  id INT NOT NULL AUTO_INCREMENT,
  relasi_id INT NOT NULL,
  pasal_anak_id INT NOT NULL,
  pasal_induk_id INT NOT NULL,
  dipicu_oleh_versi_id INT NOT NULL,
  status ENUM('perlu_ditinjau','sudah_diselaraskan','diabaikan_dengan_alasan') NOT NULL DEFAULT 'perlu_ditinjau',
  catatan TEXT NULL,
  diselesaikan_oleh INT NULL,
  diselesaikan_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hukum_notifikasi_status (status),
  KEY idx_hukum_notifikasi_relasi (relasi_id),
  CONSTRAINT fk_hukum_notifikasi_relasi
    FOREIGN KEY (relasi_id) REFERENCES hukum_relasi_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_notifikasi_anak
    FOREIGN KEY (pasal_anak_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_notifikasi_induk
    FOREIGN KEY (pasal_induk_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_notifikasi_versi
    FOREIGN KEY (dipicu_oleh_versi_id) REFERENCES hukum_pasal_versi (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_notifikasi_penyelesai
    FOREIGN KEY (diselesaikan_oleh) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  entitas VARCHAR(80) NOT NULL,
  entitas_id BIGINT NOT NULL,
  aksi VARCHAR(50) NOT NULL,
  aktor_id INT NOT NULL,
  sebelum_json JSON NULL,
  sesudah_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hukum_audit_entitas (entitas, entitas_id),
  KEY idx_hukum_audit_aktor_waktu (aktor_id, created_at),
  CONSTRAINT fk_hukum_audit_aktor
    FOREIGN KEY (aktor_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_referensi_inline (
  id INT NOT NULL AUTO_INCREMENT,
  pasal_asal_id INT NOT NULL,
  dokumen_tujuan_id INT NOT NULL,
  pasal_tujuan_nomor VARCHAR(50) NOT NULL,
  ayat_tujuan_nomor INT NULL,
  konteks_field VARCHAR(50) NOT NULL DEFAULT 'teks_utama',
  status_validasi ENUM('valid','dicabut','tidak_ditemukan') NOT NULL DEFAULT 'valid',
  terakhir_divalidasi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_referensi (pasal_asal_id, dokumen_tujuan_id, pasal_tujuan_nomor, ayat_tujuan_nomor),
  KEY idx_hukum_referensi_status (status_validasi),
  CONSTRAINT fk_hukum_referensi_asal
    FOREIGN KEY (pasal_asal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_referensi_tujuan
    FOREIGN KEY (dokumen_tujuan_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

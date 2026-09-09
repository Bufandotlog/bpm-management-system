-- Session 1: Hukum database governance
-- This file is the canonical SQL intent for the governance layer.
-- The runtime migration runner (`2026-09-09-hukum-governance.php`) performs the
-- safe, idempotent checks against the live database and refuses to proceed if
-- duplicate historical rows would break uniqueness.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hukum_keanggotaan (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  periode_id INT NOT NULL,
  jabatan ENUM('komisi_i','ketua_umum') NOT NULL,
  mulai_pada DATE NOT NULL,
  selesai_pada DATE NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_keanggotaan_user_periode_jabatan (user_id, periode_id, jabatan),
  KEY idx_hukum_keanggotaan_periode_aktif (periode_id, aktif, jabatan),
  CONSTRAINT fk_hukum_keanggotaan_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hukum_keanggotaan_periode
    FOREIGN KEY (periode_id) REFERENCES periode_kepengurusan (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_staging_approval (
  id INT NOT NULL AUTO_INCREMENT,
  staging_id INT NOT NULL,
  user_id INT NOT NULL,
  peran ENUM('komisi_i','ketua_umum') NOT NULL,
  status ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  note TEXT NULL,
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_staging_approval_slot (staging_id, peran),
  KEY idx_hukum_staging_approval_status (staging_id, status),
  CONSTRAINT fk_hukum_staging_approval_staging
    FOREIGN KEY (staging_id) REFERENCES hukum_staging (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_staging_approval_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_commit_window (
  id BIGINT NOT NULL AUTO_INCREMENT,
  commit_id INT NULL,
  user_id INT NOT NULL,
  peran ENUM('komisi_i','ketua_umum') NOT NULL,
  session_id VARCHAR(128) NULL,
  status ENUM('pending','in_progress','approved','expired','rejected','locked') NOT NULL DEFAULT 'pending',
  initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  result VARCHAR(32) NULL,
  request_id VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_commit_window_user_commit_peran (user_id, commit_id, peran),
  KEY idx_hukum_commit_window_expires (expires_at, status),
  CONSTRAINT fk_hukum_commit_window_commit
    FOREIGN KEY (commit_id) REFERENCES hukum_commit (id) ON DELETE SET NULL,
  CONSTRAINT fk_hukum_commit_window_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_commit_lockout (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  session_id VARCHAR(128) NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_reason VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_commit_lockout_user_session (user_id, session_id),
  KEY idx_hukum_commit_lockout_until (locked_until),
  CONSTRAINT fk_hukum_commit_lockout_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Enforce one in-flight workspace for the full lifecycle. The generated slot is
-- NULL for terminal states, allowing historical workspaces to coexist.
ALTER TABLE hukum_workspace
  ADD COLUMN IF NOT EXISTS active_slot TINYINT AS (
    CASE
      WHEN status IN ('aktif','diajukan','siap_commit') THEN 1
      ELSE NULL
    END
  ) STORED;

CREATE UNIQUE INDEX IF NOT EXISTS uq_hukum_workspace_active_slot
  ON hukum_workspace (dokumen_id, active_slot);

ALTER TABLE hukum_audit_log
  ADD COLUMN role_context VARCHAR(50) NULL AFTER aktor_id,
  ADD COLUMN periode_id INT NULL AFTER role_context,
  ADD COLUMN request_id VARCHAR(100) NULL AFTER periode_id,
  ADD COLUMN result VARCHAR(20) NOT NULL DEFAULT 'success' AFTER request_id,
  ADD COLUMN context_json TEXT NULL AFTER result;

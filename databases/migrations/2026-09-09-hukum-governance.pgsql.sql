-- Session 1: Hukum database governance for PostgreSQL
-- This file documents the intended Postgres schema for the governance layer.

CREATE TABLE IF NOT EXISTS hukum_keanggotaan (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  periode_id INT NOT NULL,
  jabatan VARCHAR(32) NOT NULL CHECK (jabatan IN ('komisi_i', 'ketua_umum')),
  mulai_pada DATE NOT NULL,
  selesai_pada DATE NULL,
  aktif BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, periode_id, jabatan)
);

CREATE INDEX IF NOT EXISTS idx_hukum_keanggotaan_periode_aktif
  ON hukum_keanggotaan (periode_id, aktif, jabatan);

CREATE TABLE IF NOT EXISTS hukum_staging_approval (
  id SERIAL PRIMARY KEY,
  staging_id INT NOT NULL,
  user_id INT NOT NULL,
  peran VARCHAR(32) NOT NULL CHECK (peran IN ('komisi_i', 'ketua_umum')),
  status VARCHAR(16) NOT NULL DEFAULT 'menunggu' CHECK (status IN ('menunggu', 'disetujui', 'ditolak')),
  note TEXT NULL,
  approved_at TIMESTAMP NULL,
  rejected_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (staging_id, peran)
);

CREATE INDEX IF NOT EXISTS idx_hukum_staging_approval_status
  ON hukum_staging_approval (staging_id, status);

CREATE TABLE IF NOT EXISTS hukum_commit_window (
  id BIGSERIAL PRIMARY KEY,
  commit_id INT NULL,
  user_id INT NOT NULL,
  peran VARCHAR(32) NOT NULL CHECK (peran IN ('komisi_i', 'ketua_umum')),
  session_id VARCHAR(128) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','in_progress','approved','expired','rejected','locked')),
  initiated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  completed_at TIMESTAMP NULL,
  result VARCHAR(32) NULL,
  request_id VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, commit_id, peran)
);

CREATE INDEX IF NOT EXISTS idx_hukum_commit_window_expires
  ON hukum_commit_window (expires_at, status);

CREATE TABLE IF NOT EXISTS hukum_commit_lockout (
  id BIGSERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  session_id VARCHAR(128) NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until TIMESTAMP NULL,
  last_reason VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, session_id)
);

CREATE INDEX IF NOT EXISTS idx_hukum_commit_lockout_until
  ON hukum_commit_lockout (locked_until);

CREATE UNIQUE INDEX IF NOT EXISTS uq_hukum_workspace_active_slot
  ON hukum_workspace (dokumen_id)
  WHERE status IN ('aktif', 'diajukan', 'siap_commit');

ALTER TABLE hukum_audit_log
  ADD COLUMN IF NOT EXISTS role_context VARCHAR(50),
  ADD COLUMN IF NOT EXISTS periode_id INT,
  ADD COLUMN IF NOT EXISTS request_id VARCHAR(100),
  ADD COLUMN IF NOT EXISTS result VARCHAR(20) NOT NULL DEFAULT 'success',
  ADD COLUMN IF NOT EXISTS context_json TEXT;

ALTER TABLE hukum_relasi_pasal
  ADD COLUMN IF NOT EXISTS source_version_id INTEGER NULL,
  ADD COLUMN IF NOT EXISTS target_version_id INTEGER NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'fk_hukum_relasi_source_version'
  ) THEN
    ALTER TABLE hukum_relasi_pasal
      ADD CONSTRAINT fk_hukum_relasi_source_version
      FOREIGN KEY (source_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'fk_hukum_relasi_target_version'
  ) THEN
    ALTER TABLE hukum_relasi_pasal
      ADD CONSTRAINT fk_hukum_relasi_target_version
      FOREIGN KEY (target_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS hukum_graph_snapshot (
  id BIGSERIAL PRIMARY KEY,
  commit_id INTEGER NOT NULL,
  dokumen_id INTEGER NOT NULL,
  pasal_id INTEGER NOT NULL,
  pasal_version_id INTEGER NULL,
  nomor_label VARCHAR(50) NULL,
  payload_json JSONB NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (commit_id, pasal_id)
);

CREATE INDEX IF NOT EXISTS idx_hukum_graph_snapshot_commit
  ON hukum_graph_snapshot (commit_id);
CREATE INDEX IF NOT EXISTS idx_hukum_graph_snapshot_dokumen
  ON hukum_graph_snapshot (dokumen_id);

CREATE TABLE IF NOT EXISTS hukum_graph_snapshot_edge (
  id BIGSERIAL PRIMARY KEY,
  snapshot_id BIGINT NOT NULL,
  source_pasal_id INTEGER NOT NULL,
  target_pasal_id INTEGER NOT NULL,
  source_version_id INTEGER NULL,
  target_version_id INTEGER NULL,
  jenis_relasi VARCHAR(32) NOT NULL DEFAULT 'mengacu',
  metadata_json JSONB NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_hukum_graph_snapshot_edge_snapshot
  ON hukum_graph_snapshot_edge (snapshot_id);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_commit') THEN
    ALTER TABLE hukum_graph_snapshot ADD CONSTRAINT fk_hukum_graph_snapshot_commit
      FOREIGN KEY (commit_id) REFERENCES hukum_commit (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_dokumen') THEN
    ALTER TABLE hukum_graph_snapshot ADD CONSTRAINT fk_hukum_graph_snapshot_dokumen
      FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_pasal') THEN
    ALTER TABLE hukum_graph_snapshot ADD CONSTRAINT fk_hukum_graph_snapshot_pasal
      FOREIGN KEY (pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_version') THEN
    ALTER TABLE hukum_graph_snapshot ADD CONSTRAINT fk_hukum_graph_snapshot_version
      FOREIGN KEY (pasal_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_edge_snapshot') THEN
    ALTER TABLE hukum_graph_snapshot_edge ADD CONSTRAINT fk_hukum_graph_snapshot_edge_snapshot
      FOREIGN KEY (snapshot_id) REFERENCES hukum_graph_snapshot (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_edge_source_pasal') THEN
    ALTER TABLE hukum_graph_snapshot_edge ADD CONSTRAINT fk_hukum_graph_snapshot_edge_source_pasal
      FOREIGN KEY (source_pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_edge_target_pasal') THEN
    ALTER TABLE hukum_graph_snapshot_edge ADD CONSTRAINT fk_hukum_graph_snapshot_edge_target_pasal
      FOREIGN KEY (target_pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_edge_source_version') THEN
    ALTER TABLE hukum_graph_snapshot_edge ADD CONSTRAINT fk_hukum_graph_snapshot_edge_source_version
      FOREIGN KEY (source_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_hukum_graph_snapshot_edge_target_version') THEN
    ALTER TABLE hukum_graph_snapshot_edge ADD CONSTRAINT fk_hukum_graph_snapshot_edge_target_version
      FOREIGN KEY (target_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;
  END IF;
END $$;

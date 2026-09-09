ALTER TABLE hukum_relasi_pasal
  ADD COLUMN source_version_id INT NULL AFTER pasal_induk_id,
  ADD COLUMN target_version_id INT NULL AFTER source_version_id;

ALTER TABLE hukum_relasi_pasal
  ADD CONSTRAINT fk_hukum_relasi_source_version
    FOREIGN KEY (source_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_hukum_relasi_target_version
    FOREIGN KEY (target_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS hukum_graph_snapshot (
  id BIGINT NOT NULL AUTO_INCREMENT,
  commit_id INT NOT NULL,
  dokumen_id INT NOT NULL,
  pasal_id INT NOT NULL,
  pasal_version_id INT NULL,
  nomor_label VARCHAR(50) NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hukum_graph_snapshot_commit_node (commit_id, pasal_id),
  KEY idx_hukum_graph_snapshot_commit (commit_id),
  KEY idx_hukum_graph_snapshot_dokumen (dokumen_id),
  CONSTRAINT fk_hukum_graph_snapshot_commit
    FOREIGN KEY (commit_id) REFERENCES hukum_commit (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_dokumen
    FOREIGN KEY (dokumen_id) REFERENCES hukum_dokumen (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_pasal
    FOREIGN KEY (pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_version
    FOREIGN KEY (pasal_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS hukum_graph_snapshot_edge (
  id BIGINT NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT NOT NULL,
  source_pasal_id INT NOT NULL,
  target_pasal_id INT NOT NULL,
  source_version_id INT NULL,
  target_version_id INT NULL,
  jenis_relasi ENUM('mengacu','berhubungan','induk_anak') NOT NULL DEFAULT 'mengacu',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hukum_graph_snapshot_edge_snapshot (snapshot_id),
  CONSTRAINT fk_hukum_graph_snapshot_edge_snapshot
    FOREIGN KEY (snapshot_id) REFERENCES hukum_graph_snapshot (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_edge_source_pasal
    FOREIGN KEY (source_pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_edge_target_pasal
    FOREIGN KEY (target_pasal_id) REFERENCES hukum_pasal (id) ON DELETE CASCADE,
  CONSTRAINT fk_hukum_graph_snapshot_edge_source_version
    FOREIGN KEY (source_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL,
  CONSTRAINT fk_hukum_graph_snapshot_edge_target_version
    FOREIGN KEY (target_version_id) REFERENCES hukum_pasal_versi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

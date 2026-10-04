DELETE earlier
FROM hukum_relasi_pasal earlier
JOIN hukum_relasi_pasal later
  ON later.pasal_anak_id = earlier.pasal_anak_id
 AND later.jenis_relasi = 'mengacu'
 AND earlier.jenis_relasi = 'mengacu'
 AND later.id > earlier.id;

ALTER TABLE hukum_relasi_pasal
  ADD COLUMN acuan_pasal_unik_id INT
    GENERATED ALWAYS AS (CASE WHEN jenis_relasi = 'mengacu' THEN pasal_anak_id ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_hukum_relasi_single_acuan (acuan_pasal_unik_id);

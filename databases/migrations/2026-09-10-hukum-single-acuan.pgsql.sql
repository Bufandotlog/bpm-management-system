DELETE FROM hukum_relasi_pasal earlier
USING hukum_relasi_pasal later
WHERE earlier.pasal_anak_id = later.pasal_anak_id
  AND earlier.jenis_relasi = 'mengacu'
  AND later.jenis_relasi = 'mengacu'
  AND earlier.id < later.id;

CREATE UNIQUE INDEX IF NOT EXISTS uq_hukum_relasi_single_acuan
  ON hukum_relasi_pasal (pasal_anak_id)
  WHERE jenis_relasi = 'mengacu';

# PROMPT IMPLEMENTASI FITUR MULTI-FORMAT TANDA TANGAN SURAT

## OBJECTIVE
Menambahkan opsi Pemilihan Format Tanda Tangan pada sistem pembuat dan pencetak surat (BPM/BEM INSTBUNAS Majalengka).
Fitur ini memungkinkan Sekretaris/Admin untuk memilih 1 dari 3 format tanda tangan saat membuat/mengedit surat di `buat-surat.php`, yang kemudian akan dicetak secara dinamis sesuai pilihan format di `cetak-surat.php`. Selain itu, menambahkan form pengaturan Tanda Tangan & Cap Ketua BPM di `pengaturan-surat.php`.

---

## SPESIFIKASI FORMAT TANDA TANGAN

### 1. FORMAT 1 (Panitia Pelaksana + Mengetahui Warek III & Ketua BEM)
* **Header TTD**: `PANITIA PELAKSANA [NAMA KEGIATAN] [TAHUN]`
* **Baris Atas (2 Kolom)**:
  * Kiri: `Ketua Pelaksana` (Nama & TTD Panitia Ketua)
  * Kanan: `Sekretaris` (Nama & TTD Panitia Sekretaris)
  * Stempel: `Cap Panitia`
* **Sub-Header**: `Mengetahui,`
* **Baris Bawah (2 Kolom)**:
  * Kiri: `a.n. Rektor INSTBUNAS Majalengka` / `WAREK III Bid. Kemahasiswaan` (Nama, TTD, & Cap Warek III)
  * Kanan: `Ketua BEM INSTBUNAS Majalengka` (Nama, TTD, & Cap BEM - `ttd_presma_*` / `ttd_ketua_bem_*`)

### 2. FORMAT 2 (BEM Direct Periode - 2 TTD)
* **Header TTD**: `BEM INSTBUNAS MAJALENGKA PERIODE [TAHUN_MULAI/TAHUN_SELESAI]` (Misal: PERIODE 2026/2027)
* **Baris TTD (2 Kolom)**:
  * Kiri: `Ketua BEM` (Nama & TTD Ketua BEM - `ttd_presma_*` / `ttd_ketua_bem_*`) + `Cap BEM`
  * Kanan: `Sekretaris` (Nama & TTD Sekretaris BEM - `ttd_sekretaris_*`)
* **Tanpa**: Panitia Pelaksana & Tanpa Mengetahui Warek III / BPM.

### 3. FORMAT 3 (BEM Direct + Mengetahui Warek III & Ketua BPM)
* **Header TTD**: `BEM INSTBUNAS MAJALENGKA PERIODE [TAHUN_MULAI/TAHUN_SELESAI]` (Sama dengan Format 2, bukan Panitia Pelaksana)
* **Baris Atas (2 Kolom)**:
  * Kiri: `Ketua BEM` + sub-label `INSTBUNAS Majalengka` (Nama & TTD Ketua)
  * Kanan: `Sekretaris Umum` + sub-label `INSTBUNAS Majalengka` (Nama & TTD Sekretaris)
* **Sub-Header**: `Mengetahui,`
* **Baris Bawah (2 Kolom)**:
  * Kiri: `a.n. Rektor INSTBUNAS Majalengka` / `WAREK III Bid. Kemahasiswaan` (Nama, TTD, & Cap Warek III)
  * Kanan: `Ketua BPM INSTBUNAS Majalengka` (Nama, TTD, & Cap Ketua BPM - `ttd_bpm_*` & `cap_bpm_*`)

---

## SKEMA MAPPING & KOMPATIBILITAS DATA

### 1. Data Presma / Ketua BEM vs Ketua BPM:
* **Ketua BEM**: Menggunakan key existing (`ttd_presma_name`, `ttd_presma_jabatan`, `ttd_presma_image`, `cap_presma_image`) dengan aliasing/fallback ke `ttd_ketua_bem_*`. Label di UI `pengaturan-surat.php` diperbarui menjadi **Ketua BEM**.
* **Ketua BPM (Baru untuk Format 3)**: Menggunakan key baru tersendiri di tabel `pengaturan`:
  * `ttd_bpm_name` (Nama Ketua BPM)
  * `ttd_bpm_jabatan` (Jabatan, default: "Ketua BPM INSTBUNAS Majalengka")
  * `ttd_bpm_image` (Upload TTD Gambar Ketua BPM)
  * `cap_bpm_image` (Upload Cap / Stempel BPM)

### 2. Handling Surat Lama & Edit Mode:
* **Default Fallback**: Surat lama yang belum memiliki `format_ttd` di JSON `konten_surat` otomatis fallback ke `'1'` (Format 1).
* **Edit Mode**: Di `buat-surat.php?edit=ID`, form selector akan membaca nilai `format_ttd` dari JSON. Jika tidak ada, default terpilih `'1'`. Admin bebas mengubah `format_ttd` jika ingin memperbarui format surat lama.

---

## PERUBAHAN CODEBASE

### 1. `admin/surat/pengaturan-surat.php`
* **Form & Processing**: Tambahkan handler POST `update_ttd` untuk **Ketua BPM** (`ttd_bpm_*`) dan **Cap BPM** (`cap_bpm_image`).
* **UI**: Tambahkan Card Upload "Ketua BPM" dan Card Upload "Cap BPM" di grid pengaturan TTD & Stempel. Ubah label "Presiden Mahasiswa" menjadi "Ketua BEM".

### 2. `admin/surat/buat-surat.php`
* **UI Form**: Tambahkan Card **"Format & Layout Tanda Tangan"** tepat sebelum Card **"Penanggung Jawab / Panitia"**.
* **Field**: Select / Radio `format_ttd` dengan opsi `'1'`, `'2'`, `'3'`.
* **Save Logic**: Simpan `$konten_data['format_ttd']` ke JSON `konten_surat`.

### 3. `admin/surat/cetak-surat.php`
* **Read JSON**: `$format_ttd = $konten['format_ttd'] ?? '1';`
* **Conditional Rendering**: Render struktur tabel TTD sesuai `$format_ttd` (Format 1, Format 2, atau Format 3).

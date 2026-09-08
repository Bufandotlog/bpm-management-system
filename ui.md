# Desain UI — Sistem Hukum BPM

> **Status dokumen:** Konsolidasi kebutuhan UI berdasarkan alur produk Hukum dan
> implementasi aktual pada branch ini.
>
> Dokumen ini membedakan fitur yang **sudah tersedia**, **belum tersedia**, dan
> **target pengembangan**. Detail warna dan layout piksel mengikuti standar UI
> aplikasi yang sudah ada.

## 1. Status Implementasi Saat Ini

### Sudah tersedia

- Dashboard admin Dokumen Hukum.
- Pembuatan dokumen draft.
- Pemilihan periode berdasarkan nama periode.
- Daftar dokumen dan detail ringkas.
- Halaman Review/Staging terpisah.
- Tombol approve/reject staging untuk role yang memiliki permission review.
- Halaman publik daftar produk hukum.
- Halaman publik detail dokumen dari snapshot commit aktif.
- Menu sidebar admin dan navbar publik Hukum.

### Belum tersedia

- Editor workspace/meja kerja lengkap.
- Editor BAB dan pasal.
- Editor isi pasal terstruktur.
- Preview before/after.
- Tombol Submit Staging dari UI editor.
- UI Co-Commit dengan dua pihak dan timer.
- Halaman audit relasi dan broken-reference report.
- Peta relasi publik.
- UI riwayat commit dan perbandingan antar versi.

## 2. Peta Navigasi Target

### 2.1 Admin

```text
Dashboard Admin
├── Hukum / Dokumen
├── Meja Kerja
├── Review / Staging
├── Riwayat Commit
├── Audit Relasi Pasal
└── Referensi Bermasalah
```

Saat ini menu yang tersedia adalah **Hukum / Dokumen** dan **Review / Staging**.
Menu lain ditambahkan setelah endpoint dan workflow-nya siap.

### 2.2 Publik

```text
[Beranda] [Arsip] [HUKUM] [PETA RELASI] [...menu lain...]
```

Menu publik yang tersedia saat ini adalah **HUKUM**. Nama **PETA RELASI**
digunakan sebagai target karena lebih jelas daripada label “Grafik”.

## 3. UI Admin — Dokumen Hukum

Halaman aktual: `admin/hukum-dashboard.php`.

### Komponen yang tersedia

- Statistik jumlah dokumen, workspace, staging, dan notifikasi.
- Daftar dokumen dengan pencarian judul.
- Filter visual jenis, lingkup, status, dan slug.
- Tombol **Dokumen Baru** sesuai permission.
- Form judul, slug, jenis, lingkup, nama ormawa, periode, dan deskripsi.
- Periode ditampilkan sebagai nama dan rentang tahun, tetapi API tetap menyimpan
  foreign key `periode_id`.
- Detail dokumen yang memuat struktur BAB, workspace, dan commit secara ringkas.
- Tab notifikasi peninjauan.

### Perilaku target

- Status dokumen harus selalu terlihat.
- Dokumen `draft` menampilkan aksi **Kelola Workspace**.
- Dokumen `aktif` menampilkan aksi **Lihat Riwayat** dan **Buat Perubahan**.
- Slug duplikat ditampilkan sebagai error `409`, bukan error server generik.
- Aksi destruktif harus meminta konfirmasi eksplisit.

## 4. UI Admin — Meja Kerja

Halaman target: `admin/hukum-workspace.php`.

### Komponen

| Komponen | Perilaku |
|---|---|
| Banner status | Menampilkan `aktif`, `diajukan`, atau `committed` |
| Tombol buat workspace | Aktif hanya jika belum ada workspace `aktif` untuk dokumen tersebut |
| Metadata perubahan | Judul perubahan, tujuan, pembuat, dan waktu terakhir diubah |
| Daftar draft pasal | Pasal, versi terakhir, pembuat, dan waktu perubahan |
| Simpan Draft | Menyimpan versi baru tanpa menjalankan Staging Gate |
| Submit Staging | Aktif jika draft tersedia dan menjalankan validasi atomik |
| Tarik Draft | Tersedia sebelum keputusan final sesuai aturan workflow |
| Riwayat | Read-only untuk workspace dan commit terdahulu |

### Catatan implementasi

API saat ini menggunakan `hukum_workspace`, bukan tabel legacy
`hukum_meja_kerja`. Aturan satu workspace aktif per dokumen masih perlu
diterapkan konsisten di API dan database sebelum UI ini dibuat.

## 5. UI Admin — Editor Pasal

Halaman target: bagian dari `admin/hukum-workspace.php`.

### Komponen

- Editor isi pasal terstruktur, bukan editor JSON mentah untuk pengguna umum.
- Pilihan BAB, nomor pasal, judul pasal, dan urutan.
- Tombol **Simpan Draft**.
- Tombol **Sisipkan Referensi Pasal**.
- Panel preview hasil render.
- Indikator status referensi:
  - valid;
  - target dicabut;
  - target tidak ditemukan.
- Indikator versi asal dan hash konten.
- Mode read-only untuk versi committed.

### Format referensi

Format `[[PASAL:N]]` sudah dikenali oleh helper API untuk inline reference.
Format lintas dokumen dan format ayat masih perlu ditetapkan final melalui
`graph.md`.

Simpan draft tidak boleh memicu Staging Gate atau mengubah status publik.

## 6. UI Admin — Review / Staging

Halaman aktual: `admin/hukum-staging.php`.

### Komponen yang tersedia

- Daftar staging menunggu review.
- Judul dokumen dan judul perubahan.
- Waktu pengajuan.
- Tombol **Setujui** dan **Tolak** berdasarkan permission.
- Alasan penolakan wajib diisi.

### Komponen yang perlu ditambahkan

- Detail staging lengkap.
- Daftar pasal dan versi yang diajukan.
- Diff before/after.
- Daftar notifikasi cascade.
- Pesan Staging Gate yang menyebut pasal spesifik.
- Link langsung menuju pasal yang harus diselaraskan.
- Tombol **Tarik Kembali** untuk pengaju sebelum keputusan final.

Approval staging membutuhkan dua konfirmasi: Komisi I dan Ketua Umum BPM.
Tidak ada timer ketat pada tahap staging; status menjadi `disetujui` setelah
keduanya menyetujui.

## 7. UI Admin — Co-Commit

Halaman target: bagian detail staging yang sudah disetujui.

### Komponen

- Tombol **Inisiasi Commit** untuk Komisi I.
- Tombol **Inisiasi Commit** untuk Ketua Umum BPM yang memiliki role `admin`.
- Status kedua pihak.
- Countdown independen per pihak.
- Form password dan tombol **Setuju Commit**.
- Ringkasan forum dan tanggal forum.
- Hash commit setelah berhasil.
- Pesan kegagalan spesifik.
- Banner global lockout jika kebijakan lockout aktif.

Setelah timer dimulai, UI tidak menyediakan tombol batal.

Catatan penting: endpoint aktual `api/hukum/commit.php` saat ini masih membuat
commit dari staging yang disetujui secara single-request. Co-Commit, timer,
password verification, commit window, dan global lockout belum tersedia di
implementasi aktual.

## 8. UI Admin — Audit dan Referensi Bermasalah

### Audit Relasi

Target kolom:

- Pasal asal.
- Pasal tujuan.
- Jenis relasi.
- Sumber `auto` atau `manual`.
- Nama pembuat jika manual.
- Waktu dibuat.

Filter target: tanggal, admin, jenis sumber, dan dokumen.

### Broken Reference Report

Target kolom:

- Dokumen dan pasal asal.
- Kode referensi mentah.
- Status target tidak ditemukan atau dicabut.
- Tombol **Edit Pasal Asal**.

Badge jumlah masalah dapat ditampilkan pada sidebar setelah endpoint laporan
tersedia.

## 9. UI Publik — Produk Hukum

Halaman aktual: `hukum.php` dan `hukum-detail.php`.

### Komponen yang tersedia

- Daftar hanya dokumen berstatus `aktif`.
- Filter kata kunci, jenis, dan lingkup.
- Kartu judul, jenis, lingkup, ormawa, dan deskripsi.
- Detail dokumen dari commit aktif.
- Struktur BAB dan pasal.
- Hash commit dan tanggal forum.
- Escape output untuk mencegah injeksi HTML.

### Target tambahan

- Referensi inline menjadi link jika target valid.
- Link lintas dokumen dengan nama dokumen tujuan.
- Referensi rusak tetap terlihat sebagai teks dengan penanda masalah.
- Tombol kembali ke dokumen asal.
- Navigasi pasal induk dan pasal anak.

## 10. UI Publik — Peta Relasi

Halaman target: `hukum-graph.php` atau rute yang disepakati kemudian.

Komponen:

- Visualisasi node pasal dan edge relasi.
- Legenda tipe relasi.
- Klik node untuk melihat ringkasan dan membuka pasal.
- Selector commit/periode.
- Rendering berdasarkan snapshot historis.

Fitur ini belum tersedia pada implementasi aktual dan bergantung pada keputusan
final di `graph.md` tentang struktur snapshot serta jenis edge.

## 11. Prinsip UI Lintas Layar

1. Aksi penting selalu memiliki konfirmasi eksplisit.
2. Status workflow selalu terlihat.
3. Data staging, draft, dan audit internal tidak boleh bocor ke publik.
4. Pesan error menyebutkan pasal, versi, atau alasan yang bermasalah.
5. Tombol ditampilkan berdasarkan permission server-side, bukan hanya
   disembunyikan dengan JavaScript.
6. Semua aksi mutasi menggunakan CSRF token dan endpoint API yang sama.
7. Tampilan read-only digunakan untuk commit immutable.
8. UI tidak menampilkan fitur yang endpoint backend-nya belum mendukung.

## 12. Prioritas Implementasi UI

1. Editor workspace, BAB, pasal, dan versi isi.
2. Detail staging, diff, dan Submit Staging.
3. Commit flow dan Co-Commit.
4. Riwayat commit dan perbandingan versi.
5. Referensi inline dan broken-reference report.
6. Peta relasi publik.
7. Penyempurnaan mobile dan aksesibilitas.

## 13. Keputusan yang Masih Perlu Diklarifikasi

1. Apakah menu publik final bernama **PETA RELASI** atau **JARINGAN PASAL**?
2. Apakah Audit Relasi dan Broken Reference Report satu halaman atau dua halaman?
3. Format final referensi lintas dokumen dan ayat.
4. Layout editor: form terstruktur, rich text, atau kombinasi keduanya.
5. Apakah detail staging harus dapat diakses Komisi I dan reviewer dengan
   tampilan yang sama?

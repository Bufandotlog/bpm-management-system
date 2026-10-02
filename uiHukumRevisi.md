# Revisi UI Sistem Hukum BPM

## 1. Tempat Kerja Pengembangan

Pengembangan revisi UI Hukum dilakukan pada:

```text
Branch aktif: agents/evaluasi-sistem-humum-bpm-web
Branch target yang saat ini menunjuk commit yang sama: main
Commit baseline terakhir: 1b14dbc feat(hukum): finalize role model and authorization
```

Perubahan UI saat ini masih berada di working tree dan **belum di-commit**.
Dengan demikian, branch ini menjadi meja kerja khusus untuk menyelesaikan,
menguji, dan memisahkan commit UI dari baseline role serta authorization.

Status perubahan saat dokumen ini dibuat:

```text
M admin/css/hukum.css
M admin/hukum-dashboard.php
M admin/hukum-editor.php
M api/hukum/pasal_service.php
```

## 2. Tujuan Revisi

UI lama meminta pengguna mengisi isi Pasal sebagai JSON mentah. Pola tersebut
tidak sesuai untuk pengguna awam yang lebih terbiasa menulis seperti di Word
atau WPS.

UI baru menggunakan formulir terstruktur:

```text
Dokumen
└── BAB
    └── Pasal
        ├── Isi pembuka
        ├── Ayat
        │   └── Poin
        └── ...
```

JSON tetap dipakai sebagai format internal backend, tetapi tidak ditampilkan
sebagai bidang yang harus dipahami atau diedit pengguna.

## 3. Rancangan Alur UI

### 3.1 Dashboard Dokumen Hukum

Halaman dashboard menjadi pintu masuk untuk:

1. melihat daftar dokumen;
2. melihat status dokumen;
3. melihat workspace dan staging;
4. membuka detail dokumen;
5. membuka editor untuk dokumen berstatus `draft`;
6. membuat dokumen baru jika pengguna memiliki permission.

### 3.2 Editor Tiga Langkah

#### Langkah 1 — Informasi Dokumen

Form mencakup:

- judul dokumen;
- identitas singkat/slug;
- jenis dokumen;
- lingkup dokumen;
- nama organisasi;
- periode;
- mukadimah atau pembukaan;
- deskripsi singkat.

Saat dokumen baru dibuat, editor juga membuat workspace awal agar pengguna
langsung dapat melanjutkan penyusunan isi.

#### Langkah 2 — Struktur Isi

Pengguna mengisi struktur melalui kartu dinamis:

- tambah/hapus BAB;
- tambah/hapus Pasal;
- tambah/hapus Ayat;
- tambah/hapus Poin;
- isi pembuka Pasal;
- penjelasan opsional per Pasal;
- judul BAB dan Pasal;
- nomor BAB, Pasal, Ayat, dan Poin.

#### Langkah 3 — Pratinjau dan Pengiriman

Sistem menampilkan pratinjau isi dokumen dan validasi struktur.

Tersedia dua tindakan:

- **Simpan Draft**
  - menyimpan isi seluruh dokumen sebagai versi draft;
  - workspace tetap dapat diedit;
  - hanya ditampilkan jika draft belum tersimpan atau isi berubah.
- **Ajukan untuk Review**
  - baru ditampilkan setelah draft berhasil tersimpan dan belum ada perubahan setelah penyimpanan;
  - mengirim versi Pasal yang tersimpan ke staging tanpa menyimpan ulang otomatis;
  - membuat snapshot untuk proses review;
  - hanya dapat dilakukan ketika workspace berstatus `aktif`.
  - submit Komisi I didasarkan pada role teknis `komisi_i` dan periode dokumen,
    bukan semata-mata baris keanggotaan organisasi.
- Setelah pengajuan berhasil, kedua tombol disembunyikan dan editor menampilkan
  status workspace yang sedang menunggu review.
- Status simpan draft dipulihkan saat halaman dibuka ulang dengan mencocokkan
  versi draft tiap Pasal pada workspace aktif.

## 4. Status Implementasi Saat Ini

### Sudah Diimplementasikan

- Wizard editor tiga langkah.
- Penggantian textarea JSON mentah dengan UI BAB/Pasal/Ayat/Poin.
- Penjelasan opsional per Pasal disimpan bersama isi versi, ditampilkan pada
  pratinjau dan diff review, serta ikut terlacak dalam snapshot commit.
- Halaman publik menampilkan disclosure `Penjelasan` bila teks tersedia, atau
  `Cukup Jelas` untuk Pasal tanpa penjelasan, termasuk snapshot lama.
- Halaman detail publik menampilkan mukadimah/pembukaan dari format JSON
  maupun format legacy sebelum struktur BAB dan Pasal.
- Kartu dinamis untuk menambah dan menghapus elemen struktur.
- Form informasi dokumen dan mukadimah.
- Pratinjau dokumen sebelum penyimpanan/pengajuan.
- Validasi minimal:
  - dokumen wajib dipilih;
  - workspace harus tersedia;
  - minimal satu BAB;
  - minimal satu Pasal per BAB;
  - nomor BAB dan judul BAB;
  - nomor Pasal;
  - isi pembuka atau Ayat;
  - isi Ayat atau Poin.
- Tombol `Simpan Draft`.
- Tombol `Ajukan untuk Review`.
- Daftar staging hanya menampilkan aksi `Tinjau`; persetujuan dan penolakan
  tersedia pada detail setelah reviewer melihat pratinjau.
- Pratinjau staging menampilkan mukadimah sebagai konteks dan seluruh Pasal
  dari snapshot aktif serta staging; diff hijau/merah menandai isi Pasal baru
  dan isi yang berubah.
- Penghapusan Pasal/BAB dicatat sebagai usulan beralasan di workspace, disalin
  ke staging, ditampilkan merah pada tinjauan, dan diterapkan sebagai tombstone
  pada snapshot commit tanpa menghapus data historis secara fisik.
- API pembaca struktur hanya menampilkan Pasal/BAB yang masih aktif setelah
  commit penghapusan.
- Reviewer melihat progres approval, dan keputusan hanya ditawarkan untuk
  role yang masih menunggu persetujuan.
- Tombol bergantian: `Simpan Draft` untuk perubahan belum tersimpan,
  `Ajukan untuk Review` untuk draft tersimpan tanpa perubahan.
- Tombol pengajuan hanya ditampilkan setelah draft berhasil disimpan; perubahan
  setelah simpan menyembunyikannya hingga penyimpanan berikutnya berhasil.
- Status tombol dipulihkan dari versi draft workspace saat dokumen dimuat ulang.
- Hak submit/review Komisi I mengikuti role teknis dan periode dokumen.
- Pemulihan isi draft terbaru saat editor dibuka kembali.
- Pembatasan daftar editor hanya pada dokumen berstatus `draft`.
- Tombol `Buka editor` dari detail dokumen draft di dashboard.
- Styling responsive untuk desktop dan layar kecil.
- Workspace berstatus `diajukan` masih dapat menerima versi draft lanjutan,
  tetapi tidak dapat diajukan ulang sebelum staging aktif selesai.
- JSON tetap dibuat internal sebelum dikirim ke endpoint Pasal.

### Belum Diimplementasikan

- Penyimpanan perubahan BAB/Pasal yang sudah ada secara penuh.
  Implementasi saat ini membuat elemen baru jika belum ditemukan, tetapi belum
  menyediakan endpoint update/delete struktur yang lengkap.
- Dukungan menghapus Pasal terakhir dalam BAB secara terpisah; untuk menjaga
  struktur, Pasal terakhir hanya dapat dihapus dengan mengajukan seluruh BAB.
- Pengurutan ulang BAB, Pasal, Ayat, dan Poin dengan drag-and-drop.
- Autosave berkala.
- Indikator perubahan yang belum disimpan.
- Konfirmasi ketika meninggalkan halaman dengan perubahan belum tersimpan.
- Pengeditan mukadimah dokumen lama dari editor baru.
- Pengujian browser end-to-end menggunakan akun dengan role Komisi I.
- Dukungan rich-text editor.
- Hasil validasi referensi dan alur co-commit yang lebih lengkap.

## 5. File yang Berhubungan dan Terpengaruh

### 5.1 File UI Utama

| File | Peran | Dampak |
|---|---|---|
| `admin/hukum-dashboard.php` | Dashboard daftar dokumen dan detail | Ditambahkan link `Buka editor` untuk dokumen draft |
| `admin/hukum-editor.php` | Editor dokumen baru | Direvisi besar menjadi wizard terstruktur |
| `admin/css/hukum.css` | Styling UI Hukum | Ditambahkan styling wizard, kartu struktur, pratinjau, validasi, dan responsive layout |
| `admin/core/header.php` | Autentikasi dan layout admin | Menandai halaman tinjau staging dalam navigasi Hukum |
| `admin/core/hukum-auth.php` | Permission dan actor Hukum | Menjadi gate akses editor dan dashboard, tidak diubah oleh revisi UI ini |

### 5.2 Endpoint yang Dipakai Editor

| File | Operasi yang digunakan |
|---|---|
| `api/hukum/documents.php` | Membuat dan membaca dokumen |
| `api/hukum/document_service.php` | Validasi dan penyimpanan metadata dokumen |
| `api/hukum/workspaces.php` | Membuat dan membaca workspace |
| `api/hukum/workspace_service.php` | Aturan pembuatan dan status workspace |
| `api/hukum/bab.php` | Membuat dan membaca BAB |
| `api/hukum/bab_service.php` | Validasi dan penyimpanan BAB |
| `api/hukum/pasal.php` | Membuat Pasal, membaca versi, dan menyimpan draft Pasal |
| `api/hukum/pasal_service.php` | Validasi workspace serta canonical JSON versi Pasal |
| `api/hukum/staging.php` | Mengirim versi draft ke staging |
| `api/hukum/staging_service.php` | Validasi snapshot, referensi, dan perubahan status staging |
| `admin/core/hukum-auth.php` | Pemeriksaan role teknis terhadap periode untuk aksi Hukum |
| `api/hukum/review_service.php` | Resolusi role approval staging |
| `api/hukum/commit_service.php` | Validasi role teknis untuk otorisasi commit |
| `databases/migrations/2026-10-02-hukum-technical-review-roles.php` | Migrasi aman label approval/commit lama menjadi `admin` |

### 5.3 File Workflow Lanjutan

File berikut berhubungan langsung dengan alur setelah pengguna mengajukan review:

| File | Peran |
|---|---|
| `admin/hukum-staging.php` | Daftar staging dengan satu aksi menuju halaman tinjau |
| `admin/hukum-staging-detail.php` | Pratinjau dokumen staging dan keputusan review |
| `api/hukum/review.php` | Endpoint keputusan review |
| `api/hukum/review_service.php` | Validasi approval Komisi I dan admin |
| `api/hukum/review_preview_service.php` | Menggabungkan snapshot aktif dengan perubahan dan penghapusan staging |
| `api/hukum/deletions.php` | Mencatat, membatalkan, dan membaca usulan penghapusan workspace |
| `api/hukum/deletions_service.php` | Snapshot penghapusan, stale-base guard, pemeriksaan referensi, dan tombstone |
| `api/hukum/commit.php` | Finalisasi commit |
| `api/hukum/commit_service.php` | Validasi dan penyimpanan commit |
| `api/hukum/version_service.php` | Wrapper pembuatan versi draft |

### 5.4 File Data dan Dokumentasi Terkait

| File | Peran |
|---|---|
| `databases/migrations/2026-09-07-h0-h1-h2-hukum-tables.sql` | Struktur tabel dokumen, BAB, Pasal, versi, workspace, dan staging |
| `fileSistemHukum.md` | Peta file dan arsitektur sistem Hukum |
| `perbaikanSistemHukum.md` | Konsep role dan authorization yang menjadi baseline |
| `peta_layer_sistem.md` | Pemisahan layer aplikasi |
| `ui.md` | Konsolidasi desain UI Hukum dan target jangka lanjut |
| `tests/hukum_session5_submit_smoke.php` | Smoke test pengajuan staging |
| `tests/hukum_session11m_staging_auth_smoke.php` | Smoke test authorization staging |
| `tests/hukum_review_preview_smoke.php` | Smoke test perubahan, penambahan, dan konteks Pasal |
| `tests/hukum_deletion_workflow_smoke.php` | Smoke test snapshot, alasan, guard referensi, staging, dan audit tombstone |

## 6. Kontrak Data Internal

Form Pasal dikonversi ke struktur internal seperti berikut sebelum dikirim ke
`api/hukum/pasal.php`:

```json
{
  "teks_utama": "Isi pembuka Pasal",
  "ayat": [
    {
      "nomor": "1",
      "teks": "Isi ayat",
      "poin": [
        {
          "nomor": "a",
          "teks": "Isi poin"
        }
      ]
    }
  ]
}
```

Struktur ini hanya menjadi kontrak backend. Pengguna tetap berinteraksi dengan
field dan kartu UI biasa.

## 7. Prasyarat Backend Finalisasi Commit

Backend finalisasi menyediakan kontrak berikut untuk UI yang akan dibuat:

- Persetujuan tetap memerlukan satu akun berbeda untuk peran `komisi_i` dan
  satu akun untuk `admin`.
- Komisi I dan Admin memverifikasi menggunakan akun masing-masing; verifikasi
  berlaku selama lima menit dan terikat pada satu staging.
- `GET api/hukum/commit.php?action=ready_to_finalize` menampilkan staging yang
  telah disetujui kedua peran, workspace `siap_commit`, dan belum memiliki
  commit. Data juga memuat identitas pemberi persetujuan serta status window.
- `POST api/hukum/commit.php` menerima `action=verify_window`, `staging_id`,
  dan kata sandi akun yang sedang login untuk verifikasi peran akun tersebut.
- Finalisasi tetap memakai `staging_id` dan kata sandi Admin; pemeriksaan
  approval, window kedua pihak, status, serta pencegahan commit ganda dilakukan
  kembali oleh backend.
- Akun yang sama tidak dapat memberikan kedua persetujuan atau verifikasi
  untuk kedua peran.
- Migrasi `databases/migrations/2026-10-02-hukum-commit-staging-windows.php`
  menambahkan kaitan window verifikasi ke staging. Jalankan sebelum endpoint
  finalisasi digunakan pada database baru atau lingkungan lain.

Prasyarat backend dan halaman antarmuka Finalisasi Commit sudah tersedia.
Implementasi UI saat ini menyediakan daftar staging siap finalisasi, tautan
pratinjau diff, verifikasi untuk akun pemberi persetujuan, dan konfirmasi
finalisasi oleh Admin. Tautan publik belum ditambahkan karena rute publik
dokumen belum menjadi bagian dari kontrak UI ini.

## 7. Rencana Pengembangan Berikutnya

### Fase 1 — Menyelesaikan CRUD Struktur

1. Tambahkan endpoint update BAB dan Pasal.
2. Tambahkan endpoint delete dengan validasi dependensi.
3. Simpan ID elemen secara konsisten di DOM.
4. Hindari pembuatan duplikat saat pengguna menekan `Simpan Draft` berulang.
5. Simpan urutan berdasarkan posisi aktual kartu.

### Fase 2 — Draft yang Aman

1. Tambahkan autosave opsional.
2. Tambahkan status `belum disimpan`.
3. Tambahkan optimistic concurrency berdasarkan versi terakhir.
4. Tampilkan pesan konflik bila versi berubah dari pengguna lain.
5. Tambahkan konfirmasi sebelum meninggalkan editor.

### Fase 3 — Review yang Transparan

1. Tampilkan hasil validasi referensi.
2. Tampilkan alasan penolakan langsung pada workspace.
3. Tambahkan dukungan penghapusan Pasal/BAB end-to-end sebelum memberi tanda
   diff merah untuk penghapusan entitas.

Catatan implementasi: diff isi membandingkan versi Pasal. Mukadimah dan
metadata dokumen belum menjadi bagian snapshot staging. Penghapusan hanya
berlaku setelah commit yang memenuhi persetujuan; BAB/Pasal dan versi lama
tetap disimpan untuk audit. Pasal terakhir dalam BAB harus dihapus bersama
BAB-nya. Penghapusan diblokir jika graph relation atau rujukan inline aktif
masih menunjuk pada Pasal yang akan dihapus.

Migrasi fitur penghapusan harus dijalankan melalui CLI sebelum API/UI terbaru
digunakan:

```text
php databases/migrations/2026-10-02-hukum-staging-deletions.php
```

### Fase 4 — Penyempurnaan Pengalaman Pengguna

1. Tambahkan drag-and-drop atau tombol naik/turun.
2. Tambahkan template struktur untuk AD, ART, GBHO, dan jenis dokumen lain.
3. Tambahkan pencarian BAB/Pasal pada dokumen panjang.
4. Tambahkan preview cetak.
5. Pertimbangkan rich-text editor setelah form dasar stabil.

### Fase 5 — Pengujian dan Rilis

1. Jalankan lint PHP dan validasi JavaScript.
2. Jalankan smoke test API.
3. Uji role Komisi I, admin, dan superadmin.
4. Uji draft, staging, reject, submit ulang, dan commit.
5. Uji browser pada `http://127.0.0.1:8081`.
6. Commit perubahan UI secara terpisah dari commit role baseline.

## 8. Kriteria Selesai untuk UI Tahap Pertama

UI tahap pertama dianggap stabil apabila:

- pengguna dapat membuat dokumen draft baru;
- pengguna dapat membuat workspace;
- pengguna dapat menyusun BAB, Pasal, Ayat, dan Poin tanpa melihat JSON;
- pengguna dapat memuat ulang editor tanpa kehilangan isi yang sudah disimpan;
- `Simpan Draft` tidak membuat staging;
- `Ajukan untuk Review` membuat snapshot staging;
- dokumen yang diajukan tidak dapat diajukan ulang secara duplikat;
- validasi kesalahan tampil dalam bahasa yang mudah dipahami;
- role yang tidak berwenang tetap ditolak oleh backend;
- perubahan dapat diuji tanpa mengubah konfigurasi produksi.

## 9. Catatan Operasional Lokal

Server lokal dijalankan dengan override runtime agar `BASE_URL` produksi tidak
digunakan:

```text
APP_ENV=development
BASE_URL=http://127.0.0.1:8081/
CANONICAL_HOST=127.0.0.1:8081
```

URL akses:

- Login: `http://127.0.0.1:8081/astawidya/bpm.php?key=astawidya-bpm`
- Dashboard: `http://127.0.0.1:8081/admin/hukum-dashboard.php`
- Editor: `http://127.0.0.1:8081/admin/hukum-editor.php`
- Finalisasi Commit: `http://127.0.0.1:8081/admin/hukum-commit.php`

Override tersebut tidak ditulis ke `.env` repository agar konfigurasi produksi
dan secret tetap aman.

## 10. UI Finalisasi Commit

Halaman `admin/hukum-commit.php` menampilkan staging yang siap difinalisasi
melalui `GET api/hukum/commit.php?action=ready_to_finalize`. Halaman detail
menunjukkan pemberi persetujuan, status verifikasi, kesiapan finalisasi, serta
tautan ke pratinjau diff staging lengkap.

- Komisi I dan Admin hanya dapat memverifikasi persetujuan yang mereka berikan
  sendiri, dengan kata sandi akun masing-masing.
- Verifikasi dikaitkan ke staging tertentu dan berlaku paling lama lima menit.
- Finalisasi hanya tersedia bagi Admin, setelah kedua verifikasi aktif, dengan
  konfirmasi kata sandi Admin.
- Sebelum meminta finalisasi, UI memuat ulang status daftar; backend tetap
  menjadi otoritas untuk memvalidasi ulang staging, persetujuan, dan window.
- Setelah berhasil, commit ID ditampilkan dan staging dihapus dari daftar siap
  difinalisasi.

Pengajuan staging dari editor menampilkan konfirmasi konsekuensi sebelum form
autentikasi. Pengguna mengonfirmasi dengan kata sandi; bila 2FA akun aktif,
kode autentikator juga wajib. Endpoint staging memverifikasi ulang kata sandi
dan status 2FA akun sebelum membuat snapshot, menerapkan replay protection
untuk TOTP, serta membatasi percobaan gagal menggunakan rate limit login yang
tersedia.

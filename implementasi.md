# Skema Implementasi — Sistem Hukum BPM

> **Status:** Rancangan implementasi
> **Dokumen acuan:** `PRD.md`, `meja-kerja.md`, `staging.md`, `commit.md`,
> `graph.md`, `ui.md`
>
> Dokumen ini adalah rencana teknis untuk membawa workflow Hukum dari kondisi
> API dasar saat ini menuju workflow operasional penuh.

## 1. Keputusan Produk yang Menjadi Dasar

Implementasi wajib mengikuti keputusan berikut:

1. Komisi I adalah penyusun utama dokumen hukum.
2. Keanggotaan Komisi I dan Ketua Umum BPM ditentukan melalui tabel
   keanggotaan per periode, bukan hanya role teknis.
3. Ketua Umum BPM wajib memiliki role teknis `admin` untuk mengakses fungsi
   reviewer.
4. Hanya satu workspace berstatus `aktif` untuk setiap `dokumen_id`.
5. Approval staging membutuhkan dua konfirmasi terpisah: Komisi I dan Ketua
   Umum BPM.
6. Approval staging tidak menggunakan timer ketat.
7. Commit final membutuhkan Co-Commit Komisi I dan Ketua Umum BPM dengan window
   independen dan verifikasi password.
8. Revisi dokumen memakai `dokumen_id` yang sama dan membuat workspace serta
   commit baru.
9. Commit bersifat immutable; perubahan berikutnya selalu melalui workspace baru.
10. Publik hanya membaca dokumen dan snapshot commit berstatus aktif.

### 1.1 Koreksi arsitektur yang perlu diimplementasikan

Selain keputusan produk di atas, implementasi juga harus mengikuti delapan
perbaikan arsitektur utama yang muncul dari review desain akhir:

1. `state` harus menjadi single source of truth, bukan kumpulan status yang
   tersebar di workspace, version, staging, approval, commit, dan notification.
2. Validasi `workspace aktif` harus memakai kombinasi: server-side validation +
   database unique constraint partial index, bukan hanya `COUNT(*)`.
3. Perbedaan antara `database rollback` dan `business transition` harus jelas.
   `ROLLBACK` hanya untuk transaksi gagal; status `aktif -> diajukan` adalah
   transisi bisnis yang legal dan diaudit.
4. Co-Commit tidak memakai timer 5 detik; untuk operasional yang realistis,
   gunakan window `1-5 menit` dengan clock server-side.
5. Global lockout harus dihindari. Gunakan lockout per-user dan per-session,
   bukan memblokir semua user atas satu kegagalan.
6. Staging approval dan Co-Commit harus dipisahkan secara konseptual: staging
   menilai kelayakan masuk proses pengesahan, Co-Commit menilai otorisasi final.
7. Staging Gate dimulai dari validasi deterministik (duplikasi, struktur,
   broken reference, invalid numbering) lalu baru dependency impact dan recursive
   check.
8. Graph harus berbasis relasi relational dan `pasal_version_id`, bukan relasi
   yang mengarah ke entitas mutable.

## 2. Kondisi Awal dan Gap Implementasi

### 2.1 Sudah tersedia

- Skema dasar dokumen, BAB, pasal, workspace, versi, staging, commit, relasi,
  notifikasi, dan audit.
- Auth berbasis session dan permission Hukum.
- API dokumen, BAB, pasal, workspace, versi, staging, review, dan commit.
- Dashboard admin dan halaman Review/Staging.
- Halaman publik daftar dan detail dokumen.
- Hash konten, hash commit, dan snapshot commit.

### 2.2 Belum tersedia atau belum sesuai keputusan final

- `hukum_keanggotaan` untuk Komisi I dan Ketua Umum BPM.
- Constraint satu workspace aktif per dokumen.
- Dua approval staging yang terpisah.
- Staging Gate BFS, cascade notification, dan self-commit.
- Editor admin workspace/BAB/pasal/isi.
- Detail staging dan diff.
- Co-Commit, timer independen, password verification, dan lockout yang disesuaikan.
- Withdrawal yang lengkap.
- Graph snapshot dan peta relasi publik.
- Migrasi status bisnis `menunggu_forum` dengan kompatibilitas API aktual
  `menunggu_review`.

## 3. Prinsip Pelaksanaan

1. Setiap sesi menghasilkan perubahan kecil yang dapat diuji dan di-rollback.
2. Perubahan database dibuat melalui migration forward-only.
3. Tidak menghapus endpoint lama sebelum seluruh pemanggil diaudit.
4. Semua aksi mutasi wajib memeriksa login, permission, periode, dan CSRF.
5. Semua transisi status dilakukan di server dalam transaksi database.
6. Commit dan snapshot tidak boleh diedit langsung setelah dibuat.
7. UI tidak menampilkan aksi yang belum didukung backend.
8. Validasi bisnis menghasilkan error spesifik, bukan fallback diam-diam.
9. Data historis tidak mengandalkan role saat ini; gunakan snapshot identitas
   keanggotaan dan role pada saat aksi.
10. Masing-masing transisi harus dimodelkan sebagai service method yang jelas,
    bukan sekadar `UPDATE status` yang tersebar.
11. `audit log` harus event-based dan menyimpan state before/after.
12. `graph` dan `snapshot` harus immutable dan mengacu pada `pasal_version_id`.

### 3.1 State transition matrix formal

Setiap status harus diatur dengan matrix transisi eksplisit, misalnya:

```text
WORKSPACE
  aktif
    -> submit -> diajukan
    -> [tidak ada commit]

  diajukan
    -> withdraw -> aktif
    -> reject -> aktif
    -> approve -> siap_commit

  siap_commit
    -> co-commit -> committed

  committed
    -> terminal
```

Setiap transisi harus memiliki:

- actor
- permission
- precondition
- database mutation
- audit event
- side effect
- rollback behavior

## 4. Pembagian Sesi Implementasi

| Sesi | Fokus | Hasil utama |
|---|---|---|
| 0 | Baseline dan kontrak | Inventaris pemanggil, fixture, dan kontrak API |
| 1 | Database governance | Keanggotaan, approval staging, commit window, lockout |
| 2 | Workspace invariant | Satu workspace aktif per dokumen dan withdrawal |
| 3 | Permission dan membership | Resolver Komisi I/Ketua Umum berbasis periode |
| 4 | Editor admin | UI dokumen, workspace, BAB, pasal, dan versi |
| 5 | Staging Gate | Validasi atomik, BFS, notification cascade, self-commit |
| 6 | Dual staging approval | Approval Komisi I + Ketua Umum tanpa timer |
| 7 | Co-Commit | Inisiasi, timer, password, atomic finalization |
| 8 | Graph dan references | Snapshot graph, broken reference, peta relasi |
| 9 | Public dan history | Referensi publik, riwayat, diff, version trace |
| 10 | Testing dan rollout | E2E, migration, observability, production release |

Sesi berikutnya hanya dimulai setelah acceptance criteria sesi sebelumnya lulus.

---

## Sesi 0 — Baseline dan Kontrak

### Tujuan

Mendapatkan baseline yang dapat diulang sebelum schema dan workflow diubah.

### Pekerjaan

1. Audit seluruh pemanggil endpoint lama:
   - `api/hukum/dokumen.php`
   - `api/hukum/meja-kerja.php`
   - `api/hukum/co-commit.php`
   - endpoint relasi lama.
2. Tetapkan endpoint kanonik:
   - `documents.php`
   - `workspaces.php`
   - `versions.php`
   - `staging.php`
   - `review.php`
   - `commit.php`.
3. Tambahkan fixture database untuk:
   - dua periode;
   - anggota Komisi I;
   - Ketua Umum BPM;
   - satu dokumen;
   - BAB, pasal, dan commit awal.
4. Catat status schema sebelum migration.
5. Jalankan release check dan PHP syntax check.

### Acceptance criteria

- Tidak ada pemanggil endpoint legacy yang tidak terinventaris.
- Fixture dapat dibuat dan dihapus pada database test.
- Baseline test lulus sebelum perubahan berikutnya.

---

## Sesi 1 — Konsolidasi Database Governance

### Tujuan

Menambahkan data yang diperlukan untuk membership, approval dua pihak, dan
Co-Commit tanpa merusak data historis.

### 1.1 Tabel keanggotaan

Buat `hukum_keanggotaan`:

```sql
CREATE TABLE hukum_keanggotaan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  periode_id INT NOT NULL,
  jabatan ENUM('komisi_i','ketua_umum','sekretaris','anggota') NOT NULL,
  mulai_pada DATE NOT NULL,
  selesai_pada DATE NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hukum_keanggotaan (user_id, periode_id, jabatan),
  KEY idx_hukum_keanggotaan_periode_jabatan (periode_id, jabatan, aktif)
);
```

Tambahkan foreign key ke `users` dan `periode_kepengurusan` sesuai pola schema
yang sudah digunakan. Keanggotaan menjadi sumber kebenaran historis; `admin_role`
berfungsi sebagai kontrol akses teknis saat ini, bukan sumber sejarah.

### 1.2 Approval staging

Buat tabel `hukum_staging_approval`:

```sql
CREATE TABLE hukum_staging_approval (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staging_id INT NOT NULL,
  user_id INT NOT NULL,
  peran ENUM('komisi_i','ketua_umum') NOT NULL,
  status ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  catatan TEXT NULL,
  disetujui_pada DATETIME NULL,
  ditolak_pada DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hukum_staging_approval (staging_id, peran)
);
```

Approval harus menyimpan user yang benar-benar melakukan aksi. Jangan hanya
mengandalkan role aktif karena role dapat berubah setelah periode berganti.

### 1.3 Commit window dan otorisasi

Buat tabel:

- `hukum_commit_window`: satu inisiasi per pihak dan waktu kedaluwarsa.
- `hukum_commit_authorization`: percobaan password, hasil, waktu, dan IP.
- `hukum_commit_lockout`: cooldown user/session, bukan lockout global.

Gunakan window `expires_at` yang dihitung di server. Untuk operasional yang lebih
realistis, gunakan rentang `1-5 menit`, bukan 5 detik.

### 1.4 Penyesuaian schema

- Tambahkan `UNIQUE`/partial unique index untuk satu workspace aktif per dokumen.
- Pastikan `hukum_staging` dapat menyimpan dua approval melalui tabel terpisah.
- Tambahkan metadata commit window pada commit jika diperlukan; referensi utama
  tetap berasal dari tabel window.
- Jangan mengubah commit lama secara destruktif.
- Sisipkan `hukum_audit_log` event-based untuk semua mutasi status.

### Acceptance criteria

- Migration berjalan pada database kosong dan database fixture.
- Membership dapat menyimpan Komisi I dan Ketua Umum BPM per periode.
- Satu staging dapat memiliki tepat dua slot approval.
- Percobaan authorization dapat diaudit.
- Tidak ada mekanisme global lockout yang memblokir semua user.

---

## Sesi 2 — Workspace Invariant dan Withdrawal

### Tujuan

Menegakkan satu workspace aktif per dokumen secara aman terhadap race condition.

### Pekerjaan backend

1. Ubah `POST /api/hukum/workspaces.php`:
   - lock dokumen dengan `SELECT ... FOR UPDATE`;
   - cek workspace `aktif` atau `diajukan` untuk `dokumen_id`;
   - tolak workspace aktif kedua dengan HTTP 409;
   - catat audit.
2. Gunakan unique partial index di database:

```sql
CREATE UNIQUE INDEX uq_hukum_workspace_aktif
ON hukum_workspace(dokumen_id)
WHERE status = 'aktif';
```

3. Definisikan transisi:

```text
aktif -> submit -> diajukan
aktif -> [tidak ada commit]

diajukan -> withdraw -> aktif
diajukan -> reject -> aktif
diajukan -> approve -> siap_commit

siap_commit -> co-commit -> committed
committed -> terminal
```

4. Tambahkan endpoint withdrawal:
   - hanya pengaju/Komisi I;
   - hanya saat staging `menunggu_forum` atau `menunggu_review`;
   - kembalikan versi `staged` menjadi `draft`;
   - ubah staging menjadi `dibatalkan`;
   - ubah workspace menjadi `aktif`;
   - tulis audit.
5. Larang withdrawal setelah staging disetujui atau commit dimulai.
6. Pastikan revisi dokumen aktif membuat workspace baru pada dokumen yang sama.

### Acceptance criteria

- Dua request bersamaan tidak dapat membuat dua workspace aktif untuk dokumen
  yang sama.
- Withdrawal tidak menghapus draft.
- Workspace committed tetap read-only.
- Semua status berubah melalui service method dan audit event.

---

## Sesi 3 — Permission dan Membership Resolver

### Tujuan

Memisahkan identitas jabatan historis dari role teknis akses aplikasi.

### Pekerjaan

Tambahkan helper:

- `hukum_is_komisi_i($userId, $periodeId)`
- `hukum_is_ketua_umum($userId, $periodeId)`
- `hukum_can_review_staging($stagingId)`
- `hukum_can_commit_as($stagingId, $peran)`

Aturan:

1. Komisi I harus memiliki membership `komisi_i` aktif pada periode dokumen.
2. Ketua Umum BPM harus memiliki membership `ketua_umum` aktif dan session role
   teknis `admin`.
3. Superadmin dapat mengakses administrasi teknis, tetapi tidak otomatis
   menggantikan approval bisnis tanpa audit dan kebijakan eksplisit.
4. Semua resolver menerima `periode_id` dari dokumen, bukan dari input client.

### Acceptance criteria

- User dari periode lain tidak dapat approve atau commit.
- Perubahan role saat ini tidak mengubah identitas aktor historis.
- Semua endpoint memakai resolver yang sama.

---

## Sesi 4 — Editor Admin

### Tujuan

Membuat workflow dapat dijalankan tanpa memanggil API secara manual.

### Halaman

1. `admin/hukum-dashboard.php`
   - daftar dokumen;
   - buat dokumen;
   - buka detail.
2. `admin/hukum-workspace.php`
   - buat dan lihat workspace;
   - status dan metadata;
   - daftar draft;
   - tombol submit/withdraw.
3. Editor struktur:
   - tambah/edit BAB;
   - tambah/edit pasal;
   - nomor dan urutan;
   - validasi duplikasi.
4. Editor isi:
   - form terstruktur;
   - load versi terakhir;
   - simpan sebagai versi baru;
   - tampilkan hash dan parent version.

### Aturan UI

- Aksi ditentukan oleh permission server-side.
- Workspace `diajukan` dan commit `committed` read-only.
- Simpan Draft tidak menjalankan Staging Gate.
- Error harus menampilkan pasal dan alasan yang spesifik.
- Draft baru tidak boleh mengganti versi committed.

### Acceptance criteria

Komisi I dapat membuat dokumen, struktur, workspace, dan minimal satu versi pasal
melalui UI tanpa request manual.

---

## Sesi 5 — Staging Gate dan Impact Analysis

### Tujuan

Membuat submit staging atomik dan mencegah perubahan yang tidak selaras.

### Pekerjaan

1. Di `POST /api/hukum/staging.php`, lock workspace dan seluruh versi terkait.
2. Jalankan validasi deterministik wajib sebelum BFS:
   - duplikasi pasal/nomor;
   - struktur dokumen tidak valid;
   - broken reference;
   - invalid numbering atau ayat;
   - status draft tidak cocok dengan workspace.
3. Setelah validasi dasar lolos, jalankan impact analysis dengan BFS maksimum
   depth 10 pada graph relasi.
4. Cari notifikasi aktif pada seluruh tree terdampak.
5. Jika ada masalah, rollback transaksi dan kembalikan daftar pasal bermasalah
   serta link ke editor.
6. Jika lolos, buat staging `menunggu_forum`, ubah semua versi terpilih menjadi
   `staged`, dan ubah workspace menjadi `diajukan`.
7. Self-resolution harus sangat ketat:
   - notifikasi aktif hanya bisa auto-resolve jika child berada dalam impact
     tree yang sama;
   - child harus memiliki draft baru pada workspace yang sama;
   - draft harus berbeda dari version sebelumnya;
   - draft harus lolos semua validasi;
   - tidak ada dependency unresolved lainnya.
8. Simpan draft tidak menghasilkan notifikasi baru atau validasi staging penuh.

### Acceptance criteria

- Satu draft gagal menyebabkan semua draft gagal masuk staging.
- BFS berhenti pada depth 10.
- Tidak ada notifikasi baru saat Simpan Draft.
- Self-commit hanya berlaku pada workspace yang sama dan kondisi yang sah.
- Semakin kompleksnya impact analysis hanya ditambahkan setelah validasi dasar.

---

## Sesi 6 — Dual Staging Approval

### Tujuan

Mengubah review satu pihak menjadi dua approval tanpa timer.

### Endpoint

Ubah `POST /api/hukum/review.php` menjadi aksi approval per peran:

```json
{
  "staging_id": 1,
  "decision": "approve",
  "note": null
}
```

Server menentukan peran dari membership, bukan dari field client. Status hanya
berubah `disetujui` setelah kedua pihak menyetujui. Jika salah satu menolak,
status menjadi `ditolak` dan workspace kembali `aktif`.

### Transisi

```text
menunggu_forum
  -> menunggu_forum (approval pertama)
  -> disetujui      (approval kedua)
  -> ditolak        (salah satu menolak)
```

Saat ditolak:

- versi `staged` menjadi `rejected`;
- workspace kembali `aktif`;
- catatan penolakan wajib;
- draft historis tetap tersimpan.

### UI

- tampilkan indikator `0/2`, `1/2`, atau `2/2`;
- tampilkan identitas dan waktu approval;
- tombol tolak membutuhkan alasan;
- tombol withdrawal hilang setelah `disetujui`.

### Acceptance criteria

- Satu approval tidak mengubah status menjadi `disetujui`.
- Approval kedua mengubah status secara atomik.
- User tanpa membership yang sesuai menerima 403.
- Tidak ada timer ketat pada staging approval.

---

## Sesi 7 — Co-Commit

### Tujuan

Menerapkan pengesahan final dua pihak dengan window independen, verifikasi
password, dan finalisasi atomik.

### Alur

1. Pastikan staging `disetujui` dan approval kedua pihak ada.
2. Komisi I menginisiasi window.
3. Ketua Umum BPM menginisiasi window.
4. Setiap window memiliki `expires_at` sendiri; gunakan rentang server-side
   sekitar `1-5 menit`, bukan 5 detik.
5. Setelah kedua window aktif, masing-masing memasukkan password.
6. Server memvalidasi:
   - membership aktif berdasarkan periode dokumen;
   - role teknis actual saat request;
   - password hash yang benar;
   - window belum kedaluwarsa;
   - cooldown user/session tidak aktif.
7. Setelah dua authorization valid, jalankan finalisasi dalam satu transaksi.

### Finalisasi atomik

1. Lock staging, workspace, pasal, dan commit aktif.
2. Validasi ulang hash konten dan notifikasi aktif.
3. Gabungkan versi staged dengan versi committed yang tidak berubah.
4. Sertakan snapshot graph.
5. Hitung hash commit memakai canonical JSON.
6. Insert commit baru.
7. Tandai commit lama `digantikan`.
8. Tandai versi staged `committed`.
9. Auto-resolve notifikasi self-commit yang valid.
10. Tandai staging `disetujui` dan workspace `ditutup`.
11. Ubah dokumen menjadi `aktif`.
12. Commit transaksi.

### Kegagalan

- Window expired: batalkan authorization yang belum lengkap.
- Password salah: catat percobaan gagal dan evaluasi cooldown per-user/per-session.
- Deadlock atau constraint: rollback dan tampilkan error operasional.
- Tidak ada commit setengah jalan.
- Tidak ada mekanisme global lockout yang memblokir semua user.

### Acceptance criteria

- Satu pihak tidak dapat commit sendirian.
- Password tidak pernah disimpan plaintext.
- Timer diverifikasi server-side, bukan hanya JavaScript.
- Snapshot tidak berubah setelah commit.
- Kegagalan password membatasi user/session, bukan seluruh sistem.

---

## Sesi 8 — Graph dan Reference

### Tujuan

Menyatukan relasi manual, inline reference, audit, dan snapshot historis.

### Pekerjaan

1. Gunakan `hukum_relasi_pasal` sebagai source of truth relational, bukan graph
   database pada tahap awal.
2. Setiap relasi menyimpan `source_version_id` dan `target_version_id`, bukan
   hanya `pasal_id`.
3. Tetapkan enum `jenis_relasi` final dan pilih skema referensi inline yang
   konsisten: prioritas satu tabel `hukum_relasi_pasal` dengan kolom `dibuat_oleh`.
4. Buat parser canonical untuk:
   - `[[PASAL:N]]`;
   - format lintas dokumen;
   - ayat opsional.
5. Sinkronkan relasi dalam transaksi saat versi disimpan.
6. Buat orphan check sebelum staging.
7. Simpan snapshot node dan edge pada commit serta jadikan snapshot immutable.
8. Tambahkan audit asal relasi `auto` atau `manual`.
9. Hindari `broken link` pada revisi dokumen karena revisi memakai dokumen ID yang
   sama, bukan ID baru.

### Acceptance criteria

- Referensi valid dapat dilacak ke target.
- Referensi rusak menghasilkan status yang dapat ditindaklanjuti.
- Relasi commit lama tetap dapat dibaca walaupun struktur aktif berubah.
- Bagian publik tidak pernah menampilkan `dibuat_oleh_user_id` atau data staging.

---

## Sesi 9 — Public, History, dan Diff

### Tujuan

Menyediakan transparansi publik tanpa membocorkan draft atau audit internal.

### Pekerjaan

1. Tambahkan inline reference sebagai link publik.
2. Tampilkan target dicabut atau tidak ditemukan secara aman.
3. Tambahkan halaman riwayat commit.
4. Tambahkan selector commit/periode.
5. Tambahkan diff isi pasal antar commit.
6. Tambahkan peta relasi berbasis snapshot.
7. Tampilkan hash commit dan tanggal forum.
8. Pastikan query publik hanya mengambil:
   - dokumen `aktif`;
   - commit `aktif`;
   - snapshot commit.

### Acceptance criteria

- Draft, staging, versi rejected, dan commit superseded tidak tampil publik.
- Link referensi tidak dapat mengakses dokumen privat.
- Riwayat lama konsisten dengan snapshot saat commit dibuat.

---

## Sesi 10 — Testing, Observability, dan Rollout

### 10.1 Pengujian unit/integrasi

Uji minimal:

- permission per role dan periode;
- membership kedaluwarsa;
- slug duplikat;
- satu workspace aktif per dokumen;
- concurrent workspace creation;
- immutable version;
- atomic staging;
- BFS depth limit;
- self-commit;
- dua approval staging;
- withdrawal;
- timer expiry;
- password salah;
- cooldown per-user/per-session;
- commit snapshot dan parent hash;
- publikasi active-only.

### 10.2 Pengujian end-to-end

Skenario sukses:

```text
Komisi I login
 -> buat dokumen draft
 -> buat BAB dan pasal
 -> buat workspace
 -> simpan versi
 -> submit staging
 -> approval Komisi I
 -> approval Ketua Umum BPM
 -> inisiasi Co-Commit dua pihak
 -> password dua pihak
 -> commit
 -> verifikasi halaman publik
```

Skenario gagal:

- user beda periode;
- approval hanya satu pihak;
- staging ditolak;
- notifikasi graph aktif;
- withdrawal setelah approval;
- password salah;
- timer expired;
- commit lockout;
- manipulasi isi setelah hash dibuat.

### 10.3 Observability

Catat:

- transisi status;
- durasi staging;
- jumlah reject dan withdrawal;
- kegagalan authorization;
- lockout;
- kegagalan transaksi;
- mismatch hash;
- broken reference.

Jangan mencatat password atau token sensitif.

### 10.4 Rollout

1. Backup database.
2. Jalankan migration di staging.
3. Jalankan inventory dan schema contract check.
4. Jalankan test suite dan smoke test API.
5. Aktifkan UI editor untuk Komisi I terbatas.
6. Verifikasi approval dan commit dengan fixture.
7. Aktifkan publikasi setelah satu alur sukses.
8. Monitor audit dan error selama periode observasi.
9. Aktifkan enforcement penuh setelah data dan permission tervalidasi.

Rollback hanya melalui migration rollback yang disiapkan atau feature flag.
Jangan menghapus commit historis atau menjalankan destructive reset.

## 5. Urutan Dependensi

```text
Sesi 0
  -> Sesi 1
      -> Sesi 2
      -> Sesi 3
          -> Sesi 4
          -> Sesi 5
              -> Sesi 6
                  -> Sesi 7
Sesi 5 -> Sesi 8 -> Sesi 9
Semua sesi -> Sesi 10
```

Sesi 4 dan Sesi 8 dapat berjalan paralel setelah Sesi 3, tetapi hanya jika
kontrak data dan endpoint tidak berubah tanpa koordinasi.

## 6. Definition of Done

Implementasi dianggap selesai jika:

1. Komisi I dapat menyusun dan menyimpan perubahan melalui UI.
2. Hanya satu workspace aktif per dokumen.
3. Submit staging bersifat atomik dan menjalankan impact analysis.
4. Approval staging membutuhkan Komisi I dan Ketua Umum BPM.
5. Co-Commit final membutuhkan dua password dan timer server-side.
6. Commit immutable dengan snapshot dan hash chain.
7. Revisi memakai dokumen ID yang sama.
8. Publik hanya melihat commit aktif.
9. Audit dapat menjawab siapa, kapan, apa, dan pada periode apa suatu aksi
   dilakukan.
10. Release checks, migration checks, dan E2E workflow lulus.

---

# SESSION 0 RESULT

## Repository Architecture

- Arsitektur repo saat ini adalah monolit PHP kustom, bukan framework MVC seperti Laravel/CodeIgniter.
- Entry point aplikasi ditentukan oleh file PHP halaman publik dan panel admin, misalnya `header.php`, `footer.php`, `hukum.php`, `hukum-detail.php`, serta `admin/core/header.php` dan `admin/hukum-dashboard.php`.
- Session/auth global dikonfigurasi di `config/app.php` dengan `session_start()`, cookie policy, idle timeout, dan environment check.
- Autentikasi dan otorisasi umum ada di `includes/functions.php` (`isLoggedIn()`, `requireLogin()`, `csrfVerify()`, `csrfToken()`), serta modul Hukum spesifik di `admin/core/hukum-auth.php`.
- Koneksi database di `config/database.php` dengan `getConnection()`, `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, insert/update helper, dan driver mysql/pgsql.
- Migrasi schema Hukum berada di `databases/migrations/2026-09-08-hukum-schema.sql` dan `databases/migrations/2026-09-08-hukum-schema.pgsql.sql`.
- API Hukum terpusat di `api/hukum/` dengan bootstrap `api/hukum/_bootstrap.php` untuk validasi method, csrf, JSON input, audit, dan shared helpers.
- UI admin saat ini sudah memanggil API Hukum via `fetch(hukumBase + endpoint)` di `admin/hukum-dashboard.php` dan `admin/hukum-staging.php`.
- Publik saat ini membaca snapshot commit yang aktif melalui `hukum.php` dan `hukum-detail.php`.

## Hukum Existing

- Tabel utama yang benar-benar ada saat ini: `hukum_dokumen`, `hukum_bab`, `hukum_pasal`, `hukum_workspace`, `hukum_pasal_versi`, `hukum_staging`, `hukum_staging_versi`, `hukum_commit`, `hukum_commit_approval`, `hukum_relasi_pasal`, `hukum_notifikasi`, `hukum_audit_log`, dan variannya di migrasi SQL.
- Fokus Hukum yang sudah terimplementasi pada baseline saat ini adalah:
- dokumen legal serta struktur bab/pasal;
- workspace aktif per dokumen;
- draft version per pasal;
- staging review awal;
- commit dan snapshot aktif;
- public page untuk dokumen aktif;
- audit log dan inline reference helper.
- Yang belum final secara bisnis: keanggotaan per periode (`hukum_keanggotaan`), dual approval staging, lockout per-user bukan global, dan scoping yang jelas antara `workspace`, `staging`, `approval`, dan `commit`.

## Legacy Callers

- Tidak ada file legacy `api/hukum/dokumen.php`, `api/hukum/meja-kerja.php`, atau `api/hukum/co-commit.php` pada repo aktual. Endpoint yang terbaca saat ini adalah nama kanonik yang sudah diberi pembaruan: `documents.php`, `workspaces.php`, `versions.php`, `staging.php`, `review.php`, `commit.php`, `relations.php`, `references.php`, `notifications.php`, `bab.php`, `pasal.php`.
- Caller utama yang terbaca di repo:
- `admin/hukum-dashboard.php` -> `api/hukum/documents.php`, `bab.php`, `workspaces.php`, `notifications.php`
- `admin/hukum-staging.php` -> `api/hukum/staging.php`, `review.php`
- `hukum-detail.php` -> query langsung ke table Hukum, bukan API
- `hukum.php` -> daftar dokumen aktif
- Tidak ada endpoint Hukum yang dihapus pada baseline ini; semua endpoint yang ada masih berjalan sebagai file terpisah di `api/hukum/`.

## Code Placement Map

| Capability | Existing File | Decision | Proposed Location | Reason |
| --- | --- | --- | --- | --- |
| Session/auth global | `config/app.php`, `includes/functions.php` | REUSE | `config/app.php` + `includes/functions.php` | Sumber kebenaran session dan CSRF sudah ada; tidak perlu duplikasi. |
| Hukum auth/permission | `admin/core/hukum-auth.php` | REUSE + REFACTOR | `admin/core/hukum-auth.php` | Logika role/pengguna sudah siap, tetapi harus disinkronkan dengan `hukum_keanggotaan` dan per-periode membership. |
| Hukum API bootstrap | `api/hukum/_bootstrap.php` | REUSE + REFACTOR | `api/hukum/_bootstrap.php` | Shared validation dan helper sudah ada; butuh penambahan pemeriksaan role/approval lebih formal. |
| Document CRUD | `api/hukum/documents.php` | REUSE + REFACTOR | `api/hukum/documents.php` | Endpoint ini adalah kanonik untuk dokumen; hanya perlu validasi periode dan unique constraint yang lebih ketat. |
| BAB CRUD | `api/hukum/bab.php` | REUSE | `api/hukum/bab.php` | Struktur bab sudah ada dan cocok untuk tetap dipakai. |
| Pasal CRUD | `api/hukum/pasal.php` | REUSE | `api/hukum/pasal.php` | Logika pasal dan nomor label sudah jelas. |
| Version draft | `api/hukum/versions.php` | REUSE + REFACTOR | `api/hukum/versions.php` | Draft version per pasal sudah ada, perlu integrasi ke approval dan snapshot. |
| Workspace lifecycle | `api/hukum/workspaces.php` | REUSE + REFACTOR | `api/hukum/workspaces.php` | Ini adalah titik kondisinya; butuh invariant satu workspace aktif per dokumen yang lebih ketat. |
| Staging review | `api/hukum/staging.php` | REUSE + REFACTOR | `api/hukum/staging.php` | Saat ini masih single-review; harus diubah ke dua approval terpisah. |
| Review action | `api/hukum/review.php` | REUSE + REFACTOR | `api/hukum/review.php` | Menjadi approval per role dan state transition, bukan `approve/reject` tunggal. |
| Commit final | `api/hukum/commit.php` | NEW/REUSE | `api/hukum/commit.php` | File ada, tetapi belum sepenuhnya konsisten dengan commit final dua-pihak dan snapshot hash. |
| Graph/relations | `api/hukum/relations.php`, `api/hukum/references.php` | NEW | `api/hukum/relations.php` + `api/hukum/references.php` | Struktur konseptual sudah ada, tetapi belum di-harden sesuai graph snapshot final. |
| Public pages | `hukum.php`, `hukum-detail.php` | REUSE + REFACTOR | `hukum.php`, `hukum-detail.php` | Publik sudah membaca dokumen aktif; butuh filter yang lebih tegas dan info commit hash/footer. |
| Admin Hukum UI | `admin/hukum-dashboard.php`, `admin/hukum-staging.php` | REUSE + REFACTOR | `admin/hukum-dashboard.php`, `admin/hukum-staging.php` | UI sudah ada, namun perlu sinkron dengan dual approval serta state keseluruhan. |

## API Contract

Canonical endpoint yang terlihat saat ini:

- `GET /api/hukum/documents.php` — tampilkan dokumen, filter per periode jika non-admin
- `POST /api/hukum/documents.php` — buat dokumen baru, validasi jenis/slug/periode
- `GET /api/hukum/bab.php` — daftar BAB berdasarkan dokumen
- `POST /api/hukum/bab.php` — buat BAB baru
- `GET /api/hukum/pasal.php` — daftar pasal berdasarkan dokumen
- `POST /api/hukum/pasal.php` — buat pasal baru
- `GET /api/hukum/versions.php?pasal_id=...` — lihat versi pasal
- `POST /api/hukum/versions.php` — simpan draft pasal versi baru
- `GET /api/hukum/workspaces.php?dokumen_id=...` — lihat workspace dokumen
- `POST /api/hukum/workspaces.php` — buat workspace aktif
- `GET /api/hukum/staging.php?id=...` atau tanpa id — lihat staging
- `POST /api/hukum/staging.php` — submit workspace ke staging
- `POST /api/hukum/review.php` — approve/reject staging, masih single decision
- `POST /api/hukum/commit.php` — commit final (belum sepenuhnya final sesuai desain baru)
- `GET /api/hukum/notifications.php` — daftar notifikasi peninjauan / review

Conflict atau drift yang teridentifikasi:

- `hukum_staging` saat ini hanya menyimpan satu status `menunggu_review` dan satu keputusan reviewer tunggal, tidak sesuai model final dua approval.
- `review.php` memeriksa `hukum.staging.review` saja, bukan role-based approval terhadap Komisi I dan Ketua Umum BPM yang berbeda.
- `workspaces.php` mengecek status `aktif` / `diajukan` pada dokumen secara umum, tetapi belum memakai per-dokumen unique constraint yang lebih formal di DB.
- `hukum_commit` dan `hukum_commit_approval` belum sepenuhnya terhubung dengan model final co-commit dua pihak.
- Public UI tidak membedakan antara snapshot aktif dan draft/staging internal.

## Database Baseline

Tabel dan sumber kebenaran yang sudah ada di repo:

- `hukum_dokumen` — dokumen hukum utama
- `hukum_bab` — BAB per dokumen
- `hukum_pasal` — pasal per dokumen
- `hukum_workspace` — workspace edit, status aktif/diajukan
- `hukum_pasal_versi` — versi draft/committed pasal
- `hukum_staging` — tahap review
- `hukum_staging_versi` — mapping staging ke versi
- `hukum_commit` — commit final dan snapshot
- `hukum_commit_approval` — approval co-commit
- `hukum_relasi_pasal` — relasi antar pasal
- `hukum_notifikasi` — notifikasi review / cascade
- `hukum_audit_log` — audit log event-based

Tabel yang masih perlu ditambahkan/di-finalisasi untuk governance yang benar:

- `hukum_keanggotaan` — per periode, mengikat Komisi I dan Ketua Umum BPM secara historis
- `hukum_staging_approval` — approval dua pihak terpisah
- `hukum_commit_window` — inisiasi window per pihak
- `hukum_commit_lockout` / `hukum_lockout` — cooldown non-global, per-user/per-session

## Files Created

- `databases/migrations/2026-09-08-hukum-schema.sql`
- `databases/migrations/2026-09-08-hukum-schema.pgsql.sql`
- `databases/migrations/2026-09-08-hukum-data-migration.php`
- `api/hukum/_bootstrap.php`
- `api/hukum/documents.php`
- `api/hukum/bab.php`
- `api/hukum/pasal.php`
- `api/hukum/versions.php`
- `api/hukum/workspaces.php`
- `api/hukum/staging.php`
- `api/hukum/review.php`
- `api/hukum/commit.php`
- `api/hukum/relations.php`
- `api/hukum/notifications.php`
- `api/hukum/references.php`
- `admin/core/hukum-auth.php`
- `admin/hukum-dashboard.php`
- `admin/hukum-staging.php`
- `hukum.php`
- `hukum-detail.php`
- `PRD.md`
- `meja-kerja.md`
- `staging.md`
- `commit.md`
- `graph.md`
- `ui.md`
- `implementasi.md`

## Files Modified

- `admin/core/header.php`
- `config/app.php`
- `header.php`
- `footer.php`

## Tests Executed

- `php -l config/app.php` -> PASS (no syntax errors)
- `php -l includes/functions.php` -> PASS (no syntax errors)
- `php -l config/database.php` -> PASS (no syntax errors)
- `php -l admin/core/hukum-auth.php` -> PASS (no syntax errors)
- `php -l api/hukum/*.php` -> PASS for the API files checked; no PHPLint errors were reported
- `npm test` -> FAIL by design because repository script is `echo "Error: no test specified" && exit 1`

## Acceptance Criteria

[ ] Repository inspected
[ ] Legacy callers inventoried
[ ] Existing Hukum files mapped
[ ] API contract mapped
[ ] DB baseline known
[ ] Existing tests pass
[ ] Syntax check pass
[ ] No legacy endpoint deleted

Catatan: item test pass tidak tercapai pada baseline karena script `npm test` default repo memang gagal secara eksplisit. Item syntax check pass tercapai, tetapi integrasi runtime dengan database belum diuji karena environment local belum dijalankan dengan DB aktif.

## BLOCKERS

- BLOCKER / DESIGN DECISION REQUIRED: status final governance untuk `hukum_keanggotaan` belum disetujui oleh owner, meski skema teknis sudah jelas.
- BLOCKER / DESIGN DECISION REQUIRED: owner harus memastikan apakah lockout global benar-benar dilarang pada produk akhir; baseline repo mencatat konsep lockout di beberapa dokumen, tapi keputusan akhir yang benar adalah lockout per-user/per-session.
- BLOCKER: runtime integration test masih dibatasi oleh tidak adanya DB app server atau credential aktif yang dipasang untuk app lokal.

## NEXT SESSION

SESSION 1 — Database Governance

Agenda yang aman untuk sesi berikutnya:

1. Menambahkan tabel `hukum_keanggotaan`.
2. Memperjelas `hukum_staging_approval` dan `hukum_commit_approval` dengan approval dua pihak.
3. Menetapkan invariant `workspace aktif` per `dokumen_id` di DB dan server-side.
4. Menyuntikkan policy per-role dan per-periode pada API Hukum.
5. Menjaga semua file yang ada tetap berjalan tanpa menghapus endpoint lama.

---

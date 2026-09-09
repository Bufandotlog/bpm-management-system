# PRD — Sistem Hukum BPM

## 1. Status Dokumen

- **Status:** Draft produk
- **Modul:** Hukum
- **Penyusun utama:** Komisi I
- **Reviewer utama:** Ketua Umum BPM yang ditetapkan melalui keanggotaan periode
  dan memiliki role teknis `admin`
- **Pengesahan commit:** Co-Commit Komisi I dan Ketua Umum BPM
- **Dokumen ini menjadi acuan:** alur bisnis, peran, status, dan batasan workflow Hukum

## 2. Tujuan Produk

Sistem Hukum menjadi tempat resmi untuk menyusun, meninjau, mengesahkan, menyimpan,
dan mempublikasikan dokumen hukum BPM serta dokumen hukum organisasi mahasiswa
yang berada dalam lingkup BPM.

Sistem harus menjaga agar:

1. Dokumen publik hanya berisi perubahan yang telah disahkan.
2. Setiap perubahan memiliki pembuat, reviewer, persetujuan Co-Commit, dasar forum, dan jejak audit.
3. Isi lama tidak ditimpa; setiap perubahan disimpan sebagai versi baru.
4. Akses data mengikuti periode kepengurusan dokumen.

## 3. Aktor dan Tanggung Jawab

### 3.1 Komisi I — Penyusun

Komisi I adalah penyusun utama seluruh dokumen hukum. Tanggung jawabnya:

- Membuat draft dokumen.
- Menentukan struktur BAB dan pasal.
- Membuat workspace perubahan.
- Menulis atau memperbarui isi pasal.
- Menyusun perubahan yang akan diajukan.
- Mengirim workspace ke proses review.
- Menindaklanjuti catatan penolakan reviewer.

Komisi I tidak mempublikasikan perubahan secara langsung.

### 3.2 Reviewer — Akun Role `admin`

Reviewer ditentukan dari tabel keanggotaan per periode (`hukum_keanggotaan`),
dengan jabatan Ketua Umum BPM, dan harus memiliki role teknis `admin`.

Approval staging wajib memiliki dua konfirmasi terpisah: Komisi I dan Ketua Umum
BPM. Tidak ada timer ketat pada tahap staging. Status baru menjadi `disetujui`
setelah kedua pihak menyetujui.

Reviewer bertanggung jawab untuk:

- Memeriksa isi, struktur, dan metadata perubahan.
- Memastikan perubahan sesuai keputusan atau forum yang menjadi dasar.
- Menyetujui staging jika valid.
- Menolak staging jika perlu perbaikan, dengan catatan wajib.

Reviewer hanya dapat meninjau dokumen pada periode yang menjadi kewenangannya.
Superadmin atau akun dengan akses lintas periode dapat memiliki akses teknis
tambahan sesuai kebijakan administrator.

### 3.3 Co-Commit

Commit resmi harus dilakukan bersama oleh:

- Komisi I sebagai penyusun/pengaju; dan
- Ketua Umum BPM yang terdaftar pada periode dokumen dan memiliki role `admin`.

Kedua pihak melakukan inisiasi dan persetujuan masing-masing dalam window
independen. Tidak ada pengesahan sepihak, termasuk oleh superadmin, dalam alur
normal.

### 3.4 Superadmin

Superadmin memiliki akses lintas periode dan seluruh izin modul. Akses ini
digunakan untuk administrasi, pemulihan, audit, dan dukungan teknis; bukan untuk
menggantikan proses review normal tanpa alasan yang terdokumentasi.

### 3.5 Pengguna Publik

Pengguna publik hanya dapat membaca dokumen dengan status `aktif` dan snapshot
commit aktif. Draft, staging, versi ditolak, dan commit yang sudah digantikan
tidak boleh tampil di halaman publik.

## 4. Model Data Konseptual

```text
Periode Kepengurusan
        |
        v
Keanggotaan Hukum
 (Komisi I / Ketua Umum)
        |
        v
Dokumen Hukum
   |             |
   v             v
BAB/Pasal     Workspace Perubahan
                  |
                  v
             Versi Isi Pasal
                  |
                  v
                Staging
                  |
                  v
           Review oleh Admin
                  |
                  v
            Co-Commit
                  |
                  v
          Snapshot Resmi
                  |
                  v
              Publikasi
```

`periode_id` tetap digunakan sebagai foreign key internal. Pada antarmuka,
pengguna memilih nama periode; sistem menerjemahkannya ke ID yang sesuai.

## 5. Alur Kerja Utama

### Fase 1 — Membuat dokumen

1. Komisi I masuk ke panel admin.
2. Komisi I membuat dokumen baru.
3. Komisi I memilih jenis, lingkup, periode, judul, slug, dan deskripsi.
4. Sistem membuat dokumen dengan status `draft`.
5. Slug harus unik secara global.

Jenis dokumen yang didukung:

- AD
- ART
- GBHO
- GBMO
- PERATURAN
- KEPUTUSAN

### Fase 2 — Menyusun struktur

1. Komisi I membuat BAB.
2. Komisi I membuat pasal di dalam BAB.
3. Sistem memvalidasi kepemilikan dokumen, nomor, dan urutan.
4. Struktur hanya dapat dibuat atau diubah selama dokumen masih berada pada tahap
   penyusunan yang diizinkan.

### Fase 3 — Membuka workspace

1. Komisi I membuat workspace untuk satu paket perubahan.
2. Workspace memiliki judul perubahan dan tujuan.
3. Sistem hanya boleh memiliki satu workspace berstatus `aktif` untuk setiap
   dokumen hukum.
4. Workspace yang berstatus `diajukan` tidak dapat diedit. Setelah review ditolak,
   workspace yang sama kembali `aktif`; setelah commit berhasil, workspace
   diarsipkan sebagai read-only.
5. Workspace menjadi tempat seluruh versi draft perubahan.

Contoh judul workspace:

> Perubahan ART hasil Musyawarah BPM 2026

### Fase 4 — Menyusun versi isi

1. Komisi I memilih pasal yang akan dibuat atau diubah.
2. Isi pasal disimpan sebagai JSON terstruktur.
3. Setiap penyimpanan menghasilkan record versi baru.
4. Sistem membuat hash konten untuk menjaga integritas.
5. Versi lama tetap tersimpan dan tidak ditimpa.
6. Versi dapat merujuk versi sebelumnya melalui `dibuat_dari_versi_id`.

### Fase 5 — Mengajukan staging

1. Komisi I memilih versi pasal yang sudah siap.
2. Sistem memastikan semua versi berasal dari workspace yang sama dan masih
   berstatus `draft`.
3. Sistem menjalankan validasi atomik dan Staging Gate BFS hingga depth 10.
4. Jika validasi gagal, seluruh submit ditolak dan workspace tetap `aktif`.
5. Jika lolos, sistem membuat staging berstatus `menunggu_forum`.
6. Versi terpilih berubah menjadi `staged`.
7. Workspace berubah menjadi `diajukan`.

### Fase 6 — Review oleh akun role `admin`

1. Komisi I dan Ketua Umum BPM membuka detail Review/Staging.
2. Kedua pihak memeriksa metadata perubahan dan versi pasal.
3. Masing-masing memberikan konfirmasi **Setujui** atau **Tolak** dengan catatan
   wajib jika menolak.
4. Jika salah satu pihak menolak:
   - Staging menjadi `ditolak`.
   - Versi menjadi `rejected`.
   - Workspace kembali menjadi `aktif`.
   - Komisi I memperbaiki dengan membuat versi baru.
5. Jika kedua pihak menyetujui:
   - Staging menjadi `disetujui`.
   - Perubahan siap masuk ke proses Co-Commit.

### Fase 7 — Co-Commit dan pengesahan

1. Komisi I dan reviewer `admin` menginisiasi commit secara terpisah.
2. Masing-masing pihak menyetujui dalam timer independen, termasuk verifikasi
   password.
3. Jika salah satu window berakhir atau persetujuan gagal, seluruh proses
   rollback ke kondisi sebelum commit dan dapat diulang.
4. Setelah kedua persetujuan valid, perubahan dilengkapi dengan:
   - Jenis forum.
   - Tanggal forum.
5. Sistem mengambil versi staged untuk pasal yang berubah.
6. Sistem mempertahankan versi committed untuk pasal yang tidak berubah.
7. Sistem membuat snapshot lengkap seluruh pasal dan graph.
8. Sistem menghitung hash commit dan menyimpan parent commit.
9. Commit sebelumnya ditandai `digantikan`.
10. Versi staged menjadi `committed`.
11. Workspace ditutup dan diarsipkan read-only.
12. Dokumen menjadi `aktif`.
13. Sistem membuat atau menyelesaikan notifikasi relasi jika perubahan berdampak
    pada pasal lain.

### Fase 8 — Publikasi

Halaman publik membaca dokumen aktif dan snapshot commit aktif. Dengan demikian,
perubahan draft atau staging tidak dapat terlihat publik sebelum proses review dan
commit selesai.

## 6. Status yang Digunakan

### Dokumen

| Status | Makna |
|---|---|
| `draft` | Dokumen masih disusun |
| `aktif` | Memiliki commit resmi yang dipublikasikan |
| `diarsipkan` | Tidak lagi menjadi dokumen aktif |

### Workspace

| Status | Makna |
|---|---|
| `aktif` | Sedang dikerjakan Komisi I; maksimal satu workspace aktif per dokumen |
| `diajukan` | Sudah dikirim ke staging dan dikunci dari edit |
| `committed` / `ditutup` | Commit berhasil; read-only permanen. `committed` adalah istilah bisnis, sedangkan schema aktual memakai `ditutup` |
| `dibatalkan` | Dihentikan tanpa commit |

### Versi pasal

| Status | Makna |
|---|---|
| `draft` | Dapat diperbaiki sebelum diajukan |
| `staged` | Sedang berada dalam staging |
| `committed` | Menjadi bagian dari commit resmi |
| `replaced` | Pernah resmi tetapi digantikan commit berikutnya |
| `rejected` | Ditolak reviewer |

### Staging

| Status | Makna |
|---|---|
| `menunggu_forum` / `menunggu_review` | Menunggu pemeriksaan/review sebelum pengesahan. `menunggu_forum` adalah istilah bisnis rancangan, sedangkan API/schema aktual saat ini memakai `menunggu_review` |
| `disetujui` | Disetujui dan dapat di-commit |
| `ditolak` | Ditolak dengan catatan |
| `dibatalkan` | Tidak dilanjutkan |

## 7. Aturan Bisnis Penting

1. Komisi I adalah pemilik proses penyusunan.
2. Ketua Umum BPM ditentukan dari `hukum_keanggotaan` per periode dan wajib
   memiliki role teknis `admin`.
3. Review tidak boleh dilakukan oleh pengguna tanpa akses ke periode dokumen.
4. Penolakan wajib memiliki alasan.
5. Isi pasal tidak boleh diedit dengan menimpa versi lama.
6. Commit hanya dapat dibuat dari staging yang sudah disetujui.
7. Dokumen publik harus berasal dari commit aktif.
8. Perubahan lintas periode harus ditolak kecuali pengguna memiliki akses lintas
   periode.
9. Semua aksi penting harus masuk audit log.
10. Staging wajib memiliki approval Komisi I dan Ketua Umum BPM.
11. Commit resmi wajib melalui Co-Commit Komisi I dan Ketua Umum BPM.
12. Setiap commit harus menyimpan dasar forum dan tanggal forum.

## 8. Fitur yang Sudah Tersedia dan yang Masih Dibutuhkan

### Sudah tersedia

- Skema database kanonik.
- API dokumen, BAB, pasal, workspace, versi, staging, review, dan commit.
- Pembatasan akses berdasarkan role dan periode.
- Audit log dan hash konten/commit.
- Dashboard admin dan halaman Review/Staging.
- Halaman publik berbasis snapshot commit.

### Masih dibutuhkan

- Editor admin lengkap untuk BAB, pasal, dan isi pasal.
- Preview perubahan sebelum submit.
- Perbandingan versi lama dan versi baru.
- Tampilan detail staging untuk reviewer.
- UI commit dengan metadata forum.
- Proses penyelesaian notifikasi relasi.
- Validasi format khusus untuk setiap jenis dokumen.
- Enforcement satu workspace aktif per dokumen.
- Tabel keanggotaan periode untuk menentukan Komisi I dan Ketua Umum BPM.
- Approval staging dua pihak tanpa timer ketat.
- Staging Gate BFS depth 10, cascade notification, dan self-commit sesuai
  rancangan.
- Co-Commit dua pihak, timer independen, verifikasi password, dan global
  lockout.

## 9. Keputusan yang Masih Perlu Dikonfirmasi

Bagian berikut belum ditetapkan oleh pemilik produk:

1. Apakah Komisi I boleh menjadi reviewer apabila juga memiliki role `admin`?
2. Apakah perubahan metadata dokumen, BAB, dan pasal wajib melalui staging?
3. Bagaimana aturan untuk menghapus pasal, memindahkan pasal, atau menggabungkan
   pasal?
4. Apakah dasar forum dan tanggal forum wajib diisi sebelum review, atau sebelum
   commit?
5. Siapa yang menyelesaikan notifikasi ketika pasal induk berubah?
6. Apakah dokumen `diarsipkan` masih dapat dilihat publik sebagai arsip?

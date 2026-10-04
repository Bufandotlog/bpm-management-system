# Staging — Sistem Hukum BPM

> **Status dokumen**: Konsolidasi final dari diskusi rancangan.
> **Dokumen terkait**: `meja-kerja.md`, `commit.md`, `graph.md`
> **Peran yang terlibat**: Komisi I (pengaju), Ketua Umum BPM (approver),
> dan role teknis `admin` sebagai kontrol akses.

---

## 1. Filosofi: "Gatekeeper System"

Staging adalah **gerbang validasi sistemik dan atomik** sebelum draft masuk ke tahap pengesahan formal (Commit). Berbeda dari "Simpan Draft" (lihat `meja-kerja.md` Fase B) yang hanya menyimpan ke meja kerja tanpa validasi, Staging memastikan **integritas hierarki dokumen hukum** sebelum dianggap layak direview.

**Tujuan utama**: mencegah dokumen yang "rusak secara logika" — misalnya pasal anak berubah tetapi pasal induk tidak diselaraskan, atau ada duplikasi nomor pasal — masuk ke tahap Commit.

**Efek locking**: begitu draft berhasil masuk staging, draft tersebut dikunci dari pengeditan langsung supaya proses review memiliki objek yang tetap.

---

## 2. Alur Draft, Sinyal Dampak, dan Staging

### A. Save Draft dan hubungan searah

1. Relasi disimpan saat Pasal anak (B) menyimpan `acuan_pasal_id`; backend membentuk edge `pasal_anak_id = B` dan `pasal_induk_id = A`.
2. Picker mencari dokumen → BAB → Pasal pada dokumen yang dapat diakses pengguna. Satu Pasal hanya memiliki satu target `mengacu`; satu target dapat diacu banyak Pasal. Target invalid atau periode tanpa izin membatalkan penyimpanan versi secara atomik.
3. Draft pertama sebuah Pasal tidak menimbulkan sinyal dampak. Sinyal dibuat ketika versi baru mengubah Pasal yang telah memiliki versi `committed`.
4. Setelah relasi tersinkron, backend melakukan BFS searah dari induk yang berubah ke seluruh anak yang mengacu kepadanya, termasuk lintas dokumen dan rantai A → B → C. Siklus aman karena node yang sudah dikunjungi tidak diproses ulang.
5. Relasi terdampak menerima notifikasi `perlu_ditinjau`. Editor mengambil ulang notifikasi dokumen aktif setelah Save Draft dan menampilkan banner merah pada Pasal anak.
6. Commit menyimpan edge lintas dokumen dalam snapshot sumber; halaman publik menautkan hanya ke target dalam dokumen aktif dan snapshot aktif. Penghapusan target diblokir selama ada relasi snapshot aktif.

### B. Menyelesaikan sinyal

1. Jika teks/konten Pasal anak berubah (bukan hanya daftar acuan), Save Draft versi baru anak mengubah notifikasi aktif untuk anak tersebut menjadi `sudah_diselaraskan`.
2. Jika perubahan induk tidak memerlukan perubahan pada anak, pengguna dapat memilih **"Tidak perlu berubah"** dan menyimpan alasan minimal 10 karakter. Status menjadi `diabaikan_dengan_alasan`.
3. Mengubah daftar acuan anak juga menyinkronkan graph; relasi yang dihapus tidak lagi memiliki notifikasi aktif karena notifikasi terikat ke relasi tersebut.

### C. Submit Staging dan Review

Saat Komisi I klik **"Submit Staging"**:

1. Backend mengunci workspace dan dokumen lalu memeriksa notifikasi `perlu_ditinjau` yang masih aktif pada dokumen. Satu saja sinyal aktif menolak staging dengan HTTP 409; menyimpan draft tetap diperbolehkan.
2. Setelah hard block lolos, validasi referensi dan versi dijalankan, seluruh versi terpilih ditautkan secara atomik ke staging dan berstatus `staged`.
3. Workspace berubah menjadi `diajukan`; endpoint Save Draft menolak perubahan lanjutan pada workspace tersebut.
4. Data masuk ke `hukum_staging` dengan status `menunggu_review`.
5. Komisi I dan Ketua Umum BPM melakukan approval terpisah.
6. Approval staging tidak memakai timer ketat.
7. Hasil review:

| Hasil | Status `hukum_staging` | Efek |
|---|---|---|
| **Disetujui** | `disetujui` | Data siap dikunci untuk proses commit |
| **Ditolak** | `ditolak` | Draft tidak dihapus; meja kerja kembali `aktif`, lalu Komisi I memperbaiki dan submit ulang |

Status `disetujui` hanya diberikan setelah kedua pihak menyetujui. Jika baru
satu pihak menyetujui, status tetap `menunggu_review` dengan indikator `1/2`.

---

## 3. Staging Gate (Hard Block)

BFS dijalankan saat Save Draft untuk membuat sinyal dampak searah. Saat submit, backend mengecek notifikasi aktif di seluruh dokumen, bukan hanya pada Pasal yang dipilih untuk staging. Karena itu, staging tidak bisa melewati Pasal terdampak dengan mengirim subset versi.

Pseudocode kondisi blokir:

```text
IF COUNT(notifikasi aktif WHERE status = 'perlu_ditinjau'
         AND pasal_anak.dokumen_id = dokumen_workspace) > 0
THEN REJECT submit (atomic, seluruh draft ditolak)
```

Tidak ada self-commit override untuk melewati notifikasi aktif. Pengguna harus menyelaraskan Pasal anak atau mengabaikannya dengan alasan tercatat sebelum mengajukan staging.

---

## 4. Penyelesaian Notifikasi

- Penyelarasan otomatis hanya berlaku pada notifikasi milik Pasal anak yang versi draft barunya benar-benar dibuat dalam Save Draft tersebut.
- Pengabaian membutuhkan alasan dan mengubah status notifikasi, bukan menghapus histori.
- Penyimpanan dan penyelesaian draft berjalan sebelum submit staging; keputusan tersebut tidak menjadi jalur untuk melewati validasi staging lain.

## 5. Eksepsi dan Logika Lanjutan

### A. Save Draft Boleh Berjalan Saat Ada Sinyal

- Sinyal dampak dibuat saat Save Draft mengubah Pasal yang sudah pernah committed; draft awal tidak memicu sinyal.
- Sinyal tidak mencegah pengguna menyimpan draft atau menyunting Pasal anak. Sinyal hanya memblokir submit staging hingga diselesaikan.
- Penyelesaian otomatis hanya dilakukan untuk Pasal anak yang memiliki versi baru dengan perubahan teks/konten pada penyimpanan draft saat ini; perubahan daftar acuan saja atau versi lama yang dipakai ulang tidak dianggap penyelarasan.

### B. Atomic Submit

- Semua draft dalam satu meja kerja masuk staging sekaligus, atau tidak ada yang masuk.
- Jika hanya Pasal 4 memiliki notifikasi aktif, submit untuk Pasal 2, 3, dan 4 tetap ditolak total.
- Solusinya adalah menyelaraskan Pasal 4 atau mengabaikan dampaknya dengan alasan, kemudian submit ulang seluruh draft.

### C. Meja Kerja Archived Read-Only

- Setelah meja kerja berstatus `committed`, halaman edit pasal berubah menjadi mode read-only.
- Tombol **Simpan Draft** tidak tersedia.
- Riwayat tetap dapat dibaca melalui tab **Riwayat**.

---

## 6. Contoh Alur Dampak: A → B → C

| Langkah | Aksi | Hasil |
|---|---|---|
| 1 | Pasal B menyimpan acuan ke Pasal A dan Pasal C ke Pasal B | Edge `B → A` dan `C → B` tersimpan |
| 2 | Pasal A yang sudah committed diubah lalu Save Draft | Sinyal `perlu_ditinjau` dibuat untuk B dan C |
| 3 | B disunting dan disimpan sebagai versi baru | Notifikasi B menjadi `sudah_diselaraskan`; draft tersimpan |
| 4 | Pengguna memilih alasan typo pada C | Notifikasi C menjadi `diabaikan_dengan_alasan` |
| 5 | Komisi I mengajukan staging | Lolos hanya jika tidak ada lagi notifikasi aktif di dokumen |

---

## 7. Ringkasan Teknis (Integrasi DB)

| Fitur | Implementasi Teknis |
|---|---|
| Pemicu notifikasi | Save Draft versi baru pada Pasal dengan baseline `committed` |
| Sinkronisasi relasi | `api/hukum/relationship_service.php`, dipanggil oleh `pasal_service.php` |
| Resolusi otomatis | Versi Pasal anak baru tersimpan melalui aksi `align_versions` |
| Resolusi dengan alasan | `POST /api/hukum/notifications.php`, decision `ignore` |
| Mekanisme blocking | Hard block jika notifikasi aktif ditemukan pada dokumen workspace |
| Penyimpanan draft | `hukum_pasal_versi` dengan status `draft` atau `staged` |
| Status staging | `hukum_staging.status`: `menunggu_review` → `disetujui` / `ditolak` |
| Atomicity | Seluruh validasi, relasi versi, dan perubahan status dibungkus satu transaksi |

---

## 8. Hal yang Masih Perlu Diklarifikasi (TBD)

1. **Struktur approval staging** — schema aktual masih memiliki satu kolom reviewer;
   perlu tabel approval terpisah untuk menyimpan approval Komisi I dan Ketua Umum BPM.
2. **Threshold gap penomoran (VALIDATION-02)** — apakah pengecekan ini bagian dari validasi atomik atau validasi terpisah? Nilainya harus configurable, bukan hardcode.

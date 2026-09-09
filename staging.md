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

## 2. Alur Eksekusi Staging

### A. Submit dari Meja Kerja

Saat Komisi I klik **"Submit Staging"**:

1. **Validasi atomik**: sistem memeriksa seluruh draft di meja kerja sekaligus. Jika ada satu saja pasal yang melanggar aturan (nomor pasal duplikat, struktur JSON tidak valid, dan sebagainya), seluruh submit ditolak tanpa partial submit.
2. **Snapshot pasal**: versi draft berstatus `draft` dipromosikan menjadi `staged`.
3. **Staging Gate** dijalankan sebelum promosi status final dikonfirmasi.

### B. Notifikasi Cascade

Jika Staging Gate lolos:

1. Sistem menelusuri relasi pasal secara rekursif. Detail struktur graph ada di `graph.md`.
2. Setiap pasal anak dalam tree yang terdampak perubahan pasal induk dibuatkan entri notifikasi di tabel notifikasi peninjauan, yang memuat:
   - `dipicu_oleh_pasal_versi_id`: ID versi pasal yang sedang di-staging.
   - `pasal_induk_versi_sebelum_id`: versi induk sebelum perubahan.
   - `pasal_induk_versi_sesudah_id`: versi induk sesudah perubahan.
3. Status notifikasi otomatis menjadi `perlu_ditinjau`.

### C. Status Staging dan Review

1. Data masuk ke `hukum_staging` dengan status awal `menunggu_forum`.
2. Komisi I dan Ketua Umum BPM melakukan approval terpisah.
3. Approval staging tidak memakai timer ketat.
4. Hasil review:

| Hasil | Status `hukum_staging` | Efek |
|---|---|---|
| **Disetujui** | `disetujui` | Data siap dikunci untuk proses commit |
| **Ditolak** | `ditolak` | Draft tidak dihapus; meja kerja kembali `aktif`, lalu Komisi I memperbaiki dan submit ulang |

Status `disetujui` hanya diberikan setelah kedua pihak menyetujui. Jika baru
satu pihak menyetujui, status tetap `menunggu_forum` dengan indikator `1/2`.

---

## 3. Staging Gate (Recursive BFS — "Impact Analysis")

Ini adalah bagian inti yang berfungsi sebagai *security guard* sebelum submit diterima.

1. Sistem melakukan **Breadth-First Search (BFS)** ke seluruh pohon relasi pasal terdampak, dengan batas kedalaman 10 level.
2. Sistem mengecek `hukum_relasi_pasal` atau tabel referensi terkait. Jika ditemukan satu saja pasal dalam tree yang memiliki notifikasi `perlu_ditinjau` yang masih aktif, submit ditolak secara hard block.
3. Tujuannya adalah mencegah pasal anak tertinggal dan tidak diselaraskan setelah pasal induknya berubah.

Pseudocode kondisi blokir:

```text
IF COUNT(notifikasi aktif WHERE status = 'perlu_ditinjau'
         AND pasal_id IN (tree hasil BFS)) > 0
THEN REJECT submit (atomic, seluruh draft ditolak)
```

---

## 4. Logika "Self-Commit" (Override Gate)

Jika submit ditolak karena notifikasi aktif:

1. Komisi I mengedit pasal anak yang terblokir di meja kerja yang sama.
2. Saat Submit Staging ditekan kembali, sistem mendeteksi pasal anak tersebut juga sedang diedit di workspace yang sama.
3. Sistem mengizinkan submit melewati gate untuk kasus self-commit tersebut.
4. Jika commit berhasil, sistem otomatis melakukan auto-resolve terhadap notifikasi terkait karena pasal anak dianggap telah diselaraskan bersama perubahan induk.

Ini adalah satu-satunya jalur resmi untuk melewati Staging Gate. Tidak ada override manual atau paksa di luar mekanisme ini.

---

## 5. Eksepsi dan Logika Lanjutan

### A. Simpan Draft Tidak Memicu Gate

- Menyimpan draft tidak membuat notifikasi peninjauan otomatis.
- Notifikasi baru dibuat saat Komisi I klik **Submit Staging**.
- Tujuannya mencegah spam notifikasi selama penulisan dan perapian struktur yang dapat berlangsung beberapa hari.

### B. Atomic Submit

- Semua draft dalam satu meja kerja masuk staging sekaligus, atau tidak ada yang masuk.
- Jika hanya Pasal 4 memiliki notifikasi aktif, submit untuk Pasal 2, 3, dan 4 tetap ditolak total.
- Solusinya adalah menyelesaikan notifikasi Pasal 4 atau melakukan self-commit, kemudian submit ulang seluruh draft.

### C. Meja Kerja Archived Read-Only

- Setelah meja kerja berstatus `committed`, halaman edit pasal berubah menjadi mode read-only.
- Tombol **Simpan Draft** tidak tersedia.
- Riwayat tetap dapat dibaca melalui tab **Riwayat**.

---

## 6. Contoh Alur Nyata: Multi-Day Multi-Draft

| Hari | Aksi | Kondisi Meja Kerja |
|---|---|---|
| 1 | Edit Pasal A → Simpan Draft, tutup halaman | 1 draft, meja kerja tetap `aktif` |
| 2 | Buka kembali, edit Pasal B → Simpan Draft | 2 draft (A, B) |
| 3 | Edit Pasal C → Simpan Draft | 3 draft (A, B, C) |
| 4 | Klik **Submit Staging** | BFS berjalan ke seluruh tree yang menyertakan 3 draft |

Jika semua clear, ketiga pasal masuk staging sekaligus dan meja kerja menjadi `diajukan`. Jika ada notifikasi aktif, seluruh submit ditolak dan Komisi I memperbaiki di meja kerja yang sama.

---

## 7. Ringkasan Teknis (Integrasi DB)

| Fitur | Implementasi Teknis |
|---|---|
| Pemicu notifikasi | Query ke tabel relasi pasal saat `POST /api/hukum/staging` |
| Mekanisme blocking | Hard block jika notifikasi aktif ditemukan dalam tree hasil BFS |
| Penyimpanan draft | `hukum_pasal_versi` dengan status `draft` atau `staged` |
| Status staging | `hukum_staging.status`: `menunggu_forum` → `disetujui` / `ditolak` |
| Atomicity | Seluruh validasi, relasi versi, dan perubahan status dibungkus satu transaksi |

---

## 8. Hal yang Masih Perlu Diklarifikasi (TBD)

1. **Struktur approval staging** — schema aktual masih memiliki satu kolom reviewer;
   perlu tabel approval terpisah untuk menyimpan approval Komisi I dan Ketua Umum BPM.
2. **Threshold gap penomoran (VALIDATION-02)** — apakah pengecekan ini bagian dari validasi atomik atau validasi terpisah? Nilainya harus configurable, bukan hardcode.
3. **Tabel target BFS** — apakah BFS menelusuri `hukum_relasi_pasal` langsung atau memakai tabel index/cache untuk mempercepat query hierarki hingga 10 level? Lihat `graph.md`.

# Meja Kerja (Workspace) — Sistem Hukum BPM

> **Status dokumen**: Konsolidasi final dari diskusi rancangan.
> **Dokumen terkait**: `staging.md`, `commit.md`, `graph.md`
> **Peran yang terlibat**: Komisi I (pengaju/editor), Reviewer role `admin` pada periode dokumen (approver tahap staging & commit)

---

## 1. Filosofi Utama: Single Active Workspace

**Core invariant** dari seluruh desain workflow hukum BPM:

> Pada satu waktu, hanya **1 (satu) meja kerja** yang boleh berstatus `aktif`
> untuk **setiap dokumen hukum** — bukan per-user atau per-komisi.

**Implikasi**:

- Tidak ada branch, clone, atau parallel workspace.
- Semua pihak (khususnya Komisi I) bekerja di dalam satu meja kerja aktif untuk
  dokumen yang sedang direvisi.
- Revisi dokumen lain tetap dapat berjalan karena pembatasan berlaku per
  `dokumen_id`.

**Kenapa desain ini dipilih** (dibanding model banyak workspace/branch):

| Aspek | Model Banyak Workspace | Single Active Workspace (dipakai di sini) |
|---|---|---|
| Paralelisme | Bisa banyak workspace/branch untuk dokumen yang sama | Hanya 1 aktif per dokumen |
| Multi-day editing | Biasanya terbatas per pasal | Multi-pasal, multi-hari, dalam 1 meja kerja yang sama |
| Revisi | Perlu proses clone/merge | Edit & submit ulang di meja kerja yang sama |
| Konflik data | Sering muncul di akhir (saat merge) | Dicegah sejak awal lewat Staging Gate (lihat `staging.md`) |

**Enforcement teknis**:

```sql
SELECT COUNT(*) FROM hukum_workspace
WHERE status = 'aktif' AND dokumen_id = ?;
-- Hasil query ini harus SELALU 0 atau 1 untuk setiap dokumen.
-- Upaya membuat meja kerja baru saat sudah ada yang aktif harus DITOLAK
-- di level aplikasi maupun trigger DB.
```

---

## 2. Status Meja Kerja

| Status | Arti | Aksi yang Diperbolehkan |
|---|---|---|
| `aktif` | Meja kerja utama, sedang dikerjakan atau menunggu digunakan | Edit pasal, Simpan Draft, Submit Staging, buat meja kerja baru hanya jika belum ada yang `aktif` |
| `diajukan` | Sudah di-submit ke staging, menunggu review reviewer | Tidak bisa edit/draft baru. Bisa ditarik |
| `committed` | Sudah disahkan melalui commit, bersifat *read-only* permanen (archived) | Hanya baca, lihat riwayat |
| `ditarik` *(transisional)* | User membatalkan pengajuan sebelum keputusan final | Setelah aksi ini status kembali menjadi `aktif` |

### Diagram Alur Status

```text
                    ┌─────────────────────────────────────┐
                    │                                     │
                    ▼                                     │
   [Buat Baru] → AKTIF ──Submit Staging──► DIAJUKAN       │
                    ▲                          │           │
                    │                          │           │
                    │                     ┌────┴────┐      │
                    │                     │         │      │
                    │                Ditarik    Ditolak    │
                    │                (user)    (reviewer)  │
                    └─────────────────────┴─────────┘      │
                                          │                 │
                                     Disetujui              │
                                          │                 │
                                          ▼                 │
                                     COMMIT ──gagal────────┘
                                          │
                                       berhasil
                                          │
                                          ▼
                                     COMMITTED (read-only, archived)
```

---

## 3. Lifecycle Lengkap

### Fase A — Pembuatan

1. Saat **tidak ada** meja kerja berstatus `aktif` untuk dokumen terkait,
   Komisi I dapat klik **"Buat Meja Kerja Baru"**.
2. Meja kerja dibuat dengan status awal `aktif`.
3. Sistem memastikan invariant *Single Active Workspace* terpenuhi.

### Fase B — Edit & Simpan Draft (Multi-Hari, Multi-Pasal)

Ini keunggulan utama desain dibanding model clone/branch: **satu meja kerja bisa menampung banyak draft pasal, dikerjakan lintas hari**.

- Setiap **"Simpan Draft"** membuat atau memperbarui entri di `hukum_pasal_versi` dengan status `draft`, terhubung ke meja kerja.
- **Simpan Draft tidak memicu validasi gate** seperti pengecekan cross-reference atau gap penomoran. Validasi tersebut dijalankan saat Submit Staging agar Komisi I bebas menulis dan merapikan struktur selama diperlukan.

**Contoh alur nyata**:

| Hari | Aksi | Kondisi Meja Kerja |
|---|---|---|
| 1 | Edit Pasal 3 → Simpan Draft | 1 draft |
| 2 | Edit Pasal 3 lagi + edit Pasal 2 → Simpan Draft | 2 draft (Pasal 2, 3) |
| 3 | Edit Pasal 4 → Simpan Draft | 3 draft (Pasal 2, 3, 4) |
| 4 | Klik **Submit Staging** | Lanjut ke Fase C |

### Fase C — Submit Staging (Validasi Atomik)

Saat Komisi I klik **"Submit Staging"**:

1. **Validasi atomik**: seluruh draft di meja kerja diperiksa sekaligus. Jika satu saja pasal melanggar aturan, seluruh submit ditolak tanpa partial submit.
2. **Staging Gate (BFS depth 10)**: sistem menelusuri seluruh pohon relasi pasal yang terdampak. Detail mekanisme ini ada di `staging.md`.
3. Jika lolos gate, seluruh draft naik status menjadi `staged` dan status meja kerja berubah menjadi `diajukan`.
4. Jika gagal gate, seluruh submit ditolak dan meja kerja tetap `aktif`.

> Detail penuh proses BFS, notifikasi cascade, self-commit, dan override gate dibahas di `staging.md`; dokumen ini fokus pada siklus hidup meja kerja.

### Fase D — Menunggu Review

- Status meja kerja: `diajukan`.
- Tidak bisa diedit; hanya dapat dilihat sementara.
- Menunggu approval Komisi I dan Ketua Umum BPM pada periode dokumen.

### Fase E — Finalisasi

| Hasil Review | Efek pada Meja Kerja |
|---|---|
| **Ditolak** | Status kembali `aktif`. Draft tidak hilang; Komisi I memperbaiki penyebab penolakan lalu submit ulang tanpa clone workspace baru. |
| **Disetujui** | Status staging menjadi `disetujui`. Meja kerja belum menjadi `committed`; masih menunggu proses commit. |
| **Commit berhasil** | Status final `committed`. Meja kerja diarsipkan dan menjadi read-only permanen. |
| **Commit gagal** | Rollback total ke status sebelumnya (`diajukan`, staging tetap `disetujui`) sehingga commit dapat dicoba ulang. |

Setelah `committed`, tidak ada cara mengedit meja kerja tersebut. Perubahan hukum di masa depan wajib melalui meja kerja `aktif` yang baru.

---

## 4. Ringkasan Tabel Status

| Status Meja Kerja | Aksi Diperbolehkan | Catatan |
|---|---|---|
| `aktif` | Edit, Simpan Draft, Submit Staging, buat baru jika tidak ada aktif lain untuk dokumen | Meja kerja utama, maksimal satu per dokumen |
| `diajukan` | Tidak bisa edit; dapat ditarik | Menunggu review |
| `committed` | Hanya baca | Hukum resmi, diarsipkan permanen |
| `ditarik` *(transisi)* | — | Bukan status permanen; kembali ke `aktif` |

---

## 5. Mekanisme "Ditarik" (Withdrawal)

Berbeda dengan **"Ditolak"** (keputusan reviewer), **"Ditarik"** adalah inisiatif Komisi I sebagai pengaju untuk membatalkan proses sebelum keputusan final.

### 5.1 Ditarik saat `aktif` (sebelum Submit Staging)

| Aspek | Detail |
|---|---|
| Kapan | Draft dirasa belum selesai atau perlu perubahan fundamental sebelum submit |
| Mekanisme | Tombol "Batalkan Meja Kerja" / "Tarik Draft" |
| Efek status | Tetap `aktif`; belum ada pengajuan formal |
| Audit | Tidak wajib dicatat khusus; belum ada data yang masuk tahap formal |

### 5.2 Ditarik saat `diajukan` (setelah submit, sebelum review selesai)

| Aspek | Detail |
|---|---|
| Kapan | Komisi I menyadari kesalahan fatal setelah submit atau ingin membatalkan sebelum reviewer memberi keputusan |
| Mekanisme | Entri staging dibatalkan; status meja kerja kembali `aktif`; versi pasal terkait kembali dari `staged` menjadi `draft` |
| Audit | Wajib dicatat ke `hukum_audit_log` karena data sempat masuk fase formal |

Penarikan yang terlalu sering oleh satu pihak dapat menjadi indikator *bad practice*. Threshold dan mekanisme pelaporannya masih TBD.

### 5.3 Kapan "Ditarik" Tidak Boleh Terjadi

| Kondisi | Alasan | Solusi Alternatif |
|---|---|---|
| Setelah `hukum_staging.status = 'disetujui'` | Sudah melalui otorisasi formal; Komisi I tidak boleh menarik sepihak | Minta reviewer melakukan reject terlebih dahulu |
| Setelah proses commit dimulai | Proses bersifat atomik | Biarkan commit selesai atau gagal |

---

## 6. Ringkasan Teknis (Integrasi DB)

| Kebutuhan | Implementasi |
|---|---|
| Enforce single active workspace | `SELECT COUNT(*) FROM hukum_workspace WHERE status='aktif' AND dokumen_id=?` harus ≤ 1 per dokumen, dijaga lewat transaction/trigger |
| Relasi draft ke meja kerja | `hukum_pasal_versi.workspace_id` (foreign key) |
| Submit staging | Batch process seluruh versi pada workspace yang sama dalam satu database transaction (atomic) |
| Race condition | Dicegah lewat transaction lock saat Submit Staging dijalankan |

---

## 7. Hal yang Masih Perlu Diklarifikasi (TBD)

1. **Threshold bad practice** untuk penarikan berulang — apakah ada batas otomatis, misalnya tiga kali ditarik dalam sebulan lalu notifikasi ke atasan, atau cukup imbauan manual?
2. **Notifikasi real-time** — apakah reviewer mendapat notifikasi otomatis saat Komisi I melakukan submit staging atau penarikan, atau harus mengecek manual?

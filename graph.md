# Graph — Sistem Hukum BPM

> **Status dokumen**: Konsolidasi final dari diskusi rancangan. Beberapa keputusan arsitektural masih TBD dan ditandai eksplisit pada bagian 8, dengan rekomendasi sementara di bagian terkait.
> **Dokumen terkait**: `meja-kerja.md`, `staging.md`, `commit.md`

---

## 1. Ringkasan

Sistem Graph pada BPM melacak keterhubungan antar-hukum sebagai struktur
**Directed Acyclic Graph (DAG)** untuk mendukung *impact analysis*, validasi,
versioning, dan navigasi publik.

---

## 2. Struktur Data Graph

| Elemen | Entitas Database |
|---|---|
| **Node** | `hukum_pasal` |
| **Edge** | `hukum_relasi_pasal`, dengan tipe relasi |

Data disimpan sebagai **Adjacency List**, sehingga tree relasi dapat ditarik
dengan recursive query (`SQL WITH RECURSIVE`).

### 2.1 Sumber Edge — Manual vs Otomatis

Edge dapat berasal dari:

1. **Manual** — Komisi I membuat relasi melalui UI editor cross-reference.
2. **Otomatis melalui parsing inline** — user menulis kode `[[PASAL:N]]` pada
   teks pasal dan sistem memetakan referensinya.

**Rekomendasi sementara:** kedua sumber dicatat pada `hukum_relasi_pasal` dan
dibedakan melalui `dibuat_oleh ENUM('auto','manual')`. Alternatif berupa tabel
terpisah `hukum_referensi_inline` masih perlu diputuskan.

---

## 3. Integrasi dalam Alur Kerja

### A. Saat Edit — Meja Kerja

- Komisi I dapat menulis referensi eksplisit seperti `[[PASAL:12]]`.
- Sistem memetakan pasal sumber dan target ke graph.
- UI editor menampilkan Live Graph Preview: node yang sedang diedit berwarna
  merah dan node terdampak berwarna kuning.
- Detail lifecycle meja kerja ada di `meja-kerja.md`.

### B. Saat Submit — Staging Gate

- Submit Staging menjalankan BFS dengan batas kedalaman 10.
- Sistem melacak edge dari pasal yang di-submit ke pasal lain.
- Node anak yang terdampak ditandai `perlu_ditinjau`.
- Jika node terdampak belum memiliki versi selaras dalam meja kerja, Staging
  Gate menolak submit secara atomik.
- Detail gate ada di `staging.md`.

### C. Saat Commit — Snapshot Graph

- Commit menangkap isi pasal dan struktur graph pada saat yang sama.
- Snapshot struktur graph disimpan dalam `snapshot_tree` atau mekanisme snapshot
  yang diputuskan kemudian.
- Hash commit mencakup state graph untuk menjaga integritas historis walaupun
  pasal atau relasi berubah pada masa depan.

### D. Tampilan Publik — Graph Exploration

Graph menjadi alat navigasi:

- Dari Pasal 23, publik dapat melihat pasal induk atau referensi, misalnya
  Pasal 12.
- Publik dapat melihat pasal anak atau turunan, misalnya Pasal 25.
- Visualisasi interaktif dapat memakai SVG/Canvas atau Cytoscape.js.
- Saat commit/periode dibandingkan, sistem merender graph berdasarkan snapshot
  historis masing-masing.

---

## 4. Ringkasan Integrasi per Fase

| Fase | Fungsi Graph | Aksi Sistem |
|---|---|---|
| Meja Kerja | Dependency Discovery | Mencatat link draft ke database |
| Staging | Impact Analysis | BFS dan flagging node terdampak |
| Commit | Structural Snapshot | Mengunci relasi dalam snapshot |
| Publik | Navigation & Discovery | Merender relasi sebagai navigasi pasal |

---

## 5. Aturan Main (Business Rules)

1. **Manual-first:** sistem tidak menebak relasi dari teks bebas. Komisi I wajib
   menuliskan referensi eksplisit melalui kode `[[PASAL:...]]` atau UI relasi.
   Parsing otomatis hanya menerjemahkan input eksplisit tersebut.
2. **No broken links:** sebelum pasal diarsipkan atau dihapus dari struktur
   aktif, sistem menjalankan orphan check pada relasi.
3. Jika pasal lain bergantung pada pasal yang akan dihapus, sistem menampilkan
   alert saat staging dan meminta re-mapping sebelum commit.
4. Orphan check dan Broken Reference Report harus dipastikan sebagai satu fitur
   terpadu atau dua mekanisme berbeda agar tidak terjadi implementasi redundan.

---

## 6. Audit Trail Relasi

Setiap relasi harus mencatat asal-usulnya untuk audit internal:

| Kolom | Isi |
|---|---|
| `dibuat_oleh` | `'auto'` dari parsing `[[PASAL:...]]` atau `'manual'` dari UI |
| `dibuat_oleh_user_id` | ID admin jika manual; `NULL` jika otomatis |
| `waktu_dibuat` | Timestamp pembuatan |

Kolom audit relasi tidak boleh ditampilkan kepada publik. Informasi tersebut
hanya untuk admin dan audit internal.

---

## 7. Keuntungan dan Tantangan Implementasi

### 7.1 Keuntungan

- **Auditability:** sistem dapat menjawab pasal atau dokumen mana yang terdampak
  jika pasal tertentu berubah, sampai 10 hop.
- **Compliance:** mengurangi pasal yatim dan kontradiksi antar dokumen induk-anak.
- **Transparency:** publik memahami konteks hukum dan keterkaitannya.

### 7.2 Tantangan dan Mitigasi

| Tantangan | Detail | Mitigasi |
|---|---|---|
| Data entry burden | Graph manual bergantung pada ketelitian Komisi I | Sediakan tombol Sisipkan Referensi Pasal untuk mengurangi kesalahan sintaks |
| Performance | `WITH RECURSIVE` dapat melambat pada graph besar | Cache hasil query graph pada halaman publik |

---

## 8. Hal yang Masih Perlu Diklarifikasi (TBD)

1. **Struktur tabel referensi inline:** apakah `[[PASAL:...]]` disimpan di
   `hukum_relasi_pasal` dengan `dibuat_oleh`, atau tetap memakai
   `hukum_referensi_inline` untuk broken-reference report tanpa re-parsing JSON?
2. **Nilai ENUM `jenis_relasi`:** dua kandidat yang ditemukan:
   - Versi A: `mengacu`, `berhubungan`, `sequensial`, `induk_anak`
   - Versi B: `referensi`, `perubahan`, `turunan`, `penjelasan`
3. **Status relasi:** apakah relasi memiliki tahap `draft → staged → committed`,
   atau hanya `draft → committed` mengikuti versi pasal?
4. **Snapshot versioning publik:** apakah perlu tabel `hukum_commit_tree` sebagai
   index terpisah, atau cukup kolom `snapshot_tree` pada `hukum_commit`?
5. **Orphan check vs Broken Reference Report:** apakah keduanya satu fitur yang
   sama atau dua mekanisme yang berbeda?

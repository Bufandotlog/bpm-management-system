# Commit — Sistem Hukum BPM

> **Status dokumen**: Konsolidasi final dari diskusi rancangan.
> **Dokumen terkait**: `meja-kerja.md`, `staging.md`, `graph.md`
> **Peran yang terlibat**: Komisi I dan Ketua Umum BPM yang terdaftar pada
> periode dokumen; Ketua Umum BPM wajib memiliki role teknis `admin`.

---

## 1. Ringkasan

Commit adalah **checkpoint pengesahan formal** yang mengubah dokumen dari status
staging (`disetujui`) menjadi **hukum resmi (`committed`)** yang bersifat
**immutable** — tidak dapat diubah, dihapus, atau ditimpa setelah tersimpan.

**Prinsip inti**: tidak ada satu pihak pun yang bisa mengesahkan hukum secara
sepihak. Commit hanya sah jika dilakukan bersama (**Co-Commit**) oleh Komisi I
dan Ketua Umum BPM, dalam jendela waktu
yang saling tumpang tindih.

## 2. Prasyarat (Gate) Sebelum Commit

Sistem melakukan verifikasi berikut sebelum tombol **Inisiasi Commit** dapat
diakses:

| Syarat | Detail Verifikasi |
|---|---|
| Approval staging | `hukum_staging.status` harus `disetujui`, dengan approval dari Komisi I dan Ketua Umum BPM |
| Integritas data | Setiap pasal dalam staging memiliki `hash_konten` yang valid dan cocok dengan isi |
| Cross-reference bersih | Tidak ada notifikasi `perlu_ditinjau` aktif di tree pasal mana pun |

Pemeriksaan cross-reference diulang sebagai *defense in depth* walaupun Staging
Gate seharusnya sudah meluluskannya. Jika syarat gagal, sistem harus
menampilkan bagian dan alasan yang spesifik.

## 3. Mekanisme Co-Commit dengan Timer Independen

Commit wajib melibatkan dua pihak: **Komisi I** dan **Ketua Umum BPM**. Tidak ada
override sepihak.

### 3.1 Tahap Inisiasi

1. Kedua pihak menekan **Inisiasi Commit** secara terpisah dan independen.
2. Setiap klik dicatat sebagai baris baru di `hukum_commit_window`, termasuk
   `user_id`, `role`, dan waktu inisiasi.
3. Setiap inisiasi memulai timer independen selama 5 detik.

### 3.2 Window Kesempatan

1. Setelah kedua pihak menginisiasi, tombol **Setuju Commit** muncul untuk
   keduanya.
2. Masing-masing harus menyelesaikan persetujuan sebelum timer miliknya habis.
3. Masing-masing memasukkan password akun lalu menekan **Setuju**.
4. Window tidak harus identik. Contoh: pihak pertama memulai pada detik 0
   (berakhir detik 5), pihak kedua pada detik 2 (berakhir detik 7).

Commit berhasil hanya jika kedua persetujuan valid dalam window masing-masing.

### 3.3 Eksekusi atau Kegagalan

| Kondisi | Hasil |
|---|---|
| Kedua persetujuan valid dalam window masing-masing | Lanjut ke atomic commit |
| Salah satu timer habis | Rollback total; status kembali ke kondisi `diajukan`/`disetujui` |
| Password salah | Rollback total dan dicatat sebagai kegagalan |
| Role tidak sesuai | Ditolak pada otorisasi dan tidak memengaruhi window |

Tidak boleh ada commit setengah jalan. Kegagalan pada titik mana pun
mengembalikan sistem ke kondisi sebelum inisiasi.

### 3.4 Tidak Dapat Dibatalkan Saat Timer Berjalan

Setelah timer Co-Commit dimulai, tidak tersedia tombol **Tarik**. Proses hanya
berakhir dengan:

- commit berhasil setelah kedua pihak menyetujui; atau
- commit gagal karena timer habis atau password salah, lalu rollback.

## 4. Eksekusi Snapshot dan Immutable Record

Jika Co-Commit berhasil, operasi berikut dijalankan dalam satu transaksi
database.

### 4.1 Snapshot Tree

1. Ambil seluruh versi pasal terbaru dari staging.
2. Gabungkan dengan versi committed dari pasal yang tidak berubah melalui
   `parent_commit_id`.
3. Serialize gabungan menjadi `snapshot_tree`, yang merepresentasikan kondisi
   lengkap dokumen pada titik commit, termasuk struktur relasi/graph.

### 4.2 Hashing Rantai Integritas

```text
hash_commit = SHA256(parent_hash + snapshot_tree + timestamp)
```

- `parent_hash` adalah hash commit sebelumnya.
- Rantai tersebut memungkinkan deteksi perubahan tidak sah pada snapshot atau
  commit lanjutan.

### 4.3 Persistensi Database

Operasi berikut harus atomik:

1. Insert baris baru ke `hukum_commit` dengan hash, parent, snapshot,
   timestamp, dan referensi commit window.
2. Ubah versi pasal terlibat dari `staged` menjadi `committed`.
3. Ubah commit sebelumnya dari `aktif` menjadi `digantikan`.
4. Ubah dokumen induk menjadi `aktif`.
5. Ubah meja kerja menjadi `committed`.

## 5. Audit dan Keamanan

### 5.1 Verifikasi Password

Setiap aksi **Setuju Commit** wajib memverifikasi password dengan
`password_verify()` terhadap hash password user yang tersimpan. Status login
saja tidak cukup.

### 5.2 Audit Log

Seluruh tahapan — inisiasi, input password, persetujuan, dan kegagalan —
dicatat secara lengkap. Rancangan tabel audit yang dirujuk:

- `hukum_commit_otorisasi` untuk rincian otorisasi.
- `hukum_notifikasi_audit` untuk audit trail tambahan.
- `hukum_audit_log` sebagai audit umum modul jika digunakan oleh implementasi
  kanonik.

### 5.3 Global Lockout

Jika terjadi tiga kegagalan commit dalam satu jam, sistem memberlakukan
**global lockout** selama satu jam berikutnya. Selama lockout, seluruh user tidak
dapat memulai proses commit.

Tujuannya mencegah brute-force password dan penyalahgunaan proses pengesahan.

## 6. Finalisasi dan Tampilan Publik

Setelah commit berhasil:

1. Halaman publik mengambil snapshot dari commit berstatus `aktif` terbaru.
2. Tidak ada proses publish/deploy manual terpisah.
3. Footer atau metadata dokumen menampilkan hash commit dan tanggal forum.
4. Perbandingan hukum antar commit atau periode menggunakan snapshot tersebut.

## 7. Sifat Immutable

Tidak ada pihak, termasuk admin tertinggi, yang dapat mengubah pasal berstatus
`committed` secara langsung.

Satu-satunya jalur perubahan:

```text
Buat meja kerja aktif baru
    → Ajukan staging baru
    → Review
    → Co-Commit baru
```

Setiap revisi menghasilkan commit baru dengan hash baru. Commit sebelumnya tetap
tersimpan sebagai riwayat berstatus `digantikan`, bukan dihapus.

## 8. Hal yang Masih Perlu Diklarifikasi (TBD)

1. Apakah timer expired dihitung sebagai kegagalan untuk aturan lockout, atau
   hanya percobaan password yang salah?
2. Apakah pihak kedua menerima notifikasi real-time saat pihak pertama
   menginisiasi commit?
3. Apa jalur darurat jika Komisi I atau Ketua Umum BPM berhalangan
   berkepanjangan?
4. Apakah snapshot versioning memakai tabel `hukum_commit_tree` terpisah atau
   kolom `snapshot_tree` di `hukum_commit`?
5. Bagaimana prosedur penggantian sementara jika Ketua Umum BPM atau anggota
   Komisi I berhalangan berkepanjangan?

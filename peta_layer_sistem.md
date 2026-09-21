# Peta Layer Sistem BPM dan Hukum

## 1. Tujuan dokumen
Dokumen ini berfungsi sebagai catatan arsitektur untuk memetakan peran tiap tabel dan file, agar jelas mana yang:

- harus dipertahankan
- perlu disederhanakan
- bisa digabung atau dipisah
- merupakan role aplikasi
- merupakan role event
- merupakan metadata organisasi
- merupakan governance hukum

Tujuan utamanya adalah membedakan tiga hal yang sering tercampur di sistem saat ini:

1. role / akses aplikasi
2. struktur organisasi / jabatan formal
3. proses dan governance produk hukum

---

## 2. Prinsip dasar pemisahan layer
Sistem yang ideal harus punya 4 layer yang jelas. Berikut adalah aturan konsistensi final yang dipakai untuk seluruh dokumen ini:

1. `users.role` adalah role aplikasi / teknis, bukan label organisasi.
2. `kegiatan_panitia.event_role` adalah role event / panitia, bukan role dasar aplikasi.
3. `struktur_bph`, `anggota_bph`, `kementerian`, dan `anggota_kementerian` adalah metadata organisasi untuk tampilan dan jabatan formal.
4. `hukum_keanggotaan` adalah governance hukum per periode, bukan source of truth untuk semua izin aplikasi.
5. `superadmin` adalah override global sistem, bukan approver hukum normal.
6. `admin` adalah role teknis tertinggi per periode, bukan otomatis label organisasi seperti Ketua Umum.
7. `komisi_i` adalah role teknis untuk domain hukum, bukan hanya nama jabatan organisasi.
8. Label seperti Ketua Umum, Wakil Ketua, Komisi I, Komisi III, dll. diperlakukan sebagai metadata organisasi, bukan hak akses langsung.
9. Approval produk hukum normal dilakukan oleh `komisi_i` dan `admin`, bukan `superadmin`.

### 2.1 Base role aplikasi
Role dasar aplikasi, menentukan hak akses utama user di sistem.

Contoh role yang dipakai secara konsisten:

- `superadmin`
- `admin`
- `sekretaris`
- `kominfo`
- `komisi_i`
- `anggota`

Fungsi:
- menentukan akses umum ke modul dan fitur
- bukan untuk menampilkan struktur organisasi
- bukan untuk menggambarkan tugas kegiatan
- bukan untuk mewakili jabatan formal organisasi

Sumber utama:
- `users.role`

Catatan penting:
- `admin` = role teknis per periode
- `superadmin` = override global lintas semua periode
- `komisi_i` = role domain hukum

---

### 2.2 Event role / panitia
Role dinamis per kegiatan/panitia.

Contoh role yang konsisten:

- `sie_acara`
- `sie_humas`
- `sie_logistik`
- `anggota_panitia`
- `ketuplat`

Fungsi:
- menambah akses saat ada kegiatan aktif
- bukan base role aplikasi
- bukan label organisasi permanen
- bersifat additive terhadap role dasar yang dimiliki user

Sumber utama:
- `kegiatan_panitia.event_role`

---

### 2.3 Struktur organisasi / metadata organisasi
Data organisasi untuk menampilkan struktur BPM dan jabatan formal.

Contoh:

- Ketua Umum
- Wakil Ketua
- Komisi I
- Komisi III
- Sekretaris Umum
- Bendahara Umum

Fungsi:
- menampilkan siapa yang menjabat apa di periode tertentu
- bukan hak akses teknis utama
- bukan role event
- bukan sumber izin aplikasi

Sumber utama:
- `struktur_bph`
- `anggota_bph`
- `kementerian`
- `anggota_kementerian`
- `periode_kepengurusan`

Catatan penting:
- label organisasi dapat disimpan, tetapi tidak boleh dipakai sebagai gate izin langsung

---

### 2.4 Governance hukum / proses hukum
Domain data dan proses yang khusus bagi sistem Hukum.

Contoh:

- `hukum_keanggotaan`
- `hukum_dokumen`
- `hukum_workspace`
- `hukum_staging`
- `hukum_staging_approval`
- `hukum_commit_window`
- `hukum_audit_log`

Fungsi:
- mencatat status keanggotaan hukum per periode
- menjalankan workflow hukum
- memvalidasi review, staging, dan approval hukum
- menjadi referensi governance formal untuk dokumen hukum

Sumber utama:
- migrasi Hukum di `databases/migrations/2026`

Catatan penting:
- `hukum_keanggotaan` dipahami sebagai data governance hukum, bukan sebagai satu-satunya sumber izin teknis untuk seluruh sistem
- izin akses teknis tetap diatur oleh base role dan event role
- approval hukum normal tetap mengikuti `komisi_i` + `admin`

---

## 3. Pemetaan tabel yang relevan

### 3.1 Tabel identitas dan sesi
#### `users`
Fungsi:
- akun login utama
- source of truth untuk user

Kegunaan:
- autentikasi
- role dasar aplikasi
- profil dasar pengguna

Harus dipertahankan:
- ya

Catatan:
- tidak boleh dipakai sebagai tempat menampung semua label organisasi / jabatan formal

---

#### `user_sessions`
Fungsi:
- session login aktif

Harus dipertahankan:
- ya

---

#### `login_attempts_ip`
Fungsi:
- proteksi brute force dan keamanan login

Harus dipertahankan:
- ya

---

#### `periode_kepengurusan`
Fungsi:
- periode aktif organisasi
- penentu periode kerja / periode hukum

Harus dipertahankan:
- ya

Catatan:
- ini adalah kunci untuk mapping periode aktif

---

### 3.2 Tabel role aplikasi / base role
#### `users.role`
Fungsi:
- role dasar aplikasi

Harus dipertahankan:
- ya, tapi perlu dibersihkan dan dibatasi

Catatan:
- bukan label organisasi
- bukan data event
- bukan metadata hukum

Peran ideal:
- `superadmin`
- `admin`
- `sekretaris`
- `kominfo`
- `komisi_i`
- `anggota`

---

### 3.3 Tabel struktur organisasi / tampilan
#### `struktur_bph`
Fungsi:
- menampung posisi BPH periode tertentu
- contoh: ketua, wakil_ketua, sekretaris_umum, bendahara_umum

Harus dipertahankan:
- ya

Catatan:
- ini lebih cocok sebagai metadata organisasi, bukan permission teknis

---

#### `anggota_bph`
Fungsi:
- daftar anggota BPH per periode

Harus dipertahankan:
- ya

---

#### `kementerian`
Fungsi:
- daftar komisi / kementerian bpm

Harus dipertahankan:
- ya

---

#### `anggota_kementerian`
Fungsi:
- daftar anggota tiap komisi/kementerian

Harus dipertahankan:
- ya

Catatan:
- ini jelas lebih cocok sebagai data struktur organisasi dan tampilan, bukan sebagai izin aplikasi

---

### 3.4 Tabel event / panitia
#### `kegiatan`
Fungsi:
- data kegiatan / agenda / event aktif

Harus dipertahankan:
- ya

---

#### `kegiatan_panitia`
Fungsi:
- siapa yang ditugaskan dalam kegiatan dan perannya

Harus dipertahankan:
- ya

Field yang relevan:
- `user_id`
- `kegiatan_id`
- `event_role`

Contoh event_role:
- `sie_acara`
- `sie_humas`
- `sie_logistik`
- `anggota_panitia`
- `ketuplat`

Catatan:
- ini adalah role tambahan saat event aktif
- tidak sama dengan base role aplikasi

---

### 3.5 Tabel Hukum / governance
#### `hukum_keanggotaan`
Fungsi:
- data status keanggotaan hukum per periode
- menentukan siapa yang menjabat Komisi I / Ketua Umum

Harus dipertahankan:
- ya, tetapi fungsinya harus dibatasi

Field penting:
- `user_id`
- `periode_id`
- `jabatan`
- `mulai_pada`
- `selesai_pada`
- `aktif`

Catatan:
- fungsi yang tepat: metadata governance hukum / status formal per periode
- tidak boleh dijadikan satu-satunya sumber kebenaran untuk semua akses sistem

---

#### `hukum_staging_approval`
Fungsi:
- approval review pada tahap staging

Harus dipertahankan:
- ya

---

#### `hukum_commit_window`
Fungsi:
- lock / window commit final

Harus dipertahankan:
- ya

---

#### `hukum_commit_lockout`
Fungsi:
- pencegah replay / lockout / retry berkali-kali

Harus dipertahankan:
- ya

---

#### `hukum_dokumen`
Fungsi:
- dokumen hukum utama

Harus dipertahankan:
- ya

---

#### `hukum_workspace`
Fungsi:
- workspace kerja dokumen hukum

Harus dipertahankan:
- ya

---

#### `hukum_staging`
Fungsi:
- staging review dokumen hukum

Harus dipertahankan:
- ya

---

#### `hukum_pasal`
Fungsi:
- pasal hukum

Harus dipertahankan:
- ya

---

#### `hukum_pasal_versi`
Fungsi:
- versi pasal

Harus dipertahankan:
- ya

---

#### `hukum_relasi_pasal`
Fungsi:
- relasi antar pasal

Harus dipertahankan:
- ya

---

#### `hukum_audit_log`
Fungsi:
- audit semua perubahan hukum

Harus dipertahankan:
- ya

---

## 4. File-file penting yang terlibat

### 4.1 File yang harus dipertahankan
- `includes/functions.php`
- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`
- `admin/konten/kepengurusan.php`
- `admin/kegiatan/buat-panitia.php`
- `admin/core/hukum-auth.php`
- `api/hukum/membership_service.php`
- `api/hukum/staging_service.php`
- `api/hukum/review_service.php`
- `api/hukum/commit_service.php`
- `databases/migrations/2026-09-08-hukum-schema.sql`
- `databases/migrations/2026-09-09-hukum-governance.sql`
- `databases/schema_mysql.sql`
- `databases/schema_pgsql.sql`

### 4.2 File yang perlu disederhanakan
- `admin/system/kelola-admin.php`
  - karena role admin sekarang terlalu umum
- `admin/core/dashboard.php`
  - karena dashboard terlalu mengandalkan satu `admin_role` tanpa memisahkan base role dan event role
- `admin/core/header.php`
  - karena menu/sidebar menggabungkan role dasar, event role, dan Hukum role dalam satu logika
- `admin/core/hukum-auth.php`
  - karena campur aduk antara technical role, business membership, dan izin aplikasi
- `api/hukum/membership_service.php`
  - karena bisa dipakai sebagai metadata, tapi tidak seharusnya jadi source of truth role aplikasi

### 4.3 File yang bisa dipisah atau digabung
#### Bisa dipisah
- `users.role` dan `hukum_keanggotaan.jabatan`
  - harus dipisah sebagai role teknis dan metadata organisasi

- `kegiatan_panitia.event_role` dan `users.role`
  - harus dipisah sebagai event permissions dan base permissions

- `struktur_bph` / `anggota_bph` / `kementerian` / `anggota_kementerian` dan `hukum_keanggotaan`
  - harus dipisah karena konteksnya beda: struktur organisasi umum vs governance Hukum

#### Bisa digabung dengan hati-hati
- `hukum_keanggotaan` dan `hukum_staging_approval` tetap bisa dipertahankan sebagai satu domain hukum
- `kegiatan` dan `kegiatan_panitia` tetap satu domain event
- `users` dan `user_sessions` tetap satu domain akun

---

## 5. Mana yang benar-benar redundant?
Yang paling rawan redundant jika tidak dibedakan:

1. `users.role` vs `hukum_keanggotaan.jabatan`
   - redundant jika keduanya dipakai untuk hal yang sama
   - tidak redundant jika yang satu = role aplikasi, yang lain = data keanggotaan hukum per periode

2. `users.role` vs `kegiatan_panitia.event_role`
   - redundant jika keduanya diperlakukan sebagai satu hak akses
   - tidak redundant jika yang satu = base role, yang lain = akses dinamis event

3. `struktur_bph` / `kementerian` vs `hukum_keanggotaan`
   - redundant bila semua dianggap sebagai jabatan formal yang sama
   - tidak redundant bila satu untuk struktur organisasi umum, satunya untuk governance hukum

---

## 6. Kesimpulan final
Arsitektur paling konsisten untuk sistem BPM + Hukum adalah:

1. Layer akun / akses aplikasi
   - `users.role`
   - `superadmin`, `admin`, `sekretaris`, `kominfo`, `komisi_i`, `anggota`

2. Layer event / panitia
   - `kegiatan_panitia.event_role`
   - `sie_acara`, `sie_humas`, `sie_logistik`, `anggota_panitia`, `ketuplat`

3. Layer struktur organisasi / metadata
   - `struktur_bph`, `anggota_bph`, `kementerian`, `anggota_kementerian`, `periode_kepengurusan`

4. Layer governance Hukum
   - `hukum_keanggotaan`, `hukum_dokumen`, `hukum_workspace`, `hukum_staging`, `hukum_commit_window`, `hukum_audit_log`

Bila keempat layer ini dipisah dengan jelas, maka:

- tidak ada redundancy yang merusak logika
- role teknis tetap jelas
- struktur organisasi tetap jelas
- proses hukum tetap jelas
- superadmin tetap bisa menjadi override global tanpa mencampuri approval Hukum normal

Semua itu harus dijaga sejak design awal, agar sistem tidak membangun satu "roles table" yang memuat semua kebutuhan sekaligus.

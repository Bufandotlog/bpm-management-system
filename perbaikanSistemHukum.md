# Perbaikan Konsep Sistem Hukum dan Role BPM

## 1. Tujuan dokumen
Dokumen ini merangkum versi konsep yang paling sederhana dan konsisten sesuai kebutuhan yang sudah dibahas sebelumnya:

- `admin` adalah role teknis tertinggi per periode
- `komisi_i` adalah role teknis untuk area hukum
- `sekretaris` dan `kominfo` tetap role spesifik masing-masing
- `anggota` adalah role dasar / event-oriented
- label seperti `Ketua Umum`, `Wakil Ketua`, `Komisi I`, `Komisi III` diperlakukan sebagai metadata atau label organisasi, bukan sebagai role teknis yang langsung mengubah hak akses
- approval produk hukum dilakukan oleh `komisi_i` dan `admin`
- `superadmin` adalah role puncak lintas semua fitur dan semua periode

Dokumen ini juga mencocokkan konsep baru ini dengan file-code aktual agar jelas mana yang perlu disesuaikan untuk menghindari campur aduk antara role sistem, role event, dan jabatan organisasi.

---

## 2. Aturan konsistensi final
Semua pembahasan berikut memakai definisi yang sama:

1. `users.role` = role aplikasi / teknis
2. `kegiatan_panitia.event_role` = role event / panitia
3. `struktur_bph`, `anggota_bph`, `kementerian`, `anggota_kementerian` = metadata organisasi
4. `hukum_keanggotaan` = governance hukum per periode
5. label organisasi seperti Ketua Umum, Wakil Ketua, Komisi I, Komisi III = metadata, bukan hak akses langsung
6. `admin` = role teknis tertinggi per periode
7. `superadmin` = override global lintas semua fitur dan periode
8. `komisi_i` = role teknis untuk domain hukum
9. approval hukum normal = `komisi_i` + `admin`, bukan `superadmin`

### 2.1 Base role aplikasi
Base role aplikasi yang dimaksud adalah role yang menentukan hak akses utama sistem:

- `superadmin`
- `admin`
- `sekretaris`
- `kominfo`
- `komisi_i`
- `anggota`

Penjelasan singkat:
- `superadmin` = role puncak yang bisa mengakses semua fitur dan semua periode
- `admin` = role teknis tertinggi per periode, bukan otomatis nama jabatan organisasi
- `sekretaris` = role untuk sekretariat
- `kominfo` = role untuk media/konten
- `komisi_i` = role untuk area hukum
- `anggota` = role dasar / pengguna umum

Catatan:
- `admin` bukan role umum yang bisa dibuat sembarang orang.
- `admin` diberikan hanya kepada orang yang memang ditunjuk untuk periode tertentu.
- `Ketua Umum` atau `Wakil Ketua` dapat dipakai sebagai label status organisasi, tetapi bukan role teknis yang berdiri sendiri.
- `superadmin` tidak sama dengan `admin` biasa; `superadmin` adalah override global di semua fitur dan semua periode.

### 2.2 Event role
Role ini bersifat dinamis dan hanya relevan saat ada kegiatan aktif:

- `sie_acara`
- `sie_humas`
- `sie_logistik`
- `anggota_panitia`
- `ketuplat`

Role-event ini tidak menggantikan role dasar. Kombinasinya adalah:

- base role + event role
- contoh: `komisi_i` + `sie_humas`, atau `anggota` + `sie_acara`

Catatan:
- `sie_acara`, `sie_humas`, dan `sie_logistik` adalah peran di kegiatan, bukan role utama aplikasi
- `anggota_panitia` dan `ketuplat` juga merupakan role event / panitia, bukan base role untuk akses umum

### 2.3 Label organisasi
Label organisasi hanya untuk tampilan / data struktur organisasi, bukan untuk mengatur izin:

- Ketua Umum
- Wakil Ketua
- Komisi I
- Komisi III
- dll

Label ini tidak boleh dipakai sebagai gate izin sistem secara langsung. Jika ingin disimpan, simpan sebagai data metadata, bukan sebagai hak akses yang membatasi aplikasi.

### 2.4 Approval hukum
Approval hasil hukum mengikuti aturan sederhana:

- `komisi_i` mengelola dan memproses substansi hukum
- `admin` memberi persetujuan akhir pada periode aktif

Jadi logika approval yang disarankan:

- submit / draft / review = `komisi_i`
- final approval / validasi akhir = `admin`

Catatan penting:
- `superadmin` tidak ikut dalam approval hukum normal karena ia adalah role sistem global, bukan otoritas Hukum yang biasa

### 2.5 Peran `superadmin`
`superadmin` adalah role khusus yang memiliki kontrol lintas semua fitur dan periode. Ia tidak harus mempunyai label organisasi tertentu, dan tidak sama dengan `admin` biasa.

Kewenangan utama `superadmin`:

- akses penuh ke semua modul aplikasi
- mengakses semua periode
- override pembatasan fitur yang normalnya hanya untuk admin/role tertentu
- pengelolaan user dan konfigurasi tingkat sistem

`superadmin` tetap merupakan role teknis dan bukan bagian dari label organisasi.

### 2.6 Hubungan antara role dan metadata organisasi
Hubungannya adalah:

- user dapat punya `komisi_i` sebagai role teknis
- user juga dapat punya label `Komisi I` di `hukum_keanggotaan` atau data struktur organisasi pada periode tertentu
- keduanya bisa ada, tetapi tidak boleh dianggap sama
- label organisasi memperjelas status organisasi, sedangkan role menentukan akses aplikasi

### 2.7 Keputusan implementasi langkah 1 — role model final telah dikunci
Langkah pertama yang benar-benar siap dieksekusi adalah mengunci definisi role berikut sebagai policy teknis yang dipakai di seluruh implementasi berikutnya.

#### A. Base role aplikasi (source of truth: `users.role`)
- `superadmin`
- `admin`
- `sekretaris`
- `kominfo`
- `komisi_i`
- `anggota`

Arti:
- ini adalah layer hak akses utama aplikasi
- tidak sama dengan label organisasi
- tidak sama dengan role event

#### B. Event role / panitia (source of truth: `kegiatan_panitia.event_role`)
- `sie_acara`
- `sie_humas`
- `sie_logistik`
- `anggota_panitia`
- `ketuplat`

Arti:
- ini adalah akses tambahan saat kegiatan aktif
- bukan base role aplikasi
- tidak menggantikan role utama user

#### C. Metadata organisasi (source of truth: struktur organisasi / periode kepengurusan)
- Ketua Umum
- Wakil Ketua
- Komisi I
- Komisi III
- dll

Arti:
- ini adalah status organisasi per periode
- hanya untuk tampilan / laporan / metadata
- bukan source izin aplikasi

#### D. Governance hukum (source of truth: `hukum_keanggotaan` + domain Hukum)
- status keanggotaan hukum per periode
- workflow hukum, staging, review, approval
- dokumen hukum dan audit log

Arti:
- ini adalah domain Hukum formal
- bukan satu-satunya sumber hak akses aplikasi

#### E. Approval hukum final
- `komisi_i` = role teknis yang mengolah / review / submit substansi Hukum
- `admin` = role teknis yang memberi persetujuan akhir periode aktif
- `superadmin` = override global sistem, tidak ikut alur approval hukum normal

Dengan demikian, keputusan yang dikunci untuk implementasi berikutnya adalah:

- base role menentukan access utama
- event role menambah akses saat kegiatan aktif
- label organisasi hanya metadata
- governance hukum menjaga workflow hukum
- `komisi_i` + `admin` adalah approval hukum normal

Status implementasi langkah 1: DICATAT DAN DIKUNCI SEBAGAI POLICY ARSITEKTUR.

---

## 3. Perbedaan konsep dengan kode aktual

### 3.1 Role sistem di kode aktual
File aktual yang paling relevan:

- `includes/functions.php`
- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`

Kode saat ini mendefinisikan role user seperti ini:

- `superadmin`
- `admin`
- `kominfo`
- `sekretaris`
- `anggota`

Ini artinya implementasi saat ini menganggap `admin` sebagai role sistem umum, bukan role yang dibatasi hanya untuk Ketua Umum/Wakil Ketua.

### 3.2 Role event di kode aktual
File aktual yang paling relevan:

- `admin/kegiatan/buat-panitia.php`
- `admin/core/header.php`
- `admin/core/dashboard.php`

Kode saat ini sudah memisahkan role per event, misalnya:

- `ketuplat`
- `sekretaris_panitia`
- `sie_acara`
- `sie_logistik`
- `sie_humas`
- `sie_konsumsi`
- `anggota_panitia`

Artinya, arah event-based access sudah ada dan sesuai dengan konsep yang ingin dipertahankan.

### 3.3 Struktur Hukum di kode aktual
File aktual yang paling relevan:

- `databases/migrations/2026-09-09-hukum-governance.sql`
- `admin/core/hukum-auth.php`
- `api/hukum/membership_service.php`
- `api/hukum/staging_service.php`
- `api/hukum/review_service.php`

Kode Hukum saat ini sudah membangun layer yang berbeda:

- role sistem general (`admin`, `sekretaris`, `kominfo`, `anggota`)
- membership Hukum per periode (`hukum_keanggotaan`)
- jabatan Hukum berupa `komisi_i` dan `ketua_umum`

Ini menandakan bahwa sistem sudah membuat pemisahan antara:

- role aplikasi
- jabatan / governance hukum

Namun tetap ada problema: jabatan hukum itu dipakai seperti gate izin teknis, bukan hanya sebagai metadata. Inilah yang membuat model terasa campur aduk.

---

## 4. Inkonsistensi yang utama

### 4.1 Campur aduk antara role sistem dan label organisasi
Pada kode aktual, ada campuran seperti ini:

- `users.role` punya `admin`, `sekretaris`, `kominfo`, `anggota`
- tapi di Hukum juga ada `hukum_keanggotaan.jabatan` dengan `komisi_i` dan `ketua_umum`
- lalu akses Hukum dihitung dari fungsi seperti `hukum_is_komisi_i()` dan `hukum_is_ketua_umum()`

Model seperti ini terlalu banyak lapisan untuk satu kebutuhan. Ini tidak sesuai dengan konsep yang lebih sederhana: admin sebagai role teknis puncak, komisi_i sebagai role hukum, dan jabatan organisasi hanya label.

### 4.2 Role `admin` dipakai terlalu umum
Di file aktual:

- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`

`admin` diperlakukan sebagai role umum sistem. Ini berbeda dengan kebutuhan: role admin sebaiknya hanya untuk pemegang otoritas final periode.

### 4.3 Model Hukum sudah dekat, tetapi terlalu teknis di tempat yang salah
Di `hukum_keanggotaan`, terdapat data per periode yang sebenarnya bagus untuk kepengurusan. Namun jika dipakai sebagai role teknis utama, maka akan menimbulkan dua efek yang tidak diinginkan:

- kode menjadi rumit dan sulit dibedakan antara label organisasi dan izin aplikasi
- setiap perubahan jabatan formal bisa berdampak pada izin teknis secara otomatis

Maka untuk model yang disederhanakan, lebih baik:

- `hukum_keanggotaan` dipakai sebagai metadata keanggotaan / struktur organisasi, atau sebagai referensi tambahan
- izin teknis tetap diatur dari `role` sistem (`admin`, `komisi_i`), bukan dari label organisasi semata

---

## 5. Versi konsep yang paling sederhana sesuai kebutuhan Anda

### 5.1 Role utama yang dipakai sistem
Base role yang disarankan:

- `admin` = role tertinggi per periode
- `sekretaris` = role sekretariat
- `kominfo` = role media
- `komisi_i` = role Hukum
- `anggota` = role dasar / event-oriented

### 5.2 Event role
Role dinamis per kegiatan:

- `sie_acara`
- `sie_humas`
- `sie_logistik`
- `sie_konsumsi`
- `anggota_panitia`
- `ketuplat`

### 5.3 Label organisasi (metadata saja)
Label tetap bisa ada, tetapi tidak memengaruhi hak akses langsung:

- Ketua Umum
- Wakil Ketua
- Komisi I
- Komisi III
- dll

### 5.4 Aturan approval hukum
Persetujuan produk hukum:

- `komisi_i` dapat mengerjakan, meninjau, dan mengajukan dokumen hukum
- `admin` dapat memberi persetujuan akhir

Dengan kata lain:

- `komisi_i` + `admin` = otoritas utama produk hukum

---

## 6. Pemetaan ke file actual yang perlu disesuaikan

Berikut pemetaan file yang paling relevan untuk penyesuaian konsep baru.

### 6.1 File `includes/functions.php`
Kebutuhan:
- menyesuaikan enum role user agar sesuai model baru
- memungkinkan role utama: `admin`, `sekretaris`, `kominfo`, `komisi_i`, `anggota`
- tidak lagi memaksa role umum seperti `superadmin` jika memang ingin dipangkas

Konteks aktual:
- file ini saat ini mengecek role user general di `users.role`
- saat ini role umum bertumpuk dengan model Hukum dan event-role

### 6.2 File `admin/system/kelola-admin.php`
Kebutuhan:
- form pengelolaan akses admin harus hanya memanage role admin yang benar-benar otoritas puncak per periode
- bukan role umum yang bisa diberi ke siapa saja tanpa kontrol
- jika tetap ada UI untuk role, maka daftar pilihan perlu dibatasi dan disesuaikan dengan model baru

Konteks aktual:
- file ini jelas mengelola `users.role` dan `periode_id` untuk akun admin generic
- ini adalah titik paling jelas yang perlu dibenahi agar `admin` tidak diperlakukan seperti role umum biasa

### 6.3 File `admin/core/dashboard.php`
Kebutuhan:
- dashboard harus dibangun berdasarkan role dasar + event role + akses Hukum
- bukan sekadar `switch ($admin_role)` yang menganggap semua role umum sama

Konteks aktual:
- file ini berisi switch role untuk dashboard utama
- ia merender dashboard khusus per role

### 6.4 File `admin/core/header.php`
Kebutuhan:
- sidebar dan menu harus dibangun berdasarkan kombinasi role dasar + event role + akses Hukum
- menu legal/hukum harus hanya muncul untuk `komisi_i` dan `admin` sesuai kebutuhan

Konteks aktual:
- file ini sudah mengizinkan `hukum_roles` seperti `superadmin`, `admin`, `sekretaris`, `komisi_i`, `ketua_umum_bpm`, `kominfo`, `anggota`
- ini adalah area yang sumber campur aduknya paling jelas

### 6.5 File `admin/core/hukum-auth.php`
Kebutuhan:
- sering-sering dibersihkan agar tidak lagi menegaskan `ketua_umum` dan `komisi_i` sebagai role teknis yang sama level dengan `admin`
- lebih disederhanakan menjadi:
  - admin = approval akhir
  - komisi_i = role hukum
- `hukum_business_membership_for_user()` tetap bisa dipakai jika dibutuhkan sebagai metadata keanggotaan, tetapi tidak lagi harus menjadi satu-satunya penentu hak teknis

Konteks aktual:
- file ini adalah pusat otoritas Hukum saat ini
- ia memadukan `technicalRole`, `business membership`, dan `permission` dengan sangat kuat

### 6.6 File `api/hukum/staging_service.php`
Kebutuhan:
- submit staging sebaiknya didasarkan pada peran Hukum (`komisi_i`) dan final approval `admin`
- logika `Hanya Komisi I yang dapat submit staging` tetap valid
- tetapi `admin` tidak harus dipahami sebagai role umum yang selalu dapat akses

Konteks aktual:
- file ini melakukan `SELECT ... FROM hukum_keanggotaan WHERE jabatan = 'komisi_i' ...`
- ini sudah sangat dekat dengan prinsip yang Anda inginkan, tetapi perlu dikonsolidasikan supaya tidak tercampur dengan role umum sistem

### 6.7 File `api/hukum/review_service.php`
Kebutuhan:
- review role harus dibaca dari role teknis + business membership Hukum yang relevan
- jika model disederhanakan, maka keputusan review esensinya:
  - `komisi_i` => review internal hukum
  - `admin` => final approval

Konteks aktual:
- file ini memetakan role review dengan `komisi_i` dan `ketua_umum`
- ini merupakan area yang paling cocok untuk disederhanakan agar tidak terus-menerus membandingkan `role` sistem dan `jabatan hukum` di satu tempat

### 6.8 File `api/hukum/membership_service.php`
Kebutuhan:
- fungsi ini tetap bisa dipakai sebagai operasi data kepengurusan Hukum, namun tidak lagi harus membentuk logika izin teknis utama
- lebih cocok diperlakukan sebagai metadata / assignment per periode, bukan role utama aplikasi

Konteks aktual:
- file ini jelas membangun `hukum_keanggotaan` sebagai layer teknis untuk aturan Hukum
- fungsi ini perlu dibedakan antara:
  - data organisasi Hukum
  - izin akses aplikasi

### 6.9 File `databases/migrations/2026-09-09-hukum-governance.sql`
Kebutuhan:
- tetap bisa dipertahankan untuk data governance Hukum per periode
- namun jangan dipakai sebagai satu-satunya sumber kebenaran untuk izin sistem
- label `jabatan` hanya berguna sebagai data keanggotaan, bukan sebagai role akses teknis dominan

Konteks aktual:
- file ini menampung tabel `hukum_keanggotaan`, `hukum_staging_approval`, `hukum_commit_window`, dan `hukum_commit_lockout`
- ini merupakan model yang sangat baik untuk data hukum, tetapi perlu dibatasi fungsinya agar tidak bercampur dengan `users.role`

---

## 7. Rekomendasi implementasi konseptual yang paling sederhana

### 7.1 Gunakan model 3 lapis
1. Base role aplikasi
   - `admin`
   - `sekretaris`
   - `kominfo`
   - `komisi_i`
   - `anggota`

2. Event role
   - `sie_acara`
   - `sie_humas`
   - `sie_logistik`
   - `anggota_panitia`
   - `ketuplat`

3. Label organisasi
   - Ketua Umum
   - Wakil Ketua
   - Komisi I
   - Komisi III
   - dll

### 7.2 Aturan akses paling sederhana
- `admin` = akses tertinggi periode aktif
- `komisi_i` = akses Hukum
- `sekretaris` = akses sekretariat
- `kominfo` = akses media
- `anggota` = akses minimal / event-based

### 7.3 Persetujuan Hukum
- Proses hukum dilakukan oleh `komisi_i`
- Approval akhir oleh `admin`

### 7.4 Jangan lagi campur label organisasi ke role program
Jika ada label seperti `Ketua Umum` atau `Komisi I`, simpan sebagai data saja. Tidak dipakai sebagai gate izin secara otomatis kecuali ada kebutuhan khusus yang benar-benar didefinisikan.

---

## 8. Kesimpulan
Konsep yang paling sederhana dan paling cocok dengan kebutuhan yang dibahas adalah:

- `admin` = role teknis tertinggi per periode
- `komisi_i` = role Hukum
- `sekretaris` dan `kominfo` tetap spesifik
- `anggota` tetap dasar / event-oriented
- label organisasi seperti Ketua Umum / Komisi I hanya metadata
- approval Hukum = `komisi_i` + `admin`

Sedangkan kode actual di repo sudah punya pondasi yang bagus di beberapa area, terutama untuk event-role dan Hukum governance, tetapi masih bercampur antara:

- role sistem umum
- role event
- role Hukum
- label organisasi

Itulah sebabnya perlu disederhanakan agar tidak ada ambiguitas antara "siapa yang punya hak akses" dan "siapa yang menjabat apa".

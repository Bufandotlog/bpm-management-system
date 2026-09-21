# Daftar yang kemungkinan diubah atau disederhanakan

Dokumen ini merangkum hal-hal yang kemungkinan perlu diubah atau disederhanakan agar model role di sistem BPM lebih konsisten, terutama pada modul produk hukum.

## 1. Fokus utama
Yang paling mungkin perlu dibenahi adalah pemisahan antara:

- role teknis aplikasi
- role event / panitia
- label organisasi
- akses hukum per periode

Ketentuan konsistensi final yang dipakai di dokumen ini adalah:

- `admin` = role teknis tertinggi per periode
- `sekretaris` = role sekretariat
- `kominfo` = role media
- `komisi_i` = role hukum / domain hukum
- `anggota` = role dasar / event-oriented
- `superadmin` = role sistem puncak yang bisa override semua fitur dan periode
- label seperti `Ketua Umum`, `Wakil Ketua`, `Komisi I`, `Komisi III` = metadata organisasi, bukan role teknis yang langsung membatasi akses
- approval hukum normal = `komisi_i` + `admin`
- `superadmin` tidak masuk dalam alur approval hukum normal

Semua label seperti `Ketua Umum`, `Wakil Ketua`, `Komisi I`, `Komisi III` lebih cocok diperlakukan sebagai metadata organisasi, bukan role teknis yang langsung membatasi akses.

## 1.1 Status implementasi step 1
Langkah pertama yang sudah dikunci dan siap dijadikan acuan implementasi berikutnya adalah:

- base role aplikasi: `superadmin`, `admin`, `sekretaris`, `kominfo`, `komisi_i`, `anggota`
- event role: `sie_acara`, `sie_humas`, `sie_logistik`, `anggota_panitia`, `ketuplat`
- metadata organisasi: `Ketua Umum`, `Wakil Ketua`, `Komisi I`, `Komisi III`, dll.
- governance hukum: `hukum_keanggotaan` dan workflow Hukum per periode
- approval hukum normal: `komisi_i` + `admin`
- `superadmin` tidak ikut approval hukum normal

Status: ROLE MODEL FINAL DICATAT DAN SIAP UNTUK DITRANSFER KE IMPLEMENTASI TEKNIS BERIKUTNYA.

---

## 2. Yang kemungkinan diubah atau disederhanakan

### 2.1 Struktur role utama di `users.role`
File yang paling terkait:

- `includes/functions.php`
- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`

Kemungkinan perubahan:

- membatasi role utama agar tidak terlalu umum
- membuang atau menyeimbangkan penggunaan `superadmin` dan `admin`
- memastikan `admin` hanya dipakai untuk otoritas tertinggi periode tertentu
- memastikan `komisi_i` benar-benar dipakai sebagai role Hukum
- memastikan `anggota` tetap role dasar dan tidak memiliki akses fitur yang seharusnya spesifik

### 2.2 Model admin yang sekarang terlalu umum
File yang paling terkait:

- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`

Kemungkinan perubahan:

- membatasi pembuatan akun admin agar tidak sembarang users bisa menjadi admin
- memisahkan antara:
  - `role teknis` untuk akses sistem
  - `jabatan formal` untuk label organisasi
- memberlakukan bahwa admin hanya diberikan kepada pemegang otoritas periode tertentu

### 2.3 Model event / panitia
File yang paling terkait:

- `admin/kegiatan/buat-panitia.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`

Kemungkinan perubahan:

- memperjelas peran event seperti `sie_acara`, `sie_humas`, `sie_logistik`, `anggota_panitia`, `ketuplat`
- memastikan event role tidak mengganti role utama tetapi menambah akses saat kegiatan aktif
- memastikan role event tidak membingungkan dengan role dasar seperti `admin` atau `komisi_i`

### 2.4 Model Hukum dan governance
File yang paling terkait:

- `databases/migrations/2026-09-09-hukum-governance.sql`
- `admin/core/hukum-auth.php`
- `api/hukum/membership_service.php`
- `api/hukum/staging_service.php`
- `api/hukum/review_service.php`
- `api/hukum/commit_service.php`

Kemungkinan perubahan:

- memisahkan metadata organisasi Hukum dari izin aplikasi
- tetap menaruh `hukum_keanggotaan` sebagai data keanggotaan per periode
- agar `komisi_i` dan `admin` tetap dipakai sebagai role yang jelas untuk review/approval produk hukum
- menghindari penggunaan `jabatan` hukum sebagai ancangan izin teknis yang keluar dari model role yang lebih sederhana

### 2.5 Superadmin sebagai role puncak sistem
File yang paling terkait:

- `includes/functions.php`
- `admin/system/kelola-admin.php`
- `admin/core/dashboard.php`
- `admin/core/header.php`
- `admin/core/hukum-auth.php`

Kemungkinan perubahan:

- menetapkan `superadmin` sebagai role override semua fitur dan semua periode
- memastikan `superadmin` tidak tertukar dengan `admin`
- memastikan `superadmin` tetap memiliki akses lintas periode bila memang dibutuhkan
- memastikan hak akses `superadmin` tidak dianggap sama dengan admin umum

---

## 3. Peran superadmin dalam model yang disederhanakan
Superadmin adalah role puncak sistem. Fungsinya adalah:

- override semua batas fitur aplikasi
- dapat melihat dan mengelola semua periode aktif dan non-aktif
- dapat memodifikasi role, akses, dan pengaturan lintas periode bila dibutuhkan
- dapat memantau atau mengendalikan fitur yang tidak boleh diakses oleh role biasa

Dalam model yang sederhana:

- `admin` = otoritas tertinggi per periode aktif
- `superadmin` = otoritas lintas semua periode dan semua fitur

Artinya:

- `admin` hanya berlaku sesuai periode yang aktif
- `superadmin` bisa melampaui batas periode dan fungsi normal

Jadi urutan otoritas ideal:

1. `superadmin` = override tertinggi
2. `admin` = otoritas periode tertentu
3. `sekretaris`, `kominfo`, `komisi_i`, `anggota` = peran spesifik

Catatan penting:
- `superadmin` tidak harus sama dengan `admin`
- `superadmin` tidak harus dipakai sebagai jabatan organisasi tertentu
- `superadmin` adalah role sistem, bukan label organisasi

---

## 4. Kesimpulan
Yang kemungkinan paling besar perlu dibenahi adalah:

- role umum `admin` di [admin/system/kelola-admin.php](admin/system/kelola-admin.php)
- role umum di [includes/functions.php](includes/functions.php)
- dashboard/header yang berfokus pada `admin_role` di [admin/core/dashboard.php](admin/core/dashboard.php) dan [admin/core/header.php](admin/core/header.php)
- layer Hukum yang sudah ada di [admin/core/hukum-auth.php](admin/core/hukum-auth.php) dan [databases/migrations/2026-09-09-hukum-governance.sql](databases/migrations/2026-09-09-hukum-governance.sql)

Yang paling penting adalah memisahkan:

- role teknis aplikasi
- event role
- label organisasi
- superadmin override global

Agar model lebih sederhana, lebih aman, dan lebih mudah dikelola.

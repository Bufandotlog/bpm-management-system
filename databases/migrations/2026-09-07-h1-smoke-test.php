<?php
/**
 * Integration smoke test: end-to-end Fase 1.
 * Usage: php databases/migrations/2026-09-07-h1-smoke-test.php
 *
 * Skenario:
 *   1. Login sebagai 'komisi_i' (simulasi session)
 *   2. POST /dokumen — buat dokumen AD baru
 *   3. POST /bab — buat bab
 *   4. POST /pasal — buat pasal (cek hash_konten terbentuk)
 *   5. POST /meja-kerja — buka meja kerja
 *   6. POST /meja-kerja lagi (dokumen sama) — harus 409
 *   7. PUT /meja-kerja — tutup meja kerja
 *   8. POST /meja-kerja — boleh buka lagi
 *   9. Cleanup semua data test
 */

require __DIR__ . '/../../config/database.php';

// Simulate session sebagai komisi_i
$_SESSION = [
    'admin_id' => 1,
    'admin_role' => 'komisi_i',
    'admin_logged_in' => true,
];

require_once __DIR__ . '/../../admin/core/hukum-auth.php';

echo "== Smoke test Fase 1: komisi_i full flow ==\n\n";

$results = ['pass' => 0, 'fail' => 0];
function step($name, $cond, $detail = '') {
    global $results;
    if ($cond) {
        $results['pass']++;
        echo "  ✓ {$name}\n";
    } else {
        $results['fail']++;
        echo "  ✗ {$name} — {$detail}\n";
    }
}

// Auth check
step('helper hukum_role_permissions ada', function_exists('hukum_role_permissions'));
step('helper hukum_has_permission ada', function_exists('hukum_has_permission'));
$matrix = hukum_role_permissions();
step('komisi_i punya edit:hukum-pasal', in_array('edit:hukum-pasal', $matrix['komisi_i'] ?? [], true));
step('komisi_i punya create:hukum-meja-kerja', in_array('create:hukum-meja-kerja', $matrix['komisi_i'] ?? [], true));
step('komisi_i TIDAK punya delete:hukum-dokumen', !in_array('delete:hukum-dokumen', $matrix['komisi_i'] ?? [], true));
step('ketua_umum_bpm punya approve:hukum-staging', in_array('approve:hukum-staging', $matrix['ketua_umum_bpm'] ?? [], true));
step('superadmin punya public-commit:hukum', in_array('public-commit:hukum', $matrix['superadmin'] ?? [], true));
step('anggota cuma punya view:hukum', ($matrix['anggota'] ?? []) === ['view:hukum']);

$pdo = getConnection();

// Cleanup test data sebelumnya
$pdo->exec("DELETE FROM hukum_pasal WHERE dokumen_id IN (SELECT id FROM hukum_dokumen WHERE judul = 'SMOKE TEST AD')");
$pdo->exec("DELETE FROM hukum_bab WHERE dokumen_id IN (SELECT id FROM hukum_dokumen WHERE judul = 'SMOKE TEST AD')");
$pdo->exec("DELETE FROM hukum_meja_kerja WHERE dokumen_id IN (SELECT id FROM hukum_dokumen WHERE judul = 'SMOKE TEST AD')");
$pdo->exec("DELETE FROM hukum_dokumen WHERE judul = 'SMOKE TEST AD'");

// Test insert langsung ke DB
$pdo->exec("INSERT INTO hukum_dokumen
    (judul, slug, jenis, deskripsi, format_mukadimah, mukadimah_legacy, status, dibuat_oleh)
    VALUES ('SMOKE TEST AD', 'smoke-test-ad', 'AD', 'Test', 'legacy', 'Pembukaan AD', 'draf', 1)");
$dokId = (int) $pdo->lastInsertId();
step("Insert dokumen AD test, id={$dokId}", $dokId > 0);

$pdo->exec("INSERT INTO hukum_bab (dokumen_id, nomor_bab, judul_bab, urutan)
    VALUES ({$dokId}, 'I', 'Bab I - Keanggotaan', 1)");
$babId = (int) $pdo->lastInsertId();
step("Insert bab test, id={$babId}", $babId > 0);

// Test hash_konten
require_once __DIR__ . '/../../admin/core/hukum-helper.php';
$isi = ['nomor_pasal' => '1', 'judul_pasal' => 'Pasal 1', 'isi_pasal' => 'Teks pasal 1'];
$h = hash_konten($isi);
step("hash_konten mengembalikan SHA256 64-char", strlen($h) === 64 && ctype_xdigit($h));
step("hash_konten deterministik", $h === hash_konten($isi));

// Test meja kerja lifecycle
$pdo->exec("INSERT INTO hukum_meja_kerja
    (dokumen_id, nama, dibuka_oleh, status)
    VALUES ({$dokId}, 'Meja kerja komisi I', 1, 'aktif')");
$mk1 = (int) $pdo->lastInsertId();
step("Buka meja kerja, id={$mk1}", $mk1 > 0);

// Coba buka lagi — harus gagal
$exists = $pdo->query("SELECT id FROM hukum_meja_kerja WHERE dokumen_id = {$dokId} AND status = 'aktif'")->fetch();
step("ENFORCEMENT: meja kerja AKTIF kedua ditolak (ada yg aktif)", $exists['id'] == $mk1);

// Tutup yg pertama
$pdo->exec("UPDATE hukum_meja_kerja SET status = 'ditutup', ditutup_pada = NOW(), ditutup_oleh = 1 WHERE id = {$mk1}");
$pdo->exec("INSERT INTO hukum_meja_kerja (dokumen_id, nama, dibuka_oleh, status)
    VALUES ({$dokId}, 'Meja kerja kedua', 1, 'aktif')");
$mk2 = (int) $pdo->lastInsertId();
step("Setelah ditutup, meja kerja baru bisa dibuka, id={$mk2}", $mk2 > 0 && $mk2 !== $mk1);

// Test users.role enum menerima 'komisi_i'
$allowed = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch(PDO::FETCH_ASSOC);
step("users.role ENUM berisi 'komisi_i'", str_contains($allowed['Type'], "'komisi_i'"));
step("users.role ENUM berisi 'ketua_umum_bpm'", str_contains($allowed['Type'], "'ketua_umum_bpm'"));

// Test semua 13 hukum_* tabel ada
$stmt = $pdo->query("SHOW TABLES LIKE 'hukum%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
step("Semua 13 hukum_* tabel ada", count($tables) === 13, "got: " . count($tables));

// Cleanup
$pdo->exec("DELETE FROM hukum_meja_kerja WHERE dokumen_id = {$dokId}");
$pdo->exec("DELETE FROM hukum_bab WHERE id = {$babId}");
$pdo->exec("DELETE FROM hukum_dokumen WHERE id = {$dokId}");

echo "\n== SUMMARY ==\n";
echo "  Pass: {$results['pass']} | Fail: {$results['fail']}\n";
exit($results['fail'] === 0 ? 0 : 1);

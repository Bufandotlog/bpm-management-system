<?php
// admin/core/hukum-helper.php
// Helper functions untuk modul Produk Hukum (Git-like Versioning)

/**
 * Menghitung hash kanonik (SHA256) dari sebuah isi pasal.
 * Mengurutkan kunci JSON secara alfabetis agar urutan *key* tidak mempengaruhi hasil *hash*.
 * 
 * @param array $isi_array Array asosiatif yang berisi struktur pasal (nomor, teks, ayat, poin, penjelasan)
 * @return string Hash SHA256 (64 karakter)
 */
function hash_konten(array $isi_array) {
    // 1. Lakukan pengurutan key secara rekursif (ksort)
    $isi_array = _recursive_ksort($isi_array);
    
    // 2. Encode menjadi JSON tanpa whitespace tambahan
    $json_string = json_encode($isi_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // 3. Return SHA256 hash
    return hash('sha256', $json_string);
}

/**
 * Helper internal untuk mengurutkan key array secara rekursif.
 */
function _recursive_ksort(array $array) {
    ksort($array);
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = _recursive_ksort($value);
        }
    }
    return $array;
}

/**
 * Menghitung hash commit (SHA256 berantai) untuk mengamankan integritas dokumen.
 * 
 * @param array $daftar_hash_pasal Array dari string hash_konten semua pasal yang aktif pada commit ini. (Akan di-sort otomatis)
 * @param string $parent_commit_hash Hash dari commit sebelumnya (kosongkan jika ini commit pertama)
 * @param array $metadata Informasi metadata commit (misal: forum_tipe, nomor_sk, tanggal_forum)
 * @return string Hash SHA256 (64 karakter)
 */
function hash_commit(array $daftar_hash_pasal, $parent_commit_hash = '', array $metadata = []) {
    // 1. Sortir array hash pasal (untuk menjamin urutan kanonik)
    sort($daftar_hash_pasal);
    
    // 2. Sortir metadata
    ksort($metadata);
    
    // 3. Gabungkan semuanya menjadi satu string besar (payload)
    $payload = [
        'parent_commit' => $parent_commit_hash,
        'metadata' => $metadata,
        'tree' => $daftar_hash_pasal
    ];
    
    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // 4. Return SHA256 hash
    return hash('sha256', $json_payload);
}

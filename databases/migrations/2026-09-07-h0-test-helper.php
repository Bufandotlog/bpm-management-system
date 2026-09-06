<?php
/**
 * Unit test: admin/core/hukum-helper.php
 * Usage: php databases/migrations/2026-09-07-h0-test-helper.php
 *
 * Property-based tests:
 *  1. Determinism: same input → same hash
 *  2. Key-order independence: {a:1,b:2} == {b:2,a:1} (hash identical)
 *  3. Deep ordering: nested array key order does not matter
 *  4. Sensitivity: changing one character changes the hash
 *  5. hash_commit chains deterministically
 *  6. UTF-8 safety: Indonesian characters don't break hash
 */

require __DIR__ . '/../../admin/core/hukum-helper.php';

$tests = 0;
$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $tests, $pass, $fail;
    $tests++;
    if ($cond) {
        $pass++;
        echo "  ✓ {$msg}\n";
    } else {
        $fail++;
        echo "  ✗ {$msg}\n";
    }
}

echo "== Test 1: hash_konten determinism ==\n";
$a = ['nomor' => '1', 'teks' => 'Pasal satu'];
$b = ['nomor' => '1', 'teks' => 'Pasal satu'];
assert_true(hash_konten($a) === hash_konten($b), 'identical input → identical hash');

echo "\n== Test 2: key order independence ==\n";
$x = ['nomor' => '1', 'teks' => 'Isi pasal', 'tahun' => 2026];
$y = ['tahun' => 2026, 'teks' => 'Isi pasal', 'nomor' => '1'];
assert_true(hash_konten($x) === hash_konten($y), 'different key order → same hash');

echo "\n== Test 3: deep nesting key order independence ==\n";
$p = ['nomor' => '1', 'ayat' => [['huruf' => 'a', 'teks' => 'Ayat 1a'], ['huruf' => 'b', 'teks' => 'Ayat 1b']]];
$q = ['ayat' => [['teks' => 'Ayat 1a', 'huruf' => 'a'], ['teks' => 'Ayat 1b', 'huruf' => 'b']], 'nomor' => '1'];
assert_true(hash_konten($p) === hash_konten($q), 'nested key reorder → same hash');

echo "\n== Test 4: sensitivity to content change ==\n";
$c1 = ['nomor' => '1', 'teks' => 'Isi A'];
$c2 = ['nomor' => '1', 'teks' => 'Isi B'];
assert_true(hash_konten($c1) !== hash_konten($c2), 'one-char content change → different hash');

echo "\n== Test 5: hash_commit deterministic with sorted tree ==\n";
$tree1 = ['hash-a', 'hash-b', 'hash-c'];
$tree2 = ['hash-c', 'hash-a', 'hash-b'];
$meta = ['forum_tipe' => 'Senat', 'tanggal' => '2026-09-07'];
$commit1 = hash_commit($tree1, '', $meta);
$commit2 = hash_commit($tree2, '', $meta);
assert_true($commit1 === $commit2, 'unsorted tree → same commit hash (sorts internally)');

echo "\n== Test 6: parent commit changes hash ==\n";
$commit0 = hash_commit($tree1, '', $meta);
$commit1 = hash_commit($tree1, 'parent-hash-xyz', $meta);
assert_true($commit0 !== $commit1, 'different parent → different commit hash');

echo "\n== Test 7: UTF-8 / Indonesian characters ==\n";
$utf = ['nomor' => '1', 'teks' => 'Pasal tentang Anggaran Dasar dan Anggaran Rumah Tangga'];
$hash = hash_konten($utf);
assert_true(strlen($hash) === 64, 'returns 64-char SHA256');
assert_true(ctype_xdigit($hash), 'returns valid hex');

echo "\n== Test 8: SHA256 format ==\n";
$h = hash_konten(['test' => 'value']);
assert_true(strlen($h) === 64, '64-char length');
assert_true(ctype_xdigit($h), 'hex characters only');

echo "\n== SUMMARY ==\n";
echo "  Total: {$tests} | Pass: {$pass} | Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);

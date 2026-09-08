<?php
require_once __DIR__ . '/_bootstrap.php';

$method = hukum_require_method(['GET', 'POST']);
$pdo = getConnection();

if ($method === 'GET') {
    hukum_require_permission('hukum.view');
    $id = (int) ($_GET['id'] ?? 0);
    $sql = 'SELECT * FROM hukum_dokumen';
    $params = [];
    if ($id > 0) {
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $row = dbFetchOne($sql . ' LIMIT 1', $params);
        if (!$row) {
            hukum_json_response(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        }
        hukum_require_document_period((int) $row['periode_id']);
        hukum_json_response(['success' => true, 'data' => $row]);
    }

    $user = hukum_current_user();
    if (!$user['can_access_all'] && $user['role'] !== 'superadmin') {
        $sql .= ' WHERE periode_id = ?';
        $params[] = $user['periode_id'];
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    hukum_json_response(['success' => true, 'data' => dbFetchAll($sql, $params)]);
}

hukum_require_permission('hukum.document.create');
$input = hukum_input();
$required = ['jenis', 'judul', 'slug'];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
        hukum_json_response(['success' => false, 'message' => "Field {$field} wajib diisi."], 400);
    }
}

$periodeId = (int) ($input['periode_id'] ?? 0);
if ($periodeId <= 0 && trim((string) ($input['periode_nama'] ?? '')) !== '') {
    $period = dbFetchOne(
        'SELECT id FROM periode_kepengurusan WHERE nama = ? ORDER BY is_active DESC, tahun_mulai DESC, id DESC LIMIT 1',
        [trim((string) $input['periode_nama'])]
    );
    $periodeId = (int) ($period['id'] ?? 0);
}
if ($periodeId <= 0) {
    hukum_json_response(['success' => false, 'message' => 'Periode wajib dipilih dan harus terdaftar.'], 400);
}
hukum_require_document_period($periodeId);
$allowedJenis = ['AD', 'ART', 'GBHO', 'GBMO', 'PERATURAN', 'KEPUTUSAN'];
$allowedLingkup = ['induk', 'BEM', 'BPM', 'UKM'];
$jenis = strtoupper(trim((string) $input['jenis']));
$lingkup = trim((string) ($input['lingkup'] ?? 'induk'));
$slug = trim((string) $input['slug']);
if (!in_array($jenis, $allowedJenis, true) || !in_array($lingkup, $allowedLingkup, true)) {
    hukum_json_response(['success' => false, 'message' => 'Jenis atau lingkup dokumen tidak valid.'], 400);
}
if (dbFetchOne('SELECT id FROM hukum_dokumen WHERE slug = ? LIMIT 1', [$slug])) {
    hukum_json_response([
        'success' => false,
        'code' => 'SLUG_EXISTS',
        'message' => "Slug \"{$slug}\" sudah digunakan. Gunakan slug lain.",
    ], 409);
}
if ($lingkup !== 'induk' && trim((string) ($input['nama_ormawa'] ?? '')) === '') {
    hukum_json_response(['success' => false, 'message' => 'nama_ormawa wajib untuk dokumen non-induk.'], 400);
}

$stmt = $pdo->prepare(
    'INSERT INTO hukum_dokumen
     (periode_id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, format_mukadimah,
      mukadimah_json, mukadimah_legacy, status, dibuat_oleh)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?)'
);
$format = ($input['format_mukadimah'] ?? 'json') === 'legacy' ? 'legacy' : 'json';
$mukadimah = isset($input['mukadimah_json'])
    ? hukum_decode_json_field($input['mukadimah_json'], 'mukadimah_json')
    : null;
$stmt->execute([
    $periodeId, $jenis, $lingkup, $input['nama_ormawa'] ?? null,
    trim((string) $input['judul']), $slug,
    $input['deskripsi'] ?? null, $format,
    $mukadimah === null ? null : json_encode($mukadimah, JSON_UNESCAPED_UNICODE),
    $input['mukadimah_legacy'] ?? null,
    hukum_current_user_id(),
]);
$id = (int) $pdo->lastInsertId();
hukum_audit($pdo, 'hukum_dokumen', $id, 'create', null, ['periode_id' => $periodeId, 'judul' => $input['judul']]);
hukum_json_response(['success' => true, 'id' => $id], 201);

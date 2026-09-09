<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/core/hukum-auth.php';

function hukum_create_document(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.document.create');

    $required = ['jenis', 'judul', 'slug'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException("Field {$field} wajib diisi.", 400);
        }
    }

    $periodId = (int) ($input['periode_id'] ?? 0);
    if ($periodId <= 0 && trim((string) ($input['periode_nama'] ?? '')) !== '') {
        $period = dbFetchOne(
            'SELECT id FROM periode_kepengurusan WHERE nama = ? ORDER BY is_active DESC, tahun_mulai DESC, id DESC LIMIT 1',
            [trim((string) $input['periode_nama'])]
        );
        $periodId = (int) ($period['id'] ?? 0);
    }
    if ($periodId <= 0) {
        throw new InvalidArgumentException('Periode wajib dipilih dan harus terdaftar.', 400);
    }
    hukum_require_service_period($periodId);

    $jenis = strtoupper(trim((string) $input['jenis']));
    $lingkup = trim((string) ($input['lingkup'] ?? 'induk'));
    if (!in_array($jenis, ['AD', 'ART', 'GBHO', 'GBMO', 'PERATURAN', 'KEPUTUSAN'], true)
        || !in_array($lingkup, ['induk', 'BEM', 'BPM', 'UKM'], true)) {
        throw new InvalidArgumentException('Jenis atau lingkup dokumen tidak valid.', 400);
    }
    $slug = trim((string) $input['slug']);
    if (dbFetchOne('SELECT id FROM hukum_dokumen WHERE slug = ? LIMIT 1', [$slug])) {
        throw new RuntimeException('Slug sudah digunakan. Gunakan slug lain.', 409);
    }
    if ($lingkup !== 'induk' && trim((string) ($input['nama_ormawa'] ?? '')) === '') {
        throw new InvalidArgumentException('nama_ormawa wajib untuk dokumen non-induk.', 400);
    }

    $pdo->beginTransaction();
    try {
        $mukadimah = isset($input['mukadimah_json'])
            ? hukum_decode_json_field($input['mukadimah_json'], 'mukadimah_json')
            : null;
        $stmt = $pdo->prepare(
            'INSERT INTO hukum_dokumen
             (periode_id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, format_mukadimah,
              mukadimah_json, mukadimah_legacy, status, dibuat_oleh)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?)'
        );
        $stmt->execute([
            $periodId, $jenis, $lingkup, $input['nama_ormawa'] ?? null,
            trim((string) $input['judul']), $slug,
            $input['deskripsi'] ?? null,
            ($input['format_mukadimah'] ?? 'json') === 'legacy' ? 'legacy' : 'json',
            $mukadimah === null ? null : json_encode($mukadimah, JSON_UNESCAPED_UNICODE),
            $input['mukadimah_legacy'] ?? null,
            hukum_current_user_id(),
        ]);
        $id = (int) $pdo->lastInsertId();
        hukum_audit($pdo, 'hukum_dokumen', $id, 'create', null, [
            'periode_id' => $periodId,
            'judul' => $input['judul'],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function hukum_get_document(PDO $pdo, int $documentId): array
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Dokumen tidak ditemukan.', 404);
    }
    $row = dbFetchOne('SELECT * FROM hukum_dokumen WHERE id = ? LIMIT 1', [$documentId]);
    if (!$row) {
        throw new RuntimeException('Dokumen tidak ditemukan.', 404);
    }
    hukum_require_service_permission('hukum.view');
    hukum_require_service_period((int) $row['periode_id']);
    return $row;
}

if (!function_exists('hukum_require_service_permission')) {
    function hukum_require_service_permission(string $permission): void
    {
        $actor = hukum_authenticated_actor();
        if ($actor === null) {
            throw new RuntimeException('Sesi tidak valid.', 401);
        }
        $periodId = hukum_current_user_periode_id();
        $membershipAllowed = in_array($permission, [
            'hukum.document.create',
            'hukum.document.update',
            'hukum.pasal.create',
            'hukum.pasal.update',
        ], true) && (
            hukum_business_membership_for_user(hukum_current_user_id(), $periodId, 'komisi_i')
            || hukum_business_membership_for_user(hukum_current_user_id(), $periodId, 'ketua_umum')
        );
        if (!hukum_has_permission($permission) && !$membershipAllowed) {
            throw new RuntimeException('Anda tidak memiliki izin untuk aksi ini.', 403);
        }
    }
}

if (!function_exists('hukum_require_service_period')) {
    function hukum_require_service_period(int $periodId): void
    {
        $actor = hukum_authenticated_actor();
        if ($periodId <= 0) {
            throw new InvalidArgumentException('Periode dokumen tidak valid.', 400);
        }
        if ($actor === null) {
            throw new RuntimeException('Sesi tidak valid.', 401);
        }
        if (!$actor->canAccessAll && $actor->technicalRole !== 'superadmin' && $actor->periodId !== $periodId) {
            throw new RuntimeException('Anda tidak memiliki akses ke periode dokumen ini.', 403);
        }
    }
}

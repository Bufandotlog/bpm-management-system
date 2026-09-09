<?php
declare(strict_types=1);

require_once __DIR__ . '/document_service.php';

function hukum_create_bab(PDO $pdo, array $input): array
{
    hukum_require_service_permission('hukum.document.update');
    $documentId = (int) ($input['dokumen_id'] ?? 0);
    $doc = dbFetchOne('SELECT periode_id, status FROM hukum_dokumen WHERE id = ?', [$documentId]);
    if (!$doc) {
        throw new RuntimeException('Dokumen tidak ditemukan.', 404);
    }
    hukum_require_service_period((int) $doc['periode_id']);
    if ($doc['status'] !== 'draft') {
        throw new RuntimeException('BAB hanya dapat dibuat pada dokumen draft.', 409);
    }
    foreach (['nomor_label', 'judul_bab', 'urutan'] as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException("{$field} wajib.", 400);
        }
    }
    $order = (int) $input['urutan'];
    if ($order <= 0) {
        throw new InvalidArgumentException('urutan harus lebih dari 0.', 400);
    }
    $label = trim((string) $input['nomor_label']);
    if (dbFetchOne('SELECT id FROM hukum_bab WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1', [$documentId, $label])
        || dbFetchOne('SELECT id FROM hukum_bab WHERE dokumen_id = ? AND urutan = ? LIMIT 1', [$documentId, $order])) {
        throw new RuntimeException('Nomor atau urutan BAB sudah digunakan.', 409);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_bab (dokumen_id, nomor_label, judul_bab, bagian_label, urutan) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$documentId, $label, trim((string) $input['judul_bab']), $input['bagian_label'] ?? null, $order]);
    $id = (int) $pdo->lastInsertId();
    hukum_audit($pdo, 'hukum_bab', $id, 'create', null, $input);
    return ['id' => $id];
}

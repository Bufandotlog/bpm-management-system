<?php

require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
hukum_require_csrf();

function hukum_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            hukum_json_response(['success' => false, 'message' => 'JSON request tidak valid.'], 400);
        }
        return $decoded;
    }

    return $_POST;
}

function hukum_require_method(array $methods): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
    }
    return $method;
}

function hukum_decode_json_field(mixed $value, string $field): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    hukum_json_response(['success' => false, 'message' => "{$field} harus berupa object/array JSON."], 400);
}

function hukum_audit(PDO $pdo, string $entity, int $entityId, string $action, ?array $before = null, ?array $after = null): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare(
        'INSERT INTO hukum_audit_log
         (entitas, entitas_id, aksi, aktor_id, sebelum_json, sesudah_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $entity,
        $entityId,
        $action,
        hukum_current_user_id(),
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        $ip,
    ]);
}

function hukum_canonical_json(array $value): string
{
    $sort = static function (array $input) use (&$sort): array {
        foreach ($input as $key => $item) {
            if (is_array($item) && array_keys($item) !== range(0, count($item) - 1)) {
                $input[$key] = $sort($item);
            } elseif (is_array($item)) {
                $input[$key] = array_map(
                    static fn ($child) => is_array($child) ? $sort($child) : $child,
                    $item
                );
            }
        }
        ksort($input);
        return $input;
    };

    $canonical = $sort($value);
    $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        hukum_json_response(['success' => false, 'message' => 'Isi JSON tidak dapat dinormalisasi.'], 400);
    }
    return $encoded;
}

function hukum_extract_inline_references(array $value): array
{
    $references = [];
    $walk = static function (mixed $item, string $field) use (&$walk, &$references): void {
        if (is_array($item)) {
            foreach ($item as $child) {
                $walk($child, $field);
            }
            return;
        }
        if (!is_string($item)) {
            return;
        }
        if (preg_match_all('/\[\[PASAL:([0-9]+)(?:\/AYAT:([0-9]+))?\]\]/i', $item, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $references[] = [
                    'pasal_tujuan_nomor' => (string) (int) $match[1],
                    'ayat_tujuan_nomor' => isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null,
                    'konteks_field' => $field,
                ];
            }
        }
    };
    $walk($value, 'teks_utama');
    return $references;
}

function hukum_sync_inline_references(PDO $pdo, int $pasalId, int $dokumenId, array $isi): int
{
    $pdo->prepare('DELETE FROM hukum_referensi_inline WHERE pasal_asal_id = ?')->execute([$pasalId]);
    $insert = $pdo->prepare(
        'INSERT INTO hukum_referensi_inline
         (pasal_asal_id, dokumen_tujuan_id, pasal_tujuan_nomor, ayat_tujuan_nomor, konteks_field, status_validasi)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $count = 0;
    foreach (hukum_extract_inline_references($isi) as $reference) {
        $target = dbFetchOne(
            'SELECT id FROM hukum_pasal WHERE dokumen_id = ? AND nomor_label = ? LIMIT 1',
            [$dokumenId, $reference['pasal_tujuan_nomor']]
        );
        $insert->execute([
            $pasalId,
            $dokumenId,
            $reference['pasal_tujuan_nomor'],
            $reference['ayat_tujuan_nomor'],
            $reference['konteks_field'],
            $target ? 'valid' : 'tidak_ditemukan',
        ]);
        $count++;
    }
    return $count;
}

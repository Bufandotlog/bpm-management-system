<?php

require_once __DIR__ . '/../../admin/core/hukum-auth.php';

hukum_require_login();
hukum_require_csrf();

if (!function_exists('hukum_input')) {
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
}

if (!function_exists('hukum_require_method')) {
    function hukum_require_method(array $methods): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $methods, true)) {
            hukum_json_response(['success' => false, 'message' => 'Method tidak didukung.'], 405);
        }
        return $method;
    }
}

if (!function_exists('hukum_decode_json_field')) {
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
}

if (!function_exists('hukum_request_id')) {
    function hukum_request_id(): string
    {
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string) $_SERVER['HTTP_X_REQUEST_ID'];
        }

        return 'hukum-' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('hukum_audit')) {
    function hukum_audit(PDO $pdo, string $entity, int $entityId, string $action, ?array $before = null, ?array $after = null, ?array $extra = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user = hukum_current_user();
        $requestId = hukum_request_id();
        $roleContext = strtolower(trim((string) ($extra['role_context'] ?? $user['role'])));
        $periodId = isset($extra['periode_id']) ? (int) $extra['periode_id'] : (int) ($user['periode_id'] ?? 0);
        $result = isset($extra['result']) ? (string) $extra['result'] : 'success';
        $contextJson = isset($extra['context_json']) ? json_encode($extra['context_json'], JSON_UNESCAPED_UNICODE) : null;

        $sql = 'INSERT INTO hukum_audit_log
                (entitas, entitas_id, aksi, aktor_id, role_context, periode_id, request_id, result, context_json, sebelum_json, sesudah_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $entity,
            $entityId,
            $action,
            hukum_current_user_id(),
            $roleContext !== '' ? $roleContext : null,
            $periodId > 0 ? $periodId : null,
            $requestId,
            $result,
            $contextJson,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $ip,
        ]);
    }
}

if (!function_exists('hukum_canonical_json')) {
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
}

if (!function_exists('hukum_extract_inline_references')) {
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
                        'raw_reference' => $match[0],
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
}

if (!function_exists('hukum_collect_reference_failures')) {
    function hukum_collect_reference_failures(PDO $pdo, int $sourcePasalId, array $references): array
    {
        $failures = [];
        if ($sourcePasalId <= 0) {
            return [[
                'source' => 'pasal:' . $sourcePasalId,
                'raw_reference' => null,
                'target' => null,
                'reason' => 'Pasal sumber tidak valid.',
            ]];
        }

        $source = dbFetchOne(
            'SELECT p.id, p.dokumen_id, p.nomor_label FROM hukum_pasal p WHERE p.id = ? LIMIT 1',
            [$sourcePasalId]
        );
        if (!$source) {
            return [[
                'source' => 'pasal:' . $sourcePasalId,
                'raw_reference' => null,
                'target' => null,
                'reason' => 'Pasal sumber tidak ditemukan.',
            ]];
        }

        foreach ($references as $reference) {
            $raw = (string) ($reference['raw_reference'] ?? '');
            $targetNomor = trim((string) ($reference['pasal_tujuan_nomor'] ?? ''));
            $targetAyat = isset($reference['ayat_tujuan_nomor']) ? (int) $reference['ayat_tujuan_nomor'] : null;

            if ($targetNomor === '') {
                $failures[] = [
                    'source' => (string) ($source['nomor_label'] ?? $sourcePasalId),
                    'raw_reference' => $raw,
                    'target' => null,
                    'reason' => 'Referensi tidak memiliki nomor pasal yang valid.',
                ];
                continue;
            }

            $target = dbFetchOne(
                'SELECT p.id, p.nomor_label, p.dokumen_id FROM hukum_pasal p WHERE p.nomor_label = ? OR p.nomor_label = ? LIMIT 1',
                [$targetNomor, 'Pasal ' . $targetNomor]
            );
            if (!$target) {
                $failures[] = [
                    'source' => (string) ($source['nomor_label'] ?? $sourcePasalId),
                    'raw_reference' => $raw,
                    'target' => $targetNomor,
                    'reason' => 'Target tidak ditemukan.',
                ];
                continue;
            }

            if ((int) $source['dokumen_id'] !== (int) $target['dokumen_id']) {
                $crossDocument = dbFetchOne(
                    'SELECT dokumen_tujuan_id FROM hukum_referensi_inline WHERE pasal_asal_id = ? AND pasal_tujuan_nomor = ? LIMIT 1',
                    [$sourcePasalId, $targetNomor]
                );
                if (!$crossDocument) {
                    $failures[] = [
                        'source' => (string) ($source['nomor_label'] ?? $sourcePasalId),
                        'raw_reference' => $raw,
                        'target' => $targetNomor,
                        'reason' => 'Cross-document reference tidak didukung pada contract aktif.',
                    ];
                }
            }

            if ($targetAyat !== null) {
                $targetVersion = dbFetchOne(
                    'SELECT id FROM hukum_pasal_versi WHERE pasal_id = ? ORDER BY id DESC LIMIT 1',
                    [(int) $target['id']]
                );
                if (!$targetVersion) {
                    $failures[] = [
                        'source' => (string) ($source['nomor_label'] ?? $sourcePasalId),
                        'raw_reference' => $raw,
                        'target' => $targetNomor . '/AYAT:' . $targetAyat,
                        'reason' => 'Versi target untuk ayat yang dirujuk tidak tersedia.',
                    ];
                }
            }
        }

        return $failures;
    }
}

if (!function_exists('hukum_sync_inline_references')) {
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
}

<?php

function hukum_public_document_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    return dbFetchOne(
        "SELECT id, periode_id, jenis, lingkup, nama_ormawa, judul, slug, deskripsi, mukadimah_json, status, published_at
         FROM hukum_dokumen
         WHERE slug = ? AND status = 'aktif'
         LIMIT 1",
        [$slug]
    );
}

function hukum_public_active_commit_by_document(int $documentId): ?array
{
    if ($documentId <= 0) {
        return null;
    }

    return dbFetchOne(
        "SELECT c.id, c.dokumen_id, c.parent_commit_id, c.hash_commit, c.snapshot_tree, c.forum_tipe, c.tanggal_forum, c.status, c.created_at
         FROM hukum_commit c
         WHERE c.dokumen_id = ? AND c.status = 'aktif'
         ORDER BY c.id DESC
         LIMIT 1",
        [$documentId]
    );
}

function hukum_public_history_by_document(int $documentId): array
{
    if ($documentId <= 0) {
        return [];
    }

    return dbFetchAll(
        "SELECT c.id, c.parent_commit_id, c.hash_commit, c.snapshot_tree, c.forum_tipe, c.tanggal_forum, c.status, c.created_at
         FROM hukum_commit c
         WHERE c.dokumen_id = ? AND c.status IN ('aktif', 'digantikan')
         ORDER BY c.created_at DESC, c.id DESC",
        [$documentId]
    );
}

function hukum_public_snapshot_items(?array $commit): array
{
    if (!is_array($commit) || empty($commit['snapshot_tree'])) {
        return [];
    }

    $snapshot = json_decode((string) $commit['snapshot_tree'], true);
    if (!is_array($snapshot)) {
        return [];
    }

    $items = [];
    foreach ($snapshot as $item) {
        if (is_array($item) && isset($item['pasal_id'])) {
            $items[] = $item;
        }
    }

    return $items;
}

function hukum_public_snapshot_map(?array $commit): array
{
    $map = [];
    foreach (hukum_public_snapshot_items($commit) as $item) {
        $pasalId = (int) ($item['pasal_id'] ?? 0);
        if ($pasalId > 0) {
            $map[$pasalId] = $item;
        }
    }

    return $map;
}

function hukum_public_extract_references_from_snapshot(array $snapshot): array
{
    $refs = [];
    $walk = static function ($value, string $path, &$refs) use (&$walk): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $walk($item, $path . '/' . (string) $key, $refs);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        if (preg_match_all('/\[\[PASAL:(\d+)(?:\/AYAT:(\d+))?\]\]/i', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $refs[] = [
                    'path' => $path,
                    'raw' => $match[0],
                    'pasal' => (string) (int) $match[1],
                    'ayat' => isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null,
                ];
            }
        }
    };

    foreach ($snapshot as $item) {
        if (!is_array($item)) {
            continue;
        }
        $walk($item['isi'] ?? [], 'pasal:' . ($item['pasal_id'] ?? 'n/a'), $refs);
    }

    return $refs;
}

function hukum_public_diff_snapshot($left, $right): array
{
    $leftMap = is_array($left) ? hukum_public_snapshot_map($left) : [];
    $rightMap = is_array($right) ? hukum_public_snapshot_map($right) : [];
    $allIds = array_unique(array_merge(array_keys($leftMap), array_keys($rightMap)));
    sort($allIds, SORT_NUMERIC);

    $items = [];
    foreach ($allIds as $pasalId) {
        $leftItem = $leftMap[$pasalId] ?? null;
        $rightItem = $rightMap[$pasalId] ?? null;

        if ($leftItem === null && $rightItem !== null) {
            $items[] = ['pasal_id' => (int) $pasalId, 'status' => 'added', 'before' => null, 'after' => $rightItem];
            continue;
        }

        if ($leftItem !== null && $rightItem === null) {
            $items[] = ['pasal_id' => (int) $pasalId, 'status' => 'removed', 'before' => $leftItem, 'after' => null];
            continue;
        }

        $before = json_encode($leftItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $after = json_encode($rightItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($before === $after) {
            $items[] = ['pasal_id' => (int) $pasalId, 'status' => 'unchanged', 'before' => $leftItem, 'after' => $rightItem];
            continue;
        }

        $items[] = ['pasal_id' => (int) $pasalId, 'status' => 'modified', 'before' => $leftItem, 'after' => $rightItem];
    }

    return $items;
}

function hukum_public_relation_map_for_commit(int $commitId): array
{
    if ($commitId <= 0) {
        return ['nodes' => [], 'edges' => []];
    }

    $nodes = dbFetchAll(
        "SELECT gs.id, gs.pasal_id, gs.nomor_label, gs.payload_json
         FROM hukum_graph_snapshot gs
         WHERE gs.commit_id = ?
         ORDER BY gs.pasal_id ASC",
        [$commitId]
    );

    $edges = dbFetchAll(
        "SELECT e.id, e.source_pasal_id, e.target_pasal_id, e.jenis_relasi, e.metadata_json
         FROM hukum_graph_snapshot_edge e
         JOIN hukum_graph_snapshot gs ON gs.id = e.snapshot_id
         WHERE gs.commit_id = ?
         ORDER BY e.id ASC",
        [$commitId]
    );

    return ['nodes' => $nodes, 'edges' => $edges];
}

function hukum_public_label_for_pasal_id(?int $pasalId, ?array $snapshotItem = null): string
{
    if ($pasalId === null || $pasalId <= 0) {
        return 'Pasal tidak diketahui';
    }

    if (is_array($snapshotItem) && !empty($snapshotItem['nomor_label'])) {
        return (string) $snapshotItem['nomor_label'];
    }

    return 'Pasal ' . (int) $pasalId;
}

function hukum_public_diff_status_label(string $status): string
{
    $map = [
        'added' => 'ditambahkan',
        'removed' => 'dihapus',
        'modified' => 'diubah',
        'unchanged' => 'tetap',
    ];

    return $map[$status] ?? $status;
}

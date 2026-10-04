<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
require_once __DIR__ . '/support/hukum_test_fixture.php';
$fixture = hukum_create_test_fixture();
$pdo = getConnection();
hukum_set_actor_context_provider($fixture['provider']);
require_once __DIR__ . '/../api/hukum/document_service.php';
require_once __DIR__ . '/../api/hukum/workspace_service.php';
require_once __DIR__ . '/../api/hukum/bab_service.php';
require_once __DIR__ . '/../api/hukum/pasal_service.php';
require_once __DIR__ . '/../api/hukum/staging_service.php';
require_once __DIR__ . '/../api/hukum/commit_service.php';

function hukum_relationship_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tag = bin2hex(random_bytes(6));
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Relationship impact ' . $tag,
    'slug' => 'relationship-impact-' . $tag,
    'periode_id' => $fixture['period_id'],
]);
$workspace = hukum_create_workspace(
    $pdo,
    $document['id'],
    'Relationship impact test',
    'Acuan searah',
    $fixture['actors']['komisi_i']->id
);
$bab = hukum_create_bab($pdo, [
    'dokumen_id' => $document['id'],
    'nomor_label' => 'BAB I',
    'judul_bab' => 'Materi',
    'urutan' => 1,
]);
$pasals = [];
foreach ([1, 2, 3, 4] as $order) {
    $pasals[$order] = hukum_create_pasal($pdo, [
        'dokumen_id' => $document['id'],
        'bab_id' => $bab['id'],
        'nomor_label' => 'Pasal ' . $order,
        'judul_pasal' => 'Materi ' . $order,
        'urutan' => $order,
    ]);
}
$targetDocument = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Cross-document target ' . $tag,
    'slug' => 'cross-document-target-' . $tag,
    'periode_id' => $fixture['period_id'],
]);
$targetWorkspace = hukum_create_workspace(
    $pdo,
    $targetDocument['id'],
    'Cross-document target test',
    'Target acuan eksternal',
    $fixture['actors']['komisi_i']->id
);
$targetBab = hukum_create_bab($pdo, [
    'dokumen_id' => $targetDocument['id'],
    'nomor_label' => 'BAB I',
    'judul_bab' => 'Target',
    'urutan' => 1,
]);
$targetPasal = hukum_create_pasal($pdo, [
    'dokumen_id' => $targetDocument['id'],
    'bab_id' => $targetBab['id'],
    'nomor_label' => 'Pasal 1',
    'judul_pasal' => 'Target acuan',
    'urutan' => 1,
]);
$initialTarget = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $targetPasal['id'],
    'workspace_id' => $targetWorkspace['id'],
    'isi' => ['teks_utama' => 'Target awal', 'acuan' => []],
]);
$pdo->prepare('UPDATE hukum_pasal_versi SET status = ? WHERE id = ?')
    ->execute(['committed', $initialTarget['id']]);

$initialVersions = [];
foreach ([1, 2, 3, 4] as $order) {
    $contents = ['teks_utama' => 'Versi awal Pasal ' . $order, 'acuan' => []];
    if ($order === 2) {
        $contents['acuan'] = ['Pasal 1'];
    } elseif ($order === 3) {
        $contents['acuan'] = ['Pasal 2'];
    } elseif ($order === 4) {
        $contents['acuan'] = ['Pasal 1'];
    }
    $initialVersions[$order] = hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasals[$order]['id'],
        'workspace_id' => $workspace['id'],
        'isi' => $contents,
    ]);
}
$initialVersions[4] = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[4]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Hapus acuan', 'acuan' => []],
]);
$removedRelation = $pdo->prepare(
    'SELECT COUNT(*) FROM hukum_relasi_pasal WHERE pasal_anak_id = ? AND pasal_induk_id = ?'
);
$removedRelation->execute([$pasals[4]['id'], $pasals[1]['id']]);
hukum_relationship_assert((int) $removedRelation->fetchColumn() === 0, 'An empty acuan list did not remove the old manual relation.');
$firstSingleTarget = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[4]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Satu acuan', 'acuan_pasal_id' => $pasals[1]['id']],
]);
$crossDocumentChild = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[4]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Acuan lintas dokumen', 'acuan_pasal_id' => $targetPasal['id']],
]);
$crossRelation = dbFetchOne(
    'SELECT pasal_induk_id FROM hukum_relasi_pasal
     WHERE pasal_anak_id = ? AND jenis_relasi = ?',
    [$pasals[4]['id'], 'mengacu']
);
hukum_relationship_assert(
    (int) ($crossRelation['pasal_induk_id'] ?? 0) === (int) $targetPasal['id'],
    'A child Pasal did not replace its former outgoing acuan with a cross-document Pasal ID.'
);
$outgoingCount = dbFetchOne(
    'SELECT COUNT(*) AS total FROM hukum_relasi_pasal
     WHERE pasal_anak_id = ? AND jenis_relasi = ?',
    [$pasals[4]['id'], 'mengacu']
);
hukum_relationship_assert((int) ($outgoingCount['total'] ?? 0) === 1, 'A Pasal acquired more than one outgoing mengacu relation.');

$edges = $pdo->query(
    'SELECT pasal_anak_id, pasal_induk_id FROM hukum_relasi_pasal
     WHERE pasal_anak_id IN (' . (int) $pasals[2]['id'] . ', ' . (int) $pasals[3]['id'] . ')
     ORDER BY pasal_anak_id'
)->fetchAll(PDO::FETCH_ASSOC);
$edgePairs = array_map(
    static fn (array $edge): array => [(int) $edge['pasal_anak_id'], (int) $edge['pasal_induk_id']],
    $edges
);
hukum_relationship_assert(
    $edgePairs === [
        [(int) $pasals[2]['id'], (int) $pasals[1]['id']],
        [(int) $pasals[3]['id'], (int) $pasals[2]['id']],
    ],
    'Save Draft did not persist the directed child-to-parent acuan edges.'
);
hukum_relationship_assert(
    (int) $pdo->query(
        'SELECT COUNT(*) FROM hukum_notifikasi n
         JOIN hukum_pasal p ON p.id = n.pasal_anak_id
         WHERE p.dokumen_id = ' . (int) $document['id']
    )->fetchColumn() === 0,
    'Initial draft content incorrectly raised impact notifications without a committed baseline.'
);

$versionCountBeforeInvalidAcuan = (int) $pdo->query(
    'SELECT COUNT(*) FROM hukum_pasal_versi WHERE pasal_id = ' . (int) $pasals[2]['id']
)->fetchColumn();
try {
    hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasals[2]['id'],
        'workspace_id' => $workspace['id'],
        'isi' => ['teks_utama' => 'Acuan invalid', 'acuan' => ['Pasal 999']],
    ]);
    throw new RuntimeException('Save Draft accepted an acuan target that does not exist.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 409 || !str_contains($error->getMessage(), 'tidak ditemukan')) {
        throw $error;
    }
}
hukum_relationship_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM hukum_pasal_versi WHERE pasal_id = ' . (int) $pasals[2]['id'])->fetchColumn()
        === $versionCountBeforeInvalidAcuan,
    'Invalid acuan partially persisted a Pasal draft instead of rolling back.'
);

$pdo->prepare('UPDATE hukum_pasal_versi SET status = ? WHERE id = ?')
    ->execute(['committed', $initialVersions[1]['id']]);
$changedParent = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[1]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Perubahan substansi Pasal 1', 'acuan' => []],
]);
$changedExternalTarget = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $targetPasal['id'],
    'workspace_id' => $targetWorkspace['id'],
    'isi' => ['teks_utama' => 'Perubahan target lintas dokumen', 'acuan' => []],
]);

$pending = $pdo->prepare(
    'SELECT n.pasal_anak_id, n.pasal_induk_id, n.dipicu_oleh_versi_id
     FROM hukum_notifikasi n WHERE n.status = ? ORDER BY n.pasal_anak_id'
);
$pending->execute(['perlu_ditinjau']);
$notifications = $pending->fetchAll(PDO::FETCH_ASSOC);
hukum_relationship_assert(count($notifications) === 3, 'Impact propagation did not reach B, C, and the cross-document child.');
hukum_relationship_assert(
    (int) $notifications[0]['pasal_anak_id'] === (int) $pasals[2]['id']
        && (int) $notifications[0]['pasal_induk_id'] === (int) $pasals[1]['id']
        && (int) $notifications[1]['pasal_anak_id'] === (int) $pasals[3]['id']
        && (int) $notifications[1]['pasal_induk_id'] === (int) $pasals[2]['id'],
    'Impact notifications do not preserve the directed A -> B -> C chain.'
);
foreach ($notifications as $notification) {
    $expectedTrigger = (int) $notification['pasal_anak_id'] === (int) $pasals[4]['id']
        ? (int) $changedExternalTarget['id']
        : (int) $changedParent['id'];
    hukum_relationship_assert(
        (int) $notification['dipicu_oleh_versi_id'] === $expectedTrigger,
        'Propagated impact notification does not identify the changed root version.'
    );
}

try {
    hukum_submit_staging($pdo, $workspace['id'], [$changedParent['id']], $fixture['actors']['komisi_i']->id);
    throw new RuntimeException('Staging accepted a document with unresolved impact notifications.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 409 || !str_contains($error->getMessage(), 'Staging diblokir')) {
        throw $error;
    }
}

$acuanOnlyChild = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[3]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Versi awal Pasal 3', 'acuan' => [' Pasal 2 ']],
]);
$resolvedCount = hukum_resolve_impact_notifications_for_versions($pdo, $workspace['id'], [$acuanOnlyChild['id']]);
hukum_relationship_assert($resolvedCount === 0, 'Changing only acuan metadata incorrectly marked the child content as aligned.');

$changedChild = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[2]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Penyelarasan Pasal 2', 'acuan' => ['Pasal 1']],
]);
$changedGrandchild = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[3]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Penyelarasan Pasal 3', 'acuan' => ['Pasal 2']],
]);
$changedCrossDocumentChild = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[4]['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks_utama' => 'Penyelarasan acuan lintas dokumen', 'acuan_pasal_id' => $targetPasal['id']],
]);
$resolvedCount = hukum_resolve_impact_notifications_for_versions(
    $pdo,
    $workspace['id'],
    [$changedChild['id'], $changedGrandchild['id'], $changedCrossDocumentChild['id']]
);
hukum_relationship_assert($resolvedCount === 3, 'Saving changed child drafts did not resolve their pending impact signals.');
hukum_assert_no_pending_impact_notifications($pdo, $document['id']);

$staging = hukum_submit_staging(
    $pdo,
    $workspace['id'],
    [$changedParent['id'], $changedChild['id'], $changedGrandchild['id'], $changedCrossDocumentChild['id']],
    $fixture['actors']['komisi_i']->id
);
hukum_relationship_assert(($staging['status'] ?? '') === 'menunggu_review', 'Staging did not proceed after all impact notifications were resolved.');
try {
    hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasals[1]['id'],
        'workspace_id' => $workspace['id'],
        'isi' => ['teks_utama' => 'Perubahan setelah diajukan', 'acuan' => []],
    ]);
    throw new RuntimeException('Save Draft changed a workspace after it entered staging.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 409 || !str_contains($error->getMessage(), 'Workspace')) {
        throw $error;
    }
}

$targetStaging = hukum_submit_staging(
    $pdo,
    $targetWorkspace['id'],
    [$changedExternalTarget['id']],
    $fixture['actors']['komisi_i']->id
);
$createCommit = static function (int $documentId, int $stagingId, int $actorId) use ($pdo): int {
    $pdo->prepare(
        'INSERT INTO hukum_commit
         (dokumen_id, staging_id, parent_commit_id, hash_commit, snapshot_tree, forum_tipe, tanggal_forum, status, dibuat_oleh)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $documentId,
        $stagingId,
        hash('sha256', bin2hex(random_bytes(16))),
        '[]',
        'sidang',
        date('Y-m-d'),
        'aktif',
        $actorId,
    ]);
    return (int) $pdo->lastInsertId();
};
$targetCommitId = $createCommit(
    $targetDocument['id'],
    $targetStaging['id'],
    $fixture['actors']['komisi_i']->id
);
hukum_commit_snapshot_graph($pdo, $targetCommitId, $targetDocument['id']);
$sourceCommitId = $createCommit(
    $document['id'],
    $staging['id'],
    $fixture['actors']['komisi_i']->id
);
hukum_commit_snapshot_graph($pdo, $sourceCommitId, $document['id']);
$snapshotEdge = dbFetchOne(
    'SELECT e.source_pasal_id, e.target_pasal_id, e.source_version_id, e.target_version_id
     FROM hukum_graph_snapshot_edge e
     JOIN hukum_graph_snapshot s ON s.id = e.snapshot_id
     WHERE s.commit_id = ? AND e.source_pasal_id = ? AND e.target_pasal_id = ?',
    [$sourceCommitId, $pasals[4]['id'], $targetPasal['id']]
);
hukum_relationship_assert(
    $snapshotEdge !== null
        && (int) $snapshotEdge['source_version_id'] === (int) $changedCrossDocumentChild['id']
        && (int) $snapshotEdge['target_version_id'] === (int) $changedExternalTarget['id'],
    'Public source commit did not snapshot the cross-document acuan against the active target version.'
);
$pdo->prepare('UPDATE hukum_dokumen SET status = ? WHERE id IN (?, ?)')
    ->execute(['aktif', $document['id'], $targetDocument['id']]);
$publicTarget = dbFetchOne(
    "SELECT e.source_pasal_id, e.target_pasal_id, d.judul AS dokumen_judul, d.slug AS dokumen_slug
     FROM hukum_graph_snapshot_edge e
     JOIN hukum_graph_snapshot source_snapshot ON source_snapshot.id = e.snapshot_id
     JOIN hukum_pasal p ON p.id = e.target_pasal_id
     JOIN hukum_dokumen d ON d.id = p.dokumen_id AND d.status = 'aktif'
     JOIN (
         SELECT dokumen_id, MAX(id) AS commit_id
         FROM hukum_commit
         WHERE status = 'aktif'
         GROUP BY dokumen_id
     ) current_commit ON current_commit.dokumen_id = d.id
     JOIN hukum_graph_snapshot target_snapshot
       ON target_snapshot.commit_id = current_commit.commit_id
      AND target_snapshot.pasal_id = p.id
      AND target_snapshot.is_active = 1
     WHERE source_snapshot.commit_id = ? AND e.jenis_relasi = 'mengacu'
       AND e.source_pasal_id = ?",
    [$sourceCommitId, $pasals[4]['id']]
);
hukum_relationship_assert(
    $publicTarget !== null
        && (int) $publicTarget['source_pasal_id'] === (int) $pasals[4]['id']
        && (int) $publicTarget['target_pasal_id'] === (int) $targetPasal['id']
        && (string) $publicTarget['dokumen_slug'] === 'cross-document-target-' . $tag,
    'Public acuan lookup did not resolve the committed cross-document target.'
);
try {
    hukum_deletion_assert_no_active_relations($pdo, $targetCommitId, [$targetPasal['id']]);
    throw new RuntimeException('Deletion allowed removal of a cross-document acuan target.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 409 || !str_contains($error->getMessage(), 'relasi aktif')) {
        throw $error;
    }
}

echo "PASS directed relationship sync, A -> B -> C impact, draft alignment, and staging hard block\n";

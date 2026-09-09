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
require_once __DIR__ . '/../api/hukum/review_service.php';
require_once __DIR__ . '/../api/hukum/commit_service.php';

function hukum_11q_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hukum_11q_approve_and_commit(PDO $pdo, array $fixture, int $stagingId, string $suffix): array
{
    hukum_set_actor_context_provider($fixture['provider']->as('komisi_i'));
    hukum_review_decide($pdo, $stagingId, 'approve', null, $fixture['actors']['komisi_i']->id);
    hukum_set_actor_context_provider($fixture['provider']->as('ketua_umum'));
    hukum_review_decide($pdo, $stagingId, 'approve', null, $fixture['actors']['ketua_umum']->id);
    hukum_commit_create_window($pdo, $fixture['actors']['komisi_i']->id, 'komisi_i', $fixture['credentials']['komisi_i'], $suffix . '-a');
    hukum_commit_create_window($pdo, $fixture['actors']['ketua_umum']->id, 'ketua_umum', $fixture['credentials']['ketua_umum'], $suffix . '-b');
    hukum_set_actor_context_provider($fixture['provider']->as('komisi_i'));
    return hukum_commit_finalize(
        $pdo,
        $stagingId,
        $fixture['actors']['komisi_i']->id,
        $fixture['credentials']['komisi_i'],
        $suffix . '-final',
        $suffix . '-session'
    );
}

$tag = bin2hex(random_bytes(6));
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Session 11Q ' . $tag,
    'slug' => 'session-11q-' . $tag,
    'periode_id' => $fixture['period_id'],
]);
$workspaceA = hukum_create_workspace($pdo, $document['id'], 'Graph baseline', 'Session 11Q', $fixture['actors']['komisi_i']->id);
$bab = hukum_create_bab($pdo, [
    'dokumen_id' => $document['id'],
    'nomor_label' => 'BAB I',
    'judul_bab' => 'Materi',
    'urutan' => 1,
]);
$pasals = [];
foreach ([1, 2, 3] as $order) {
    $pasals[$order] = hukum_create_pasal($pdo, [
        'dokumen_id' => $document['id'],
        'bab_id' => $bab['id'],
        'nomor_label' => 'Pasal ' . $order,
        'judul_pasal' => 'Materi ' . $order,
        'urutan' => $order,
    ]);
}
$versionsA = [];
foreach ([1, 2, 3] as $order) {
    $versionsA[$order] = hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasals[$order]['id'],
        'workspace_id' => $workspaceA['id'],
        'isi' => ['teks' => 'A' . $order],
    ]);
}
$stagingA = hukum_submit_staging($pdo, $workspaceA['id'], array_column($versionsA, 'id'), $fixture['actors']['komisi_i']->id);
$commitA = hukum_11q_approve_and_commit($pdo, $fixture, $stagingA['id'], '11q-a-' . $tag);
$graphA = $pdo->prepare('SELECT pasal_id, pasal_version_id FROM hukum_graph_snapshot WHERE commit_id = ? ORDER BY pasal_id');
$graphA->execute([$commitA['id']]);
$graphARows = $graphA->fetchAll(PDO::FETCH_ASSOC);
hukum_11q_assert(count($graphARows) === 3, 'First graph snapshot does not contain three unique nodes.');
$firstGraph = $graphARows;

$workspaceB = hukum_create_workspace($pdo, $document['id'], 'Graph revision', 'Session 11Q', $fixture['actors']['komisi_i']->id);
$versionsB = [];
foreach ([1, 3] as $order) {
    $versionsB[$order] = hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasals[$order]['id'],
        'workspace_id' => $workspaceB['id'],
        'isi' => ['teks' => 'B' . $order],
    ]);
}
$uncommittedP2 = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasals[2]['id'],
    'workspace_id' => $workspaceB['id'],
    'isi' => ['teks' => 'UNCOMMITTED-DRAFT'],
]);
$stagingB = hukum_submit_staging($pdo, $workspaceB['id'], [$versionsB[1]['id'], $versionsB[3]['id']], $fixture['actors']['komisi_i']->id);
$commitB = hukum_11q_approve_and_commit($pdo, $fixture, $stagingB['id'], '11q-b-' . $tag);
$graphB = $pdo->prepare('SELECT pasal_id, pasal_version_id FROM hukum_graph_snapshot WHERE commit_id = ? ORDER BY pasal_id');
$graphB->execute([$commitB['id']]);
$graphBRows = $graphB->fetchAll(PDO::FETCH_ASSOC);
hukum_11q_assert(count($graphBRows) === 3, 'Revision graph snapshot does not contain three unique nodes.');
$expected = [
    (int) $pasals[1]['id'] => (int) $versionsB[1]['id'],
    (int) $pasals[2]['id'] => (int) $versionsA[2]['id'],
    (int) $pasals[3]['id'] => (int) $versionsB[3]['id'],
];
foreach ($graphBRows as $row) {
    hukum_11q_assert((int) $row['pasal_version_id'] === $expected[(int) $row['pasal_id']], 'Revision graph selected the wrong canonical version.');
    hukum_11q_assert((int) $row['pasal_version_id'] !== (int) $uncommittedP2['id'], 'Revision graph exposed an uncommitted draft.');
}
$graphA->execute([$commitA['id']]);
hukum_11q_assert($graphA->fetchAll(PDO::FETCH_ASSOC) === $firstGraph, 'Historical graph snapshot changed after revision.');
$unique = (int) $pdo->query("SELECT COUNT(*) FROM hukum_graph_snapshot WHERE commit_id = {$commitB['id']}") ->fetchColumn();
$distinct = (int) $pdo->query("SELECT COUNT(DISTINCT pasal_id) FROM hukum_graph_snapshot WHERE commit_id = {$commitB['id']}") ->fetchColumn();
hukum_11q_assert($unique === $distinct, 'Graph snapshot contains duplicate nodes.');
$constraint = $pdo->query("SHOW CREATE TABLE hukum_graph_snapshot")->fetch(PDO::FETCH_ASSOC);
hukum_11q_assert(str_contains((string) ($constraint['Create Table'] ?? ''), 'uq_hukum_graph_snapshot_commit_node'), 'Graph uniqueness constraint is missing.');
hukum_11q_assert(hukum_commit_verify_snapshot($pdo, $commitA['id']) && hukum_commit_verify_snapshot($pdo, $commitB['id']), 'Commit snapshot verification failed.');
echo "PASS Session 11Q canonical graph first/revision/multi-Pasal smoke\n";

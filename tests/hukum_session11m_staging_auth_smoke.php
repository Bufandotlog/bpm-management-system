<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
require_once __DIR__ . '/support/hukum_test_fixture.php';
$fixture = hukum_create_test_fixture();
$pdo = getConnection();
require_once __DIR__ . '/../api/hukum/_bootstrap.php';
require_once __DIR__ . '/../api/hukum/document_service.php';
require_once __DIR__ . '/../api/hukum/workspace_service.php';
require_once __DIR__ . '/../api/hukum/bab_service.php';
require_once __DIR__ . '/../api/hukum/pasal_service.php';
require_once __DIR__ . '/../api/hukum/staging_service.php';
require_once __DIR__ . '/../api/hukum/review_service.php';
require_once __DIR__ . '/../api/hukum/commit_service.php';

$provider = $fixture['provider'];
hukum_set_actor_context_provider($provider);
$suffix = bin2hex(random_bytes(6));
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Session 11M ' . $suffix,
    'slug' => 'session-11m-' . $suffix,
    'periode_id' => $fixture['period_id'],
]);
$workspace = hukum_create_workspace($pdo, $document['id'], 'Staging test', 'Session 11M', $fixture['actors']['komisi_i']->id);
$bab = hukum_create_bab($pdo, [
    'dokumen_id' => $document['id'],
    'nomor_label' => 'BAB I',
    'judul_bab' => 'Umum',
    'urutan' => 1,
]);
$pasal = hukum_create_pasal($pdo, [
    'dokumen_id' => $document['id'],
    'bab_id' => $bab['id'],
    'nomor_label' => 'Pasal 1',
    'judul_pasal' => 'Materi',
    'urutan' => 1,
]);
$version = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasal['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['teks' => 'Draft valid'],
]);
$staging = hukum_submit_staging($pdo, $workspace['id'], [$version['id']], $fixture['actors']['komisi_i']->id);

$row = $pdo->prepare('SELECT status, diajukan_oleh FROM hukum_staging WHERE id = ?');
$row->execute([$staging['id']]);
$stored = $row->fetch(PDO::FETCH_ASSOC);
if (($stored['status'] ?? '') !== 'menunggu_review' || (int) $stored['diajukan_oleh'] !== $fixture['actors']['komisi_i']->id) {
    throw new RuntimeException('Staging record invalid.');
}

$first = hukum_review_decide($pdo, $staging['id'], 'approve', null, $fixture['actors']['komisi_i']->id);
if (($first['role'] ?? '') !== 'komisi_i') {
    throw new RuntimeException('Komisi I approval failed.');
}
try {
    hukum_review_decide($pdo, $staging['id'], 'approve', null, $fixture['actors']['komisi_i']->id);
    throw new RuntimeException('Same actor approval was accepted twice.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 403 && $error->getCode() !== 409) {
        throw $error;
    }
}
hukum_set_actor_context_provider($provider->as('ketua_umum'));
$second = hukum_review_decide($pdo, $staging['id'], 'approve', null, $fixture['actors']['ketua_umum']->id);
if (($second['status'] ?? '') !== 'disetujui') {
    throw new RuntimeException('Second approval did not complete staging.');
}

$a = hukum_commit_create_window($pdo, $fixture['actors']['komisi_i']->id, 'komisi_i', $fixture['credentials']['komisi_i'], '11m-a');
$b = hukum_commit_create_window($pdo, $fixture['actors']['ketua_umum']->id, 'ketua_umum', $fixture['credentials']['ketua_umum'], '11m-b');
if ($a['status'] !== 'approved' || $b['status'] !== 'approved') {
    throw new RuntimeException('Commit authorization did not verify.');
}
try {
    hukum_commit_create_window($pdo, $fixture['actors']['komisi_i']->id, 'ketua_umum', $fixture['credentials']['komisi_i'], '11m-same');
    throw new RuntimeException('Same actor received two commit roles.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 403) {
        throw $error;
    }
}
try {
    hukum_commit_create_window($pdo, $fixture['actors']['komisi_i']->id, 'komisi_i', 'wrong-password', '11m-wrong');
    throw new RuntimeException('Wrong password accepted.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 403) {
        throw $error;
    }
}

$secretSearch = json_encode($fixture['credentials'], JSON_UNESCAPED_SLASHES);
$auditSecret = $pdo->prepare('SELECT COUNT(*) FROM hukum_audit_log WHERE context_json LIKE ? OR sebelum_json LIKE ? OR sesudah_json LIKE ?');
$auditSecret->execute(['%' . $secretSearch . '%', '%' . $secretSearch . '%', '%' . $secretSearch . '%']);
if ((int) $auditSecret->fetchColumn() !== 0) {
    throw new RuntimeException('TEST credential leaked into audit.');
}
echo "PASS Session 11M staging/review/auth smoke\n";

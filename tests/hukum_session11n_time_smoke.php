<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
require_once __DIR__ . '/support/hukum_test_fixture.php';
require_once __DIR__ . '/../admin/core/hukum-clock.php';

$fixture = hukum_create_test_fixture();
$pdo = getConnection();
$provider = $fixture['provider'];
hukum_set_actor_context_provider($provider);
require_once __DIR__ . '/../api/hukum/document_service.php';
require_once __DIR__ . '/../api/hukum/workspace_service.php';
require_once __DIR__ . '/../api/hukum/bab_service.php';
require_once __DIR__ . '/../api/hukum/pasal_service.php';
require_once __DIR__ . '/../api/hukum/staging_service.php';
require_once __DIR__ . '/../api/hukum/review_service.php';
require_once __DIR__ . '/../api/hukum/commit_service.php';
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Session 11N ' . bin2hex(random_bytes(4)),
    'slug' => 'session-11n-' . bin2hex(random_bytes(4)),
    'periode_id' => $fixture['period_id'],
]);
$workspace = hukum_create_workspace($pdo, (int) $document['id'], 'Commit verification', 'Session 11N', $fixture['actors']['komisi_i']->id);
$bab = hukum_create_bab($pdo, ['dokumen_id' => $document['id'], 'nomor_label' => 'BAB I', 'judul_bab' => 'Umum', 'urutan' => 1]);
$pasal = hukum_create_pasal($pdo, ['dokumen_id' => $document['id'], 'bab_id' => $bab['id'], 'nomor_label' => 'Pasal 1', 'judul_pasal' => 'Materi', 'urutan' => 1]);
$version = hukum_create_pasal_draft($pdo, ['pasal_id' => $pasal['id'], 'workspace_id' => $workspace['id'], 'isi' => ['teks' => 'Window expiry']]);
$staging = hukum_submit_staging($pdo, $workspace['id'], [$version['id']], $fixture['actors']['komisi_i']->id);
$stagingId = (int) $staging['id'];
hukum_set_actor_context_provider($provider->as('komisi_i'));
hukum_review_decide($pdo, $stagingId, 'approve', null, $fixture['actors']['komisi_i']->id);
hukum_set_actor_context_provider($provider->as('ketua_umum'));
hukum_review_decide($pdo, $stagingId, 'approve', null, $fixture['actors']['ketua_umum']->id);
hukum_set_actor_context_provider($provider->as('komisi_i'));
$actor = $fixture['actors']['komisi_i'];
$password = $fixture['credentials']['komisi_i'];
$base = new DateTimeImmutable('2030-01-01 00:00:00');
hukum_test_clock_set($base);
$session = '11n-' . bin2hex(random_bytes(4));
$window = hukum_commit_create_window($pdo, $actor->id, 'komisi_i', $password, $session, $stagingId);
if ($window['status'] !== 'approved') {
    throw new RuntimeException('Initial authorization was not approved.');
}
hukum_test_clock_advance(300);
if (!hukum_commit_is_window_approved($pdo, $actor->id, 'komisi_i', $stagingId)) {
    throw new RuntimeException('Authorization must remain valid at the exact expiry boundary.');
}
hukum_test_clock_advance(1);
if (hukum_commit_is_window_approved($pdo, $actor->id, 'komisi_i', $stagingId)) {
    throw new RuntimeException('Authorization remained valid after expiry.');
}

$wrongSession = '11n-lock-' . bin2hex(random_bytes(4));
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        hukum_commit_create_window($pdo, $actor->id, 'komisi_i', 'wrong-password', $wrongSession, $stagingId);
    } catch (RuntimeException $error) {
        if ($error->getCode() !== 403) {
            throw $error;
        }
    }
}
try {
    hukum_commit_check_cooldown($pdo, $actor->id, $wrongSession);
    throw new RuntimeException('Lockout did not activate after threshold.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 423) {
        throw $error;
    }
}
hukum_test_clock_advance(300);
hukum_commit_check_cooldown($pdo, $actor->id, $wrongSession);
hukum_test_clock_reset();
echo "PASS Session 11N deterministic clock/lockout smoke\n";

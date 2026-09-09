<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
require_once __DIR__ . '/hukum_test_fixture.php';
$fixture = hukum_create_test_fixture(false);
$operation = (string) ($argv[1] ?? '');
$id = (int) ($argv[2] ?? 0);
$actorKey = (string) ($argv[3] ?? 'komisi_i');
$readyFile = (string) ($argv[4] ?? '');
$goFile = (string) ($argv[5] ?? '');
$provider = $fixture['provider']->as($actorKey);
hukum_set_actor_context_provider($provider);

require_once __DIR__ . '/../../api/hukum/workspace_service.php';
require_once __DIR__ . '/../../api/hukum/staging_service.php';
require_once __DIR__ . '/../../api/hukum/review_service.php';
require_once __DIR__ . '/../../api/hukum/commit_service.php';

if ($readyFile === '' || $goFile === '') {
    throw new InvalidArgumentException('Concurrency barrier files are required.');
}
touch($readyFile);
$deadline = microtime(true) + 15;
while (!is_file($goFile) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($goFile)) {
    throw new RuntimeException('Concurrency barrier timed out.');
}

$pdo = getConnection();
$actor = $fixture['actors'][$actorKey];
$credential = $fixture['credentials'][$actorKey];
if (in_array($operation, ['authorization', 'finalize'], true)) {
    $credentialFile = (string) ($argv[6] ?? '');
    $credential = is_file($credentialFile) ? trim((string) file_get_contents($credentialFile)) : null;
    if (!is_string($credential) || $credential === '') {
        throw new RuntimeException('Worker credential file is missing.');
    }
}
$result = ['success' => false, 'actor' => $actorKey];
try {
    if ($operation === 'workspace') {
        $pdo->beginTransaction();
        $result['value'] = hukum_create_workspace($pdo, $id, 'Concurrent workspace', 'Session 11O', $actor->id);
        $pdo->commit();
    } elseif ($operation === 'staging') {
        $result['value'] = hukum_submit_staging($pdo, $id, [(int) ($argv[6] ?? 0)], $actor->id);
    } elseif ($operation === 'approval') {
        $result['value'] = hukum_review_decide($pdo, $id, 'approve', null, $actor->id);
    } elseif ($operation === 'authorization') {
        $result['value'] = hukum_commit_create_window($pdo, $actor->id, 'komisi_i', $credential, '11o-race-' . $id . '-' . $actorKey);
    } elseif ($operation === 'finalize') {
        $result['value'] = hukum_commit_finalize($pdo, $id, $actor->id, $credential, '11o-finalize-' . $actorKey, '11o-finalize-' . $actorKey);
    } else {
        throw new InvalidArgumentException('Unknown concurrency operation.');
    }
    $result['success'] = true;
} catch (Throwable $error) {
    $result['error'] = ['class' => $error::class, 'code' => $error->getCode(), 'message' => $error->getMessage()];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

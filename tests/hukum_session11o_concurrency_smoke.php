<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
require_once __DIR__ . '/support/hukum_test_fixture.php';
require_once __DIR__ . '/support/hukum_concurrency_harness.php';
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

function hukum_11o_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixtureAdmin = $fixture['actors']['ketua_umum'];
$mismatchedActor = new HukumAuthenticatedActorContext(
    $fixture['actors']['komisi_i']->id,
    $fixture['actors']['komisi_i']->username,
    $fixture['actors']['komisi_i']->displayName,
    'sekretaris',
    $fixture['actors']['komisi_i']->periodId,
    false,
    'test_fixture',
    true
);
$fixtureNonAdmin = new HukumAuthenticatedActorContext(
    $fixtureAdmin->id,
    $fixtureAdmin->username,
    $fixtureAdmin->displayName,
    'sekretaris',
    $fixtureAdmin->periodId,
    false,
    'test_fixture',
    true
);
hukum_set_actor_context_provider($provider->as('ketua_umum'));
try {
    hukum_commit_finalize($pdo, 900000001, $mismatchedActor->id, 'unused', '11o-identity-a', '11o-identity-a');
    throw new RuntimeException('Finalization accepted an explicit non-admin actor under an admin context.');
} catch (RuntimeException $error) {
    hukum_11o_assert(str_contains($error->getMessage(), 'Identitas aktor'), 'Unexpected identity mismatch result: ' . $error->getMessage());
}
hukum_set_actor_context_provider(new HukumTestActorProvider(['nonadmin' => $fixtureNonAdmin], 'nonadmin'));
try {
    hukum_commit_finalize($pdo, 900000002, $fixtureAdmin->id, 'unused', '11o-identity-b', '11o-identity-b');
    throw new RuntimeException('Finalization accepted an admin actor under a non-admin context.');
} catch (RuntimeException $error) {
    hukum_11o_assert(str_contains($error->getMessage(), 'Aktor teknis'), 'Unexpected reverse technical-role result: ' . $error->getMessage());
}
hukum_set_actor_context_provider($provider->as('ketua_umum'));
try {
    hukum_commit_finalize($pdo, 900000003, $fixtureAdmin->id, 'unused', '11o-identity-c', '11o-identity-c');
    throw new RuntimeException('Finalization same-actor probe unexpectedly succeeded.');
} catch (RuntimeException $error) {
    hukum_11o_assert(!str_contains($error->getMessage(), 'Identitas aktor'), 'Matching admin identity was rejected: ' . $error->getMessage());
}
hukum_set_actor_context_provider($provider);

function hukum_11o_prepare(string $operation, array $workers, array $args): array
{
    $dir = sys_get_temp_dir() . '/hukum-11o-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException('Unable to create concurrency barrier directory.');
    }
    $go = $dir . '/go';
    $commands = [];
    foreach ($workers as $index => $worker) {
        $ready = $dir . '/ready-' . $index;
        $command = [PHP_BINARY, __DIR__ . '/support/hukum_concurrency_worker.php', $operation, (string) $args[0], $worker, $ready, $go];
        foreach (array_slice($args, 1) as $arg) {
            if (is_array($arg)) {
                $arg = $arg[$index] ?? null;
            }
            $command[] = (string) $arg;
        }
        $commands[] = implode(' ', array_map('escapeshellarg', $command));
    }
    $processes = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrency worker.');
        }
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $readyCount = count(glob($dir . '/ready-*') ?: []);
        if ($readyCount === count($workers)) {
            break;
        }
        usleep(10000);
    }
    if (count(glob($dir . '/ready-*') ?: []) !== count($workers)) {
        throw new RuntimeException('Concurrency workers did not reach barrier.');
    }
    touch($go);
    $results = [];
    foreach ($processes as $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exitCode = proc_close($process['process']);
        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid worker result: ' . trim($stderr . ' ' . $stdout));
        }
        $decoded['exit_code'] = $exitCode;
        $results[] = $decoded;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
    return $results;
}

$suffix = bin2hex(random_bytes(6));
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN', 'lingkup' => 'induk',
    'judul' => 'Session 11O ' . $suffix, 'slug' => 'session-11o-' . $suffix,
    'periode_id' => $fixture['period_id'],
]);
$workspaceRace = hukum_11o_prepare('workspace', ['komisi_i', 'komisi_i'], [$document['id']]);
$workspaceSuccesses = count(array_filter($workspaceRace, static fn (array $row): bool => $row['success'] === true));
hukum_11o_assert($workspaceSuccesses === 1, 'Workspace race did not produce exactly one success.');
$workspaceCount = (int) $pdo->query("SELECT COUNT(*) FROM hukum_workspace WHERE dokumen_id = {$document['id']} AND status IN ('aktif','diajukan','siap_commit')")->fetchColumn();
hukum_11o_assert($workspaceCount === 1, 'Workspace race left an invalid active count.');

$stagingDocument = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN', 'lingkup' => 'induk',
    'judul' => 'Session 11O staging ' . $suffix, 'slug' => 'session-11o-staging-' . $suffix,
    'periode_id' => $fixture['period_id'],
]);
$workspace = hukum_create_workspace($pdo, $stagingDocument['id'], 'Second workflow', 'Session 11O', $fixture['actors']['komisi_i']->id);
$bab = hukum_create_bab($pdo, ['dokumen_id' => $stagingDocument['id'], 'nomor_label' => 'BAB I', 'judul_bab' => 'Umum', 'urutan' => 1]);
$pasal = hukum_create_pasal($pdo, ['dokumen_id' => $stagingDocument['id'], 'bab_id' => $bab['id'], 'nomor_label' => 'Pasal 1', 'judul_pasal' => 'Materi', 'urutan' => 1]);
$version = hukum_create_pasal_draft($pdo, ['pasal_id' => $pasal['id'], 'workspace_id' => $workspace['id'], 'isi' => ['teks' => 'Concurrent draft']]);
$stagingRace = hukum_11o_prepare('staging', ['komisi_i', 'komisi_i'], [$workspace['id'], $version['id']]);
hukum_11o_assert(count(array_filter($stagingRace, static fn (array $row): bool => $row['success'] === true)) === 1, 'Staging race did not produce exactly one success.');
$stagingId = (int) $pdo->query("SELECT id FROM hukum_staging WHERE workspace_id = {$workspace['id']} ORDER BY id DESC LIMIT 1")->fetchColumn();
hukum_11o_assert((int) $pdo->query("SELECT COUNT(*) FROM hukum_staging WHERE workspace_id = {$workspace['id']}")->fetchColumn() === 1, 'Staging race created duplicates.');

$approvalRace = hukum_11o_prepare('approval', ['komisi_i', 'ketua_umum'], [$stagingId]);
hukum_11o_assert(count(array_filter($approvalRace, static fn (array $row): bool => $row['success'] === true)) === 2, 'Distinct approval race did not complete both approvals.');
$approvalCount = (int) $pdo->query("SELECT COUNT(*) FROM hukum_staging_approval WHERE staging_id = {$stagingId} AND status = 'disetujui'")->fetchColumn();
hukum_11o_assert($approvalCount === 2, 'Approval race did not leave exactly two approvals.');

$credentialDir = sys_get_temp_dir() . '/hukum-11o-credentials-' . bin2hex(random_bytes(6));
mkdir($credentialDir, 0700, true);
$cleanupCredentials = static function () use (&$credentialFiles, $credentialDir): void {
    foreach ($credentialFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($credentialDir)) {
        rmdir($credentialDir);
    }
};
register_shutdown_function($cleanupCredentials);
$credentialFiles = [];
foreach (['komisi_i', 'ketua_umum'] as $key) {
    $credentialFiles[$key] = $credentialDir . '/' . $key;
    file_put_contents($credentialFiles[$key], $fixture['credentials'][$key]);
    chmod($credentialFiles[$key], 0600);
}
$authWindowCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM hukum_commit_window WHERE user_id = {$fixture['actors']['komisi_i']->id} AND peran = 'komisi_i' AND commit_id IS NULL")->fetchColumn();
$authRace = hukum_11o_prepare('authorization', ['komisi_i', 'komisi_i'], [$stagingId, [$credentialFiles['komisi_i'], $credentialFiles['komisi_i']]]);
hukum_11o_assert(count(array_filter($authRace, static fn (array $row): bool => $row['success'] === true)) >= 1, 'Commit authorization race produced no valid authorization.');
$authWindowCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM hukum_commit_window WHERE user_id = {$fixture['actors']['komisi_i']->id} AND peran = 'komisi_i' AND commit_id IS NULL")->fetchColumn();
hukum_11o_assert(($authWindowCountAfter - $authWindowCountBefore) <= 1, 'Commit authorization race created duplicate active windows: ' . json_encode(['before' => $authWindowCountBefore, 'after' => $authWindowCountAfter, 'workers' => $authRace]));
$authB = hukum_commit_create_window($pdo, $fixture['actors']['ketua_umum']->id, 'ketua_umum', $fixture['credentials']['ketua_umum'], '11o-auth-b');
hukum_11o_assert($authB['status'] === 'approved', 'Second commit authorization setup failed.');
$komisiWindowId = (int) $pdo->query(
    "SELECT id FROM hukum_commit_window WHERE user_id = {$fixture['actors']['komisi_i']->id} AND peran = 'komisi_i' AND status = 'approved' AND commit_id IS NULL ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$ketuaWindowId = (int) $pdo->query(
    "SELECT id FROM hukum_commit_window WHERE user_id = {$fixture['actors']['ketua_umum']->id} AND peran = 'ketua_umum' AND status = 'approved' AND commit_id IS NULL ORDER BY id DESC LIMIT 1"
)->fetchColumn();
hukum_11o_assert($komisiWindowId > 0 && $ketuaWindowId > 0, 'Approved authorization windows were not prepared.');
$pdo->prepare('UPDATE hukum_staging SET status = \'menunggu_review\' WHERE id = ?')->execute([$stagingId]);
$pdo->prepare('UPDATE hukum_workspace SET status = \'diajukan\' WHERE id = ?')->execute([(int) $workspace['id']]);
try {
    hukum_commit_finalize($pdo, $stagingId, $fixture['actors']['komisi_i']->id, $fixture['credentials']['komisi_i'], '11o-invalid-state', '11o-invalid-state');
    throw new RuntimeException('Finalization accepted a non-ready state.');
} catch (RuntimeException $error) {
    hukum_11o_assert(str_contains($error->getMessage(), 'siap commit'), 'Unexpected invalid-state rejection: ' . $error->getMessage());
}
$pdo->prepare('UPDATE hukum_staging SET status = \'disetujui\' WHERE id = ?')->execute([$stagingId]);
$pdo->prepare('UPDATE hukum_workspace SET status = \'siap_commit\' WHERE id = ?')->execute([(int) $workspace['id']]);
$GLOBALS['hukum_commit_test_failure_point'] = 'after_commit_insert';
try {
    hukum_commit_finalize($pdo, $stagingId, $fixture['actors']['komisi_i']->id, $fixture['credentials']['komisi_i'], '11o-failure', '11o-failure');
    throw new RuntimeException('Commit failure injection did not abort finalization.');
} catch (RuntimeException $error) {
    hukum_11o_assert(str_contains($error->getMessage(), 'TEST commit failure injection'), 'Unexpected failure injection result: ' . $error->getCode() . ' ' . $error->getMessage());
}
hukum_commit_test_set_failure_point(null);
hukum_11o_assert((int) $pdo->query("SELECT COUNT(*) FROM hukum_commit WHERE staging_id = {$stagingId}")->fetchColumn() === 0, 'Failed finalization left a commit row.');
hukum_11o_assert((string) $pdo->query("SELECT status FROM hukum_workspace WHERE id = {$workspace['id']}")->fetchColumn() === 'siap_commit', 'Failed finalization changed workspace state.');
$finalizeRace = hukum_11o_prepare('finalize', ['komisi_i', 'ketua_umum'], [$stagingId, [$credentialFiles['komisi_i'], $credentialFiles['ketua_umum']]]);
$finalizeSuccesses = count(array_filter($finalizeRace, static fn (array $row): bool => $row['success'] === true));
if ($finalizeSuccesses !== 1) {
    $cleanupCredentials();
    throw new RuntimeException('Finalization race did not produce exactly one success: ' . json_encode($finalizeRace));
}
$finalCommitId = (int) $pdo->query("SELECT id FROM hukum_commit WHERE staging_id = {$stagingId} LIMIT 1")->fetchColumn();
$consumedWindowIds = array_map(
    'intval',
    $pdo->query("SELECT id FROM hukum_commit_window WHERE commit_id = {$finalCommitId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
);
hukum_11o_assert($consumedWindowIds === [$komisiWindowId, $ketuaWindowId], 'Finalization consumed windows other than the validated IDs.');
$commitCount = (int) $pdo->query("SELECT COUNT(*) FROM hukum_commit WHERE staging_id = {$stagingId}")->fetchColumn();
hukum_11o_assert($commitCount === 1, 'Finalization race created duplicate commits.');
$commitId = (int) $pdo->query("SELECT id FROM hukum_commit WHERE staging_id = {$stagingId} LIMIT 1")->fetchColumn();
$commitHash = (string) $pdo->query("SELECT hash_commit FROM hukum_commit WHERE id = {$commitId}")->fetchColumn();
hukum_11o_assert(hukum_commit_verify_snapshot($pdo, $commitId), 'Immutable commit snapshot verification failed.');
hukum_11o_assert((int) $pdo->query("SELECT COUNT(*) FROM hukum_graph_snapshot WHERE commit_id = {$commitId}")->fetchColumn() > 0, 'Graph snapshot was not created.');
hukum_11o_assert((string) $pdo->query("SELECT status FROM hukum_workspace WHERE id = {$workspace['id']}")->fetchColumn() === 'ditutup', 'Workspace was not finalized.');
hukum_11o_assert((string) $pdo->query("SELECT status FROM hukum_staging WHERE id = {$stagingId}")->fetchColumn() === 'disetujui', 'Staging was not finalized.');
hukum_11o_assert((int) $pdo->query("SELECT COUNT(*) FROM hukum_audit_log WHERE entitas = 'hukum_commit' AND entitas_id = {$commitId} AND aksi = 'finalize' AND result = 'success'")->fetchColumn() === 1, 'Commit audit sequence is inconsistent.');
try {
    hukum_set_actor_context_provider($provider->as('komisi_i'));
    hukum_commit_finalize($pdo, $stagingId, $fixture['actors']['komisi_i']->id, $fixture['credentials']['komisi_i'], '11o-replay-a', '11o-replay-a');
    throw new RuntimeException('Replay with first credential was accepted.');
} catch (RuntimeException $error) {
    hukum_11o_assert($error->getCode() === 409, 'Replay rejection did not use a conflict response: ' . $error->getCode() . ' ' . $error->getMessage());
}
try {
    hukum_set_actor_context_provider($provider->as('ketua_umum'));
    hukum_commit_finalize($pdo, $stagingId, $fixture['actors']['ketua_umum']->id, $fixture['credentials']['ketua_umum'], '11o-replay-b', '11o-replay-b');
    throw new RuntimeException('Replay with second credential was accepted.');
} catch (RuntimeException $error) {
    hukum_11o_assert($error->getCode() === 409, 'Second replay rejection did not use a conflict response: ' . $error->getCode() . ' ' . $error->getMessage());
}
hukum_11o_assert((string) $pdo->query("SELECT hash_commit FROM hukum_commit WHERE id = {$commitId}")->fetchColumn() === $commitHash, 'Replay mutated immutable commit hash.');
$cleanupCredentials();
echo "PASS Session 11O workspace/staging/approval/finalization/replay concurrency smoke\n";

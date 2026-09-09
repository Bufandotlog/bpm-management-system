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

function hukum_11p_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hukum_11p_prepare_approval(array $fixture, PDO $pdo, string $suffix): int
{
    $document = hukum_create_document($pdo, [
        'jenis' => 'PERATURAN',
        'lingkup' => 'induk',
        'judul' => 'Session 11P ' . $suffix,
        'slug' => 'session-11p-' . strtolower($suffix) . '-' . bin2hex(random_bytes(4)),
        'periode_id' => $fixture['period_id'],
    ]);
    $workspace = hukum_create_workspace($pdo, $document['id'], 'Concurrent approval', 'Session 11P', $fixture['actors']['komisi_i']->id);
    $bab = hukum_create_bab($pdo, ['dokumen_id' => $document['id'], 'nomor_label' => 'BAB I', 'judul_bab' => 'Umum', 'urutan' => 1]);
    $pasal = hukum_create_pasal($pdo, ['dokumen_id' => $document['id'], 'bab_id' => $bab['id'], 'nomor_label' => 'Pasal 1', 'judul_pasal' => 'Materi', 'urutan' => 1]);
    $version = hukum_create_pasal_draft($pdo, ['pasal_id' => $pasal['id'], 'workspace_id' => $workspace['id'], 'isi' => ['teks' => 'Session 11P']]);
    $staging = hukum_submit_staging($pdo, $workspace['id'], [$version['id']], $fixture['actors']['komisi_i']->id);
    return (int) $staging['id'];
}

function hukum_11p_workers(string $operation, int $id, array $actors): array
{
    $dir = sys_get_temp_dir() . '/hukum-11p-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $go = $dir . '/go';
    $processes = [];
    foreach ($actors as $index => $actor) {
        $ready = $dir . '/ready-' . $index;
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            __DIR__ . '/support/hukum_concurrency_worker.php',
            $operation,
            (string) $id,
            $actor,
            $ready,
            $go,
        ]));
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Session 11P worker.');
        }
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && count(glob($dir . '/ready-*') ?: []) < count($actors)) {
        usleep(10000);
    }
    hukum_11p_assert(count(glob($dir . '/ready-*') ?: []) === count($actors), 'Session 11P workers did not reach barrier.');
    touch($go);
    $results = [];
    foreach ($processes as $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exitCode = proc_close($process['process']);
        $decoded = json_decode(trim($stdout), true);
        hukum_11p_assert(is_array($decoded), 'Invalid Session 11P worker result: ' . trim($stderr . ' ' . $stdout));
        $decoded['exit_code'] = $exitCode;
        $results[] = $decoded;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
    return $results;
}

$dualStagingId = hukum_11p_prepare_approval($fixture, $pdo, 'dual');
$dualResults = hukum_11p_workers('approval', $dualStagingId, ['komisi_i', 'ketua_umum']);
hukum_11p_assert(count(array_filter($dualResults, static fn (array $row): bool => $row['success'] === true)) === 2, 'Concurrent dual approval did not produce two successes.');
$dualRows = $pdo->prepare("SELECT peran, status, user_id FROM hukum_staging_approval WHERE staging_id = ?");
$dualRows->execute([$dualStagingId]);
$dualApprovals = $dualRows->fetchAll(PDO::FETCH_ASSOC);
hukum_11p_assert(count(array_filter($dualApprovals, static fn (array $row): bool => $row['status'] === 'disetujui')) === 2, 'Dual approval count is not exactly two.');
hukum_11p_assert(count(array_unique(array_column($dualApprovals, 'peran'))) === 2, 'Dual approval roles are not distinct.');
$dualWorkspace = $pdo->prepare('SELECT w.status FROM hukum_workspace w JOIN hukum_staging s ON s.workspace_id = w.id WHERE s.id = ?');
$dualWorkspace->execute([$dualStagingId]);
hukum_11p_assert($dualWorkspace->fetchColumn() === 'siap_commit', 'Workspace did not reach siap_commit after dual approval.');

$sameActorStagingId = hukum_11p_prepare_approval($fixture, $pdo, 'same-actor');
$sameActorResults = hukum_11p_workers('approval', $sameActorStagingId, ['komisi_i', 'komisi_i']);
hukum_11p_assert(count(array_filter($sameActorResults, static fn (array $row): bool => $row['success'] === true)) === 1, 'Same actor concurrent approval produced more than one success.');
$sameRows = $pdo->prepare("SELECT peran, status, user_id FROM hukum_staging_approval WHERE staging_id = ?");
$sameRows->execute([$sameActorStagingId]);
$sameApprovals = $sameRows->fetchAll(PDO::FETCH_ASSOC);
hukum_11p_assert(count(array_filter($sameApprovals, static fn (array $row): bool => $row['status'] === 'disetujui')) === 1, 'Same actor produced dual-party approval.');
$sameWorkspace = $pdo->prepare('SELECT w.status FROM hukum_workspace w JOIN hukum_staging s ON s.workspace_id = w.id WHERE s.id = ?');
$sameWorkspace->execute([$sameActorStagingId]);
hukum_11p_assert($sameWorkspace->fetchColumn() !== 'siap_commit', 'Same actor incorrectly advanced workspace to siap_commit.');

$lockSession = '11p-lock-' . bin2hex(random_bytes(6));
for ($attempt = 0; $attempt < 3; $attempt++) {
    try {
        hukum_commit_create_window($pdo, $fixture['actors']['komisi_i']->id, 'komisi_i', 'invalid-password', $lockSession);
    } catch (RuntimeException $error) {
        hukum_11p_assert($error->getCode() === 403, 'Unexpected failed-password response.');
    }
}
try {
    hukum_commit_check_cooldown($pdo, $fixture['actors']['komisi_i']->id, $lockSession);
    throw new RuntimeException('Actor A was not locked after threshold.');
} catch (RuntimeException $error) {
    hukum_11p_assert($error->getCode() === 423, 'Actor A lockout response was not 423.');
}
$actorBWindow = hukum_commit_create_window($pdo, $fixture['actors']['ketua_umum']->id, 'ketua_umum', $fixture['credentials']['ketua_umum'], '11p-unrelated-' . bin2hex(random_bytes(4)));
hukum_11p_assert($actorBWindow['status'] === 'approved', 'Unrelated Actor B was affected by Actor A lockout.');

$secretSearch = json_encode($fixture['credentials'], JSON_UNESCAPED_SLASHES);
$secretCheck = $pdo->prepare('SELECT COUNT(*) FROM hukum_audit_log WHERE context_json LIKE ? OR sebelum_json LIKE ? OR sesudah_json LIKE ?');
$secretCheck->execute(['%' . $secretSearch . '%', '%' . $secretSearch . '%', '%' . $secretSearch . '%']);
hukum_11p_assert((int) $secretCheck->fetchColumn() === 0, 'Session 11P credential leaked into audit.');

echo "PASS Session 11P approval subcases and unrelated lockout smoke\n";

<?php
declare(strict_types=1);

function hukum_run_concurrent_php(array $commands): array
{
    if (strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''))) !== 'test') {
        throw new RuntimeException('Concurrency harness hanya dapat berjalan pada APP_ENV=test.');
    }
    if ($commands === []) {
        throw new InvalidArgumentException('Concurrency command wajib diisi.');
    }
    $processes = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Proses concurrency tidak dapat dimulai.');
        }
        $processes[] = ['proc' => $process, 'pipes' => $pipes];
    }
    $results = [];
    foreach ($processes as $process) {
        $results[] = [
            'exit_code' => proc_close($process['proc']),
            'stdout' => stream_get_contents($process['pipes'][1]),
            'stderr' => stream_get_contents($process['pipes'][2]),
        ];
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
    }
    return $results;
}

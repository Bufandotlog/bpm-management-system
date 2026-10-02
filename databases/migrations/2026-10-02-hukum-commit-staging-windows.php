<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$pdo = getConnection();
$driver = strtolower((string) DB_CONNECTION);
if (!in_array($driver, ['mysql', 'pgsql'], true)) {
    throw new RuntimeException("Unsupported database driver: {$driver}");
}

$columnSql = $driver === 'pgsql'
    ? 'SELECT 1 FROM information_schema.columns
       WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
    : 'SELECT 1 FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
$column = $pdo->prepare($columnSql);
$column->execute(['hukum_commit_window', 'staging_id']);
if ($column->fetchColumn() === false) {
    $pdo->exec(
        $driver === 'pgsql'
            ? 'ALTER TABLE hukum_commit_window ADD COLUMN staging_id INTEGER NULL'
            : 'ALTER TABLE hukum_commit_window ADD COLUMN staging_id INT NULL AFTER commit_id'
    );
}

if ($driver === 'pgsql') {
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_hukum_commit_window_staging_role_status
         ON hukum_commit_window (staging_id, peran, status)'
    );
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_hukum_commit_window_staging_user_role
         ON hukum_commit_window (staging_id, user_id, peran)'
    );
} else {
    $indexes = $pdo->query(
        "SELECT index_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'hukum_commit_window'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('idx_hukum_commit_window_staging_role_status', $indexes, true)) {
        $pdo->exec(
            'CREATE INDEX idx_hukum_commit_window_staging_role_status
             ON hukum_commit_window (staging_id, peran, status)'
        );
    }
    if (!in_array('uq_hukum_commit_window_staging_user_role', $indexes, true)) {
        $pdo->exec(
            'CREATE UNIQUE INDEX uq_hukum_commit_window_staging_user_role
             ON hukum_commit_window (staging_id, user_id, peran)'
        );
    }
}

echo "Hukum commit verification windows are now scoped to staging.\n";

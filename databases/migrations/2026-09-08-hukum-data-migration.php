<?php
/**
 * Safe Phase 7 migration assistant.
 *
 * Default mode is read-only. Use --apply to create backups and migrate only
 * legacy-only tables. Use --retire only after reviewing the printed inventory.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$apply = in_array('--apply', $argv, true);
$retire = in_array('--retire', $argv, true);
try {
    $pdo = getConnection();
} catch (RuntimeException $error) {
    fwrite(STDERR, "Database connection unavailable: {$error->getMessage()}\n");
    exit(4);
}
$legacyOnlyTables = [
    'hukum_meja_kerja',
    'hukum_commit_window',
    'hukum_commit_otorisasi',
    'hukum_notifikasi_peninjauan',
    'hukum_notifikasi_audit',
];
$sharedTables = ['hukum_dokumen', 'hukum_pasal', 'hukum_pasal_versi', 'hukum_staging', 'hukum_commit'];

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
    );
    $stmt->execute([$table]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

echo "Hukum Phase 7 migration inventory\n";
echo $apply ? "Mode: APPLY\n" : "Mode: DRY-RUN (no writes)\n";
echo "Retire legacy-only tables: " . ($retire ? "YES\n" : "NO\n");

$blocked = [];
foreach ($sharedTables as $table) {
    if (!table_exists($pdo, $table)) {
        $blocked[] = "Missing canonical table: {$table}";
        continue;
    }
    $columns = table_columns($pdo, $table);
    echo sprintf("canonical %-24s rows=%d columns=%s\n", $table, table_count($pdo, $table), implode(',', $columns));

    $legacyColumns = array_intersect($columns, ['meja_kerja_id', 'mukadimah', 'gap_pasal', 'diajukan_pada', 'daftar_pasal_versi_id']);
    if ($legacyColumns !== []) {
        $blocked[] = "Legacy columns remain in {$table}: " . implode(', ', $legacyColumns);
    }
}

$legacyFound = [];
foreach ($legacyOnlyTables as $table) {
    if (!table_exists($pdo, $table)) {
        continue;
    }
    $legacyFound[] = $table;
    echo sprintf("legacy    %-24s rows=%d columns=%s\n", $table, table_count($pdo, $table), implode(',', table_columns($pdo, $table)));
}

if ($blocked !== []) {
    echo "\nBLOCKED: schema conflicts require an explicit column-level migration before retirement.\n";
    foreach ($blocked as $message) {
        echo " - {$message}\n";
    }
    exit(2);
}

if (!$apply || $legacyFound === []) {
    echo $legacyFound === [] ? "\nNo legacy-only tables detected.\n" : "\nDry-run complete. Re-run with --apply --retire after review.\n";
    exit(0);
}

if (!$retire) {
    echo "\nNo changes made. --retire is required to remove legacy-only tables.\n";
    exit(0);
}

$backupSuffix = date('Ymd_His');
$pdo->beginTransaction();
try {
    foreach ($legacyFound as $table) {
        $backup = "{$table}_backup_{$backupSuffix}";
        $pdo->exec("CREATE TABLE `{$backup}` LIKE `{$table}`");
        $pdo->exec("INSERT INTO `{$backup}` SELECT * FROM `{$table}`");
        $pdo->exec("DROP TABLE `{$table}`");
        echo "Backed up and retired {$table} -> {$backup}\n";
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration failed; transaction rolled back: {$error->getMessage()}\n");
    exit(3);
}

echo "Migration complete. Backups are retained; delete them only after verification.\n";

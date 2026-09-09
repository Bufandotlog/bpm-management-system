<?php
/**
 * Align the MariaDB workspace status enum with the Hukum state machine.
 *
 * This migration only widens the enum and preserves every existing row.
 * MariaDB ALTER TABLE has implicit commit semantics, so the operation is
 * intentionally isolated and must be rerun only after an explicit preflight.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$pdo = getConnection();
$driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

if ($driver !== 'mysql') {
    throw new RuntimeException('Workspace state alignment requires the MariaDB/MySQL driver.');
}

$tableExists = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'hukum_workspace'"
)->fetchColumn();

if ((int) $tableExists !== 1) {
    throw new RuntimeException('hukum_workspace does not exist; apply the base Hukum schema first.');
}

$column = $pdo->query(
    "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'hukum_workspace'
       AND column_name = 'status'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$column) {
    throw new RuntimeException('hukum_workspace.status does not exist.');
}

$requiredStates = ['aktif', 'diajukan', 'siap_commit', 'committed', 'ditutup', 'dibatalkan'];
$columnType = strtolower((string) ($column['COLUMN_TYPE'] ?? ''));
$missingStates = array_values(array_filter(
    $requiredStates,
    static fn (string $state): bool => !str_contains($columnType, "'" . $state . "'")
));

if ($missingStates === []) {
    echo "Workspace status enum already aligned; no changes required.\n";
    exit(0);
}

if (!str_starts_with($columnType, 'enum(')) {
    throw new RuntimeException('hukum_workspace.status is not an ENUM; refusing implicit schema conversion.');
}

$enumSql = implode(',', array_map(
    static fn (string $state): string => "'" . $state . "'",
    $requiredStates
));

$pdo->exec(
    "ALTER TABLE hukum_workspace
     MODIFY COLUMN status ENUM({$enumSql}) NOT NULL DEFAULT 'aktif'"
);

$verifiedType = strtolower((string) $pdo->query(
    "SELECT COLUMN_TYPE
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'hukum_workspace'
       AND column_name = 'status'
     LIMIT 1"
)->fetchColumn());

$remainingStates = array_values(array_filter(
    $requiredStates,
    static fn (string $state): bool => !str_contains($verifiedType, "'" . $state . "'")
));

if ($remainingStates !== []) {
    throw new RuntimeException(
        'Workspace status enum verification failed; missing: ' . implode(', ', $remainingStates)
    );
}

echo "Workspace status enum aligned successfully.\n";

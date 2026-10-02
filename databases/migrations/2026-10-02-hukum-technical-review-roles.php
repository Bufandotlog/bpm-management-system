<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$pdo = getConnection();
$driver = strtolower((string) DB_CONNECTION);

function hukumRoleMigrationAssertNoCollision(PDO $pdo, string $table, string $groupBy, string $where = ''): void
{
    $sql = "SELECT COUNT(*) FROM (
        SELECT {$groupBy}
        FROM {$table}
        {$where}
        GROUP BY {$groupBy}
        HAVING SUM(CASE WHEN peran = 'admin' THEN 1 ELSE 0 END) > 0
           AND SUM(CASE WHEN peran = 'ketua_umum' THEN 1 ELSE 0 END) > 0
    ) role_collisions";
    if ((int) $pdo->query($sql)->fetchColumn() > 0) {
        throw new RuntimeException(
            "Cannot migrate {$table}: both admin and ketua_umum approval roles exist in the same slot."
        );
    }
}

function hukumRoleMigrationReplacePostgresCheck(PDO $pdo, string $table): void
{
    $stmt = $pdo->prepare(
        "SELECT c.conname, pg_get_constraintdef(c.oid) AS definition
         FROM pg_constraint c
         JOIN pg_class t ON t.oid = c.conrelid
         JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = current_schema() AND t.relname = ? AND c.contype = 'c'"
    );
    $stmt->execute([$table]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
        if (stripos((string) $constraint['definition'], 'peran') === false) {
            continue;
        }
        $name = str_replace('"', '""', (string) $constraint['conname']);
        $pdo->exec('ALTER TABLE "' . $table . '" DROP CONSTRAINT "' . $name . '"');
    }

    $pdo->exec(
        'ALTER TABLE "' . $table . '" ADD CONSTRAINT "' . $table .
        '_peran_canonical_check" CHECK (peran IN (\'komisi_i\', \'admin\'))'
    );
}

foreach (['hukum_staging_approval', 'hukum_commit_window'] as $table) {
    if ($driver === 'pgsql') {
        $column = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
        );
    } else {
        $column = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
    }
    $column->execute([$table, 'peran']);
    if ($column->fetchColumn() === false) {
        throw new RuntimeException("Required column {$table}.peran is missing.");
    }
}

hukumRoleMigrationAssertNoCollision($pdo, 'hukum_staging_approval', 'staging_id');
hukumRoleMigrationAssertNoCollision(
    $pdo,
    'hukum_commit_window',
    'user_id, commit_id',
    'WHERE commit_id IS NOT NULL'
);

if ($driver === 'pgsql') {
    $pdo->beginTransaction();
    try {
        foreach (['hukum_staging_approval', 'hukum_commit_window'] as $table) {
            hukumRoleMigrationReplacePostgresCheck($pdo, $table);
            $pdo->prepare("UPDATE {$table} SET peran = 'admin' WHERE peran = 'ketua_umum'")->execute();
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
} elseif ($driver === 'mysql') {
    $pdo->exec(
        "ALTER TABLE hukum_staging_approval
         MODIFY peran ENUM('komisi_i','admin','ketua_umum') NOT NULL"
    );
    $pdo->exec(
        "ALTER TABLE hukum_commit_window
         MODIFY peran ENUM('komisi_i','admin','ketua_umum') NOT NULL"
    );
    $pdo->exec("UPDATE hukum_staging_approval SET peran = 'admin' WHERE peran = 'ketua_umum'");
    $pdo->exec("UPDATE hukum_commit_window SET peran = 'admin' WHERE peran = 'ketua_umum'");
    $pdo->exec(
        "ALTER TABLE hukum_staging_approval
         MODIFY peran ENUM('komisi_i','admin') NOT NULL"
    );
    $pdo->exec(
        "ALTER TABLE hukum_commit_window
         MODIFY peran ENUM('komisi_i','admin') NOT NULL"
    );
} else {
    throw new RuntimeException("Unsupported database driver: {$driver}");
}

echo "Hukum review roles aligned to komisi_i/admin.\n";

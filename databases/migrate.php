<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Database migrations must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

if (DB_CONNECTION !== 'mysql') {
    fwrite(STDERR, "The Docker migration runner currently supports MySQL/MariaDB only.\n");
    exit(1);
}

try {
    $pdo = getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $lock = $pdo->query("SELECT GET_LOCK('bpm-schema-migrations', 30)")->fetchColumn();
    if ((string)$lock !== '1') {
        throw new RuntimeException('Could not acquire the database migration lock.');
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $migrationFiles = glob(__DIR__ . '/migrations/*.migration.php') ?: [];
        sort($migrationFiles, SORT_STRING);

        foreach ($migrationFiles as $migrationFile) {
            $filename = basename($migrationFile);
            if (!preg_match('/^(\d{4}-\d{2}-\d{2}-[a-z0-9-]+)\.migration\.php$/', $filename, $matches)) {
                throw new RuntimeException("Invalid migration filename: {$filename}");
            }

            $version = $matches[1];
            $contents = file_get_contents($migrationFile);
            if ($contents === false) {
                throw new RuntimeException("Unable to read migration: {$filename}");
            }
            $checksum = hash('sha256', $contents);

            $lookup = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
            $lookup->execute([$version]);
            $appliedChecksum = $lookup->fetchColumn();

            if ($appliedChecksum !== false) {
                if (!hash_equals((string)$appliedChecksum, $checksum)) {
                    throw new RuntimeException("Applied migration was modified: {$version}");
                }
                echo "Already applied: {$version}\n";
                continue;
            }

            $migration = require $migrationFile;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration must return a callable: {$filename}");
            }

            echo "Applying: {$version}\n";
            $migration($pdo);

            $record = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $record->execute([$version, $checksum]);
            echo "Applied: {$version}\n";
        }

        assertLogisticsSchema($pdo);
        echo "Database schema checks passed.\n";
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bpm-schema-migrations')");
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Database migration failed: {$error->getMessage()}\n");
    exit(1);
}

function assertLogisticsSchema(PDO $pdo): void
{
    $table = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $table->execute(['lampiran_pinjam']);
    if ((int)$table->fetchColumn() !== 1) {
        throw new RuntimeException('Required table lampiran_pinjam is missing.');
    }

    $column = $pdo->prepare(
        'SELECT data_type, is_nullable FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $column->execute(['lampiran_pinjam', 'kegiatan_id']);
    $columnDefinition = $column->fetch(PDO::FETCH_ASSOC);
    if (
        $columnDefinition === false
        || strtolower((string)$columnDefinition['data_type']) !== 'int'
        || strtoupper((string)$columnDefinition['is_nullable']) !== 'YES'
    ) {
        throw new RuntimeException(
            'Required column lampiran_pinjam.kegiatan_id is missing or has an unexpected definition.'
        );
    }

    $index = $pdo->prepare(
        'SELECT column_name, non_unique
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?
         ORDER BY seq_in_index'
    );
    $index->execute(['lampiran_pinjam', 'idx_lampiran_pinjam_kegiatan_periode']);
    $indexRows = $index->fetchAll(PDO::FETCH_ASSOC);
    $indexColumns = array_map(
        static fn(array $row): string => (string)$row['column_name'],
        $indexRows
    );
    $indexIsNonUnique = array_reduce(
        $indexRows,
        static fn(bool $isNonUnique, array $row): bool =>
            $isNonUnique && (int)$row['non_unique'] === 1,
        true
    );
    if ($indexColumns !== ['kegiatan_id', 'periode_id'] || !$indexIsNonUnique) {
        throw new RuntimeException(
            'Required index idx_lampiran_pinjam_kegiatan_periode is missing or has an unexpected definition.'
        );
    }
}

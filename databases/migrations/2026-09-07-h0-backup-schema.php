<?php
/**
 * Backup schema-only database ke file SQL.
 * Usage: php databases/migrations/2026-09-07-h0-backup-schema.php
 */
require __DIR__ . '/../../config/database.php';

$backupDir = __DIR__;
$filename = 'schema_pre_hukum_' . date('Ymd_His') . '.sql';
$filepath = $backupDir . '/' . $filename;

try {
    $pdo = getConnection();
    $driver = DB_CONNECTION;
    $dbName = DB_NAME;

    if ($driver !== 'mysql') {
        die("Backup ini hanya support MySQL/MariaDB\n");
    }

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);

    $sql = "-- ============================================\n";
    $sql .= "-- Schema backup sebelum migrasi hukum\n";
    $sql .= "-- Tanggal: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: {$dbName}\n";
    $sql .= "-- Tabel: " . count($tables) . "\n";
    $sql .= "-- ============================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['Create Table'] ?? $row['Create View'] ?? '';
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $createSql . ";\n\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    file_put_contents($filepath, $sql);

    echo "✓ Backup schema saved: {$filepath}\n";
    echo "  Tables: " . count($tables) . "\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

<?php
/**
 * Migration runner: apply SQL file to database.
 * Usage: php databases/migrations/2026-09-07-apply-migration.php
 *
 * Args: --file=<path>
 *
 * Strategy:
 *  - Strip block comments and line comments from SQL
 *  - Split by semicolon at statement boundary
 *  - Idempotent: skip "already exists" errors
 */
require __DIR__ . '/../../config/database.php';

$file = __DIR__ . '/2026-09-07-h0-h1-h2-hukum-tables.sql';
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $file = substr($arg, 7);
    }
}

if (!is_file($file)) {
    die("File not found: {$file}\n");
}

try {
    $pdo = getConnection();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $sql = file_get_contents($file);

    // Strip block comments /* ... */
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    // Strip line comments -- ...
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Split by ';' but respect string literals (very simple: split on ; at end of line)
    $statements = preg_split('/;\s*(?:\n|$)/', $sql);
    $statements = array_map('trim', $statements);
    $statements = array_filter($statements, fn($s) => $s !== '');

    $applied = 0;
    $skipped = 0;
    $errors = [];

    foreach ($statements as $idx => $stmt) {
        try {
            $pdo->exec($stmt);
            $applied++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $errno = $e->errorInfo[1] ?? 0;
            // Idempotent skip
            $idempotent = str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key')
                || in_array($errno, [1050, 1060, 1061, 1062, 121, 1826], true);
            if ($idempotent) {
                $skipped++;
                continue;
            }
            $errors[] = "Statement #{$idx}: " . $msg . "\n  -- SQL head: " . substr($stmt, 0, 200);
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "✓ Migration: {$applied} statements applied, {$skipped} skipped (idempotent)\n";
    if (!empty($errors)) {
        echo "✗ Errors:\n" . implode("\n", $errors) . "\n";
        exit(1);
    }
    echo "  File: {$file}\n";
} catch (Exception $e) {
    die("✗ Migration FAILED: " . $e->getMessage() . "\n");
}

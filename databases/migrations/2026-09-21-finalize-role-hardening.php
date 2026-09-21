<?php
/**
 * Final hardening for the canonical app-role model.
 *
 * Purpose:
 * - normalize remaining legacy values in users.role
 * - lock the DB to the final canonical role set
 * - preserve historical organization metadata in hukum_keanggotaan without letting it act as a technical role source
 *
 * Usage:
 *   php databases/migrations/2026-09-21-finalize-role-hardening.php
 *   php databases/migrations/2026-09-21-finalize-role-hardening.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo = getConnection();

$canonicalRoles = ['superadmin', 'admin', 'sekretaris', 'kominfo', 'komisi_i', 'anggota'];
$legacyAliases = [
    'superadmin' => 'superadmin',
    'super_admin' => 'superadmin',
    'super-admin' => 'superadmin',
    'admin' => 'admin',
    'ketua_umum_bpm' => 'admin',
    'ketua-umum-bpm' => 'admin',
    'ketua_umum' => 'admin',
    'ketua umum' => 'admin',
    'ketua umum bpm' => 'admin',
    'sekretaris' => 'sekretaris',
    'sekertaris' => 'sekretaris',
    'sekertaris_bpm' => 'sekretaris',
    'kominfo' => 'kominfo',
    'kom-info' => 'kominfo',
    'kom info' => 'kominfo',
    'komisi_i' => 'komisi_i',
    'komisi-i' => 'komisi_i',
    'komisi_i_hukum' => 'komisi_i',
    'komisi 1' => 'komisi_i',
    'komisi1' => 'komisi_i',
    'komisi-1' => 'komisi_i',
    'komisi_1' => 'komisi_i',
    'anggota' => 'anggota',
    'member' => 'anggota',
    'user' => 'anggota',
    'default' => 'anggota',
];

function normalizeRoleHardeningValue(string $raw): string
{
    $value = strtolower(trim($raw));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[\s\-_]+/', '_', $value);
    $value = trim((string) $value, '_');

    global $legacyAliases;
    if (isset($legacyAliases[$value])) {
        return $legacyAliases[$value];
    }

    if (preg_match('/^(super|superadmin|super_admin|super-admin)$/', $value) || str_contains($value, 'superadmin')) {
        return 'superadmin';
    }

    if (preg_match('/^(ketua|ketua_umum|ketua_umum_bpm)$/', $value) || str_contains($value, 'ketua_umum')) {
        return 'admin';
    }

    if (preg_match('/^(komisi|komisi_i|komisi_1|komisi1|komisi-1)$/', $value) || str_contains($value, 'komisi')) {
        if (str_contains($value, '1') || $value === 'komisi_i' || $value === 'komisi' || $value === 'komisi_1' || $value === 'komisi-1') {
            return 'komisi_i';
        }
    }

    if (in_array($value, ['sekretaris', 'sekertaris', 'sekertaris_bpm'], true)) {
        return 'sekretaris';
    }

    if (in_array($value, ['kominfo', 'kom_info', 'kom-info'], true)) {
        return 'kominfo';
    }

    if (in_array($value, ['anggota', 'member', 'user', 'default'], true)) {
        return 'anggota';
    }

    return $value;
}

function driverName(PDO $pdo): string
{
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    return $driver === 'pgsql' ? 'pgsql' : 'mysql';
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "users.role is not available: {$e->getMessage()}\n");
    exit(3);
}

if ($columns === false) {
    fwrite(STDERR, "The users.role column is missing. Nothing to harden.\n");
    exit(0);
}

$currentRoleRows = $pdo->query("SELECT id, role FROM users WHERE role IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$changes = [];
foreach ($currentRoleRows as $row) {
    $before = (string) $row['role'];
    $after = normalizeRoleHardeningValue($before);
    if ($after === '' || !in_array($after, $canonicalRoles, true)) {
        continue;
    }
    if (strtolower(trim($before)) !== $after) {
        $changes[] = ['id' => (int) $row['id'], 'before' => $before, 'after' => $after];
    }
}

if ($changes === []) {
    echo "No legacy users.role values detected.\n";
} else {
    echo "Legacy role values to normalize:\n";
    foreach ($changes as $change) {
        echo sprintf(" - user #%d: %s => %s\n", $change['id'], $change['before'], $change['after']);
    }
}

if (!$apply) {
    echo "Dry-run complete. Re-run with --apply to update rows and enforce canonical constraints.\n";
    exit(0);
}

$backupName = 'users_role_backup_' . date('Ymd_His');
try {
    if ($changes !== []) {
        $ids = implode(',', array_map('intval', array_column($changes, 'id')));
        $pdo->exec("CREATE TABLE `{$backupName}` LIKE `users`");
        $pdo->exec("INSERT INTO `{$backupName}` SELECT * FROM `users` WHERE id IN ({$ids})");

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        foreach ($changes as $change) {
            $stmt->execute([':role' => $change['after'], ':id' => $change['id']]);
        }
        $pdo->commit();
    }

    $driver = driverName($pdo);
    if ($driver === 'mysql') {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','sekretaris','kominfo','komisi_i','anggota') NOT NULL DEFAULT 'anggota'");
    } else {
        $pdo->exec("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('superadmin','admin','sekretaris','kominfo','komisi_i','anggota')) NOT VALID");
    }

    echo "Final role hardening applied successfully. Backup retained in {$backupName}.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Hardening failed; transaction rolled back: {$error->getMessage()}\n");
    exit(4);
}

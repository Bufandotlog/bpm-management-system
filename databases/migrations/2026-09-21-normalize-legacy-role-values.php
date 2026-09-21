<?php
/**
 * Normalize legacy technical user-role values without destroying the historical metadata.
 *
 * Scope:
 * - only updates the technical user access field `users.role`
 * - leaves historical labels such as `hukum_keanggotaan.jabatan` and `peran` intact
 * - creates a backup table before writing any value change
 *
 * Usage:
 *   php databases/migrations/2026-09-21-normalize-legacy-role-values.php
 *   php databases/migrations/2026-09-21-normalize-legacy-role-values.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo = getConnection();

$aliasMap = [
    'superadmin' => 'superadmin',
    'super_admin' => 'superadmin',
    'super-admin' => 'superadmin',
    'super admin' => 'superadmin',
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

function normalizeLegacyRoleValue(string $raw): string
{
    $value = strtolower(trim($raw));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[\s\-_]+/', '_', $value);
    $value = trim((string) $value, '_');

    global $aliasMap;
    if (isset($aliasMap[$value])) {
        return $aliasMap[$value];
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

    return $value;
}

function valueNeedsMigration(string $currentValue): bool
{
    $normalized = normalizeLegacyRoleValue($currentValue);
    return $normalized !== '' && $normalized !== strtolower(trim($currentValue));
}

try {
    $rows = $pdo->query("SELECT id, role FROM users WHERE role IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Table users.role is not available or database is not ready: {$e->getMessage()}\n");
    exit(3);
}

$changes = [];
foreach ($rows as $row) {
    $current = (string) $row['role'];
    $normalized = normalizeLegacyRoleValue($current);
    if ($normalized === '') {
        continue;
    }

    $canonical = in_array($normalized, ['superadmin', 'admin', 'sekretaris', 'kominfo', 'komisi_i', 'anggota'], true)
        ? $normalized
        : strtolower(trim($current));

    if ($canonical !== strtolower(trim($current))) {
        $changes[] = [
            'id' => (int) $row['id'],
            'before' => $current,
            'after' => $canonical,
        ];
    }
}

if ($changes === []) {
    echo "No legacy role values require migration in users.role.\n";
    exit(0);
}

$targetValues = array_values(array_unique(array_map('strtolower', array_column($changes, 'before'))));
$backupTable = 'users_role_legacy_backup_' . date('Ymd_His');

echo "Legacy role values detected in users.role:\n";
foreach ($changes as $change) {
    echo sprintf(" - user #%d: %s => %s\n", $change['id'], $change['before'], $change['after']);
}

if (!$apply) {
    echo "Dry-run complete. Re-run with --apply to migrate and create backup table {$backupTable}.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $pdo->exec("CREATE TABLE `{$backupTable}` LIKE `users`");
    $pdo->exec(
        "INSERT INTO `{$backupTable}` SELECT * FROM `users` WHERE id IN (" . implode(',', array_map('intval', array_column($changes, 'id'))) . ")"
    );

    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    foreach ($changes as $change) {
        $stmt->execute([
            ':role' => $change['after'],
            ':id' => $change['id'],
        ]);
    }

    $pdo->commit();
    echo "Migration applied successfully. Backup retained in {$backupTable}.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration failed and was rolled back: {$error->getMessage()}\n");
    exit(4);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../api/hukum/membership_service.php';

final class HukumTestActorProvider implements HukumActorContextProvider
{
    public function __construct(private readonly array $actors, private readonly string $activeActor) {}

    public function current(): ?HukumAuthenticatedActorContext
    {
        return $this->actors[$this->activeActor] ?? null;
    }

    public function as(string $actor): self
    {
        if (!isset($this->actors[$actor])) {
            throw new InvalidArgumentException('Test actor tidak terdaftar.');
        }
        return new self($this->actors, $actor);
    }
}

function hukum_test_require_environment(PDO $pdo): void
{
    if (strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''))) !== 'test') {
        throw new RuntimeException('Test fixture hanya dapat berjalan pada APP_ENV=test.');
    }
    if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
        throw new RuntimeException('Test fixture memerlukan MariaDB/MySQL.');
    }
    $identity = $pdo->query('SELECT DATABASE() db, CURRENT_USER() usr')->fetch(PDO::FETCH_ASSOC);
    if (($identity['db'] ?? '') !== 'bpm' || !str_contains((string) ($identity['usr'] ?? ''), '@localhost')) {
        throw new RuntimeException('Database identity bukan TEST yang diizinkan.');
    }
}

function hukum_create_test_fixture(bool $refreshCredentials = true): array
{
    $pdo = getConnection();
    hukum_test_require_environment($pdo);
    try {
        $period = $pdo->query("SELECT id FROM periode_kepengurusan WHERE nama = 'TEST HUKUM 11J' LIMIT 1")->fetchColumn();
        if (!$period) {
            $pdo->prepare(
                'INSERT INTO periode_kepengurusan (nama, tahun_mulai, tahun_selesai, deskripsi, is_active)
                 VALUES (?, ?, ?, ?, 0)'
            )->execute(['TEST HUKUM 11J', 2026, 2027, 'Isolated Hukum test fixture']);
            $period = (int) $pdo->lastInsertId();
        }

        $actors = [];
        $credentials = [];
        foreach ([
            'komisi_i' => ['username' => 'test_hukum_11j_komisi_i', 'name' => 'Test Hukum Komisi I', 'role' => 'admin'],
            'ketua_umum' => ['username' => 'test_hukum_11j_ketua_umum', 'name' => 'Test Hukum Ketua Umum', 'role' => 'admin'],
        ] as $key => $definition) {
            $stmt = $pdo->prepare('SELECT id, username, nama, role, periode_id, can_access_all FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$definition['username']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $password = bin2hex(random_bytes(24));
                $pdo->prepare(
                    'INSERT INTO users (username, password, nama, role, periode_id, can_access_all, is_active)
                     VALUES (?, ?, ?, ?, ?, 0, 1)'
                )->execute([
                    $definition['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $definition['name'],
                    $definition['role'],
                    $period,
                ]);
                $user = [
                    'id' => (int) $pdo->lastInsertId(),
                    'username' => $definition['username'],
                    'nama' => $definition['name'],
                    'role' => $definition['role'],
                    'periode_id' => $period,
                    'can_access_all' => 0,
                ];
                $credentials[$key] = $password;
            } elseif ($refreshCredentials) {
                $password = bin2hex(random_bytes(24));
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                $credentials[$key] = $password;
            } else {
                $credentials[$key] = null;
            }
            $actors[$key] = new HukumAuthenticatedActorContext(
                (int) $user['id'], (string) $user['username'], (string) $user['nama'],
                (string) $user['role'], (int) $period, (bool) $user['can_access_all'],
                'test_fixture', true
            );
        }
        $admin = $actors['ketua_umum'];
        hukum_set_actor_context_provider(new HukumTestActorProvider($actors, 'ketua_umum'));
        foreach ($actors as $key => $actor) {
            if (!hukum_resolve_membership($pdo, $actor->id, (int) $period, $key)) {
                hukum_create_membership($pdo, $admin, $actor->id, (int) $period, $key, '2026-01-01');
            }
        }
        return [
            'period_id' => (int) $period,
            'actors' => $actors,
            'credentials' => $credentials,
            'provider' => new HukumTestActorProvider($actors, 'komisi_i'),
        ];
    } catch (Throwable $error) {
        throw $error;
    }
}

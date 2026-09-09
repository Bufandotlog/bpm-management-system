<?php
declare(strict_types=1);

final class HukumAuthenticatedActorContext
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $technicalRole,
        public readonly int $periodId,
        public readonly bool $canAccessAll,
        public readonly string $authenticationMethod,
        public readonly bool $isTestContext
    ) {
        if ($this->id <= 0 || $this->username === '' || $this->technicalRole === '') {
            throw new InvalidArgumentException('Authenticated actor context tidak lengkap.');
        }
    }
}

interface HukumActorContextProvider
{
    public function current(): ?HukumAuthenticatedActorContext;
}

final class HukumProductionSessionActorProvider implements HukumActorContextProvider
{
    public function current(): ?HukumAuthenticatedActorContext
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!function_exists('isLoggedIn') || !isLoggedIn()
            || empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
            return null;
        }

        $required = ['admin_username', 'admin_name', 'admin_role', 'admin_periode_id'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $_SESSION)) {
                return null;
            }
        }

        return new HukumAuthenticatedActorContext(
            (int) $_SESSION['admin_id'],
            (string) $_SESSION['admin_username'],
            (string) $_SESSION['admin_name'],
            strtolower(trim((string) $_SESSION['admin_role'])),
            (int) $_SESSION['admin_periode_id'],
            !empty($_SESSION['admin_can_access_all']),
            'production_session',
            false
        );
    }
}

function hukum_actor_context_provider(): HukumActorContextProvider
{
    static $provider;
    if ($provider instanceof HukumActorContextProvider) {
        return $provider;
    }

    $provider = new HukumProductionSessionActorProvider();
    return $provider;
}

function hukum_set_actor_context_provider(?HukumActorContextProvider $provider): void
{
    if ($provider !== null) {
        $environment = strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')));
        if ($environment !== 'test') {
            throw new RuntimeException('Test actor context hanya dapat diaktifkan pada APP_ENV=test.');
        }
        $actor = $provider->current();
        if ($actor === null || !$actor->isTestContext) {
            throw new RuntimeException('Provider actor test tidak valid.');
        }
    }
    $GLOBALS['hukum_actor_context_provider'] = $provider;
}

function hukum_authenticated_actor(): ?HukumAuthenticatedActorContext
{
    $provider = $GLOBALS['hukum_actor_context_provider'] ?? null;
    if ($provider instanceof HukumActorContextProvider) {
        return $provider->current();
    }

    return hukum_actor_context_provider()->current();
}

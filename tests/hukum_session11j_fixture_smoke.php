<?php
declare(strict_types=1);

putenv('APP_ENV=test');
require_once __DIR__ . '/support/hukum_test_fixture.php';

putenv('APP_ENV=development');
try {
    hukum_create_test_fixture();
    throw new RuntimeException('Fixture accepted a non-test environment.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'APP_ENV=test')) {
        throw $error;
    }
}
putenv('APP_ENV=test');

$fixture = hukum_create_test_fixture();
$rerun = hukum_create_test_fixture();
if ($fixture['period_id'] !== $rerun['period_id']
    || $fixture['actors']['komisi_i']->id !== $rerun['actors']['komisi_i']->id
    || $fixture['actors']['ketua_umum']->id !== $rerun['actors']['ketua_umum']->id) {
    throw new RuntimeException('Fixture is not idempotent.');
}
$provider = $fixture['provider'];
hukum_set_actor_context_provider($provider);
$actorA = hukum_authenticated_actor();
if (!$actorA || !$actorA->isTestContext || $actorA->technicalRole !== 'admin') {
    throw new RuntimeException('Actor A context invalid.');
}
if (!hukum_is_komisi_i($actorA->id, $fixture['period_id'])) {
    throw new RuntimeException('Actor A membership invalid.');
}

$provider = $provider->as('ketua_umum');
hukum_set_actor_context_provider($provider);
$actorB = hukum_authenticated_actor();
if (!$actorB || !$actorB->isTestContext || !hukum_is_ketua_umum($actorB->id, $fixture['period_id'])) {
    throw new RuntimeException('Actor B membership invalid.');
}
if (!hukum_technical_role_is_admin($actorB->technicalRole)) {
    throw new RuntimeException('Actor B technical role invalid.');
}

hukum_set_actor_context_provider(null);
if (hukum_authenticated_actor() !== null) {
    throw new RuntimeException('Anonymous context was accepted.');
}

echo "Hukum TEST fixture/auth smoke passed.\n";

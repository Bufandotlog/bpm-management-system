<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require_once __DIR__ . '/support/hukum_test_fixture.php';

$fixture = hukum_create_test_fixture();
hukum_set_actor_context_provider($fixture['provider']);
require_once __DIR__ . '/../api/hukum/_bootstrap.php';
require_once __DIR__ . '/../api/hukum/document_service.php';
require_once __DIR__ . '/../api/hukum/workspace_service.php';
require_once __DIR__ . '/../api/hukum/bab_service.php';
require_once __DIR__ . '/../api/hukum/pasal_service.php';

$pdo = getConnection();
$suffix = bin2hex(random_bytes(6));
$document = hukum_create_document($pdo, [
    'jenis' => 'PERATURAN',
    'lingkup' => 'induk',
    'judul' => 'Session 11L Application Test ' . $suffix,
    'slug' => 'session-11l-' . $suffix,
    'periode_id' => $fixture['period_id'],
]);
$documentId = (int) $document['id'];

$workspace = hukum_create_workspace(
    $pdo,
    $documentId,
    'Session 11L draft',
    'Application service smoke',
    $fixture['actors']['komisi_i']->id
);
$bab = hukum_create_bab($pdo, [
    'dokumen_id' => $documentId,
    'nomor_label' => 'BAB I',
    'judul_bab' => 'Ketentuan Umum',
    'urutan' => 1,
]);
$pasal = hukum_create_pasal($pdo, [
    'dokumen_id' => $documentId,
    'bab_id' => $bab['id'],
    'nomor_label' => 'Pasal 1',
    'judul_pasal' => 'Definisi',
    'urutan' => 1,
]);
$draft = hukum_create_pasal_draft($pdo, [
    'pasal_id' => $pasal['id'],
    'workspace_id' => $workspace['id'],
    'isi' => ['blocks' => [['type' => 'paragraph', 'text' => 'Draft valid']]],
]);

if (!$pdo->query('SELECT id FROM hukum_dokumen WHERE id = ' . $documentId)->fetchColumn()
    || !$pdo->query('SELECT id FROM hukum_bab WHERE id = ' . (int) $bab['id'])->fetchColumn()
    || !$pdo->query('SELECT id FROM hukum_pasal WHERE id = ' . (int) $pasal['id'])->fetchColumn()
    || !$pdo->query('SELECT id FROM hukum_pasal_versi WHERE id = ' . (int) $draft['id'])->fetchColumn()) {
    throw new RuntimeException('Application service entity verification failed.');
}

try {
    hukum_create_bab($pdo, [
        'dokumen_id' => $documentId,
        'nomor_label' => 'BAB I',
        'judul_bab' => 'Duplicate',
        'urutan' => 2,
    ]);
    throw new RuntimeException('Duplicate BAB was accepted.');
} catch (RuntimeException $error) {
    if ($error->getCode() !== 409) {
        throw $error;
    }
}

try {
    hukum_create_pasal_draft($pdo, [
        'pasal_id' => $pasal['id'],
        'workspace_id' => $workspace['id'],
        'isi' => ['blocks' => []],
        'expected_version' => $draft['id'],
    ]);
} catch (RuntimeException $error) {
    throw $error;
}

$foreignProvider = new class($fixture['actors']['ketua_umum']) implements HukumActorContextProvider {
    public function __construct(private readonly HukumAuthenticatedActorContext $actor) {}
    public function current(): ?HukumAuthenticatedActorContext
    {
        return new HukumAuthenticatedActorContext(
            $this->actor->id,
            $this->actor->username,
            $this->actor->displayName,
            $this->actor->technicalRole,
            $this->actor->periodId + 999999,
            false,
            'test_fixture',
            true
        );
    }
};
hukum_set_actor_context_provider($foreignProvider);
try {
    hukum_create_document($pdo, [
        'jenis' => 'PERATURAN',
        'lingkup' => 'induk',
        'judul' => 'Should be rejected by period policy',
        'slug' => 'session-11l-auth-' . $suffix,
        'periode_id' => $fixture['period_id'],
    ]);
    throw new RuntimeException('Cross-period document was accepted.');
} catch (RuntimeException $error) {
    if (!in_array($error->getCode(), [400, 403], true)) {
        throw $error;
    }
}

echo "PASS Session 11L application service smoke\n";

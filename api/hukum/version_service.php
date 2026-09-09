<?php
declare(strict_types=1);

require_once __DIR__ . '/pasal_service.php';

function hukum_create_draft_version(PDO $pdo, array $input): array
{
    return hukum_create_pasal_draft($pdo, $input);
}

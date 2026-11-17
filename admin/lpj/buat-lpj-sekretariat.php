<?php
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';
requireSekretaris();

$periode_id = (int)getUserPeriode();
$error = '';
$success = '';
$lpj_id = (int)($_GET['id'] ?? 0);
$validTriwulan = ['I', 'II', 'MUBESMA'];
$requestedTriwulan = strtoupper(trim((string)($_GET['triwulan'] ?? '')));
if (!in_array($requestedTriwulan, $validTriwulan, true)) {
    $requestedTriwulan = 'I';
}
$sanitizeLpjText = static function (string $value, int $maxLength): string {
    $value = strip_tags($value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;
    return mb_substr(trim($value), 0, $maxLength);
};
$lpj = $lpj_id > 0
    ? dbFetchOne("SELECT * FROM lpj_sekretariat WHERE id = ? AND periode_id = ?", [$lpj_id, $periode_id], "ii")
    : dbFetchOne(
        "SELECT * FROM lpj_sekretariat WHERE periode_id = ? AND triwulan = ?",
        [$periode_id, $requestedTriwulan],
        "is"
    );
if ($lpj && $lpj_id === 0) {
    $lpj_id = (int)$lpj['id'];
}
$triwulan = strtoupper((string)($lpj['triwulan'] ?? $requestedTriwulan));
$formKeadaanObjektif = $lpj['keadaan_objektif'] ?? '';
$formPenutup = $lpj['penutup'] ?? '';

if ($lpj_id > 0 && !$lpj) {
    http_response_code(404);
    $error = 'LPJ Sekretariat tidak ditemukan pada periode aktif.';
}

$categories = [
    'L' => ['title' => 'Surat Keluar (Eksternal)', 'date' => 'Tanggal', 'party' => 'Dituju Kepada'],
    'D' => ['title' => 'Surat Keluar (Internal)', 'date' => 'Tanggal', 'party' => 'Dituju Kepada'],
    'M' => ['title' => 'Surat Masuk (Eksternal)', 'date' => 'Tanggal Diterima', 'party' => 'Asal Instansi'],
    'I' => ['title' => 'Surat Masuk (Internal)', 'date' => 'Tanggal Diterima', 'party' => 'Asal Instansi'],
];
$fetchLatestLetters = static function () use ($periode_id, $categories): array {
    $grouped = array_fill_keys(array_keys($categories), []);
    $letters = dbFetchAll(
        "SELECT id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan
         FROM arsip_surat
         WHERE periode_id = ? AND jenis_surat IN ('L', 'D', 'M', 'I')
           AND (status_arsip = 'archived' OR status_arsip IS NULL)
         ORDER BY jenis_surat, tanggal_dikirim ASC, id ASC",
        [$periode_id],
        "i"
    );
    foreach ($letters as $letter) {
        $grouped[$letter['jenis_surat']][] = [
            'archive_id' => (int)$letter['id'],
            'tanggal_dikirim' => $letter['tanggal_dikirim'] ?? '',
            'nomor_surat' => $letter['nomor_surat'] ?? '',
            'perihal' => $letter['perihal'] ?? '',
            'tujuan' => $letter['tujuan'] ?? '',
        ];
    }
    return $grouped;
};
$decodeLetterSnapshot = static function (?string $json) use ($categories): array {
    $decoded = json_decode($json ?? '', true);
    $grouped = array_fill_keys(array_keys($categories), []);
    if (!is_array($decoded)) {
        return $grouped;
    }
    foreach ($categories as $type => $_category) {
        if (isset($decoded[$type]) && is_array($decoded[$type])) {
            $grouped[$type] = array_values(array_filter(
                $decoded[$type],
                static fn($letter) => is_array($letter)
            ));
        }
    }
    return $grouped;
};
$mergeLetterSnapshots = static function (array ...$snapshots) use ($categories): array {
    $merged = array_fill_keys(array_keys($categories), []);
    foreach ($categories as $type => $_category) {
        $seen = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot[$type] ?? [] as $letter) {
                $archiveId = (int)($letter['archive_id'] ?? 0);
                $key = $archiveId > 0
                    ? 'archive:' . $archiveId
                    : 'manual:' . hash('sha256', json_encode([
                        $letter['tanggal_dikirim'] ?? '',
                        $letter['nomor_surat'] ?? '',
                        $letter['perihal'] ?? '',
                        $letter['tujuan'] ?? '',
                    ], JSON_UNESCAPED_UNICODE));
                if (!isset($seen[$key])) {
                    $merged[$type][] = $letter;
                    $seen[$key] = true;
                }
            }
        }
    }
    return $merged;
};
$lettersByType = $lpj && !empty($lpj['surat_arsip'])
    ? $decodeLetterSnapshot($lpj['surat_arsip'])
    : null;
if ($lettersByType === null && $triwulan === 'MUBESMA') {
    $quarterRows = dbFetchAll(
        "SELECT surat_arsip FROM lpj_sekretariat
         WHERE periode_id = ? AND triwulan IN ('I', 'II') ORDER BY triwulan ASC",
        [$periode_id],
        "i"
    );
    $quarterSnapshots = [];
    foreach ($quarterRows as $quarterRow) {
        if (!empty($quarterRow['surat_arsip'])) {
            $quarterSnapshots[] = $decodeLetterSnapshot($quarterRow['surat_arsip']);
        }
    }
    $lettersByType = $quarterSnapshots
        ? $mergeLetterSnapshots(...$quarterSnapshots)
        : $fetchLatestLetters();
}
if ($lettersByType === null) {
    $lettersByType = $fetchLatestLetters();
}

$membership = json_decode($lpj['keanggotaan'] ?? '', true);
if (!is_array($membership)) {
    if ($triwulan === 'MUBESMA') {
        $quarterMemberships = dbFetchAll(
            "SELECT keanggotaan FROM lpj_sekretariat
             WHERE periode_id = ? AND triwulan IN ('I', 'II')
             ORDER BY CASE triwulan WHEN 'II' THEN 0 ELSE 1 END",
            [$periode_id],
            "i"
        );
        foreach ($quarterMemberships as $quarterMembership) {
            $candidate = json_decode($quarterMembership['keanggotaan'] ?? '', true);
            if (is_array($candidate) && $candidate) {
                $membership = $candidate;
                break;
            }
        }
    }
    if (!is_array($membership)) {
        $membership = [];
    }
    if (!$membership) {
        $membership = dbFetchAll(
        "SELECT DISTINCT ab.nama, ab.jabatan
         FROM anggota_bph ab
         LEFT JOIN struktur_bph sb ON sb.id = ab.bph_id
         WHERE ab.periode_id = ?
           AND (LOWER(ab.jabatan) LIKE '%sekretaris%'
             OR LOWER(COALESCE(sb.jabatan, '')) LIKE '%sekretaris%'
             OR LOWER(COALESCE(sb.posisi, '')) LIKE '%sekretariat%')
         ORDER BY ab.urutan ASC, ab.id ASC",
        [$periode_id],
        "i"
        );
        $membership = array_map(static fn($member) => [
            'nama' => $member['nama'],
            'jabatan' => $member['jabatan'],
        ], $membership);
    }
}
if (!$membership) {
    $membership = [['nama' => '', 'jabatan' => '']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($lpj_id === 0 || $lpj !== null)) {
    $formKeadaanObjektif = $sanitizeLpjText((string)($_POST['keadaan_objektif'] ?? ''), 10000);
    $formPenutup = $sanitizeLpjText((string)($_POST['penutup'] ?? ''), 10000);
    $postedTriwulan = strtoupper(trim((string)($_POST['triwulan'] ?? $triwulan)));
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } elseif ($lpj && $postedTriwulan !== strtoupper((string)$lpj['triwulan'])) {
        $error = 'Jenis triwulan tidak boleh diubah saat mengedit LPJ yang sudah ada.';
    } else {
        if (!in_array($postedTriwulan, $validTriwulan, true)) {
            $error = 'Jenis triwulan LPJ tidak valid.';
        } else {
            $triwulan = $postedTriwulan;
            $lpj = dbFetchOne(
                "SELECT * FROM lpj_sekretariat WHERE periode_id = ? AND triwulan = ?",
                [$periode_id, $triwulan],
                "is"
            );
            $lpj_id = (int)($lpj['id'] ?? 0);
        }
        $keadaan_objektif = $formKeadaanObjektif;
        $penutup = $formPenutup;
        $status = ($_POST['status'] ?? 'draft') === 'submitted' ? 'submitted' : 'draft';
        $membership = [];
        $memberNames = (array)($_POST['anggota_nama'] ?? []);
        $memberRoles = (array)($_POST['anggota_jabatan'] ?? []);
        foreach ($memberNames as $index => $name) {
            $name = sanitizeText((string)$name, 150);
            $role = sanitizeText((string)($memberRoles[$index] ?? ''), 150);
            if ($name !== '' || $role !== '') {
                if ($name === '' || $role === '') {
                    $membership[] = ['nama' => $name, 'jabatan' => $role];
                    $error = 'Nama dan jabatan setiap anggota harus diisi.';
                    break;
                }
                $membership[] = ['nama' => $name, 'jabatan' => $role];
            }
        }

        $postedLetters = array_fill_keys(array_keys($categories), []);
        foreach ($categories as $type => $_category) {
            foreach ((array)($_POST['surat'][$type] ?? []) as $letter) {
                if (!is_array($letter)) {
                    continue;
                }
                $archiveId = max(0, (int)($letter['archive_id'] ?? 0));
                $snapshotLetter = [
                    'archive_id' => $archiveId,
                    'tanggal_dikirim' => $sanitizeLpjText((string)($letter['tanggal_dikirim'] ?? ''), 50),
                    'nomor_surat' => sanitizeText((string)($letter['nomor_surat'] ?? ''), 255),
                    'perihal' => sanitizeText((string)($letter['perihal'] ?? ''), 255),
                    'tujuan' => $sanitizeLpjText((string)($letter['tujuan'] ?? ''), 1000),
                ];
                if (implode('', [
                    $snapshotLetter['tanggal_dikirim'],
                    $snapshotLetter['nomor_surat'],
                    $snapshotLetter['perihal'],
                    $snapshotLetter['tujuan'],
                ]) !== '') {
                    $postedLetters[$type][] = $snapshotLetter;
                }
            }
        }

        $action = (string)($_POST['action'] ?? 'save');
        if ($error === '' && $action === 'sync_archives') {
            $freshLetters = $triwulan === 'MUBESMA' ? null : $fetchLatestLetters();
            if ($triwulan === 'MUBESMA') {
                $quarterRows = dbFetchAll(
                    "SELECT surat_arsip FROM lpj_sekretariat
                     WHERE periode_id = ? AND triwulan IN ('I', 'II') ORDER BY triwulan ASC",
                    [$periode_id],
                    "i"
                );
                $quarterSnapshots = [];
                foreach ($quarterRows as $quarterRow) {
                    if (!empty($quarterRow['surat_arsip'])) {
                        $quarterSnapshots[] = $decodeLetterSnapshot($quarterRow['surat_arsip']);
                    }
                }
                $freshLetters = $quarterSnapshots
                    ? $mergeLetterSnapshots(...$quarterSnapshots)
                    : $fetchLatestLetters();
            }
            $manualLetters = array_fill_keys(array_keys($categories), []);
            foreach ($postedLetters as $type => $rows) {
                $manualLetters[$type] = array_values(array_filter(
                    $rows,
                    static fn($letter) => (int)$letter['archive_id'] === 0
                ));
            }
            $lettersByType = $mergeLetterSnapshots($freshLetters, $manualLetters);
            $success = 'Arsip surat terbaru disinkronkan. Tinjau perubahan lalu simpan LPJ untuk memperbarui snapshot.';
        } elseif ($error === '') {
            $lettersByType = $postedLetters;

        if ($error === '' && $status === 'submitted' && ($keadaan_objektif === '' || !$membership)) {
            $error = 'LPJ yang disubmit harus memiliki keadaan objektif dan minimal satu anggota sekretariat.';
        }

        if ($error === '') {
            $membershipJson = json_encode($membership, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $contentSaved = false;
            $oldFilePath = $lpj['file_path'] ?? '';
            $outputPath = null;
            try {
                if ($lpj) {
                    dbQuery(
                        "UPDATE lpj_sekretariat
                         SET status = ?, keadaan_objektif = ?, keanggotaan = ?, surat_arsip = ?, penutup = ?, file_path = NULL
                         WHERE id = ? AND periode_id = ?",
                        [$status, $keadaan_objektif, $membershipJson, json_encode($lettersByType, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $penutup, $lpj_id, $periode_id],
                        "sssssii"
                    );
                } else {
                    dbQuery(
                            "INSERT INTO lpj_sekretariat (periode_id, triwulan, status, keadaan_objektif, keanggotaan, surat_arsip, penutup)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$periode_id, $triwulan, $status, $keadaan_objektif, $membershipJson, json_encode($lettersByType, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $penutup],
                        "issssss"
                    );
                    $lpj_id = dbLastId();
                }
                $contentSaved = true;

                $periode = getPeriodeData($periode_id);
                $config = [
                    'periode' => $periode ? [
                        'nama' => $periode['nama'],
                        'tahun_mulai' => $periode['tahun_mulai'],
                        'tahun_selesai' => $periode['tahun_selesai'],
                    ] : [],
                    'cover' => [
                        'triwulan' => $triwulan,
                        'kementerian' => 'Sekretariat Umum',
                    ],
                    'keadaan_objektif' => $keadaan_objektif,
                    'keanggotaan' => $membership,
                    'surat' => $lettersByType,
                    'penutup' => $penutup,
                ];
                $jsonPath = tempnam(sys_get_temp_dir(), 'lpj-sekretariat-');
                if ($jsonPath === false) {
                    throw new RuntimeException('Tidak dapat membuat file sementara untuk generator LPJ.');
                }
                $outputDirectory = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'lpj';
                if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
                    throw new RuntimeException('Folder penyimpanan LPJ tidak dapat dibuat.');
                }
                $filename = 'LPJ_Sekretariat_' . $triwulan . '_' . $periode_id . '_' . date('Ymd_His') . '.docx';
                $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . $filename;
                try {
                    file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $script = escapeshellarg(__DIR__ . '/../../scratch/bpm_lpj_manager.py');
                    $command = 'python3 ' . $script . ' generate-secretary '
                        . escapeshellarg($outputPath) . ' ' . escapeshellarg($jsonPath) . ' 2>&1';
                    exec($command, $generatorOutput, $generatorStatus);
                } finally {
                    if (is_file($jsonPath)) {
                        unlink($jsonPath);
                    }
                }
                if ($generatorStatus !== 0 || !is_file($outputPath)) {
                    throw new RuntimeException('Generator DOCX gagal: ' . implode("\n", $generatorOutput));
                }
                $relativePath = 'lpj/' . $filename;
                dbQuery(
                    "UPDATE lpj_sekretariat SET file_path = ? WHERE id = ? AND periode_id = ?",
                    [$relativePath, $lpj_id, $periode_id],
                    "sii"
                );
                if ($oldFilePath !== '' && $oldFilePath !== $relativePath) {
                    deleteFile($oldFilePath);
                }
                redirect('admin/lpj/arsip-lpj-sekretariat.php', 'LPJ Sekretariat berhasil disimpan dan DOCX dibuat.', 'success');
            } catch (Throwable $exception) {
                error_log('[LPJ Sekretariat] ' . $exception->getMessage());
                if ($outputPath !== null && is_file($outputPath)) {
                    unlink($outputPath);
                }
                if ($contentSaved) {
                    dbQuery(
                        "UPDATE lpj_sekretariat SET file_path = NULL WHERE id = ? AND periode_id = ?",
                        [$lpj_id, $periode_id],
                        "ii"
                    );
                    if ($oldFilePath !== '') {
                        deleteFile($oldFilePath);
                    }
                }
                $error = 'LPJ tersimpan sebagai data, tetapi proses DOCX gagal. Detail: ' . $exception->getMessage();
            }
        }
        }
    }
}
?>

<style>
    .secretariat-lpj { max-width:1100px; margin:0 auto 36px; }
    .secretariat-lpj .page-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
    .secretariat-lpj .page-header h1 { margin:0; }
    .secretariat-lpj .page-header .btn-secondary { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 15px; border-radius:8px; text-decoration:none; }
    .lpj-step-progress { display:flex; justify-content:space-between; gap:8px; background:#0f1217; border:1px solid #2a3545; border-radius:12px; padding:12px 16px; margin-bottom:20px; }
    .lpj-step-progress button { flex:1; display:flex; align-items:center; justify-content:center; gap:9px; min-width:0; padding:11px 12px; border:0; border-bottom:2px solid transparent; background:transparent; color:#7f8a99; font-size:.88rem; font-weight:600; cursor:pointer; transition:color .2s,border-color .2s,background .2s; }
    .lpj-step-progress button:hover { color:#dce8f8; background:rgba(255,255,255,.025); }
    .lpj-step-progress button.active { color:#8BB9F0; border-bottom-color:#4A90E2; }
    .lpj-step-progress button.completed { color:#b5c8df; }
    .step-number { display:inline-flex; width:27px; height:27px; flex:0 0 27px; align-items:center; justify-content:center; border:1px solid #35445b; border-radius:50%; color:#9aa8ba; font-size:.78rem; }
    .lpj-step-progress button.active .step-number { color:#fff; background:#357abd; border-color:#4A90E2; box-shadow:0 0 0 4px rgba(74,144,226,.12); }
    .lpj-step-progress button.completed .step-number { color:#fff; background:#26734d; border-color:#2e9b65; }
    .lpj-panel { display:none; }
    .lpj-panel.active { display:block; animation:lpj-panel-in .25s ease both; }
    @keyframes lpj-panel-in { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
    .lpj-panel-card { overflow:hidden; background:linear-gradient(145deg,#121923,#0f131a); border:1px solid #2a3545; border-radius:14px; box-shadow:0 8px 26px rgba(0,0,0,.2); }
    .lpj-panel-heading { padding:20px 24px 16px; border-bottom:1px solid rgba(255,255,255,.06); }
    .lpj-panel-heading h2 { display:flex; align-items:center; gap:10px; margin:0 0 5px; color:#f4f7fb; font-size:1.08rem; }
    .lpj-panel-heading h2 i { color:#6ea8e8; }
    .lpj-panel-heading p { margin:0; color:#8d9aad; font-size:.84rem; }
    .lpj-panel-content { padding:22px 24px 24px; }
    .period-summary { display:flex; align-items:center; gap:12px; margin-bottom:22px; padding:14px 16px; border:1px solid rgba(74,144,226,.2); border-radius:10px; background:rgba(74,144,226,.08); }
    .period-summary > i { display:grid; place-items:center; width:38px; height:38px; flex:0 0 38px; border-radius:10px; color:#8BB9F0; background:rgba(74,144,226,.15); }
    .period-summary-text { flex:1; min-width:160px; }
    .period-summary small { display:block; color:#8d9aad; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; }
    .period-summary strong { display:block; margin-top:2px; color:#f4f7fb; font-size:.94rem; }
    .period-select-label { display:grid; gap:5px; min-width:210px; color:#8d9aad; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; }
    .period-select-label select { width:100%; min-height:39px; padding:8px 32px 8px 10px; border:1px solid #35445b; border-radius:7px; color:#eef4fc; background:#0b111a; font:inherit; font-size:.82rem; text-transform:none; letter-spacing:normal; }
    .period-select-label select:focus { outline:0; border-color:#4A90E2; box-shadow:0 0 0 3px rgba(74,144,226,.13); }
    .lpj-field { margin-bottom:20px; }
    .lpj-field:last-child { margin-bottom:0; }
    .lpj-field label,.member-row label { display:block; margin:0 0 8px; color:#dce4ef; font-size:.88rem; font-weight:650; }
    .lpj-field label .field-kicker { color:#75a9e3; margin-right:5px; }
    .lpj-field textarea { display:block; width:100%; min-height:170px; padding:14px 15px; resize:vertical; border:1px solid #35445b; border-radius:9px; outline:0; background:#090e16; color:#f2f5f9; font:inherit; line-height:1.65; transition:border-color .2s,box-shadow .2s; }
    .lpj-field textarea:focus,.member-row input:focus { border-color:#4A90E2; box-shadow:0 0 0 3px rgba(74,144,226,.15); }
    .lpj-field textarea#penutup { min-height:125px; }
    .field-hint { display:block; margin-top:7px; color:#77869a; font-size:.77rem; }
    .membership-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .membership-toolbar p,.archive-intro { margin:0; color:#8d9aad; font-size:.84rem; line-height:1.6; }
    .member-list { display:grid; gap:10px; margin-bottom:16px; }
    .member-row { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) auto; gap:12px; align-items:end; padding:16px; border:1px solid #2b3748; border-radius:10px; background:rgba(7,12,20,.62); }
    .member-row input { width:100%; min-height:43px; padding:10px 12px; border:1px solid #35445b; border-radius:8px; outline:0; background:#080c14; color:#fff; font:inherit; transition:border-color .2s,box-shadow .2s; }
    .member-row input::placeholder { color:#718096; }
    .member-row .btn-delete { min-height:42px; padding:0 13px; border-radius:8px; }
    .lpj-add-member { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border:1px dashed rgba(74,144,226,.5); border-radius:8px; background:rgba(74,144,226,.07); color:#8BB9F0; cursor:pointer; transition:background .2s,border-color .2s; }
    .lpj-add-member:hover { border-color:#4A90E2; background:rgba(74,144,226,.14); }
    .archive-intro { margin-bottom:14px; padding:12px 14px; border-radius:9px; border-left:3px solid #4A90E2; background:rgba(74,144,226,.07); }
    .lpj-archive-heading { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .lpj-sync-button { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:39px; padding:9px 13px; border:1px solid rgba(74,144,226,.45); border-radius:8px; color:#bcd8f7; background:rgba(74,144,226,.11); font:inherit; font-size:.8rem; font-weight:650; cursor:pointer; transition:background .2s,border-color .2s; }
    .lpj-sync-button:hover { border-color:#4A90E2; background:rgba(74,144,226,.2); }
    .archive-category { margin:12px 0; overflow:hidden; border:1px solid #2a3545; border-radius:11px; background:#0e131b; transition:border-color .2s,box-shadow .2s; }
    .archive-category.open { border-color:#3b5f87; box-shadow:0 5px 18px rgba(0,0,0,.18); }
    .archive-category > button { width:100%; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 17px; border:0; color:#f0f4f9; background:linear-gradient(100deg,#151e2a,#111720); cursor:pointer; font:inherit; text-align:left; }
    .archive-category-title { display:flex; align-items:center; gap:11px; font-size:.9rem; font-weight:700; }
    .archive-category-title > i { display:grid; place-items:center; width:34px; height:34px; border-radius:9px; color:#8BB9F0; background:rgba(74,144,226,.12); }
    .archive-count { display:inline-flex; align-items:center; justify-content:center; min-width:28px; height:23px; margin-left:3px; padding:0 8px; border-radius:12px; color:#b6c8dd; background:#253449; font-size:.74rem; }
    .archive-category-chevron { color:#8291a5; transition:transform .2s; }
    .archive-category.open .archive-category-chevron { transform:rotate(180deg); color:#8BB9F0; }
    .archive-category-content { display:none; padding:14px; overflow-x:auto; border-top:1px solid rgba(255,255,255,.05); }
    .archive-category.open .archive-category-content { display:block; animation:lpj-panel-in .2s ease both; }
    .archive-category table { width:100%; border-collapse:separate; border-spacing:0; min-width:880px; color:#dce4ef; font-size:.83rem; }
    .archive-category th,.archive-category td { padding:11px 12px; border-right:1px solid #2c394b; border-bottom:1px solid #2c394b; text-align:left; vertical-align:top; line-height:1.5; }
    .archive-category th:first-child,.archive-category td:first-child { border-left:1px solid #2c394b; }
    .archive-category thead th { position:sticky; top:0; color:#dce8f7; background:#1b2b40; font-size:.75rem; font-weight:700; letter-spacing:.025em; }
    .archive-category thead th:first-child { border-top-left-radius:7px; }
    .archive-category thead th:last-child { border-top-right-radius:7px; }
    .archive-category tbody tr { background:rgba(255,255,255,.012); }
    .archive-category tbody tr:nth-child(even) { background:rgba(255,255,255,.035); }
    .archive-category tbody tr:hover { background:rgba(74,144,226,.08); }
    .letter-input { width:100%; min-width:110px; min-height:36px; padding:7px 9px; border:1px solid transparent; border-radius:6px; outline:0; color:#e7edf5; background:rgba(5,9,15,.55); font:inherit; font-size:.8rem; }
    .letter-input:hover { border-color:#35445b; }
    .letter-input:focus { border-color:#4A90E2; background:#080c14; box-shadow:0 0 0 2px rgba(74,144,226,.12); }
    .remove-letter { display:grid; place-items:center; width:32px; height:32px; border:1px solid rgba(220,53,69,.28); border-radius:7px; color:#f08b95; background:rgba(220,53,69,.08); cursor:pointer; }
    .remove-letter:hover { color:#fff; background:#bd3442; }
    .add-letter { margin-top:12px; }
    .archive-empty { display:flex; align-items:center; gap:10px; padding:18px; border:1px dashed #35445b; border-radius:8px; color:#8d9aad; font-size:.86rem; }
    .archive-empty i { color:#7891af; }
    .lpj-form-actions { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:18px; padding:14px; border:1px solid #252f3e; border-radius:12px; background:rgba(13,17,24,.94); }
    .lpj-form-actions .action-group { display:flex; align-items:center; justify-content:flex-end; gap:9px; flex-wrap:wrap; }
    .lpj-form-actions button { min-height:42px; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 15px; border-radius:8px; font:inherit; font-size:.85rem; font-weight:650; cursor:pointer; transition:transform .15s,background .2s,border-color .2s; }
    .lpj-form-actions button:hover:not(:disabled) { transform:translateY(-1px); }
    .lpj-form-actions .btn-secondary { border:1px solid #35445b; color:#c8d2df; background:#171e29; }
    .lpj-form-actions .btn-secondary:hover:not(:disabled) { border-color:#50637b; background:#1e2938; }
    .lpj-form-actions .btn-primary { border:1px solid #3d82c8; color:#fff; background:linear-gradient(135deg,#367fca,#2864a3); }
    .lpj-form-actions .btn-primary:hover:not(:disabled) { background:linear-gradient(135deg,#438ed8,#3274b9); }
    .lpj-form-actions .submit-button { border-color:#2d8d62; background:linear-gradient(135deg,#25885d,#1b6b49); }
    .lpj-form-actions .submit-button:hover:not(:disabled) { background:linear-gradient(135deg,#30a06e,#237d55); }
    .lpj-form-actions button:disabled { opacity:.42; cursor:not-allowed; }
    @media(max-width:700px) {
        .secretariat-lpj { margin-bottom:100px; }
        .secretariat-lpj .page-header { align-items:flex-start; }
        .secretariat-lpj .page-header h1 { font-size:1.25rem; }
        .lpj-step-progress { gap:3px; padding:8px; }
        .lpj-step-progress button { flex:0 0 auto; gap:0; padding:7px 5px; }
        .lpj-step-progress button:not(.active) .step-label { display:none; }
        .lpj-step-progress button:not(.active) .step-number { width:31px; height:31px; flex-basis:31px; }
        .lpj-step-progress button.active { flex:1; gap:7px; font-size:.78rem; }
        .lpj-panel-heading { padding:17px 17px 14px; }
        .lpj-panel-content { padding:17px; }
        .period-summary { align-items:flex-start; flex-wrap:wrap; }
        .period-summary-text { padding-top:2px; }
        .period-select-label { width:100%; }
        .lpj-archive-heading { align-items:flex-start; }
        .lpj-sync-button { width:100%; }
        .member-row { grid-template-columns:1fr; gap:10px; }
        .member-row .btn-delete { justify-self:end; }
        .lpj-form-actions { position:fixed; z-index:900; right:12px; bottom:12px; left:12px; margin:0; padding:9px; box-shadow:0 8px 30px rgba(0,0,0,.55); backdrop-filter:blur(12px); }
        .lpj-form-actions .action-group { gap:6px; }
        .lpj-form-actions button { min-height:40px; padding:9px 10px; font-size:.76rem; }
        .lpj-form-actions .save-draft span { display:none; }
    }
    @media(max-width:420px) {
        .lpj-form-actions { gap:6px; }
        .lpj-form-actions button { padding:8px; }
        .lpj-form-actions .submit-button { flex:1; }
    }
</style>

<div class="secretariat-lpj">
<div class="page-header">
    <h1><i class="fas fa-file-signature"></i> <?php echo $lpj ? 'Edit' : 'Buat'; ?> LPJ Sekretariat</h1>
    <a class="btn-secondary" href="arsip-lpj-sekretariat.php"><i class="fas fa-archive"></i> Arsip LPJ Sekretariat</a>
</div>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="lpj-step-progress" id="stepProgress" aria-label="Tahapan pengisian LPJ">
    <button type="button" class="active" data-step-target="1" aria-current="step"><span class="step-number">1</span><span class="step-label">Informasi Dasar</span></button>
    <button type="button" data-step-target="2"><span class="step-number">2</span><span class="step-label">Keanggotaan</span></button>
    <button type="button" data-step-target="3"><span class="step-number">3</span><span class="step-label">Arsip Surat</span></button>
</div>
<form method="POST" id="secretariatLpjForm" class="admin-form">
    <?php echo csrfField(); ?>
    <input type="hidden" name="status" id="lpjStatus" value="<?php echo htmlspecialchars($lpj['status'] ?? 'draft'); ?>">
    <input type="hidden" name="triwulan" id="triwulanValue" value="<?php echo htmlspecialchars($triwulan); ?>">
    <section class="lpj-panel active" data-step-panel="1">
        <div class="lpj-panel-card">
            <div class="lpj-panel-heading"><h2><i class="fas fa-info-circle"></i> Informasi Dasar</h2><p>Pilih jenis laporan dan lengkapi gambaran umum sekretariat.</p></div>
            <div class="lpj-panel-content">
                <div class="period-summary">
                    <i class="fas fa-calendar-alt"></i>
                    <div class="period-summary-text"><small>Periode Kepengurusan</small><strong><?php echo htmlspecialchars(($periode_data['nama'] ?? 'Periode aktif') . ' (' . ($periode_data['tahun_mulai'] ?? '') . '-' . ($periode_data['tahun_selesai'] ?? '') . ')'); ?></strong></div>
                    <label class="period-select-label" for="triwulanSelect">Jenis LPJ
                        <select id="triwulanSelect" onchange="changeSecretariatTriwulan(this.value)">
                            <option value="I" <?php echo $triwulan === 'I' ? 'selected' : ''; ?>>Triwulan I</option>
                            <option value="II" <?php echo $triwulan === 'II' ? 'selected' : ''; ?>>Triwulan II</option>
                            <option value="MUBESMA" <?php echo $triwulan === 'MUBESMA' ? 'selected' : ''; ?>>MUBESMA (gabungan Triwulan I &amp; II)</option>
                        </select>
                    </label>
                </div>
                <div class="lpj-field">
                    <label for="keadaan_objektif"><span class="field-kicker">A.</span> Keadaan Objektif Sekretariat</label>
                    <textarea id="keadaan_objektif" name="keadaan_objektif" rows="7" maxlength="10000" placeholder="Jelaskan kondisi, peran, dan pelaksanaan fungsi sekretariat selama periode kepengurusan..."><?php echo htmlspecialchars($formKeadaanObjektif); ?></textarea>
                    <small class="field-hint">Uraikan keadaan sekretariat sebagai dasar pertanggungjawaban pada laporan.</small>
                </div>
                <div class="lpj-field">
                    <label for="penutup"><span class="field-kicker">D.</span> Penutup</label>
                    <textarea id="penutup" name="penutup" rows="5" maxlength="10000" placeholder="Tuliskan penutup laporan..."><?php echo htmlspecialchars($formPenutup); ?></textarea>
                </div>
            </div>
        </div>
    </section>
    <section class="lpj-panel" data-step-panel="2">
        <div class="lpj-panel-card">
            <div class="lpj-panel-heading"><h2><i class="fas fa-users"></i> Susunan Keanggotaan</h2><p>Susun nama dan jabatan anggota sekretariat untuk dicantumkan dalam LPJ.</p></div>
            <div class="lpj-panel-content">
            <div class="membership-toolbar"><p>Data awal diambil dari struktur BPH bila tersedia. Nama dan jabatan dapat disesuaikan.</p><span class="archive-count"><span id="memberCount"><?php echo count(array_filter($membership, static fn($member) => !empty($member['nama']) || !empty($member['jabatan']))); ?></span> anggota</span></div>
            <div id="members" class="member-list">
            <?php foreach ($membership as $member): ?>
                <div class="member-row">
                    <div><label>Nama</label><input name="anggota_nama[]" maxlength="150" placeholder="Nama anggota" value="<?php echo htmlspecialchars($member['nama'] ?? ''); ?>"></div>
                    <div><label>Jabatan</label><input name="anggota_jabatan[]" maxlength="150" placeholder="Jabatan dalam sekretariat" value="<?php echo htmlspecialchars($member['jabatan'] ?? ''); ?>"></div>
                    <button class="btn-delete" type="button" onclick="this.closest('.member-row').remove(); updateMemberCount()"><i class="fas fa-trash-alt"></i> Hapus</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="lpj-add-member" id="addMember"><i class="fas fa-plus"></i> Tambah Anggota</button>
            </div>
        </div>
    </section>
    <section class="lpj-panel" data-step-panel="3">
        <div class="lpj-panel-card">
            <div class="lpj-panel-heading lpj-archive-heading">
                <div><h2><i class="fas fa-folder-open"></i> Arsip Surat</h2><p>Surat tersimpan sebagai snapshot pada LPJ <?php echo htmlspecialchars($triwulan); ?>.</p></div>
                <button class="lpj-sync-button" type="submit" name="action" value="sync_archives" formnovalidate><i class="fas fa-sync-alt"></i> Sinkronkan Arsip Terbaru</button>
            </div>
            <div class="lpj-panel-content">
            <p class="archive-intro"><i class="fas fa-info-circle"></i> LPJ tidak berubah saat arsip bertambah. Sinkronisasi mengganti baris dari arsip dengan data terbaru dan mempertahankan baris yang ditambahkan manual. Simpan LPJ untuk menyimpan perubahan.</p>
            <?php foreach ($categories as $type => $category): $items = $lettersByType[$type]; ?>
                <div class="archive-category">
                    <button type="button" aria-expanded="false">
                        <span class="archive-category-title"><i class="fas <?php echo in_array($type, ['L', 'D'], true) ? 'fa-paper-plane' : 'fa-inbox'; ?>"></i><?php echo htmlspecialchars($category['title']); ?><span class="archive-count"><?php echo count($items); ?></span></span>
                        <i class="fas fa-chevron-down archive-category-chevron"></i>
                    </button>
                    <div class="archive-category-content">
                        <table>
                            <thead><tr><th>No.</th><th><?php echo htmlspecialchars($category['date']); ?></th><th>Nomor Surat</th><th>Perihal</th><th><?php echo htmlspecialchars($category['party']); ?></th><th>Aksi</th></tr></thead>
                            <tbody data-letter-rows="<?php echo $type; ?>" data-next-index="<?php echo count($items); ?>">
                            <?php foreach ($items as $index => $item):
                                $dateValue = (string)($item['tanggal_dikirim'] ?? '');
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                                    $dateValue = date('d/m/Y', strtotime($dateValue));
                                }
                            ?>
                                <tr>
                                    <td class="letter-number"><?php echo $index + 1; ?></td>
                                    <td><input class="letter-input" name="surat[<?php echo $type; ?>][<?php echo $index; ?>][tanggal_dikirim]" value="<?php echo htmlspecialchars($dateValue); ?>" placeholder="DD/MM/YYYY"></td>
                                    <td><input class="letter-input" name="surat[<?php echo $type; ?>][<?php echo $index; ?>][nomor_surat]" value="<?php echo htmlspecialchars($item['nomor_surat'] ?? ''); ?>"></td>
                                    <td><input class="letter-input" name="surat[<?php echo $type; ?>][<?php echo $index; ?>][perihal]" value="<?php echo htmlspecialchars($item['perihal'] ?? ''); ?>"></td>
                                    <td><input class="letter-input" name="surat[<?php echo $type; ?>][<?php echo $index; ?>][tujuan]" value="<?php echo htmlspecialchars($item['tujuan'] ?? ''); ?>"></td>
                                    <td><input type="hidden" name="surat[<?php echo $type; ?>][<?php echo $index; ?>][archive_id]" value="<?php echo max(0, (int)($item['archive_id'] ?? 0)); ?>"><button class="remove-letter" type="button" aria-label="Hapus surat"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!$items): ?><p class="archive-empty empty-category"><i class="far fa-folder-open"></i>Belum ada surat dalam kategori ini.</p><?php endif; ?>
                        <button class="lpj-add-member add-letter" type="button" data-letter-type="<?php echo $type; ?>"><i class="fas fa-plus"></i> Tambah Surat Manual</button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
    <div class="lpj-form-actions">
        <button type="button" id="previousStep" class="btn-secondary"><i class="fas fa-arrow-left"></i> Sebelumnya</button>
        <div class="action-group">
            <button type="submit" name="action" value="save" class="btn-secondary save-draft" onclick="document.getElementById('lpjStatus').value='draft'"><i class="fas fa-save"></i><span>Simpan Draft &amp; Generate DOCX</span></button>
            <button type="submit" name="action" value="save" class="btn-primary submit-button" onclick="document.getElementById('lpjStatus').value='submitted'"><i class="fas fa-check-circle"></i> Submit &amp; Generate DOCX</button>
            <button type="button" id="nextStep" class="btn-primary">Berikutnya <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</form>
</div>
<script>
(() => {
    const buttons = [...document.querySelectorAll('[data-step-target]')];
    const panels = [...document.querySelectorAll('[data-step-panel]')];
    let active = 1;
    const show = step => {
        active = Math.max(1, Math.min(3, step));
        buttons.forEach(button => {
            const stepNumber = Number(button.dataset.stepTarget);
            button.classList.toggle('active', stepNumber === active);
            button.classList.toggle('completed', stepNumber < active);
            if (stepNumber === active) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });
        panels.forEach(panel => panel.classList.toggle('active', Number(panel.dataset.stepPanel) === active));
        document.getElementById('previousStep').disabled = active === 1;
        document.getElementById('nextStep').style.display = active === 3 ? 'none' : '';
    };
    window.updateMemberCount = () => {
        const count = [...document.querySelectorAll('#members .member-row')].filter(row =>
            row.querySelector('[name="anggota_nama[]"]').value.trim() ||
            row.querySelector('[name="anggota_jabatan[]"]').value.trim()
        ).length;
        document.getElementById('memberCount').textContent = count;
    };
    buttons.forEach(button => button.addEventListener('click', () => show(Number(button.dataset.stepTarget))));
    document.getElementById('previousStep').addEventListener('click', () => show(active - 1));
    document.getElementById('nextStep').addEventListener('click', () => show(active + 1));
    document.getElementById('addMember').addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'member-row';
        row.innerHTML = '<div><label>Nama</label><input name="anggota_nama[]" maxlength="150" placeholder="Nama anggota"></div><div><label>Jabatan</label><input name="anggota_jabatan[]" maxlength="150" placeholder="Jabatan dalam sekretariat"></div><button class="btn-delete" type="button"><i class="fas fa-trash-alt"></i> Hapus</button>';
        row.querySelector('button').addEventListener('click', () => {
            row.remove();
            window.updateMemberCount();
        });
        row.querySelectorAll('input').forEach(input => input.addEventListener('input', window.updateMemberCount));
        document.getElementById('members').appendChild(row);
        window.updateMemberCount();
    });
    document.querySelectorAll('#members input').forEach(input => input.addEventListener('input', window.updateMemberCount));
    document.querySelectorAll('.archive-category > button').forEach(button => button.addEventListener('click', () => {
        const category = button.closest('.archive-category');
        category.classList.toggle('open');
        button.setAttribute('aria-expanded', category.classList.contains('open') ? 'true' : 'false');
    }));
    document.querySelectorAll('.add-letter').forEach(button => button.addEventListener('click', () => {
        const type = button.dataset.letterType;
        const body = document.querySelector(`[data-letter-rows="${type}"]`);
        const index = Number(body.dataset.nextIndex || body.querySelectorAll('tr').length);
        body.dataset.nextIndex = String(index + 1);
        const row = document.createElement('tr');
        row.innerHTML = `<td class="letter-number"></td><td><input class="letter-input" name="surat[${type}][${index}][tanggal_dikirim]" placeholder="DD/MM/YYYY"></td><td><input class="letter-input" name="surat[${type}][${index}][nomor_surat]" placeholder="Nomor surat"></td><td><input class="letter-input" name="surat[${type}][${index}][perihal]" placeholder="Perihal"></td><td><input class="letter-input" name="surat[${type}][${index}][tujuan]" placeholder="Asal/tujuan surat"></td><td><input type="hidden" name="surat[${type}][${index}][archive_id]" value="0"><button class="remove-letter" type="button" aria-label="Hapus surat"><i class="fas fa-times"></i></button></td>`;
        body.appendChild(row);
        row.querySelector('.remove-letter').addEventListener('click', () => {
            row.remove();
            updateLetterNumbers(body);
        });
        row.querySelector('.letter-input').focus();
        const empty = button.parentElement.querySelector('.empty-category');
        if (empty) empty.remove();
        updateLetterNumbers(body);
    }));
    document.querySelectorAll('.remove-letter').forEach(button => button.addEventListener('click', () => {
        const body = button.closest('tbody');
        button.closest('tr').remove();
        updateLetterNumbers(body);
    }));
    window.changeSecretariatTriwulan = value => {
        if (!['I', 'II', 'MUBESMA'].includes(value)) return;
        const current = new URL(window.location.href);
        current.searchParams.set('triwulan', value);
        current.searchParams.delete('id');
        if (document.querySelector('#members input, #secretariatLpjForm textarea')) {
            const changed = [...document.querySelectorAll('#members input, #secretariatLpjForm textarea, #secretariatLpjForm .letter-input')]
                .some(field => field.value !== field.defaultValue);
            if (changed && !confirm('Pindah triwulan akan memuat LPJ lain. Pastikan perubahan saat ini sudah disimpan. Lanjutkan?')) {
                document.getElementById('triwulanSelect').value = '<?php echo htmlspecialchars($triwulan, ENT_QUOTES); ?>';
                return;
            }
        }
        window.location.assign(current.toString());
    };
    function updateLetterNumbers(body) {
        body.querySelectorAll('tr').forEach((row, index) => {
            row.querySelector('.letter-number').textContent = String(index + 1);
        });
    }
    show(1);
})();
</script>
<?php require_once __DIR__ . '/../core/footer.php'; ?>

<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
$canVerifyCommit = hukum_has_permission('hukum.commit.verify');
$canFinalizeCommit = hukum_has_permission('hukum.commit.create');
$actor = hukum_current_user();
$stagingId = max(0, (int) ($_GET['staging_id'] ?? 0));
$hukumCssVersion = file_exists(__DIR__ . '/css/hukum.css') ? filemtime(__DIR__ . '/css/hukum.css') : '1';
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=<?php echo (int) $hukumCssVersion; ?>">

<div class="hukum-shell hukum-commit-page">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-file-signature"></i> Finalisasi Commit</h1>
            <p>Tinjau staging yang telah disetujui dan selesaikan verifikasi sebelum menetapkannya sebagai versi dokumen.</p>
        </div>
        <button id="refreshCommitList" class="hukum-btn" type="button"><i class="fas fa-sync"></i> Muat ulang</button>
    </div>

    <div id="commitNotice" class="hukum-notice" role="status" aria-live="polite"></div>
    <div class="hukum-tabs" role="tablist" aria-label="Daftar commit">
        <button id="readyCommitTab" class="hukum-tab active" type="button" role="tab" aria-selected="true" aria-controls="readyCommitPanel">
            Siap Difinalisasi
        </button>
        <button id="commitHistoryTab" class="hukum-tab" type="button" role="tab" aria-selected="false" aria-controls="commitHistoryPanel">
            Riwayat Commit
        </button>
    </div>

    <section id="readyCommitPanel" class="hukum-panel active" role="tabpanel" aria-labelledby="readyCommitTab">
        <section class="hukum-card">
            <div class="hukum-toolbar">
                <div>
                    <h2>Siap Difinalisasi</h2>
                    <p class="hukum-muted">Staging tampil di sini setelah persetujuan Komisi I dan Admin lengkap.</p>
                </div>
                <span id="commitCount" class="hukum-badge">Memuat...</span>
            </div>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Dokumen</th><th>Persetujuan</th><th>Verifikasi</th><th>Diajukan</th><th>Aksi</th></tr></thead>
                    <tbody id="readyCommitBody"><tr><td colspan="5" class="hukum-empty">Memuat staging...</td></tr></tbody>
                </table>
            </div>
        </section>
    </section>

    <section id="commitHistoryPanel" class="hukum-panel" role="tabpanel" aria-labelledby="commitHistoryTab" hidden>
        <section class="hukum-card">
            <div class="hukum-toolbar">
                <div>
                    <h2>Riwayat Commit</h2>
                    <p class="hukum-muted">Hanya commit yang sudah terbentuk ditampilkan. Status commit dan status staging dicatat terpisah.</p>
                </div>
                <span id="commitHistoryCount" class="hukum-badge">Memuat...</span>
            </div>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Dokumen</th><th>Status Commit</th><th>Status Staging</th><th>Finalisasi</th><th>Persetujuan</th><th>Aksi</th></tr></thead>
                    <tbody id="commitHistoryBody"><tr><td colspan="6" class="hukum-empty">Memuat riwayat commit...</td></tr></tbody>
                </table>
            </div>
        </section>
    </section>

    <section id="commitDetail" class="hukum-card hukum-commit-detail" hidden>
        <div class="hukum-toolbar">
            <div>
                <span class="hukum-kicker">Tinjauan finalisasi</span>
                <h2 id="commitDocumentTitle"></h2>
                <p id="commitDocumentMeta" class="hukum-muted"></p>
            </div>
            <button id="closeCommitDetail" class="hukum-btn" type="button">Kembali ke daftar</button>
        </div>

        <div class="hukum-commit-preview-link">
            <p>Periksa snapshot staging dan diff lengkap sebelum melanjutkan. Pratinjau terbuka di tab baru agar halaman finalisasi ini tetap tersedia.</p>
            <a id="commitPreviewLink" class="hukum-btn gold" target="_blank" rel="noopener">
                <i class="fas fa-eye"></i> Buka pratinjau perubahan
            </a>
        </div>

        <div class="hukum-commit-approvals" id="commitApprovalCards"></div>

        <div class="hukum-commit-readiness">
            <h3>Kesiapan finalisasi</h3>
            <ul id="commitReadinessList"></ul>
            <p class="hukum-muted">Setiap verifikasi berlaku paling lama 5 menit. Muat ulang status sebelum finalisasi jika waktu berlalu.</p>
            <div class="hukum-actions" id="commitDetailActions"></div>
        </div>
    </section>
</div>

<dialog id="commitPasswordDialog" class="hukum-commit-dialog" aria-labelledby="commitDialogTitle" aria-describedby="commitDialogText">
    <form id="commitPasswordForm">
        <div class="hukum-commit-dialog-heading">
            <span class="hukum-kicker" id="commitDialogKicker">Konfirmasi akun</span>
            <h2 id="commitDialogTitle">Verifikasi</h2>
            <p id="commitDialogText" class="hukum-muted"></p>
        </div>
        <label for="commitPassword">Kata sandi akun Anda</label>
        <input id="commitPassword" name="password" type="password" autocomplete="current-password" required>
        <p id="commitDialogError" class="hukum-commit-dialog-error" role="alert" hidden></p>
        <div class="hukum-actions">
            <button id="commitPasswordSubmit" class="hukum-btn gold" type="submit">Lanjutkan</button>
            <button id="commitPasswordCancel" class="hukum-btn" type="button">Batal</button>
        </div>
    </form>
</dialog>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const hukumActorId = <?php echo (int) $actor['id']; ?>;
const hukumActorRole = <?php echo json_encode((string) $actor['role'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumCanVerifyCommit = <?php echo $canVerifyCommit ? 'true' : 'false'; ?>;
const hukumCanFinalizeCommit = <?php echo $canFinalizeCommit ? 'true' : 'false'; ?>;
let readyCommitRows = [];
let commitHistoryRows = [];
let selectedCommit = null;
let selectedReview = null;
let dialogAction = null;

async function hukumRequest(endpoint, options = {}) {
    const response = await fetch(hukumBase + endpoint, {
        ...options,
        headers: {Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-Token':hukumCsrf, ...(options.headers || {})}
    });
    const body = await response.json().catch(() => ({message:'Respons server tidak valid.'}));
    if (!response.ok || body.success === false) throw new Error(body.message || 'Permintaan gagal.');
    return body;
}
function hukumEscape(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
}
function commitNotice(message, type = 'success') {
    const node = document.getElementById('commitNotice');
    node.textContent = message;
    node.className = 'hukum-notice show ' + type;
}
function isActive(value) { return value === true || value === 1 || value === '1'; }
function displayStatus(value) {
    const labels = {disetujui:'Disetujui', menunggu:'Menunggu', ditolak:'Ditolak'};
    return labels[value] || value || 'Belum ada';
}
function verificationLabel(value) { return isActive(value) ? 'Aktif' : 'Belum diverifikasi'; }
function approvalFor(role) { return selectedReview?.approval_summary?.[role] || {status:'menunggu', user_id:null}; }
function roleLabel(role) { return role === 'komisi_i' ? 'Komisi I' : 'Admin'; }
function commitStatusLabel(value) {
    const labels = {aktif:'Aktif', digantikan:'Digantikan'};
    return labels[value] || value || 'Tidak diketahui';
}
function stagingStatusLabel(value) {
    const labels = {menunggu_review:'Menunggu review', ditolak:'Ditolak', disetujui:'Disetujui'};
    return labels[value] || value || 'Tidak diketahui';
}

function setCommitTab(tab) {
    const showHistory = tab === 'history';
    document.getElementById('readyCommitTab').classList.toggle('active', !showHistory);
    document.getElementById('readyCommitTab').setAttribute('aria-selected', String(!showHistory));
    document.getElementById('commitHistoryTab').classList.toggle('active', showHistory);
    document.getElementById('commitHistoryTab').setAttribute('aria-selected', String(showHistory));
    document.getElementById('readyCommitPanel').classList.toggle('active', !showHistory);
    document.getElementById('commitHistoryPanel').classList.toggle('active', showHistory);
    document.getElementById('readyCommitPanel').hidden = showHistory;
    document.getElementById('commitHistoryPanel').hidden = !showHistory;
    if (showHistory && selectedCommit) closeCommitDetail();
}

function renderReadyCommitList() {
    const body = document.getElementById('readyCommitBody');
    document.getElementById('commitCount').textContent = readyCommitRows.length + ' staging';
    if (!readyCommitRows.length) {
        body.innerHTML = '<tr><td colspan="5" class="hukum-empty">Belum ada staging yang siap difinalisasi.</td></tr>';
        return;
    }
    body.innerHTML = readyCommitRows.map(item => `<tr>
        <td><strong>${hukumEscape(item.judul)}</strong><br><small class="hukum-muted">${hukumEscape(item.jenis || '')} · Staging #${Number(item.staging_id)}</small></td>
        <td>Komisi I: ${hukumEscape(item.komisi_i_nama)}<br>Admin: ${hukumEscape(item.admin_nama)}</td>
        <td><span class="hukum-badge">Komisi I: ${verificationLabel(item.komisi_i_verified)}</span><br><span class="hukum-badge">Admin: ${verificationLabel(item.admin_verified)}</span></td>
        <td>${hukumEscape(item.diajukan_at || '—')}</td>
        <td><button class="hukum-btn gold" type="button" data-open-staging="${Number(item.staging_id)}">Tinjau</button></td>
    </tr>`).join('');
    body.querySelectorAll('[data-open-staging]').forEach(button => {
        button.addEventListener('click', () => openCommitDetail(Number(button.dataset.openStaging)));
    });
}

function renderCommitHistory() {
    const body = document.getElementById('commitHistoryBody');
    document.getElementById('commitHistoryCount').textContent = commitHistoryRows.length + ' commit';
    if (!commitHistoryRows.length) {
        body.innerHTML = '<tr><td colspan="6" class="hukum-empty">Belum ada commit final pada periode yang dapat Anda akses.</td></tr>';
        return;
    }
    body.innerHTML = commitHistoryRows.map(item => {
        const finalizer = item.finalizer_name || item.finalizer_username || 'Akun tidak tercatat';
        const komisiName = item.komisi_i_name || item.komisi_i_username || 'Tidak tercatat';
        const adminName = item.admin_name || item.admin_username || 'Tidak tercatat';
        const reviewNote = item.staging_review_note
            ? `<br><small class="hukum-muted">Catatan staging: ${hukumEscape(item.staging_review_note)}</small>`
            : '';
        const replacementDate = item.replaced_at
            ? `<br><small class="hukum-muted">Digantikan: ${hukumEscape(item.replaced_at)}</small>`
            : '';
        return `<tr>
            <td><strong>${hukumEscape(item.judul || 'Dokumen tanpa judul')}</strong><br><small class="hukum-muted">${hukumEscape(item.jenis || '')} · Commit #${Number(item.commit_id || item.id)} · Staging #${Number(item.staging_id)}</small></td>
            <td><span class="hukum-badge">${hukumEscape(commitStatusLabel(item.commit_status || item.status))}</span></td>
            <td><span class="hukum-badge">${hukumEscape(stagingStatusLabel(item.staging_status))}</span>${reviewNote}</td>
            <td>${hukumEscape(finalizer)}<br><small class="hukum-muted">${hukumEscape(item.created_at || '—')}</small>${replacementDate}</td>
            <td>Komisi I: ${hukumEscape(komisiName)} (${hukumEscape(displayStatus(item.komisi_i_approval_status))})<br>Admin: ${hukumEscape(adminName)} (${hukumEscape(displayStatus(item.admin_approval_status))})</td>
            <td><a class="hukum-btn" target="_blank" rel="noopener" href="hukum-staging-detail.php?staging_id=${Number(item.staging_id)}">Detail staging</a></td>
        </tr>`;
    }).join('');
}

function approvalCardsMarkup() {
    return ['komisi_i', 'admin'].map(role => {
        const approval = approvalFor(role);
        const name = role === 'komisi_i' ? selectedCommit.komisi_i_nama : selectedCommit.admin_nama;
        const verified = isActive(selectedCommit[role + '_verified']);
        return `<article class="hukum-review-approval">
            <strong>${roleLabel(role)}</strong>
            <span class="hukum-badge">${hukumEscape(displayStatus(approval.status))}</span>
            <p>${hukumEscape(name || 'Pemberi persetujuan tidak tercatat')}</p>
            ${approval.approved_at ? `<small class="hukum-muted">Disetujui: ${hukumEscape(approval.approved_at)}</small>` : ''}
            <p><span class="hukum-badge">${verified ? 'Verifikasi aktif' : 'Belum diverifikasi'}</span></p>
        </article>`;
    }).join('');
}

function renderCommitDetail() {
    if (!selectedCommit || !selectedReview) return;
    document.getElementById('commitDocumentTitle').textContent = selectedCommit.judul || selectedReview.judul || 'Dokumen Hukum';
    document.getElementById('commitDocumentMeta').textContent =
        `${selectedCommit.jenis || 'Dokumen'} · Staging #${selectedCommit.staging_id} · Diajukan ${selectedCommit.diajukan_at || '—'}`;
    document.getElementById('commitPreviewLink').href =
        <?php echo json_encode(baseUrl('admin/hukum-staging-detail.php?staging_id=')); ?> + encodeURIComponent(selectedCommit.staging_id);
    document.getElementById('commitApprovalCards').innerHTML = approvalCardsMarkup();

    const komisiApproved = approvalFor('komisi_i').status === 'disetujui';
    const adminApproved = approvalFor('admin').status === 'disetujui';
    const komisiVerified = isActive(selectedCommit.komisi_i_verified);
    const adminVerified = isActive(selectedCommit.admin_verified);
    const checks = [
        ['Persetujuan Komisi I', komisiApproved],
        ['Persetujuan Admin', adminApproved],
        ['Verifikasi Komisi I', komisiVerified],
        ['Verifikasi Admin', adminVerified],
    ];
    document.getElementById('commitReadinessList').innerHTML = checks.map(([label, ready]) =>
        `<li class="${ready ? 'ready' : 'pending'}"><i class="fas ${ready ? 'fa-check-circle' : 'fa-clock'}"></i> ${label}: ${ready ? 'Lengkap' : 'Belum lengkap'}</li>`
    ).join('');

    const actions = document.getElementById('commitDetailActions');
    actions.replaceChildren();
    const currentRole = hukumActorRole;
    const ownApproval = approvalFor(currentRole);
    if (hukumCanVerifyCommit && ['admin', 'komisi_i'].includes(currentRole)
        && ownApproval.status === 'disetujui' && Number(ownApproval.user_id) === hukumActorId) {
        const verifyButton = document.createElement('button');
        verifyButton.type = 'button';
        verifyButton.className = 'hukum-btn';
        verifyButton.textContent = isActive(selectedCommit[currentRole + '_verified'])
            ? 'Perbarui verifikasi saya'
            : 'Verifikasi persetujuan saya';
        verifyButton.addEventListener('click', () => openPasswordDialog('verify', currentRole));
        actions.append(verifyButton);
    }
    const readyToFinalize = komisiApproved && adminApproved && komisiVerified && adminVerified;
    if (hukumCanFinalizeCommit && readyToFinalize) {
        const finalizeButton = document.createElement('button');
        finalizeButton.type = 'button';
        finalizeButton.className = 'hukum-btn gold';
        finalizeButton.textContent = 'Finalisasi Commit';
        finalizeButton.addEventListener('click', () => openPasswordDialog('finalize', 'admin'));
        actions.append(finalizeButton);
    }
    if (!actions.children.length) {
        const note = document.createElement('p');
        note.className = 'hukum-muted';
        note.textContent = hukumCanFinalizeCommit && !readyToFinalize
            ? 'Finalisasi tersedia setelah kedua pihak menyelesaikan verifikasi.'
            : 'Tidak ada tindakan yang perlu dilakukan oleh akun Anda pada staging ini.';
        actions.append(note);
    }
}

async function loadReadyCommitList() {
    try {
        const result = await hukumRequest('commit.php?action=ready_to_finalize');
        readyCommitRows = result.data || [];
        renderReadyCommitList();
        return true;
    } catch (error) {
        document.getElementById('readyCommitBody').innerHTML =
            `<tr><td colspan="5" class="hukum-empty">${hukumEscape(error.message)}</td></tr>`;
        document.getElementById('commitCount').textContent = 'Gagal memuat';
        commitNotice(error.message, 'error');
        return false;
    }
}

async function loadCommitHistory() {
    try {
        const result = await hukumRequest('commit.php?action=history');
        commitHistoryRows = result.data || [];
        renderCommitHistory();
        return true;
    } catch (error) {
        document.getElementById('commitHistoryBody').innerHTML =
            `<tr><td colspan="6" class="hukum-empty">${hukumEscape(error.message)}</td></tr>`;
        document.getElementById('commitHistoryCount').textContent = 'Gagal memuat';
        commitNotice('Riwayat commit gagal dimuat: ' + error.message, 'error');
        return false;
    }
}

async function openCommitDetail(stagingId) {
    const item = readyCommitRows.find(row => Number(row.staging_id) === stagingId);
    if (!item) return;
    selectedCommit = item;
    selectedReview = null;
    document.getElementById('commitDetail').hidden = false;
    document.getElementById('commitDocumentTitle').textContent = 'Memuat pratinjau...';
    document.getElementById('commitApprovalCards').replaceChildren();
    document.getElementById('commitDetailActions').replaceChildren();
    const url = new URL(window.location.href);
    url.searchParams.set('staging_id', String(stagingId));
    window.history.replaceState({}, '', url);
    try {
        const result = await hukumRequest('review.php?staging_id=' + encodeURIComponent(stagingId));
        selectedReview = result.data;
        renderCommitDetail();
        document.getElementById('commitDetail').scrollIntoView({behavior:'smooth', block:'start'});
    } catch (error) {
        commitNotice(error.message, 'error');
        document.getElementById('commitDocumentTitle').textContent = 'Pratinjau tidak dapat dimuat';
    }
}

function closeCommitDetail() {
    document.getElementById('commitDetail').hidden = true;
    selectedCommit = null;
    selectedReview = null;
    const url = new URL(window.location.href);
    url.searchParams.delete('staging_id');
    window.history.replaceState({}, '', url);
}

function openPasswordDialog(action, role) {
    if (!selectedCommit) return;
    dialogAction = {action, role, stagingId:Number(selectedCommit.staging_id)};
    const isFinalize = action === 'finalize';
    document.getElementById('commitDialogKicker').textContent = isFinalize ? 'Konfirmasi finalisasi' : 'Verifikasi persetujuan';
    document.getElementById('commitDialogTitle').textContent = isFinalize ? 'Finalisasi Commit' : 'Verifikasi ' + roleLabel(role);
    document.getElementById('commitDialogText').textContent = isFinalize
        ? `Anda akan menetapkan staging #${dialogAction.stagingId} sebagai commit final untuk “${selectedCommit.judul}”. Tindakan ini mengaktifkan versi dokumen tersebut.`
        : `Masukkan kata sandi akun Anda untuk memverifikasi persetujuan ${roleLabel(role)} pada staging #${dialogAction.stagingId}.`;
    document.getElementById('commitDialogError').hidden = true;
    document.getElementById('commitDialogError').textContent = '';
    document.getElementById('commitPasswordSubmit').textContent = isFinalize ? 'Konfirmasi Finalisasi' : 'Verifikasi';
    document.getElementById('commitPassword').value = '';
    document.getElementById('commitPasswordDialog').showModal();
    document.getElementById('commitPassword').focus();
}

async function performFinalize(password, stagingId) {
    const freshList = await hukumRequest('commit.php?action=ready_to_finalize');
    const freshItem = (freshList.data || []).find(row => Number(row.staging_id) === stagingId);
    if (!freshItem || !isActive(freshItem.komisi_i_verified) || !isActive(freshItem.admin_verified)) {
        throw new Error('Status staging atau verifikasi berubah/kedaluwarsa. Muat ulang daftar dan verifikasi kembali bila diperlukan.');
    }
    return hukumRequest('commit.php', {
        method:'POST',
        body:JSON.stringify({action:'finalize', staging_id:stagingId, password})
    });
}

document.getElementById('commitPasswordForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!dialogAction) return;
    const action = {...dialogAction};
    const password = document.getElementById('commitPassword').value;
    const submit = document.getElementById('commitPasswordSubmit');
    let successMessage = '';
    submit.disabled = true;
    try {
        if (action.action === 'verify') {
            await hukumRequest('commit.php', {
                method:'POST',
                body:JSON.stringify({action:'verify_window', staging_id:action.stagingId, password})
            });
            successMessage = `Verifikasi ${roleLabel(action.role)} berhasil. Verifikasi berlaku paling lama 5 menit.`;
        } else {
            const result = await performFinalize(password, action.stagingId);
            successMessage = `Commit berhasil dibuat dengan ID #${result.data.id}.`;
        }
    } catch (error) {
        commitNotice(error.message, 'error');
        const dialogError = document.getElementById('commitDialogError');
        dialogError.textContent = error.message;
        dialogError.hidden = false;
        return;
    } finally {
        submit.disabled = false;
    }
    document.getElementById('commitPasswordDialog').close();
    document.getElementById('commitPassword').value = '';
    document.getElementById('commitDialogError').hidden = true;
    dialogAction = null;
    commitNotice(successMessage);
    const selectedStagingId = Number(selectedCommit?.staging_id);
    if (!(await loadReadyCommitList())) {
        commitNotice(successMessage + ' Daftar tidak dapat diperbarui; muat ulang halaman untuk melihat status terbaru.', 'error');
        return;
    }
    if (!(await loadCommitHistory())) {
        commitNotice(successMessage + ' Antrean sudah diperbarui, tetapi riwayat commit gagal dimuat.', 'error');
    }
    const selectedStillReady = readyCommitRows.find(row => Number(row.staging_id) === selectedStagingId);
    if (selectedStillReady) {
        selectedCommit = selectedStillReady;
        try {
            const detail = await hukumRequest('review.php?staging_id=' + encodeURIComponent(selectedStillReady.staging_id));
            selectedReview = detail.data;
            renderCommitDetail();
        } catch (error) {
            commitNotice(successMessage + ' Status berhasil diperbarui, tetapi detail perlu dimuat ulang: ' + error.message, 'error');
        }
    } else if (selectedCommit) {
        document.getElementById('commitDetail').hidden = true;
        selectedCommit = null;
        selectedReview = null;
        const url = new URL(window.location.href);
        url.searchParams.delete('staging_id');
        window.history.replaceState({}, '', url);
    }
});

document.getElementById('commitPasswordCancel').addEventListener('click', () => {
    document.getElementById('commitPasswordDialog').close();
    dialogAction = null;
});
document.getElementById('readyCommitTab').addEventListener('click', () => setCommitTab('ready'));
document.getElementById('commitHistoryTab').addEventListener('click', () => setCommitTab('history'));
document.getElementById('refreshCommitList').addEventListener('click', async () => {
    const [readyLoaded] = await Promise.all([loadReadyCommitList(), loadCommitHistory()]);
    if (readyLoaded && selectedCommit) {
        const refreshed = readyCommitRows.find(row => Number(row.staging_id) === Number(selectedCommit.staging_id));
        if (refreshed) {
            selectedCommit = refreshed;
            try {
                const detail = await hukumRequest('review.php?staging_id=' + encodeURIComponent(refreshed.staging_id));
                selectedReview = detail.data;
                renderCommitDetail();
            } catch (error) {
                commitNotice(error.message, 'error');
            }
        }
    }
});
document.getElementById('closeCommitDetail').addEventListener('click', closeCommitDetail);
document.getElementById('commitPasswordDialog').addEventListener('click', event => {
    if (event.target === event.currentTarget) {
        event.currentTarget.close();
        dialogAction = null;
    }
});
document.getElementById('commitPasswordDialog').addEventListener('close', () => {
    dialogAction = null;
});

(async () => {
    await Promise.all([loadReadyCommitList(), loadCommitHistory()]);
    const requestedId = <?php echo $stagingId; ?>;
    if (requestedId > 0) {
        if (readyCommitRows.some(row => Number(row.staging_id) === requestedId)) {
            await openCommitDetail(requestedId);
        } else {
            commitNotice('Staging tidak lagi tersedia untuk finalisasi. Daftar telah diperbarui.', 'error');
        }
    }
})();
</script>
<?php require_once __DIR__ . '/core/footer.php'; ?>

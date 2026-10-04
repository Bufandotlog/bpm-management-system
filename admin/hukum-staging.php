<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=1">

<div class="hukum-shell">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-layer-group"></i> Review / Staging</h1>
            <p>Periksa perubahan dokumen sebelum disetujui atau ditolak.</p>
        </div>
        <button class="hukum-btn" type="button" onclick="hukumLoadStaging()"><i class="fas fa-sync"></i> Refresh</button>
    </div>
    <div id="hukumNotice" class="hukum-notice"></div>
    <div class="hukum-tabs" role="tablist" aria-label="Daftar staging">
        <button id="pendingTab" class="hukum-tab active" type="button" role="tab" aria-selected="true" aria-controls="pendingPanel" data-panel="pendingPanel">Perlu diperiksa</button>
        <button id="historyTab" class="hukum-tab" type="button" role="tab" aria-selected="false" aria-controls="historyPanel" data-panel="historyPanel">Riwayat</button>
    </div>
    <section id="pendingPanel" class="hukum-panel active" role="tabpanel" aria-labelledby="pendingTab">
        <div class="hukum-card">
            <div class="hukum-toolbar">
                <h2>Menunggu Review</h2>
                <span id="pendingCount" class="hukum-badge">0 item</span>
            </div>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Dokumen</th><th>Perubahan</th><th>Approval</th><th>Diajukan</th><th>Aksi</th></tr></thead>
                    <tbody id="pendingStagingBody"><tr><td colspan="5" class="hukum-empty">Memuat data...</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>
    <section id="historyPanel" class="hukum-panel" role="tabpanel" aria-labelledby="historyTab" hidden>
        <div class="hukum-card">
            <div class="hukum-toolbar">
                <h2>Riwayat Staging</h2>
                <span id="historyCount" class="hukum-badge">0 item</span>
            </div>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Dokumen</th><th>Hasil</th><th>Keputusan</th><th>Diajukan</th><th>Aksi</th></tr></thead>
                    <tbody id="historyStagingBody"><tr><td colspan="5" class="hukum-empty">Memuat riwayat...</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
async function hukumRequest(endpoint, options = {}) {
    const response = await fetch(hukumBase + endpoint, {
        ...options,
        headers: {Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-Token':hukumCsrf, ...(options.headers || {})}
    });
    const body = await response.json().catch(() => ({message:'Respons server tidak valid.'}));
    if (!response.ok || body.success === false) throw new Error(body.message || 'Permintaan gagal.');
    return body;
}
function hukumEscape(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }
function hukumNotice(message, type = 'success') {
    const node = document.getElementById('hukumNotice');
    node.textContent = message; node.className = 'hukum-notice show ' + type;
    window.setTimeout(() => { node.className = 'hukum-notice'; }, 4500);
}
function stagingStatusLabel(item) {
    if (item.status === 'ditolak') return 'Ditolak';
    if (item.status === 'disetujui' && item.commit_id) return 'Sudah di-commit';
    if (item.status === 'disetujui') return 'Disetujui · menunggu commit';
    return item.status || 'Tidak diketahui';
}
function stagingDecisionSummary(item) {
    const summary = item.approval_summary || {};
    const reviewer = item.decision_by_name || item.decision_by_username || 'Tidak tercatat';
    if (item.status === 'ditolak') {
        return `<span class="hukum-badge">${hukumEscape(stagingStatusLabel(item))}</span><br><small>Ditolak oleh ${hukumEscape(reviewer)}</small><br><small>${hukumEscape(item.review_note || 'Alasan tidak tercatat')}</small>`;
    }
    const approvedBy = ['komisi_i', 'admin']
        .map(role => summary[role]?.status === 'disetujui'
            ? `${role === 'komisi_i' ? 'Komisi I' : 'Admin'}: ${summary[role].user_name || summary[role].username || 'akun tidak tercatat'}`
            : null)
        .filter(Boolean);
    return `<span class="hukum-badge">${hukumEscape(stagingStatusLabel(item))}</span><br><small>${approvedBy.map(hukumEscape).join('<br>') || `Disetujui oleh ${hukumEscape(reviewer)}`}</small>`;
}
function renderStagingRows(rows, target, emptyMessage, showApproval) {
    if (!rows.length) {
        target.innerHTML = `<tr><td colspan="5" class="hukum-empty">${hukumEscape(emptyMessage)}</td></tr>`;
        return;
    }
    target.innerHTML = rows.map(item => {
        const summary = item.approval_summary || {progress:'0/2', komisi_i:{status:'menunggu'}, admin:{status:'menunggu'}};
        const approval = showApproval
            ? `<span class="hukum-badge">${hukumEscape(summary.progress || '0/2')}</span><br><small>Komisi I: ${hukumEscape(summary.komisi_i?.status || 'menunggu')} / Admin: ${hukumEscape(summary.admin?.status || 'menunggu')}</small>`
            : stagingDecisionSummary(item);
        const action = item.status === 'menunggu_review' ? 'Tinjau' : 'Lihat riwayat';
        return `<tr>
            <td>${hukumEscape(item.judul)}</td>
            <td>${hukumEscape(item.judul_perubahan)}</td>
            <td>${approval}</td>
            <td>${hukumEscape(item.diajukan_at)}</td>
            <td><a class="hukum-btn ${showApproval ? 'gold' : ''}" href="hukum-staging-detail.php?staging_id=${Number(item.id)}">${action}</a></td>
        </tr>`;
    }).join('');
}
async function hukumLoadStaging() {
    try {
        const [pendingResult, rejectedResult, approvedResult] = await Promise.all([
            hukumRequest('review.php?status=menunggu_review'),
            hukumRequest('review.php?status=ditolak'),
            hukumRequest('review.php?status=disetujui')
        ]);
        const pending = pendingResult.data || [];
        const history = [...(rejectedResult.data || []), ...(approvedResult.data || [])]
            .sort((left, right) => Number(right.id) - Number(left.id));
        document.getElementById('pendingCount').textContent = pending.length + ' item';
        document.getElementById('historyCount').textContent = history.length + ' item';
        renderStagingRows(pending, document.getElementById('pendingStagingBody'), 'Tidak ada staging menunggu review.', true);
        renderStagingRows(history, document.getElementById('historyStagingBody'), 'Belum ada riwayat staging.', false);
    } catch (error) {
        hukumNotice(error.message, 'error');
    }
}
document.querySelectorAll('.hukum-tabs [role="tab"]').forEach(tab => tab.addEventListener('click', () => {
    document.querySelectorAll('.hukum-tabs [role="tab"]').forEach(item => {
        const selected = item === tab;
        item.classList.toggle('active', selected);
        item.setAttribute('aria-selected', selected ? 'true' : 'false');
        document.getElementById(item.dataset.panel).classList.toggle('active', selected);
        document.getElementById(item.dataset.panel).hidden = !selected;
    });
}));
hukumLoadStaging();
</script>
<?php require_once __DIR__ . '/core/footer.php'; ?>

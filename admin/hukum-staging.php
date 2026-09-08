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
    <div class="hukum-card">
        <div class="hukum-toolbar">
            <h2>Menunggu Review</h2>
            <span id="stagingCount" class="hukum-badge">0 item</span>
        </div>
        <div class="hukum-table-wrap">
            <table class="hukum-table">
                <thead><tr><th>Dokumen</th><th>Perubahan</th><th>Diajukan</th><th>Aksi</th></tr></thead>
                <tbody id="stagingBody"><tr><td colspan="4" class="hukum-empty">Memuat data...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const hukumCanReview = <?php echo hukum_has_permission('hukum.staging.review') ? 'true' : 'false'; ?>;
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
async function hukumLoadStaging() {
    try {
        const result = await hukumRequest('staging.php');
        const rows = (result.data || []).filter(item => item.status === 'menunggu_review');
        document.getElementById('stagingCount').textContent = rows.length + ' item';
        document.getElementById('stagingBody').innerHTML = rows.length ? rows.map(item => `<tr>
            <td>${hukumEscape(item.judul)}</td>
            <td>${hukumEscape(item.judul_perubahan)}</td>
            <td>${hukumEscape(item.diajukan_at)}</td>
            <td>${hukumCanReview ? `<button class="hukum-btn gold" type="button" onclick="hukumReview(${Number(item.id)}, 'approve')">Setujui</button>
                <button class="hukum-btn danger" type="button" onclick="hukumReview(${Number(item.id)}, 'reject')">Tolak</button>` : '<span class="hukum-muted">Read-only</span>'}</td>
        </tr>`).join('') : '<tr><td colspan="4" class="hukum-empty">Tidak ada staging menunggu review.</td></tr>';
    } catch (error) {
        hukumNotice(error.message, 'error');
    }
}
async function hukumReview(id, decision) {
    const note = decision === 'reject' ? window.prompt('Alasan penolakan wajib diisi:') : '';
    if (decision === 'reject' && !note) return;
    try {
        await hukumRequest('review.php', {method:'POST', body:JSON.stringify({staging_id:id, decision, note})});
        hukumNotice('Status staging diperbarui.');
        hukumLoadStaging();
    } catch (error) {
        hukumNotice(error.message, 'error');
    }
}
hukumLoadStaging();
</script>
<?php require_once __DIR__ . '/core/footer.php'; ?>

<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
$hukumUser = hukum_current_user();
$hukumDefaultPeriodeId = $hukumUser['periode_id'] > 0
    ? $hukumUser['periode_id']
    : (function_exists('getActivePeriodeId') ? getActivePeriodeId() : 0);
$hukumPeriods = dbFetchAll(
    'SELECT id, nama, tahun_mulai, tahun_selesai, is_active
     FROM periode_kepengurusan ORDER BY tahun_mulai DESC, id DESC'
);
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=1">

<div class="hukum-shell">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-balance-scale"></i> Dokumen Hukum</h1>
            <p>Kelola dokumen, workspace, review, dan notifikasi perubahan hukum.</p>
        </div>
        <div class="hukum-actions">
            <?php if (hukum_has_permission('hukum.document.create')): ?>
                <button class="hukum-btn gold" type="button" onclick="hukumToggleCreate(true)"><i class="fas fa-plus"></i> Dokumen Baru</button>
            <?php endif; ?>
            <button class="hukum-btn" type="button" onclick="hukumLoadAll()"><i class="fas fa-sync"></i> Refresh</button>
        </div>
    </div>

    <div id="hukumNotice" class="hukum-notice"></div>
    <div class="hukum-grid">
        <div class="hukum-stat"><strong id="statDocuments">0</strong><span>Dokumen</span></div>
        <div class="hukum-stat"><strong id="statWorkspaces">0</strong><span>Workspace aktif/diajukan</span></div>
        <div class="hukum-stat"><strong id="statStaging">0</strong><span>Menunggu review</span></div>
        <div class="hukum-stat"><strong id="statNotifications">0</strong><span>Perlu ditinjau</span></div>
    </div>

    <div class="hukum-tabs" role="tablist">
        <button class="hukum-tab active" data-panel="documents" type="button">Dokumen</button>
        <button class="hukum-tab" data-panel="notifications" type="button">Notifikasi</button>
    </div>

    <section class="hukum-panel active" id="panel-documents">
        <div class="hukum-card">
            <div class="hukum-toolbar">
                <h2>Daftar Dokumen</h2>
                <input id="documentSearch" type="search" placeholder="Cari judul..." aria-label="Cari dokumen">
            </div>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Judul</th><th>Jenis</th><th>Lingkup</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody id="documentsBody"><tr><td colspan="5" class="hukum-empty">Memuat data...</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="hukum-card hukum-drawer" id="documentDetail" hidden></div>
    </section>

    <section class="hukum-panel" id="panel-notifications">
        <div class="hukum-card">
            <h2>Notifikasi Peninjauan</h2>
            <div class="hukum-table-wrap">
                <table class="hukum-table">
                    <thead><tr><th>Dokumen anak</th><th>Pasal induk</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody id="notificationsBody"><tr><td colspan="4" class="hukum-empty">Memuat data...</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if (hukum_has_permission('hukum.document.create')): ?>
    <div class="hukum-card hukum-drawer" id="createDocument" hidden>
        <h2>Dokumen Hukum Baru</h2>
        <form id="createDocumentForm" class="hukum-form-grid">
            <div class="form-group"><label for="docTitle">Judul</label><input id="docTitle" name="judul" required></div>
            <div class="form-group"><label for="docSlug">Slug</label><input id="docSlug" name="slug" required></div>
            <div class="form-group"><label for="docJenis">Jenis</label><select id="docJenis" name="jenis"><option>AD</option><option>ART</option><option>GBHO</option><option>GBMO</option><option>PERATURAN</option><option>KEPUTUSAN</option></select></div>
            <div class="form-group"><label for="docLingkup">Lingkup</label><select id="docLingkup" name="lingkup"><option value="induk">Induk</option><option>BEM</option><option>BPM</option><option>UKM</option></select></div>
            <div class="form-group"><label for="docOrmawa">Nama Ormawa</label><input id="docOrmawa" name="nama_ormawa"></div>
            <div class="form-group"><label for="docPeriode">Periode</label><select id="docPeriode" name="periode_nama" required>
                <option value="">Pilih periode</option>
                <?php foreach ($hukumPeriods as $period): ?>
                    <?php $periodLabel = $period['nama'] . ' (' . $period['tahun_mulai'] . '/' . $period['tahun_selesai'] . ')' . ((int) $period['is_active'] === 1 ? ' - aktif' : ''); ?>
                    <option value="<?php echo htmlspecialchars($period['nama'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (int) $period['id'] === (int) $hukumDefaultPeriodeId ? 'selected' : ''; ?>><?php echo htmlspecialchars($periodLabel); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-group full"><label for="docDescription">Deskripsi</label><textarea id="docDescription" name="deskripsi"></textarea></div>
            <div class="hukum-actions full"><button class="hukum-btn gold" type="submit">Simpan Draft</button><button class="hukum-btn" type="button" onclick="hukumToggleCreate(false)">Batal</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const hukumCanReview = <?php echo hukum_has_permission('hukum.staging.review') ? 'true' : 'false'; ?>;
let hukumDocuments = [];
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
function hukumToggleCreate(open) { document.getElementById('createDocument').hidden = !open; }
function hukumRenderDocuments() {
    const query = document.getElementById('documentSearch').value.toLowerCase();
    const rows = hukumDocuments.filter(item => (item.judul + ' ' + item.jenis).toLowerCase().includes(query));
    document.getElementById('documentsBody').innerHTML = rows.length ? rows.map(item => `<tr>
        <td><strong>${hukumEscape(item.judul)}</strong><br><small class="hukum-muted">${hukumEscape(item.slug)}</small></td>
        <td>${hukumEscape(item.jenis)}</td><td>${hukumEscape(item.lingkup)}</td>
        <td><span class="hukum-badge">${hukumEscape(item.status)}</span></td>
        <td><button class="hukum-btn" type="button" onclick="hukumShowDocument(${Number(item.id)})">Detail</button></td></tr>`).join('')
        : '<tr><td colspan="5" class="hukum-empty">Belum ada dokumen.</td></tr>';
    document.getElementById('statDocuments').textContent = hukumDocuments.length;
}
async function hukumLoadDocuments() {
    const result = await hukumRequest('documents.php');
    hukumDocuments = result.data || []; hukumRenderDocuments();
}
async function hukumShowDocument(id) {
    const result = await hukumRequest('documents.php?id=' + id);
    const [bab, workspaces, commits] = await Promise.all([
        hukumRequest('bab.php?dokumen_id=' + id), hukumRequest('workspaces.php?dokumen_id=' + id), hukumRequest('commit.php?dokumen_id=' + id)
    ]);
    const detail = document.getElementById('documentDetail'); detail.hidden = false;
    const babRows = (bab.data || []).map(item => `<li>${hukumEscape(item.nomor_label)} — ${hukumEscape(item.judul_bab)}</li>`).join('') || '<li class="hukum-muted">Belum ada BAB.</li>';
    detail.innerHTML = `<div class="hukum-toolbar"><h2>${hukumEscape(result.data.judul)}</h2><button class="hukum-btn" type="button" onclick="document.getElementById('documentDetail').hidden=true">Tutup</button></div>
        <p class="hukum-muted">${hukumEscape(result.data.deskripsi || 'Tidak ada deskripsi.')}</p>
        <div class="hukum-grid"><div class="hukum-stat"><strong>${(bab.data || []).length}</strong><span>BAB</span></div>
        <div class="hukum-stat"><strong>${(workspaces.data || []).filter(item => ['aktif','diajukan'].includes(item.status)).length}</strong><span>Workspace aktif</span></div>
        <div class="hukum-stat"><strong>${(commits.data || []).filter(item => item.status === 'aktif').length}</strong><span>Commit aktif</span></div></div>
        <h3>Struktur BAB</h3><ul>${babRows}</ul>`;
    document.getElementById('statWorkspaces').textContent = (workspaces.data || []).filter(item => ['aktif','diajukan'].includes(item.status)).length;
}
async function hukumLoadStaging() {
    const result = await hukumRequest('staging.php');
    const rows = (result.data || []).filter(item => item.status === 'menunggu_review');
    document.getElementById('statStaging').textContent = rows.length;
}
async function hukumReview(id, decision) {
    const note = decision === 'reject' ? window.prompt('Alasan penolakan wajib diisi:') : '';
    if (decision === 'reject' && !note) return;
    try { await hukumRequest('review.php', {method:'POST', body:JSON.stringify({staging_id:id, decision, note})}); hukumNotice('Status staging diperbarui.'); hukumLoadAll(); }
    catch (error) { hukumNotice(error.message, 'error'); }
}
async function hukumLoadNotifications() {
    const result = await hukumRequest('notifications.php');
    const rows = result.data || []; document.getElementById('statNotifications').textContent = rows.length;
    document.getElementById('notificationsBody').innerHTML = rows.length ? rows.map(item => `<tr><td>${hukumEscape(item.anak_dokumen)} / Pasal ${hukumEscape(item.anak_nomor)}</td><td>${hukumEscape(item.induk_dokumen)} / Pasal ${hukumEscape(item.induk_nomor)}</td><td><span class="hukum-badge">${hukumEscape(item.status)}</span></td>
        <td>${hukumCanReview ? `<button class="hukum-btn" type="button" onclick="hukumResolve(${Number(item.id)}, 'align')">Selaraskan</button> <button class="hukum-btn danger" type="button" onclick="hukumResolve(${Number(item.id)}, 'ignore')">Abaikan</button>` : '<span class="hukum-muted">Read-only</span>'}</td></tr>`).join('')
        : '<tr><td colspan="4" class="hukum-empty">Tidak ada notifikasi.</td></tr>';
}
async function hukumResolve(id, decision) {
    const note = decision === 'ignore' ? window.prompt('Alasan mengabaikan wajib diisi:') : '';
    if (decision === 'ignore' && !note) return;
    try { await hukumRequest('notifications.php', {method:'POST', body:JSON.stringify({id, decision, note})}); hukumNotice('Notifikasi diperbarui.'); hukumLoadNotifications(); }
    catch (error) { hukumNotice(error.message, 'error'); }
}
async function hukumLoadAll() { try { await Promise.all([hukumLoadDocuments(), hukumLoadStaging(), hukumLoadNotifications()]); } catch (error) { hukumNotice(error.message, 'error'); } }
document.querySelectorAll('.hukum-tab').forEach(tab => tab.addEventListener('click', () => {
    document.querySelectorAll('.hukum-tab').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.hukum-panel').forEach(item => item.classList.remove('active'));
    tab.classList.add('active'); document.getElementById('panel-' + tab.dataset.panel).classList.add('active');
}));
document.getElementById('documentSearch').addEventListener('input', hukumRenderDocuments);
const createForm = document.getElementById('createDocumentForm');
if (createForm) createForm.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    try { await hukumRequest('documents.php', {method:'POST', body:JSON.stringify(data)}); form.reset(); hukumToggleCreate(false); hukumNotice('Dokumen draft berhasil dibuat.'); hukumLoadDocuments(); }
    catch (error) { hukumNotice(error.message, 'error'); }
});
hukumLoadAll();
</script>
<?php require_once __DIR__ . '/core/footer.php'; ?>

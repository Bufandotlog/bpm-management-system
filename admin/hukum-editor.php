<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
$defaultDocId = (int) ($_GET['dokumen_id'] ?? 0);
$documents = dbFetchAll(
    'SELECT id, judul, slug, status, periode_id FROM hukum_dokumen ORDER BY updated_at DESC, id DESC'
);
$periods = dbFetchAll(
    'SELECT id, nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan ORDER BY tahun_mulai DESC, id DESC'
);
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=1">

<div class="hukum-shell">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-pen-ruler"></i> Editor Draft Hukum</h1>
            <p>Drafting dokumen hukum, struktur BAB/Pasal, dan versi konten dengan mode kerja aktif.</p>
        </div>
        <button class="hukum-btn" type="button" onclick="hukumRefreshAll()"><i class="fas fa-sync"></i> Refresh</button>
    </div>

    <div id="hukumNotice" class="hukum-notice"></div>

    <div class="hukum-card">
        <div class="hukum-toolbar">
            <h2>Dokumen & Workspace</h2>
            <div class="hukum-actions">
                <?php if (hukum_has_permission('hukum.document.create')): ?>
                    <button class="hukum-btn gold" type="button" id="showCreateDocBtn">Buat Dokumen</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="hukum-form-grid">
            <div class="form-group">
                <label for="documentSelect">Dokumen</label>
                <select id="documentSelect">
                    <option value="">Pilih dokumen</option>
                    <?php foreach ($documents as $doc): ?>
                        <option value="<?php echo (int) $doc['id']; ?>" <?php echo $defaultDocId === (int) $doc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['judul'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Workspace</label>
                <div id="workspaceBadge" class="hukum-badge">Belum dipilih</div>
            </div>
            <div class="form-group">
                <label>Draft Count</label>
                <div id="draftCountBadge" class="hukum-badge">0</div>
            </div>
            <div class="form-group">
                <label>Version Count</label>
                <div id="versionCountBadge" class="hukum-badge">0</div>
            </div>
        </div>
        <div class="hukum-actions" style="margin-top: 12px;">
            <button class="hukum-btn" type="button" id="createWorkspaceBtn">Buat Workspace</button>
            <button class="hukum-btn" type="button" id="submitStagingBtn">Submit ke Review</button>
            <button class="hukum-btn danger" type="button" id="withdrawWorkspaceBtn">Withdraw</button>
        </div>
    </div>

    <?php if (hukum_has_permission('hukum.document.create')): ?>
    <div class="hukum-card" id="createDocumentCard" hidden>
        <h2>Dokumen Hukum Baru</h2>
        <form id="createDocumentForm" class="hukum-form-grid">
            <div class="form-group"><label for="docTitle">Judul</label><input id="docTitle" name="judul" required></div>
            <div class="form-group"><label for="docSlug">Slug</label><input id="docSlug" name="slug" required></div>
            <div class="form-group"><label for="docJenis">Jenis</label><select id="docJenis" name="jenis"><option>AD</option><option>ART</option><option>GBHO</option><option>GBMO</option><option>PERATURAN</option><option>KEPUTUSAN</option></select></div>
            <div class="form-group"><label for="docLingkup">Lingkup</label><select id="docLingkup" name="lingkup"><option value="induk">Induk</option><option>BEM</option><option>BPM</option><option>UKM</option></select></div>
            <div class="form-group"><label for="docOrmawa">Nama Ormawa</label><input id="docOrmawa" name="nama_ormawa"></div>
            <div class="form-group"><label for="docPeriode">Periode</label><select id="docPeriode" name="periode_id">
                <option value="">Pilih periode</option>
                <?php foreach ($periods as $period): ?>
                    <option value="<?php echo (int) $period['id']; ?>"><?php echo htmlspecialchars(($period['nama'] ?? 'Periode') . ' (' . ($period['tahun_mulai'] ?? '') . '/' . ($period['tahun_selesai'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-group full"><label for="docDescription">Deskripsi</label><textarea id="docDescription" name="deskripsi"></textarea></div>
            <div class="hukum-actions full">
                <button class="hukum-btn gold" type="submit">Simpan</button>
                <button class="hukum-btn" type="button" onclick="document.getElementById('createDocumentCard').hidden = true">Batal</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="hukum-grid" style="margin-top: 18px;">
        <div class="hukum-card">
            <h2>Struktur Dokumen</h2>
            <form id="babForm" class="hukum-form-grid">
                <div class="form-group"><label for="babNomor">Nomor BAB</label><input id="babNomor" name="nomor_label" required></div>
                <div class="form-group"><label for="babJudul">Judul BAB</label><input id="babJudul" name="judul_bab" required></div>
                <div class="form-group"><label for="babBagian">Bagian Label</label><input id="babBagian" name="bagian_label"></div>
                <div class="form-group"><label for="babUrutan">Urutan</label><input id="babUrutan" name="urutan" type="number" min="1" required></div>
                <div class="hukum-actions full">
                    <button class="hukum-btn gold" type="submit">Tambah BAB</button>
                </div>
            </form>
        </div>

        <div class="hukum-card">
            <h2>Pasal</h2>
            <form id="pasalForm" class="hukum-form-grid">
                <div class="form-group"><label for="pasalBabId">BAB</label><select id="pasalBabId" name="bab_id"></select></div>
                <div class="form-group"><label for="pasalNomor">Nomor Pasal</label><input id="pasalNomor" name="nomor_label" required></div>
                <div class="form-group"><label for="pasalJudul">Judul Pasal</label><input id="pasalJudul" name="judul_pasal"></div>
                <div class="form-group"><label for="pasalUrutan">Urutan</label><input id="pasalUrutan" name="urutan" type="number" min="1" required></div>
                <div class="hukum-actions full">
                    <button class="hukum-btn gold" type="submit">Tambah Pasal</button>
                </div>
            </form>
        </div>
    </div>

    <div class="hukum-card" style="margin-top: 18px;">
        <div class="hukum-toolbar">
            <h2>Content Editor</h2>
            <span id="editorState" class="hukum-badge">Ready</span>
        </div>
        <form id="draftForm" class="hukum-form-grid">
            <div class="form-group full">
                <label for="pasalSelect">Pasal</label>
                <select id="pasalSelect"></select>
            </div>
            <div class="form-group full">
                <label for="draftContent">Isi konten (JSON terstruktur)</label>
                <textarea id="draftContent" rows="14" spellcheck="false" placeholder='{"teks_utama":"...","ayat":[...]}'></textarea>
            </div>
            <div class="hukum-actions full">
                <button class="hukum-btn gold" type="submit">Simpan Draft</button>
            </div>
        </form>
    </div>

    <div class="hukum-card" style="margin-top: 18px;">
        <h2>Version History</h2>
        <div class="hukum-table-wrap">
            <table class="hukum-table">
                <thead>
                    <tr><th>Versi</th><th>Status</th><th>Parent</th><th>Hash</th><th>Validasi</th><th>Updated</th></tr>
                </thead>
                <tbody id="versionBody">
                    <tr><td colspan="6" class="hukum-empty">Belum ada versi.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const hukumPeriods = <?php echo json_encode($periods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const state = {
    documents: [],
    selectedDocumentId: <?php echo $defaultDocId > 0 ? (int) $defaultDocId : 0; ?>,
    workspace: null,
    babs: [],
    pasals: [],
    versions: [],
    latestVersionId: null,
    latestUpdatedAt: null,
    activeWorkspaceStatus: null
};

function hukumEscape(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
}

function hukumNotice(message, type = 'success') {
    const node = document.getElementById('hukumNotice');
    node.textContent = message;
    node.className = 'hukum-notice show ' + type;
    window.setTimeout(() => { node.className = 'hukum-notice'; }, 4500);
}

async function hukumRequest(endpoint, options = {}) {
    const response = await fetch(hukumBase + endpoint, {
        ...options,
        headers: {Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-Token': hukumCsrf, ...(options.headers || {})}
    });
    const body = await response.json().catch(() => ({message: 'Respons server tidak valid.'}));
    if (!response.ok || body.success === false) {
        throw new Error(body.message || 'Permintaan gagal.');
    }
    return body;
}

function setEditorState(label, status = 'info') {
    const node = document.getElementById('editorState');
    node.textContent = label;
    node.className = 'hukum-badge';
    if (status === 'warning') node.style.background = 'rgba(245,158,11,0.18)';
    else if (status === 'error') node.style.background = 'rgba(239,68,68,0.18)';
    else node.style.background = 'rgba(201,162,39,.16)';
}

function getSelectedDocumentId() {
    return Number(document.getElementById('documentSelect').value || state.selectedDocumentId || 0);
}

function renderDocumentOptions() {
    const select = document.getElementById('documentSelect');
    select.innerHTML = '<option value="">Pilih dokumen</option>' + state.documents.map(item => `<option value="${Number(item.id)}">${item.judul}</option>`).join('');
    if (state.selectedDocumentId) {
        select.value = String(state.selectedDocumentId);
    }
}

function renderBabOptions() {
    const select = document.getElementById('pasalBabId');
    select.innerHTML = '<option value="">Tanpa BAB</option>' + state.babs.map(item => `<option value="${Number(item.id)}">${item.nomor_label} — ${item.judul_bab}</option>`).join('');
}

function renderPasalOptions() {
    const select = document.getElementById('pasalSelect');
    select.innerHTML = state.pasals.map(item => `<option value="${Number(item.id)}">${item.nomor_label} — ${item.judul_pasal || 'Tanpa judul'}</option>`).join('');
    if (state.pasals.length && !select.value) {
        select.value = String(state.pasals[0].id);
    }
    if (state.pasals.length === 0) {
        document.getElementById('draftContent').value = '';
    }
}

function renderVersionTable() {
    const body = document.getElementById('versionBody');
    if (!state.versions.length) {
        body.innerHTML = '<tr><td colspan="6" class="hukum-empty">Belum ada versi.</td></tr>';
        return;
    }
    body.innerHTML = state.versions.map(item => `
        <tr>
            <td>${Number(item.id)}</td>
            <td><span class="hukum-badge">${hukumEscape(item.status || 'draft')}</span></td>
            <td>${item.dibuat_dari_versi_id ? Number(item.dibuat_dari_versi_id) : '-'}</td>
            <td>${hukumEscape((item.hash_konten || '').slice(0, 12))}</td>
            <td>${hukumEscape(item.status === 'draft' ? 'valid' : 'final')}</td>
            <td>${hukumEscape(item.updated_at || item.created_at || '-')}</td>
        </tr>
    `).join('');
}

async function hukumLoadDocuments() {
    const result = await hukumRequest('documents.php');
    state.documents = result.data || [];
    renderDocumentOptions();
}

async function hukumLoadDocumentData(docId) {
    if (!docId) {
        state.workspace = null;
        state.babs = [];
        state.pasals = [];
        state.versions = [];
        document.getElementById('workspaceBadge').textContent = 'Belum dipilih';
        document.getElementById('draftCountBadge').textContent = '0';
        document.getElementById('versionCountBadge').textContent = '0';
        renderBabOptions();
        renderPasalOptions();
        renderVersionTable();
        return;
    }

    const [workspaces, babs, pasals] = await Promise.all([
        hukumRequest('workspaces.php?dokumen_id=' + docId),
        hukumRequest('bab.php?dokumen_id=' + docId),
        hukumRequest('pasal.php?dokumen_id=' + docId)
    ]);

    state.workspaces = workspaces.data || [];
    state.workspace = (state.workspaces || []).find(item => item.status === 'aktif') || (state.workspaces || []).find(item => item.status === 'diajukan') || null;
    state.babs = babs.data || [];
    state.pasals = pasals.data || [];
    state.activeWorkspaceStatus = state.workspace ? state.workspace.status : 'none';
    document.getElementById('workspaceBadge').textContent = state.workspace ? `${state.workspace.status} #${state.workspace.id}` : 'Belum ada workspace';
    document.getElementById('draftCountBadge').textContent = String(state.pasals.length || 0);

    const selectedPasalId = Number(document.getElementById('pasalSelect').value || 0);
    if (state.pasals.length) {
        const nextPasal = state.pasals.find(item => Number(item.id) === selectedPasalId) || state.pasals[0];
        if (nextPasal) {
            await hukumLoadVersions(nextPasal.id);
        }
    } else {
        state.versions = [];
        renderVersionTable();
    }

    renderBabOptions();
    renderPasalOptions();
    document.getElementById('versionCountBadge').textContent = String(state.versions.length || 0);

    const canEdit = state.workspace && state.workspace.status === 'aktif';
    setEditorState(canEdit ? 'Workspace aktif' : 'Read-only', canEdit ? 'info' : 'warning');
    document.getElementById('draftContent').readOnly = !canEdit;
}

async function hukumLoadVersions(pasalId) {
    if (!pasalId) {
        state.versions = [];
        renderVersionTable();
        return;
    }
    const result = await hukumRequest('pasal.php?pasal_id=' + pasalId);
    state.versions = result.data || [];
    state.latestVersionId = state.versions.length ? Number(state.versions[0].id) : null;
    state.latestUpdatedAt = state.versions.length ? (state.versions[0].updated_at || state.versions[0].created_at || null) : null;
    renderVersionTable();
    document.getElementById('versionCountBadge').textContent = String(state.versions.length || 0);

    const pasal = state.pasals.find(item => Number(item.id) === Number(pasalId));
    if (pasal && state.versions.length) {
        const latest = state.versions[0];
        try {
            const content = typeof latest.isi === 'string' ? JSON.parse(latest.isi) : latest.isi;
            document.getElementById('draftContent').value = JSON.stringify(content, null, 2);
        } catch (error) {
            document.getElementById('draftContent').value = latest.isi || '';
        }
    }
}

async function hukumRefreshAll() {
    await hukumLoadDocuments();
    const docId = getSelectedDocumentId();
    if (docId) {
        await hukumLoadDocumentData(docId);
    }
}

async function createDocument(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    const result = await hukumRequest('documents.php', {method: 'POST', body: JSON.stringify(data)});
    document.getElementById('createDocumentCard').hidden = true;
    form.reset();
    hukumNotice('Dokumen berhasil dibuat.');
    await hukumLoadDocuments();
    if (result.id) {
        state.selectedDocumentId = Number(result.id);
        renderDocumentOptions();
        await hukumLoadDocumentData(state.selectedDocumentId);
    }
}

async function createWorkspace() {
    const docId = getSelectedDocumentId();
    if (!docId) {
        hukumNotice('Pilih dokumen terlebih dahulu.', 'error');
        return;
    }
    const result = await hukumRequest('workspaces.php', {
        method: 'POST',
        body: JSON.stringify({
            dokumen_id: docId,
            judul_perubahan: 'Draft konten hukum baru',
            tujuan: 'Kerja drafting dokumen di admin editor'
        })
    });
    hukumNotice('Workspace dibuat.');
    state.selectedDocumentId = docId;
    await hukumLoadDocumentData(docId);
}

async function submitWorkspace() {
    const docId = getSelectedDocumentId();
    if (!docId) {
        hukumNotice('Pilih dokumen terlebih dahulu.', 'error');
        return;
    }
    if (!state.workspace || state.workspace.status !== 'aktif') {
        hukumNotice('Workspace aktif belum tersedia.', 'error');
        return;
    }
    const versionIds = state.versions.filter(item => item.status === 'draft').map(item => Number(item.id));
    if (!versionIds.length) {
        hukumNotice('Belum ada draft yang bisa dikirim untuk review.', 'error');
        return;
    }
    const result = await hukumRequest('staging.php', {
        method: 'POST',
        body: JSON.stringify({workspace_id: Number(state.workspace.id), pasal_versi_ids: versionIds})
    });
    hukumNotice('Draft dikirim ke review.');
    await hukumLoadDocumentData(docId);
}

async function withdrawWorkspace() {
    const docId = getSelectedDocumentId();
    if (!docId || !state.workspace) {
        hukumNotice('Workspace tidak valid untuk ditarik.', 'error');
        return;
    }
    await hukumRequest('workspaces.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'withdraw',
            workspace_id: Number(state.workspace.id),
            reason: 'Withdraw oleh Komisi I dari editor draft'
        })
    });
    hukumNotice('Workspace ditarik kembali ke status aktif.');
    await hukumLoadDocumentData(docId);
}

async function createBab(event) {
    event.preventDefault();
    const docId = getSelectedDocumentId();
    if (!docId) {
        hukumNotice('Pilih dokumen terlebih dahulu.', 'error');
        return;
    }
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    data.dokumen_id = docId;
    await hukumRequest('bab.php', {method: 'POST', body: JSON.stringify(data)});
    form.reset();
    hukumNotice('BAB berhasil ditambahkan.');
    await hukumLoadDocumentData(docId);
}

async function createPasal(event) {
    event.preventDefault();
    const docId = getSelectedDocumentId();
    if (!docId) {
        hukumNotice('Pilih dokumen terlebih dahulu.', 'error');
        return;
    }
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    data.dokumen_id = docId;
    await hukumRequest('pasal.php', {method: 'POST', body: JSON.stringify(data)});
    form.reset();
    hukumNotice('Pasal berhasil ditambahkan.');
    await hukumLoadDocumentData(docId);
}

async function saveDraft(event) {
    event.preventDefault();
    const docId = getSelectedDocumentId();
    if (!docId) {
        hukumNotice('Pilih dokumen terlebih dahulu.', 'error');
        return;
    }
    const pasalId = Number(document.getElementById('pasalSelect').value || 0);
    if (!pasalId) {
        hukumNotice('Pilih pasal yang akan diedit.', 'error');
        return;
    }
    if (!state.workspace || state.workspace.status !== 'aktif') {
        hukumNotice('Hanya workspace aktif yang dapat menyimpan draft.', 'error');
        return;
    }
    let payload;
    try {
        payload = JSON.parse(document.getElementById('draftContent').value || '{}');
    } catch (error) {
        hukumNotice('Isi draft harus valid JSON.', 'error');
        return;
    }
    const raw = {
        pasal_id: pasalId,
        workspace_id: Number(state.workspace.id),
        isi: payload,
        expected_version: state.latestVersionId,
        expected_updated_at: state.latestUpdatedAt
    };
    const result = await hukumRequest('pasal.php', {method:'POST', body: JSON.stringify(raw)});
    state.latestVersionId = Number(result.latest_version_id || result.id || state.latestVersionId || 0);
    state.latestUpdatedAt = new Date().toISOString();
    hukumNotice('Draft berhasil disimpan.');
    await hukumLoadDocumentData(docId);
    const currentPasal = document.getElementById('pasalSelect').value;
    if (currentPasal) {
        await hukumLoadVersions(Number(currentPasal));
    }
}

document.getElementById('documentSelect').addEventListener('change', async event => {
    const docId = Number(event.target.value || 0);
    state.selectedDocumentId = docId;
    if (docId) {
        await hukumLoadDocumentData(docId);
    }
});

document.getElementById('pasalSelect').addEventListener('change', async event => {
    const pasalId = Number(event.target.value || 0);
    if (pasalId) {
        await hukumLoadVersions(pasalId);
    }
});

document.getElementById('createDocumentForm').addEventListener('submit', createDocument);
document.getElementById('babForm').addEventListener('submit', createBab);
document.getElementById('pasalForm').addEventListener('submit', createPasal);
document.getElementById('draftForm').addEventListener('submit', saveDraft);
document.getElementById('createWorkspaceBtn').addEventListener('click', createWorkspace);
document.getElementById('submitStagingBtn').addEventListener('click', submitWorkspace);
document.getElementById('withdrawWorkspaceBtn').addEventListener('click', withdrawWorkspace);
document.getElementById('showCreateDocBtn').addEventListener('click', () => {
    document.getElementById('createDocumentCard').hidden = false;
});

async function loadPeriods() {
    const select = document.getElementById('docPeriode');
    if (!select) return;
    const periods = Array.isArray(hukumPeriods) ? hukumPeriods : [];
    select.innerHTML = '<option value="">Pilih periode</option>' + periods.map(item => `<option value="${Number(item.id)}">${hukumEscape(item.nama || 'Periode')} (${hukumEscape(item.tahun_mulai || '')}/${hukumEscape(item.tahun_selesai || '')})</option>`).join('');
    if (periods.length) {
        select.value = String(periods[0].id);
    }
}

(async function init() {
    await hukumLoadDocuments();
    await loadPeriods();
    const docId = state.selectedDocumentId || Number(document.getElementById('documentSelect').value || 0);
    if (docId) {
        await hukumLoadDocumentData(docId);
    }
})();
</script>

<?php require_once __DIR__ . '/core/footer.php'; ?>

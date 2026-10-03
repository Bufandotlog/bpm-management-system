<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
$defaultDocId = (int) ($_GET['dokumen_id'] ?? 0);
$actor = hukum_current_user();
$hukumCssVersion = file_exists(__DIR__ . '/css/hukum.css') ? filemtime(__DIR__ . '/css/hukum.css') : '1';
$documentSql =
    'SELECT id, judul, slug, jenis, status, periode_id FROM hukum_dokumen
     WHERE status IN (\'draft\', \'aktif\')';
$documentParams = [];
if (!$actor['can_access_all'] && $actor['role'] !== 'superadmin') {
    $documentSql .= ' AND periode_id = ?';
    $documentParams[] = $actor['periode_id'];
}
$documentSql .= ' ORDER BY updated_at DESC, id DESC';
$documents = dbFetchAll($documentSql, $documentParams);
$periods = dbFetchAll(
    'SELECT id, nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan ORDER BY tahun_mulai DESC, id DESC'
);
$submitAuth = dbFetchOne('SELECT totp_enabled FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [$actor['id']], 'i');
$submitRequires2fa = (bool) ($submitAuth['totp_enabled'] ?? false);
$canEditHukum = hukum_has_permission('hukum.document.update');
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=<?php echo (int) $hukumCssVersion; ?>">

<div class="hukum-shell">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-pen-ruler"></i> Editor Dokumen Hukum</h1>
            <p>Buat dokumen dengan formulir sederhana. Format teknis disimpan otomatis oleh sistem.</p>
        </div>
        <a class="hukum-btn" href="<?php echo baseUrl('admin/hukum-dashboard.php'); ?>"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
    <div id="hukumNotice" class="hukum-notice"></div>

    <div class="hukum-card hukum-editor-context">
        <div class="hukum-toolbar">
            <div>
                <span class="hukum-kicker">Dokumen aktif</span>
                <h2 id="activeDocumentTitle">Pilih atau buat dokumen</h2>
            </div>
            <div class="hukum-actions">
                <select id="documentSelect" aria-label="Pilih dokumen">
                    <option value="">Pilih dokumen</option>
                    <?php foreach ($documents as $document): ?>
                        <option value="<?php echo (int) $document['id']; ?>" <?php echo $defaultDocId === (int) $document['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($document['judul'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="hukum-progress" aria-label="Langkah editor">
            <button class="hukum-step active" type="button" data-step="1"><b>1</b><span>Informasi</span></button>
            <button class="hukum-step" type="button" data-step="2" disabled><b>2</b><span>Struktur isi</span></button>
            <button class="hukum-step" type="button" data-step="3" disabled><b>3</b><span>Pratinjau & kirim</span></button>
        </div>
    </div>

    <?php if (hukum_has_permission('hukum.document.create')): ?>
    <section class="hukum-card hukum-step-panel active" id="step-1">
        <div class="hukum-section-heading">
            <div><span class="hukum-kicker">Langkah 1</span><h2>Informasi dokumen</h2></div>
            <div class="hukum-actions"><span class="hukum-badge" id="workspaceBadge">Belum ada workspace</span><button class="hukum-btn subtle" id="createWorkspaceBtn" type="button" hidden>Buat workspace</button></div>
        </div>
        <form id="createDocumentForm" class="hukum-form-grid">
            <div class="form-group"><label for="docTitle">Judul dokumen <span class="required">*</span></label><input id="docTitle" name="judul" required placeholder="Contoh: Anggaran Dasar BPM"></div>
            <div class="form-group"><label for="docSlug">Identitas singkat <span class="required">*</span></label><input id="docSlug" name="slug" required placeholder="anggaran-dasar-bpm"></div>
            <div class="form-group"><label for="docJenis">Jenis <span class="required">*</span></label><select id="docJenis" name="jenis"><option>AD</option><option>ART</option><option>GBHO</option><option>GBMO</option><option>PERATURAN</option><option>KEPUTUSAN</option></select></div>
            <div class="form-group"><label for="docLingkup">Lingkup <span class="required">*</span></label><select id="docLingkup" name="lingkup"><option value="induk">Induk</option><option value="BEM">BEM</option><option value="BPM">BPM</option><option value="UKM">UKM</option></select></div>
            <div class="form-group"><label for="docOrmawa">Nama organisasi</label><input id="docOrmawa" name="nama_ormawa" placeholder="Wajib untuk lingkup BEM/BPM/UKM"></div>
            <div class="form-group"><label for="docPeriode">Periode <span class="required">*</span></label><select id="docPeriode" name="periode_id" required><option value="">Pilih periode</option><?php foreach ($periods as $period): ?><option value="<?php echo (int) $period['id']; ?>"><?php echo htmlspecialchars($period['nama'] . ' (' . $period['tahun_mulai'] . '/' . $period['tahun_selesai'] . ')', ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="form-group full"><label for="docOpening">Mukadimah / pembukaan</label><textarea id="docOpening" rows="5" placeholder="Tuliskan pembukaan dokumen dengan bahasa biasa."></textarea></div>
            <div class="form-group full"><label for="docDescription">Deskripsi singkat</label><textarea id="docDescription" name="deskripsi" rows="3"></textarea></div>
            <div class="hukum-actions full"><button class="hukum-btn gold" id="createDocumentSubmit" type="submit">Buat dokumen & workspace</button><button class="hukum-btn" id="continueToStructure" type="button" data-next="2" hidden>Lanjut ke struktur <i class="fas fa-arrow-right"></i></button></div>
        </form>
        <div id="step1Existing" class="hukum-empty" hidden>Informasi dokumen terisi otomatis dan hanya dapat dilihat di sini. Untuk membuat dokumen baru, pilih <strong>Pilih dokumen</strong> pada daftar di atas.</div>
    </section>
    <?php else: ?>
    <section class="hukum-card hukum-step-panel active" id="step-1"><div class="hukum-empty">Anda dapat melihat dokumen, tetapi tidak memiliki izin membuat atau mengubah draft.</div></section>
    <?php endif; ?>

    <section class="hukum-card hukum-step-panel" id="step-2">
        <div class="hukum-section-heading">
            <div><span class="hukum-kicker">Langkah 2</span><h2>Susun isi dokumen</h2><p class="hukum-muted">Tambahkan BAB, Pasal, ayat, dan poin seperlunya. Tidak perlu menulis JSON.</p></div>
            <span class="hukum-badge" id="editorState">Belum siap</span>
        </div>
        <div id="structureEmpty" class="hukum-empty">Buat atau pilih dokumen terlebih dahulu.</div>
        <div id="structureEditor" hidden>
            <section class="hukum-deletion-panel">
                <h3>Usulan penghapusan</h3>
                <p class="hukum-muted">Penghapusan belum mengubah dokumen aktif. Perubahan berlaku setelah dua persetujuan dan commit.</p>
                <div id="deletionRequests"><p class="hukum-muted">Belum ada usulan penghapusan.</p></div>
            </section>
            <div id="babList" class="hukum-structure-list"></div>
            <button class="hukum-btn outline-add" type="button" id="addBabBtn"><i class="fas fa-plus"></i> Tambah BAB</button>
        </div>
        <div class="hukum-actions wizard-actions"><button class="hukum-btn" type="button" data-prev="1"><i class="fas fa-arrow-left"></i> Kembali</button><button class="hukum-btn gold" type="button" data-next="3">Lihat pratinjau <i class="fas fa-arrow-right"></i></button></div>
    </section>

    <section class="hukum-card hukum-step-panel" id="step-3">
        <div class="hukum-section-heading"><div><span class="hukum-kicker">Langkah 3</span><h2>Periksa dan simpan</h2><p class="hukum-muted">Draft menyimpan pekerjaan. Pengajuan membuat snapshot lengkap untuk proses review.</p></div></div>
        <div id="preview" class="hukum-preview"><div class="hukum-empty">Belum ada isi untuk dipratinjau.</div></div>
        <div class="hukum-validation" id="validation"></div>
        <p class="hukum-muted" id="draftSaveStatus" role="status">Simpan draft untuk melanjutkan ke pengajuan review.</p>
        <div class="hukum-actions wizard-actions">
            <button class="hukum-btn" type="button" data-prev="2"><i class="fas fa-arrow-left"></i> Kembali edit</button>
            <button class="hukum-btn" type="button" id="saveDraftBtn"><i class="fas fa-floppy-disk"></i> Simpan Draft</button>
            <button class="hukum-btn gold" type="button" id="submitStagingBtn" hidden><i class="fas fa-paper-plane"></i> Ajukan untuk Review</button>
        </div>
    </section>
</div>

<dialog id="submitWarningDialog" class="hukum-commit-dialog" aria-labelledby="submitWarningTitle" aria-describedby="submitWarningText">
    <div class="hukum-commit-dialog-heading">
        <span class="hukum-kicker">Sebelum mengajukan</span>
        <h2 id="submitWarningTitle">Kirim workspace ke staging?</h2>
        <div id="submitWarningText" class="hukum-submit-warning-copy">
            <p>Setelah dikirim, sistem membuat snapshot staging untuk ditinjau Komisi I dan Admin. Isi yang diajukan menjadi bahan review; workspace tidak dapat diedit selama menunggu review.</p>
            <p>Jika disetujui, staging akan menunggu proses Finalisasi Commit. Perubahan belum menjadi versi aktif/publik sampai commit difinalisasi.</p>
        </div>
    </div>
    <div class="hukum-actions">
        <button id="continueSubmitButton" class="hukum-btn gold" type="button">Tetap lanjutkan</button>
        <button id="cancelSubmitButton" class="hukum-btn" type="button">Batalkan</button>
    </div>
</dialog>

<dialog id="submitCredentialsDialog" class="hukum-commit-dialog" aria-labelledby="submitCredentialsTitle" aria-describedby="submitCredentialsText">
    <form id="submitCredentialsForm">
        <div class="hukum-commit-dialog-heading">
            <span class="hukum-kicker">Konfirmasi identitas</span>
            <h2 id="submitCredentialsTitle">Konfirmasi pengajuan</h2>
            <p id="submitCredentialsText" class="hukum-muted">Masukkan kata sandi akun Anda untuk mengirim workspace ke staging.</p>
        </div>
        <label for="submitPassword">Kata sandi akun Anda</label>
        <input id="submitPassword" name="password" type="password" autocomplete="current-password" required>
        <?php if ($submitRequires2fa): ?>
            <label for="submitTotpCode">Kode autentikator (2FA)</label>
            <input id="submitTotpCode" name="totp_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
        <?php endif; ?>
        <p id="submitCredentialsError" class="hukum-commit-dialog-error" role="alert" hidden></p>
        <div class="hukum-actions">
            <button id="confirmSubmitButton" class="hukum-btn gold" type="submit">Konfirmasi & kirim</button>
            <button id="cancelCredentialsButton" class="hukum-btn" type="button">Batalkan</button>
        </div>
    </form>
</dialog>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const canRequestDeletions = <?php echo hukum_has_permission('hukum.document.update') ? 'true' : 'false'; ?>;
const submitRequires2fa = <?php echo $submitRequires2fa ? 'true' : 'false'; ?>;
const initialDocuments = <?php echo json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const state = { documents: initialDocuments || [], documentId: <?php echo $defaultDocId; ?>, document: null, workspace: null, babs: [], pasals: [], deletions: [], versions: new Map(), saving: false, savedSignature: null, savedVersionIds: [], loadedSignature: null };

function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }
function notice(message, type = 'success') { const node = document.getElementById('hukumNotice'); node.textContent = message; node.className = 'hukum-notice show ' + type; window.setTimeout(() => node.className = 'hukum-notice', 5000); }
async function request(endpoint, options = {}) {
    const response = await fetch(hukumBase + endpoint, {...options, headers: {Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':hukumCsrf,...(options.headers || {})}});
    const body = await response.json().catch(() => ({message:'Respons server tidak valid.'}));
    if (!response.ok || body.success === false) throw new Error(body.message || 'Permintaan gagal.');
    return body;
}
function activeDoc() { return state.documents.find(item => Number(item.id) === Number(state.documentId)); }
function setDocumentFormMode(existing) {
    const form = document.getElementById('createDocumentForm');
    if (!form) return;
    form.querySelectorAll('.form-group input, .form-group select, .form-group textarea').forEach(field => {
        field.disabled = existing;
    });
    document.getElementById('step1Existing').hidden = !existing;
    refreshWorkflowActions();
}
function refreshWorkflowActions() {
    const existing = Boolean(state.documentId);
    const hasWorkspace = Boolean(state.workspace);
    const createDocumentButton = document.getElementById('createDocumentSubmit');
    const createWorkspaceButton = document.getElementById('createWorkspaceBtn');
    const continueButton = document.getElementById('continueToStructure');
    if (createDocumentButton) createDocumentButton.hidden = existing;
    if (createWorkspaceButton) createWorkspaceButton.hidden = !existing || hasWorkspace;
    if (continueButton) continueButton.hidden = !hasWorkspace;
    document.querySelectorAll('.hukum-step[data-step="2"], .hukum-step[data-step="3"]').forEach(button => {
        button.disabled = !existing;
    });
}
function setDocumentFormValues(doc) {
    if (!document.getElementById('docTitle')) return;
    document.getElementById('docTitle').value = doc.judul || '';
    document.getElementById('docSlug').value = doc.slug || '';
    document.getElementById('docJenis').value = doc.jenis || '';
    document.getElementById('docLingkup').value = doc.lingkup || '';
    document.getElementById('docOrmawa').value = doc.nama_ormawa || '';
    document.getElementById('docPeriode').value = String(doc.periode_id || '');
    document.getElementById('docDescription').value = doc.deskripsi || '';

    let opening = doc.mukadimah_legacy || '';
    if (doc.format_mukadimah !== 'legacy' && doc.mukadimah_json) {
        try {
            const content = typeof doc.mukadimah_json === 'string'
                ? JSON.parse(doc.mukadimah_json)
                : doc.mukadimah_json;
            if (typeof content === 'string') opening = content;
            else if (content && typeof content === 'object') {
                opening = content.teks || content.text || content.isi || content.pembukaan || opening;
            }
        } catch (error) {
            opening = doc.mukadimah_legacy || '';
        }
    }
    document.getElementById('docOpening').value = opening;
}
function setStep(number) {
    if (number > 1 && !state.documentId) {
        notice('Pilih atau buat dokumen terlebih dahulu sebelum melanjutkan.', 'error');
        return;
    }
    document.querySelectorAll('.hukum-step-panel').forEach(panel => panel.classList.toggle('active', panel.id === 'step-' + number));
    document.querySelectorAll('.hukum-step').forEach(step => step.classList.toggle('active', Number(step.dataset.step) === number));
    if (number === 3) renderPreview();
}
function formValue(id) { return document.getElementById(id)?.value.trim() || ''; }
function makePoint() {
    const wrapper = document.createElement('div'); wrapper.className = 'hukum-point-row';
    wrapper.innerHTML = '<input class="point-number" placeholder="a" aria-label="Nomor poin"><textarea class="point-text" rows="2" placeholder="Isi poin"></textarea><button class="hukum-icon-btn danger remove-point" type="button" title="Hapus poin"><i class="fas fa-trash"></i></button>';
    wrapper.querySelector('button').onclick = () => wrapper.remove(); return wrapper;
}
function makeAyat() {
    const wrapper = document.createElement('div'); wrapper.className = 'hukum-ayat-card';
    wrapper.innerHTML = '<div class="hukum-inline-heading"><label>Ayat <input class="ayat-number" placeholder="1"></label><button class="hukum-btn subtle add-point" type="button"><i class="fas fa-plus"></i> Poin</button><button class="hukum-icon-btn danger remove-ayat" type="button" title="Hapus ayat"><i class="fas fa-trash"></i></button></div><textarea class="ayat-text" rows="3" placeholder="Tuliskan isi ayat"></textarea><div class="point-list"></div>';
    wrapper.querySelector('.add-point').onclick = () => wrapper.querySelector('.point-list').appendChild(makePoint());
    wrapper.querySelector('.remove-ayat').onclick = () => wrapper.remove(); return wrapper;
}
function nextPasalLabel() {
    const labels = [
        ...state.pasals.map(pasal => pasal.nomor_label || ''),
        ...[...document.querySelectorAll('.pasal-number')].map(field => field.value)
    ];
    const usedNumbers = labels
        .map(label => String(label).trim().match(/^(?:pasal\s*)?(\d+)$/i))
        .filter(Boolean)
        .map(match => Number(match[1]));
    let next = Math.max(0, ...usedNumbers) + 1;
    const used = new Set(labels.map(label => String(label).trim().toLowerCase()));
    while (used.has(`pasal ${next}`.toLowerCase()) || used.has(String(next))) next++;
    return `Pasal ${next}`;
}
function makePasal() {
    const wrapper = document.createElement('article'); wrapper.className = 'hukum-pasal-card';
    wrapper.innerHTML = '<div class="hukum-inline-heading"><div class="hukum-pasal-title"><label>Pasal <input class="pasal-number" placeholder="1"></label><input class="pasal-heading" placeholder="Judul pasal (opsional)"></div><button class="hukum-icon-btn danger remove-pasal" type="button" title="Hapus pasal"><i class="fas fa-trash"></i></button></div><textarea class="pasal-opening" rows="3" placeholder="Isi pembuka pasal (opsional)"></textarea><label class="hukum-field-label">Penjelasan (opsional)<textarea class="pasal-explanation" rows="3" placeholder="Tambahkan penjelasan untuk Pasal ini jika diperlukan."></textarea></label><div class="ayat-list"></div><button class="hukum-btn subtle add-ayat" type="button"><i class="fas fa-plus"></i> Tambah ayat</button>';
    wrapper.querySelector('.pasal-number').value = nextPasalLabel();
    wrapper.querySelector('.remove-pasal').hidden = !canRequestDeletions;
    wrapper.querySelector('.add-ayat').onclick = () => wrapper.querySelector('.ayat-list').appendChild(makeAyat());
    wrapper.querySelector('.remove-pasal').onclick = () => {
        const pasalId = Number(wrapper.dataset.pasalId || 0);
        if (pasalId > 0) requestDeletion('pasal', pasalId, wrapper);
        else { wrapper.remove(); renderPreview(); refreshDraftSaveState(); }
    }; return wrapper;
}
function makeBab() {
    const wrapper = document.createElement('section'); wrapper.className = 'hukum-bab-card';
    wrapper.innerHTML = '<div class="hukum-inline-heading"><div class="hukum-bab-title"><label>BAB <input class="bab-number" placeholder="I"></label><input class="bab-heading" placeholder="Judul BAB"></div><button class="hukum-icon-btn danger remove-bab" type="button" title="Hapus BAB"><i class="fas fa-trash"></i></button></div><div class="pasal-list"></div><button class="hukum-btn subtle add-pasal" type="button"><i class="fas fa-plus"></i> Tambah Pasal</button>';
    wrapper.querySelector('.remove-bab').hidden = !canRequestDeletions;
    wrapper.querySelector('.add-pasal').onclick = () => wrapper.querySelector('.pasal-list').appendChild(makePasal());
    wrapper.querySelector('.remove-bab').onclick = () => {
        const babId = Number(wrapper.dataset.babId || 0);
        if (babId > 0) requestDeletion('bab', babId, wrapper);
        else { wrapper.remove(); renderPreview(); refreshDraftSaveState(); }
    }; return wrapper;
}
function collectStructure() {
    return [...document.querySelectorAll('.hukum-bab-card')].map(bab => ({
        id: bab.dataset.babId ? Number(bab.dataset.babId) : null,
        nomor: bab.querySelector('.bab-number').value.trim(), judul: bab.querySelector('.bab-heading').value.trim(),
        pasal: [...bab.querySelectorAll(':scope > .pasal-list > .hukum-pasal-card')].map(pasal => ({
            id: pasal.dataset.pasalId ? Number(pasal.dataset.pasalId) : null,
            nomor: pasal.querySelector('.pasal-number').value.trim(), judul: pasal.querySelector('.pasal-heading').value.trim(), pembuka: pasal.querySelector('.pasal-opening').value.trim(), penjelasan: pasal.querySelector('.pasal-explanation').value.trim(),
            ayat: [...pasal.querySelectorAll(':scope > .ayat-list > .hukum-ayat-card')].map(ayat => ({
                nomor: ayat.querySelector('.ayat-number').value.trim(), teks: ayat.querySelector('.ayat-text').value.trim(),
                poin: [...ayat.querySelectorAll(':scope > .point-list > .hukum-point-row')].map(point => ({nomor: point.querySelector('.point-number').value.trim(), teks: point.querySelector('.point-text').value.trim()}))
            }))
        }))
    }));
}
function draftSignature() {
    return JSON.stringify({
      structure: collectStructure().map(bab => ({
        nomor: bab.nomor, judul: bab.judul,
        pasal: bab.pasal.map(pasal => ({
        nomor: pasal.nomor, judul: pasal.judul, pembuka: pasal.pembuka, penjelasan: pasal.penjelasan,
            ayat: pasal.ayat.map(ayat => ({
                nomor: ayat.nomor, teks: ayat.teks,
                poin: ayat.poin.map(point => ({nomor: point.nomor, teks: point.teks}))
            }))
        }))
      })),
      deletions: state.deletions.map(deletion => [deletion.id, deletion.entity_type, deletion.entity_id, deletion.reason])
    });
}
function refreshDraftSaveState() {
    const button = document.getElementById('submitStagingBtn');
    const saveButton = document.getElementById('saveDraftBtn');
    const status = document.getElementById('draftSaveStatus');
    if (!button || !status) return;
    const canEdit = <?php echo $canEditHukum ? 'true' : 'false'; ?>
        && Boolean(state.documentId && state.workspace?.status === 'aktif');
    const hasSavedSubmission = Boolean(state.savedSignature)
        && state.savedSignature === draftSignature()
        && (state.savedVersionIds.length > 0 || state.deletions.length > 0)
        && canEdit;
    button.hidden = !hasSavedSubmission;
    button.disabled = state.saving;
    if (saveButton) {
        saveButton.hidden = !canEdit || hasSavedSubmission;
        saveButton.disabled = state.saving;
    }
    if (state.saving) {
        status.textContent = 'Menyimpan perubahan...';
    } else if (!state.documentId) {
        status.textContent = 'Pilih dokumen untuk mulai menyusun draft.';
    } else if (state.workspace?.status === 'diajukan') {
        status.textContent = 'Draft sedang menunggu review.';
    } else if (state.workspace?.status === 'siap_commit') {
        status.textContent = 'Workspace telah disetujui dan menunggu commit; dokumen hanya dapat ditinjau.';
    } else if (!canEdit) {
        status.textContent = 'Workspace aktif diperlukan untuk menyimpan atau mengajukan draft.';
    } else if (hasSavedSubmission) {
        status.textContent = 'Draft tersimpan. Ajukan untuk review jika sudah siap.';
    } else {
        status.textContent = 'Simpan draft untuk melanjutkan ke pengajuan review.';
    }
}
function setStructureReadOnly(readOnly) {
    document.querySelectorAll('#babList input, #babList textarea, #babList button, #addBabBtn, #deletionRequests [data-cancel-deletion]')
        .forEach(control => { control.disabled = readOnly; });
}
function validateStructure() {
    const errors = []; const structure = collectStructure(); const seenPasalLabels = new Set();
    if (!state.documentId) errors.push('Pilih atau buat dokumen terlebih dahulu.');
    if (!state.workspace || !['aktif', 'diajukan', 'siap_commit'].includes(state.workspace.status)) errors.push('Workspace belum tersedia untuk dokumen ini.');
    if (!structure.length && state.deletions.length === 0) errors.push('Tambahkan minimal satu BAB atau catat penghapusan.');
    structure.forEach((bab, bi) => {
        if (!bab.nomor || !bab.judul) errors.push(`BAB ${bi + 1} harus memiliki nomor dan judul.`);
        if (!bab.pasal.length && !(bab.id && state.deletions.some(deletion => deletion.entity_type === 'bab' && Number(deletion.entity_id) === bab.id))) errors.push(`BAB ${bab.nomor || bi + 1} harus memiliki minimal satu Pasal.`);
        bab.pasal.forEach((pasal, pi) => {
            if (!pasal.nomor) errors.push(`Pasal pada BAB ${bab.nomor || bi + 1} harus memiliki nomor.`);
            const normalizedLabel = pasal.nomor.trim().replace(/^pasal\s*/i, '').toLowerCase();
            if (normalizedLabel) {
                const duplicateInDocument = state.pasals.some(existing =>
                    Number(existing.id) !== Number(pasal.id || 0)
                    && String(existing.nomor_label || '').trim().replace(/^pasal\s*/i, '').toLowerCase() === normalizedLabel
                );
                if (seenPasalLabels.has(normalizedLabel) || duplicateInDocument) {
                    errors.push(`Nomor Pasal "${pasal.nomor}" sudah digunakan. Nomor Pasal harus unik dalam satu dokumen.`);
                }
                seenPasalLabels.add(normalizedLabel);
            }
            if (!pasal.pembuka && !pasal.ayat.length) errors.push(`Pasal ${pasal.nomor || pi + 1} belum memiliki isi.`);
            pasal.ayat.forEach((ayat, ai) => { if (!ayat.teks && !ayat.poin.length) errors.push(`Ayat ${ayat.nomor || ai + 1} pada Pasal ${pasal.nomor || pi + 1} belum memiliki isi.`); });
        });
    });
    return errors;
}
function renderPreview() {
    const structure = collectStructure(); const preview = document.getElementById('preview');
    preview.innerHTML = structure.length ? structure.map(bab => `<div class="preview-bab"><h3>BAB ${escapeHtml(bab.nomor)} <small>${escapeHtml(bab.judul)}</small></h3>${bab.pasal.map(pasal => `<div class="preview-pasal"><h4>Pasal ${escapeHtml(pasal.nomor)} ${escapeHtml(pasal.judul)}</h4>${pasal.pembuka ? `<p>${escapeHtml(pasal.pembuka)}</p>` : ''}${pasal.ayat.map(ayat => `<p><b>(${escapeHtml(ayat.nomor)})</b> ${escapeHtml(ayat.teks)}${ayat.poin.length ? '<ul>' + ayat.poin.map(point => `<li>${escapeHtml(point.nomor)}) ${escapeHtml(point.teks)}</li>`).join('') + '</ul>' : ''}</p>`).join('')}${pasal.penjelasan ? `<p class="preview-explanation"><strong>Penjelasan:</strong> ${escapeHtml(pasal.penjelasan)}</p>` : ''}</div>`).join('')}</div>`).join('') : '<div class="hukum-empty">Belum ada isi untuk dipratinjau.</div>';
    const errors = validateStructure();
    const statusMessage = state.workspace?.status === 'siap_commit'
        ? 'Struktur valid. Workspace sudah disetujui dan menunggu commit.'
        : state.workspace?.status === 'diajukan'
            ? 'Struktur valid. Workspace sedang menunggu review.'
            : 'Struktur siap disimpan atau diajukan.';
    document.getElementById('validation').innerHTML = errors.length
        ? '<strong>Perlu diperbaiki:</strong><ul>' + errors.map(error => `<li>${escapeHtml(error)}</li>`).join('') + '</ul>'
        : `<strong class="valid">${escapeHtml(statusMessage)}</strong>`;
}
async function loadDocument(id) {
    const documentId = Number(id || 0);
    state.documentId = documentId; state.workspace = null; state.babs = []; state.pasals = []; state.versions.clear();
    state.deletions = []; state.loadedSignature = null;
    state.savedSignature = null; state.savedVersionIds = [];
    refreshWorkflowActions();
    refreshDraftSaveState();
    const doc = activeDoc(); document.getElementById('activeDocumentTitle').textContent = doc?.judul || 'Pilih atau buat dokumen';
    if (!documentId) {
        state.document = null;
        setDocumentFormMode(false);
        document.getElementById('createDocumentForm')?.reset();
        document.getElementById('structureEmpty').hidden = false;
        document.getElementById('structureEditor').hidden = true;
        document.getElementById('deletionRequests').innerHTML = '<p class="hukum-muted">Belum ada usulan penghapusan.</p>';
        return;
    }
    setDocumentFormMode(true);
    if (doc) setDocumentFormValues(doc);
    const [documentResult, workspaces, babs, pasals] = await Promise.all([
        request('documents.php?id=' + documentId),
        request('workspaces.php?dokumen_id=' + documentId),
        request('bab.php?dokumen_id=' + documentId),
        request('pasal.php?dokumen_id=' + documentId)
    ]);
    if (state.documentId !== documentId) return;
    state.document = documentResult.data;
    setDocumentFormValues(state.document);
    document.getElementById('activeDocumentTitle').textContent = state.document.judul || doc?.judul || 'Dokumen Hukum';
    const openWorkspaceStatuses = ['aktif', 'diajukan', 'siap_commit'];
    state.workspace = openWorkspaceStatuses
        .map(status => (workspaces.data || []).find(item => item.status === status))
        .find(Boolean) || null;
    refreshWorkflowActions();
    state.babs = babs.data || []; state.pasals = pasals.data || [];
    state.deletions = state.workspace
        ? (await request('deletions.php?workspace_id=' + Number(state.workspace.id))).data || []
        : [];
    const workspaceBadge = document.getElementById('workspaceBadge');
    if (workspaceBadge) workspaceBadge.textContent = state.workspace ? `${state.workspace.status} #${state.workspace.id}` : 'Belum ada workspace';
    const createWorkspaceButton = document.getElementById('createWorkspaceBtn');
    if (createWorkspaceButton) createWorkspaceButton.hidden = Boolean(state.workspace);
    const editorState = document.getElementById('editorState');
    if (editorState) {
        editorState.textContent = state.workspace?.status === 'aktif'
            ? 'Siap diedit'
            : state.workspace?.status === 'diajukan'
                ? 'Menunggu review'
                : state.workspace?.status === 'siap_commit'
                    ? 'Menunggu commit'
                    : 'Baca saja';
    }
    document.getElementById('structureEmpty').hidden = true; document.getElementById('structureEditor').hidden = false;
    renderStructureFromServer();
    await hydratePasalContent();
    setStructureReadOnly(<?php echo $canEditHukum ? 'false' : 'true'; ?> || state.workspace?.status !== 'aktif');
    refreshDraftSaveState();
}
async function createWorkspace() {
    if (!state.documentId) return notice('Pilih dokumen terlebih dahulu.', 'error');
    try {
        const result = await request('workspaces.php', {method:'POST', body:JSON.stringify({dokumen_id:state.documentId, judul_perubahan:'Penyusunan dokumen hukum', tujuan:'Draft melalui editor terstruktur'})});
        notice('Workspace berhasil dibuat.'); await loadDocument(state.documentId);
    } catch (error) { notice(error.message, 'error'); }
}
function renderStructureFromServer() {
    const list = document.getElementById('babList'); list.innerHTML = '';
    const deletedPasalIds = new Set(state.deletions.flatMap(deletion =>
        (deletion.snapshot?.pasals || []).map(pasal => Number(pasal.pasal_id))
    ));
    const deletedBabIds = new Set(state.deletions.filter(deletion => deletion.entity_type === 'bab').map(deletion => Number(deletion.entity_id)));
    const renderedPasalIds = new Set();
    state.babs.forEach((bab, index) => {
        if (deletedBabIds.has(Number(bab.id))) return;
        const node = makeBab(); node.dataset.babId = String(bab.id); node.querySelector('.bab-number').value = bab.nomor_label || index + 1; node.querySelector('.bab-heading').value = bab.judul_bab || '';
        state.pasals.filter(pasal => Number(pasal.bab_id) === Number(bab.id) && !deletedPasalIds.has(Number(pasal.id))).forEach(pasal => {
            const p = makePasal(); p.dataset.pasalId = String(pasal.id); renderedPasalIds.add(Number(pasal.id)); p.querySelector('.pasal-number').value = pasal.nomor_label || ''; p.querySelector('.pasal-heading').value = pasal.judul_pasal || ''; node.querySelector('.pasal-list').appendChild(p);
        }); list.appendChild(node);
    });
    const unassignedPasals = state.pasals.filter(pasal => !renderedPasalIds.has(Number(pasal.id)) && !deletedPasalIds.has(Number(pasal.id)));
    if (!state.babs.length && unassignedPasals.length) list.appendChild(makeBab());
    const firstBab = list.querySelector('.hukum-bab-card');
    if (firstBab) {
        const pasalList = firstBab.querySelector('.pasal-list');
        unassignedPasals.forEach(pasal => {
            const p = makePasal();
            p.dataset.pasalId = String(pasal.id);
            p.querySelector('.pasal-number').value = pasal.nomor_label || '';
            p.querySelector('.pasal-heading').value = pasal.judul_pasal || '';
            pasalList.appendChild(p);
        });
    }
    if (!list.children.length && state.deletions.length === 0) list.appendChild(makeBab());
    renderDeletionRequests();
}
function renderDeletionRequests() {
    const container = document.getElementById('deletionRequests');
    if (!container) return;
    container.innerHTML = state.deletions.length ? state.deletions.map(deletion =>
        `<div class="hukum-deletion-request"><span><strong>${deletion.entity_type === 'bab' ? 'BAB' : 'Pasal'} #${Number(deletion.entity_id)}</strong> · ${escapeHtml(deletion.reason)}</span><button type="button" class="hukum-btn subtle" data-cancel-deletion="${Number(deletion.id)}">Batalkan</button></div>`
    ).join('') : '<p class="hukum-muted">Belum ada usulan penghapusan.</p>';
}
async function requestDeletion(entityType, entityId, element) {
    if (!state.workspace || state.workspace.status !== 'aktif') return notice('Workspace aktif diperlukan untuk mencatat penghapusan.', 'error');
    if (state.loadedSignature !== draftSignature()) return notice('Simpan draft perubahan isi terlebih dahulu sebelum mengusulkan penghapusan.', 'error');
    const reason = window.prompt(`Jelaskan alasan penghapusan ${entityType === 'bab' ? 'BAB' : 'Pasal'} (minimal 10 karakter):`);
    if (reason === null) return;
    if (reason.trim().length < 10) return notice('Alasan penghapusan minimal 10 karakter.', 'error');
    try {
        const result = await request('deletions.php', {method:'POST', body:JSON.stringify({
            workspace_id:Number(state.workspace.id), entity_type:entityType, entity_id:Number(entityId), reason:reason.trim()
        })});
        state.deletions.push(result.data);
        element.remove();
        state.loadedSignature = draftSignature();
        renderDeletionRequests();
        refreshDraftSaveState();
        renderPreview();
        notice('Usulan penghapusan tercatat di workspace. Perubahan aktif setelah seluruh persetujuan dan commit.');
    } catch (error) { notice(error.message, 'error'); }
}
async function cancelDeletion(deletionId) {
    if (!state.workspace || state.workspace.status !== 'aktif') return;
    try {
        await request('deletions.php', {method:'DELETE', body:JSON.stringify({workspace_id:Number(state.workspace.id), deletion_id:Number(deletionId)})});
        await loadDocument(state.documentId);
        notice('Usulan penghapusan dibatalkan; data dokumen tidak berubah.');
    } catch (error) { notice(error.message, 'error'); }
}
async function hydratePasalContent() {
    const nodes = [...document.querySelectorAll('.hukum-pasal-card')];
    const pasals = state.pasals.filter(pasal => nodes.some(node => Number(node.dataset.pasalId) === Number(pasal.id)));
    const savedVersions = new Map();
    await Promise.all(pasals.map(async pasal => {
        const versions = await request('pasal.php?pasal_id=' + Number(pasal.id));
        const pasalVersions = versions.data || [];
        const currentWorkspaceDraft = pasalVersions.find(version =>
            Number(version.workspace_id) === Number(state.workspace?.id)
            && version.status === 'draft'
        );
        const latest = currentWorkspaceDraft || pasalVersions[0];
        if (!latest) return;
        let content;
        try { content = typeof latest.isi === 'string' ? JSON.parse(latest.isi) : latest.isi; } catch (error) { return; }
        const node = nodes.find(item => Number(item.dataset.pasalId) === Number(pasal.id));
        if (!node || !content) return;
        if (currentWorkspaceDraft && Number(latest.id) === Number(currentWorkspaceDraft.id)) {
            savedVersions.set(Number(pasal.id), currentWorkspaceDraft);
        }
        node.querySelector('.pasal-opening').value = content.teks_utama || '';
        node.querySelector('.pasal-explanation').value = content.penjelasan || '';
        const ayatList = node.querySelector('.ayat-list');
        ayatList.innerHTML = '';
        (content.ayat || []).forEach(ayat => {
            const ayatNode = makeAyat();
            ayatNode.querySelector('.ayat-number').value = ayat.nomor || '';
            ayatNode.querySelector('.ayat-text').value = ayat.teks || '';
            (ayat.poin || []).forEach(point => {
                const pointNode = makePoint();
                pointNode.querySelector('.point-number').value = point.nomor || '';
                pointNode.querySelector('.point-text').value = point.teks || '';
                ayatNode.querySelector('.point-list').appendChild(pointNode);
            });
            ayatList.appendChild(ayatNode);
        });
    }));
    const orderedPasalIds = [...document.querySelectorAll('.hukum-pasal-card')]
        .map(node => Number(node.dataset.pasalId))
        .filter(id => id > 0);
    const orderedSavedVersions = orderedPasalIds.map(id => savedVersions.get(id));
    if (orderedSavedVersions.length > 0 && orderedSavedVersions.every(Boolean)
        && orderedSavedVersions.length === state.pasals.filter(pasal =>
            !state.deletions.some(deletion => (deletion.snapshot?.pasals || []).some(item => Number(item.pasal_id) === Number(pasal.id)))
        ).length) {
        state.savedSignature = draftSignature();
        state.savedVersionIds = orderedSavedVersions.map(version => Number(version.id));
    }
    state.loadedSignature = draftSignature();
}
async function createDocument(event) {
    event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    const opening = formValue('docOpening'); if (opening) data.mukadimah_json = JSON.stringify({teks: opening});
    try {
        const result = await request('documents.php', {method:'POST', body:JSON.stringify(data)}); state.documentId = Number(result.id);
        refreshWorkflowActions();
        const workspace = await request('workspaces.php', {method:'POST', body:JSON.stringify({dokumen_id:state.documentId, judul_perubahan:'Penyusunan dokumen baru', tujuan:'Draft dokumen melalui editor terstruktur'})});
        state.workspace = {id:workspace.id, status:workspace.status}; state.documents.push({...data, id:state.documentId}); document.getElementById('documentSelect').value = String(state.documentId);
        refreshWorkflowActions();
        await loadDocument(state.documentId); notice('Dokumen dan workspace berhasil dibuat.'); setStep(2);
    } catch (error) { notice(error.message, 'error'); }
}
async function persistStructure() {
    const errors = validateStructure(); if (errors.length) throw new Error(errors[0]);
    const babs = collectStructure(); const versionIds = [];
    const babNodes = [...document.querySelectorAll('.hukum-bab-card')];
    let nextPasalOrder = state.pasals.reduce((max, pasal) => Math.max(max, Number(pasal.urutan) || 0), 0) + 1;
    for (let bi = 0; bi < babs.length; bi++) {
        const bab = babs[bi]; const babNode = babNodes[bi]; let serverBab = bab.id ? state.babs.find(item => Number(item.id) === bab.id) : null;
        if (!serverBab) {
            const result = await request('bab.php', {method:'POST', body:JSON.stringify({dokumen_id:state.documentId, workspace_id:state.workspace.id, nomor_label:bab.nomor, judul_bab:bab.judul, urutan:state.babs.length + bi + 1})});
            serverBab = {id:result.id, nomor_label:bab.nomor};
            state.babs.push(serverBab);
            bab.id = Number(result.id);
            babNode.dataset.babId = String(result.id);
        }
        for (let pi = 0; pi < bab.pasal.length; pi++) {
            const pasal = bab.pasal[pi]; const pasalNode = babNode.querySelectorAll(':scope > .pasal-list > .hukum-pasal-card')[pi]; let serverPasal = pasal.id ? state.pasals.find(item => Number(item.id) === pasal.id) : null;
            if (!serverPasal) {
                const result = await request('pasal.php', {method:'POST', body:JSON.stringify({dokumen_id:state.documentId, workspace_id:state.workspace.id, bab_id:serverBab.id, nomor_label:pasal.nomor, judul_pasal:pasal.judul, urutan:nextPasalOrder})});
                serverPasal = {id:result.id, dokumen_id:state.documentId, bab_id:serverBab.id, nomor_label:pasal.nomor, urutan:nextPasalOrder};
                state.pasals.push(serverPasal);
                pasal.id = Number(result.id);
                pasalNode.dataset.pasalId = String(result.id);
                nextPasalOrder++;
            }
            const isi = {teks_utama:pasal.pembuka, penjelasan:pasal.penjelasan, ayat:pasal.ayat.map(ayat => ({nomor:ayat.nomor, teks:ayat.teks, poin:ayat.poin.map(point => ({nomor:point.nomor, teks:point.teks}))}))};
            const result = await request('pasal.php', {method:'POST', body:JSON.stringify({pasal_id:serverPasal.id, workspace_id:state.workspace.id, isi})}); versionIds.push(Number(result.latest_version_id || result.id));
        }
    }
    return versionIds;
}
async function saveDraft() {
    if (state.saving) return; state.saving = true;
    refreshDraftSaveState();
    try {
        const signature = draftSignature();
        const versionIds = await persistStructure();
        state.savedSignature = signature;
        state.savedVersionIds = versionIds;
        state.loadedSignature = signature;
        notice('Seluruh dokumen berhasil disimpan sebagai draft.');
    } catch (error) {
        state.savedSignature = null;
        state.savedVersionIds = [];
        notice(error.message, 'error');
    } finally {
        state.saving = false;
        refreshDraftSaveState();
    }
}
function startSubmitReview() {
    if (state.saving) return;
    document.getElementById('submitWarningDialog').showModal();
}
function continueSubmitReview() {
    document.getElementById('submitWarningDialog').close();
    document.getElementById('submitPassword').value = '';
    const totpInput = document.getElementById('submitTotpCode');
    if (totpInput) totpInput.value = '';
    document.getElementById('submitCredentialsError').hidden = true;
    document.getElementById('submitCredentialsError').textContent = '';
    document.getElementById('submitCredentialsDialog').showModal();
    document.getElementById('submitPassword').focus();
}
async function submitReview(password, totpCode) {
    if (state.saving) return; state.saving = true;
    refreshDraftSaveState();
    const submitButton = document.getElementById('confirmSubmitButton');
    submitButton.disabled = true;
    try {
        if (!state.workspace || state.workspace.status !== 'aktif') throw new Error('Workspace sudah diajukan atau belum aktif.');
        if (!state.savedSignature || state.savedSignature !== draftSignature()
            || (!state.savedVersionIds.length && !state.deletions.length)) {
            throw new Error('Simpan draft terlebih dahulu. Setelah mengubah isi, simpan kembali sebelum mengajukan.');
        }
        await request('staging.php', {method:'POST', body:JSON.stringify({
            workspace_id:Number(state.workspace.id),
            pasal_versi_ids:state.savedVersionIds,
            password,
            ...(submitRequires2fa ? {totp_code:totpCode} : {})
        })});
        state.savedSignature = null;
        state.savedVersionIds = [];
        state.workspace.status = 'diajukan';
        document.getElementById('submitCredentialsDialog').close();
        document.getElementById('submitPassword').value = '';
        if (document.getElementById('submitTotpCode')) document.getElementById('submitTotpCode').value = '';
        notice('Workspace berhasil dikirim ke staging untuk ditinjau Komisi I dan Admin.');
        await loadDocument(state.documentId);
    } catch (error) {
        notice(error.message, 'error');
        const errorNode = document.getElementById('submitCredentialsError');
        errorNode.textContent = error.message;
        errorNode.hidden = false;
    } finally {
        state.saving = false;
        submitButton.disabled = false;
        refreshDraftSaveState();
    }
}
document.getElementById('documentSelect').addEventListener('change', event => loadDocument(event.target.value).catch(error => notice(error.message, 'error')));
document.getElementById('createDocumentForm')?.addEventListener('submit', createDocument);
document.getElementById('addBabBtn').addEventListener('click', () => { document.getElementById('babList').appendChild(makeBab()); refreshDraftSaveState(); });
document.getElementById('babList').addEventListener('input', refreshDraftSaveState);
document.getElementById('babList').addEventListener('change', refreshDraftSaveState);
document.getElementById('babList').addEventListener('click', event => {
    if (event.target.closest('.add-point, .remove-point, .add-ayat, .remove-ayat, .add-pasal, .remove-pasal, .remove-bab')) {
        window.setTimeout(refreshDraftSaveState, 0);
    }
});
document.getElementById('deletionRequests').addEventListener('click', event => {
    const button = event.target.closest('[data-cancel-deletion]');
    if (button) cancelDeletion(Number(button.dataset.cancelDeletion));
});
document.getElementById('createWorkspaceBtn')?.addEventListener('click', createWorkspace);
document.getElementById('saveDraftBtn')?.addEventListener('click', saveDraft);
document.getElementById('submitStagingBtn')?.addEventListener('click', startSubmitReview);
document.getElementById('continueSubmitButton').addEventListener('click', continueSubmitReview);
document.getElementById('cancelSubmitButton').addEventListener('click', () => document.getElementById('submitWarningDialog').close());
document.getElementById('cancelCredentialsButton').addEventListener('click', () => document.getElementById('submitCredentialsDialog').close());
document.getElementById('submitCredentialsForm').addEventListener('submit', event => {
    event.preventDefault();
    const password = document.getElementById('submitPassword').value;
    const totpCode = document.getElementById('submitTotpCode')?.value || '';
    submitReview(password, totpCode);
});
document.querySelectorAll('[data-next]').forEach(button => button.addEventListener('click', () => { if (!state.documentId) return notice('Pilih atau buat dokumen terlebih dahulu.', 'error'); setStep(Number(button.dataset.next)); }));
document.querySelectorAll('[data-prev]').forEach(button => button.addEventListener('click', () => setStep(Number(button.dataset.prev))));
document.querySelectorAll('.hukum-step').forEach(button => button.addEventListener('click', () => setStep(Number(button.dataset.step))));
document.getElementById('docLingkup')?.addEventListener('change', event => { document.getElementById('docOrmawa').required = event.target.value !== 'induk'; });
refreshWorkflowActions();
(async function init() { if (state.documentId) { document.getElementById('documentSelect').value = String(state.documentId); await loadDocument(state.documentId); setStep(state.workspace ? 2 : 1); } })().catch(error => notice(error.message, 'error'));
</script>

<?php require_once __DIR__ . '/core/footer.php'; ?>

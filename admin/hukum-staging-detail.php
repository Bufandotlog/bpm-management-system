<?php
require_once __DIR__ . '/core/header.php';
require_once __DIR__ . '/core/hukum-auth.php';

hukum_require_permission('hukum.view');
$stagingId = (int) ($_GET['staging_id'] ?? 0);
?>
<link rel="stylesheet" href="<?php echo baseUrl('admin/css/hukum.css'); ?>?v=1">

<div class="hukum-shell">
    <div class="page-header">
        <div>
            <span class="hukum-kicker">Review / Staging</span>
            <h1 id="reviewTitle"><i class="fas fa-code-branch"></i> Tinjau Perubahan</h1>
            <p id="reviewSubtitle">Memuat dokumen dan perbandingan versi...</p>
        </div>
        <a class="hukum-btn" href="<?php echo htmlspecialchars(baseUrl('admin/hukum-staging.php'), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-arrow-left"></i> Kembali ke Staging
        </a>
    </div>

    <div id="reviewNotice" class="hukum-notice"></div>
    <div id="reviewError" class="hukum-card hukum-review-error" hidden></div>
    <div id="reviewContent" hidden>
        <section class="hukum-card hukum-review-summary">
            <div>
                <span class="hukum-kicker">Ringkasan perubahan</span>
                <h2 id="changeSummary"></h2>
                <p id="baseCommitInfo" class="hukum-muted"></p>
            </div>
            <div class="hukum-review-legend" aria-label="Legenda diff">
                <span class="hukum-diff-legend added">Ditambahkan</span>
                <span class="hukum-diff-legend removed">Sebelumnya</span>
                <span class="hukum-diff-legend unchanged">Tidak berubah</span>
            </div>
        </section>

        <section class="hukum-card">
            <div class="hukum-toolbar">
                <div>
                    <h2>Status Persetujuan</h2>
                    <p id="stagingStatus" class="hukum-muted"></p>
                </div>
                <span id="approvalProgress" class="hukum-badge"></span>
            </div>
            <div id="approvalRows" class="hukum-review-approvals"></div>
        </section>

        <section class="hukum-card">
            <div class="hukum-toolbar">
                <div>
                    <h2>Pratinjau Dokumen</h2>
                    <p class="hukum-muted">Mukadimah ditampilkan sebagai konteks; diff staging mencakup isi versi Pasal.</p>
                </div>
            </div>
            <div id="reviewOpening" class="hukum-review-opening" hidden></div>
            <div id="reviewDocument" class="hukum-review-document"></div>
        </section>

        <section id="reviewDecision" class="hukum-card hukum-review-decision" hidden>
            <span class="hukum-kicker">Tindakan reviewer</span>
            <h2>Keputusan Review</h2>
            <p class="hukum-muted">Pastikan seluruh perubahan telah diperiksa sebelum memberikan keputusan.</p>
            <div class="hukum-actions">
                <button id="approveButton" class="hukum-btn gold" type="button">Setujui</button>
                <button id="rejectButton" class="hukum-btn danger" type="button">Tolak</button>
            </div>
        </section>
    </div>
</div>

<script>
const hukumCsrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hukumBase = <?php echo json_encode(baseUrl('api/hukum/')); ?>;
const stagingId = <?php echo json_encode($stagingId); ?>;
let reviewCanDecide = false;

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

function reviewNotice(message, type = 'success') {
    const node = document.getElementById('reviewNotice');
    node.textContent = message;
    node.className = 'hukum-notice show ' + type;
}

function reviewContentLines(data) {
    if (!data || typeof data !== 'object') return [];
    const lines = [];
    const appendText = (label, value) => {
        const text = String(value ?? '').trim();
        if (!text) return;
        const parts = text.split(/\r?\n/);
        parts.forEach((part, index) => lines.push((index === 0 ? label : '  ') + part));
    };
    // Include metadata (nomor/judul) so renames are visible in diff
    appendText('Nomor: ', data.nomor_label);
    appendText('Judul: ', data.judul_pasal);
    
    const content = data.isi || data;
    if (content && typeof content === 'object') {
        appendText('Isi utama: ', content.teks_utama || content.teks || content.text);
        appendText('Penjelasan: ', content.penjelasan);
        (Array.isArray(content.ayat) ? content.ayat : []).forEach((ayat, index) => {
            appendText('Ayat ' + String(ayat.nomor || index + 1) + ': ', ayat.teks);
            (Array.isArray(ayat.poin) ? ayat.poin : []).forEach((poin, pointIndex) => {
                appendText('  Poin ' + String(poin.nomor || pointIndex + 1) + ': ', poin.teks);
            });
        });
    }
    if (!lines.length) {
        return JSON.stringify(content, null, 2).split(/\r?\n/);
    }
    return lines;
}

function reviewDiff(before, after, change) {
    const oldLines = before ? reviewContentLines(before) : [];
    const newLines = after ? reviewContentLines(after) : [];
    if (change === 'unchanged') {
        return newLines.map(line => ({type:'unchanged', text:line}));
    }

    const n = oldLines.length;
    const m = newLines.length;
    if (n * m > 120000) {
        return oldLines.map(text => ({type:'removed', text}))
            .concat(newLines.map(text => ({type:'added', text})));
    }
    const width = m + 1;
    const table = new Uint32Array((n + 1) * width);
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            table[i * width + j] = oldLines[i] === newLines[j]
                ? table[(i + 1) * width + j + 1] + 1
                : Math.max(table[(i + 1) * width + j], table[i * width + j + 1]);
        }
    }

    const operations = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (oldLines[i] === newLines[j]) {
            operations.push({type:'unchanged', text:oldLines[i++]});
            j++;
        } else if (table[(i + 1) * width + j] >= table[i * width + j + 1]) {
            operations.push({type:'removed', text:oldLines[i++]});
        } else {
            operations.push({type:'added', text:newLines[j++]});
        }
    }
    while (i < n) operations.push({type:'removed', text:oldLines[i++]});
    while (j < m) operations.push({type:'added', text:newLines[j++]});
    return operations;
}

function renderDocument(documentData) {
    const pasals = documentData.pasals || [];
    const counts = {added:0, modified:0, removed:0, unchanged:0};
    const html = pasals.map(pasal => {
        counts[pasal.change] = (counts[pasal.change] || 0) + 1;
        const babTitle = pasal.bab_nomor_label
            ? `${hukumEscape(pasal.bab_nomor_label)}${pasal.bab_judul ? ' — ' + hukumEscape(pasal.bab_judul) : ''}`
            : 'Bagian tanpa BAB';
        const stateLabel = pasal.change === 'added' ? 'Ditambahkan'
            : pasal.change === 'removed' ? 'Akan dihapus'
            : pasal.change === 'modified' ? 'Diubah' : 'Tidak berubah';
        const diff = reviewDiff(pasal.before, pasal.after, pasal.change);
        const diffHtml = diff.length
            ? diff.map(line => `<div class="hukum-diff-line ${line.type}"><span>${line.type === 'added' ? '+' : line.type === 'removed' ? '−' : ' '}</span><code>${hukumEscape(line.text)}</code></div>`).join('')
            : '<p class="hukum-muted">Belum ada isi pada Pasal ini.</p>';
        const deletionReason = pasal.deletion_reason
            ? `<p class="hukum-review-deletion-reason"><strong>Alasan penghapusan:</strong> ${hukumEscape(pasal.deletion_reason)}</p>`
            : '';
        return `<article class="hukum-review-pasal ${pasal.change}">
            <div class="hukum-review-bab">${babTitle}</div>
            <div class="hukum-review-pasal-heading">
                <h3>${hukumEscape(pasal.nomor_label)}${pasal.judul_pasal ? ' — ' + hukumEscape(pasal.judul_pasal) : ''}</h3>
                <span class="hukum-diff-legend ${pasal.change}">${stateLabel}</span>
            </div>
            ${deletionReason}
            <div class="hukum-diff-lines">${diffHtml}</div>
        </article>`;
    }).join('');
    const deletedBabHtml = (documentData.deleted_babs || []).map(entry => {
        const bab = entry.bab || {};
        return `<article class="hukum-review-pasal removed">
            <div class="hukum-review-bab">${hukumEscape(bab.nomor_label || '')}</div>
            <div class="hukum-review-pasal-heading"><h3>${hukumEscape(bab.judul_bab || 'BAB')}</h3><span class="hukum-diff-legend removed">BAB akan dihapus</span></div>
            <p class="hukum-review-deletion-reason"><strong>Alasan penghapusan:</strong> ${hukumEscape(entry.reason)}</p>
        </article>`;
    }).join('');
    document.getElementById('reviewDocument').innerHTML = deletedBabHtml + html || '<div class="hukum-empty">Belum ada Pasal pada snapshot aktif atau staging ini.</div>';
    const opening = document.getElementById('reviewOpening');
    if (documentData.document.pembukaan) {
        opening.innerHTML = `<h3>Mukadimah / Pembukaan</h3><p>${hukumEscape(documentData.document.pembukaan)}</p><small>Data dokumen saat ini; belum termasuk versi staging.</small>`;
        opening.hidden = false;
    } else {
        opening.hidden = true;
        opening.textContent = '';
    }
    document.getElementById('changeSummary').textContent =
        `${counts.added} Pasal ditambahkan · ${counts.modified} Pasal diubah · ${counts.removed} Pasal akan dihapus · ${counts.unchanged} Pasal tidak berubah`;
}

function renderApprovals(item) {
    const summary = item.approval_summary || {};
    document.getElementById('approvalProgress').textContent = summary.progress || '0/2';
    document.getElementById('stagingStatus').textContent = 'Status staging: ' + (item.status || 'tidak diketahui');
    document.getElementById('approvalRows').innerHTML = ['komisi_i', 'admin'].map(role => {
        const approval = summary[role] || {status:'menunggu'};
        const label = role === 'komisi_i' ? 'Komisi I' : 'Admin';
        const note = approval.note ? `<p class="hukum-muted">Catatan: ${hukumEscape(approval.note)}</p>` : '';
        return `<div class="hukum-review-approval"><strong>${label}</strong><span class="hukum-badge">${hukumEscape(approval.status || 'menunggu')}</span>${note}</div>`;
    }).join('');
}

async function loadReview() {
    if (!Number.isInteger(Number(stagingId)) || Number(stagingId) <= 0) {
        document.getElementById('reviewError').textContent = 'ID staging tidak valid.';
        document.getElementById('reviewError').hidden = false;
        return;
    }
    try {
        const result = await hukumRequest('review.php?staging_id=' + encodeURIComponent(stagingId));
        const item = result.data;
        document.getElementById('reviewTitle').textContent = 'Tinjau: ' + (item.review_document.document.judul || item.judul);
        document.getElementById('reviewSubtitle').textContent = item.judul_perubahan || 'Pratinjau lengkap perubahan dokumen sebelum keputusan review.';
        document.getElementById('baseCommitInfo').textContent = item.review_document.base_commit_id
            ? 'Versi pembanding: commit aktif #' + item.review_document.base_commit_id
            : 'Belum ada commit aktif; isi staging menjadi dasar dokumen pertama.';
        renderApprovals(item);
        renderDocument(item.review_document);
        reviewCanDecide = Boolean(item.can_review);
        document.getElementById('reviewDecision').hidden = !reviewCanDecide;
        document.getElementById('reviewContent').hidden = false;
        document.getElementById('reviewError').hidden = true;
    } catch (error) {
        document.getElementById('reviewError').textContent = error.message;
        document.getElementById('reviewError').hidden = false;
    }
}

async function submitDecision(decision) {
    if (!reviewCanDecide) return;
    let note = null;
    if (decision === 'approve') {
        if (!window.confirm('Setujui perubahan staging ini?')) return;
    } else {
        note = window.prompt('Masukkan alasan penolakan:');
        if (!note || !note.trim()) {
            reviewNotice('Alasan penolakan wajib diisi.', 'error');
            return;
        }
    }
    const buttons = document.querySelectorAll('#reviewDecision button');
    buttons.forEach(button => { button.disabled = true; });
    try {
        const result = await hukumRequest('review.php', {
            method:'POST',
            body:JSON.stringify({staging_id:Number(stagingId), decision, note})
        });
        reviewNotice(decision === 'approve'
            ? (result.status === 'disetujui' ? 'Persetujuan lengkap. Staging siap untuk commit.' : 'Peran review Anda telah menyetujui staging.')
            : 'Staging ditolak.');
        await loadReview();
    } catch (error) {
        reviewNotice(error.message, 'error');
    } finally {
        buttons.forEach(button => { button.disabled = false; });
    }
}

document.getElementById('approveButton').addEventListener('click', () => submitDecision('approve'));
document.getElementById('rejectButton').addEventListener('click', () => submitDecision('reject'));
loadReview();
</script>
<?php require_once __DIR__ . '/core/footer.php'; ?>

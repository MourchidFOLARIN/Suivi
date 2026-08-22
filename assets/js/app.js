const API_URL = 'api/prospects.php';

const STATUTS = {
    nouveau:   { label: 'Nouveau',              step: 0 },
    invite:    { label: 'Invité',               step: 1 },
    presente:  { label: 'Présentation faite',   step: 2 },
    interesse: { label: 'Intéressé',            step: 3 },
    inscrit:   { label: 'Inscrit',              step: 4 },
    perdu:     { label: 'Perdu',                step: -1 },
};
const PIPELINE_STEPS = ['nouveau', 'invite', 'presente', 'interesse', 'inscrit'];

let currentFilter = '';
let currentSearch = '';
let currentSort = 'date_desc';
let editingId = null;
let currentProspectsData = [];

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    chargerStats();
    chargerProspects();

    const themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) themeToggleBtn.addEventListener('click', toggleTheme);

    document.getElementById('btn-open-add').addEventListener('click', () => ouvrirModal());

    const mobileFab = document.getElementById('btn-mobile-fab');
    if (mobileFab) mobileFab.addEventListener('click', () => ouvrirModal());

    document.getElementById('btn-close-modal').addEventListener('click', fermerModal);
    document.getElementById('btn-cancel').addEventListener('click', fermerModal);
    document.getElementById('modal-overlay').addEventListener('click', (e) => {
        if (e.target.id === 'modal-overlay') fermerModal();
    });
    document.getElementById('prospect-form').addEventListener('submit', soumettreForm);

    document.getElementById('search-input').addEventListener('input', (e) => {
        currentSearch = e.target.value.trim();
        chargerProspects();
    });

    const sortSelect = document.getElementById('sort-select');
    if (sortSelect) {
        sortSelect.addEventListener('change', (e) => {
            currentSort = e.target.value;
            trierEtAfficherProspects();
        });
    }

    document.querySelectorAll('#status-filters .pill').forEach(pill => {
        pill.addEventListener('click', () => {
            document.querySelectorAll('#status-filters .pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            currentFilter = pill.dataset.statut;
            chargerProspects();
        });
    });

    const btnCloseConfirm = document.getElementById('btn-close-confirm');
    const btnConfirmCancel = document.getElementById('btn-confirm-cancel');
    const btnConfirmOk = document.getElementById('btn-confirm-ok');
    const confirmOverlay = document.getElementById('confirm-modal-overlay');

    if (btnCloseConfirm) btnCloseConfirm.addEventListener('click', () => fermerConfirmModal(false));
    if (btnConfirmCancel) btnConfirmCancel.addEventListener('click', () => fermerConfirmModal(false));
    if (btnConfirmOk) btnConfirmOk.addEventListener('click', () => fermerConfirmModal(true));
    if (confirmOverlay) {
        confirmOverlay.addEventListener('click', (e) => {
            if (e.target.id === 'confirm-modal-overlay') fermerConfirmModal(false);
        });
    }
});

/* ---------------- Thème Dark / Light ---------------- */

function initTheme() {
    const saved = localStorage.getItem('suivi_prospects_theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    appliquerTheme(saved || (prefersDark ? 'dark' : 'light'));
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    appliquerTheme(next);
    localStorage.setItem('suivi_prospects_theme', next);
}

function appliquerTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('theme-toggle');
    if (btn) {
        btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        btn.title = theme === 'dark' ? 'Passer au thème clair' : 'Passer au thème sombre';
    }
}

/* ---------------- Modale de confirmation ---------------- */

let confirmResolve = null;

function demanderConfirmation({ title = 'Confirmation', text = 'Êtes-vous sûr ?', confirmText = 'Confirmer' } = {}) {
    return new Promise((resolve) => {
        document.getElementById('confirm-modal-title').textContent = title;
        document.getElementById('confirm-modal-text').textContent = text;
        document.getElementById('btn-confirm-ok').textContent = confirmText;
        confirmResolve = resolve;
        document.getElementById('confirm-modal-overlay').classList.add('open');
    });
}

function fermerConfirmModal(result = false) {
    document.getElementById('confirm-modal-overlay').classList.remove('open');
    if (confirmResolve) { confirmResolve(result); confirmResolve = null; }
}

/* ---------------- Appels API ---------------- */

async function chargerStats() {
    try {
        const res = await fetch(`${API_URL}?action=stats`);
        const json = await res.json();
        if (json.success) {
            document.getElementById('stat-total').textContent = json.data.total;
            document.getElementById('stat-encours').textContent = json.data.en_cours;
            document.getElementById('stat-inscrits').textContent = json.data.inscrits;
            document.getElementById('stat-taux').textContent = json.data.taux_conversion + '%';
        }
    } catch (err) { console.error('Erreur stats :', err); }
}

async function chargerProspects() {
    const params = new URLSearchParams();
    if (currentFilter) params.set('statut', currentFilter);
    if (currentSearch) params.set('recherche', currentSearch);

    try {
        const res = await fetch(`${API_URL}?${params.toString()}`);
        const json = await res.json();
        if (json.success) {
            currentProspectsData = json.data || [];
            trierEtAfficherProspects();
        } else {
            afficherToast(json.message || 'Erreur de chargement.', true);
        }
    } catch (err) {
        afficherToast('Impossible de contacter le serveur. Vérifie que PHP/MySQL sont démarrés.', true);
        console.error(err);
    }
}

function trierEtAfficherProspects() {
    const prospects = [...currentProspectsData];

    prospects.sort((a, b) => {
        if (currentSort === 'nom_asc') {
            return `${a.nom || ''} ${a.prenom || ''}`.toLowerCase()
                .localeCompare(`${b.nom || ''} ${b.prenom || ''}`.toLowerCase(), 'fr');
        } else if (currentSort === 'relance_asc') {
            if (!a.prochaine_relance && !b.prochaine_relance) return 0;
            if (!a.prochaine_relance) return 1;
            if (!b.prochaine_relance) return -1;
            return a.prochaine_relance.localeCompare(b.prochaine_relance);
        } else {
            const dA = a.date_ajout || '';
            const dB = b.date_ajout || '';
            if (dA && dB) return dB.localeCompare(dA);
            return Number(b.id) - Number(a.id);
        }
    });

    afficherProspects(prospects);
}

async function supprimerProspect(id) {
    const ok = await demanderConfirmation({
        title: 'Supprimer ce prospect ?',
        text: 'Cette action est définitive et ne pourra pas être annulée.',
        confirmText: 'Supprimer',
    });
    if (!ok) return;

    try {
        const res = await fetch(`${API_URL}?id=${id}`, { method: 'DELETE' });
        const json = await res.json();
        afficherToast(json.message, !json.success);
        chargerProspects();
        chargerStats();
    } catch (err) { afficherToast('Erreur lors de la suppression.', true); }
}

async function changerStatut(id, statut) {
    try {
        const res = await fetch(API_URL, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, statut }),
        });
        const json = await res.json();
        if (json.success) {
            afficherToast('Statut mis à jour.');
            chargerProspects();
            chargerStats();
        } else {
            afficherToast(json.message || 'Erreur de mise à jour du statut.', true);
        }
    } catch (err) { afficherToast('Erreur de mise à jour du statut.', true); }
}

/* ---------------- Rendu des cartes ---------------- */

const WHATSAPP_ICON = `<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.012 2c-5.506 0-9.989 4.478-9.99 9.984 0 1.763.459 3.486 1.332 5.002L2 22l5.139-1.345a9.92 9.92 0 0 0 4.87 1.28h.005c5.507 0 9.99-4.479 9.99-9.986 0-2.667-1.038-5.176-2.924-7.062A9.92 9.92 0 0 0 12.012 2zm.005 16.592h-.004a8.25 8.25 0 0 1-4.205-1.155l-.302-.18-3.123.818.833-3.042-.197-.314a8.27 8.27 0 0 1-1.267-4.42c0-4.562 3.712-8.274 8.277-8.274 2.21 0 4.288.861 5.85 2.424a8.22 8.22 0 0 1 2.42 5.853c0 4.563-3.712 8.274-8.282 8.274zm4.537-6.2c-.248-.124-1.468-.724-1.696-.807-.228-.083-.394-.124-.559.124-.165.248-.641.807-.786.973-.145.165-.29.186-.538.062-.248-.124-1.047-.386-1.995-1.231-.738-.658-1.236-1.47-1.381-1.718-.145-.248-.015-.382.109-.505.111-.11.248-.29.372-.434.124-.145.165-.248.248-.414.083-.165.041-.31-.021-.434-.062-.124-.559-1.347-.765-1.844-.201-.486-.405-.419-.559-.427l-.476-.008c-.165 0-.434.062-.661.31-.228.248-.869.849-.869 2.071 0 1.222.89 2.401 1.013 2.566.124.165 1.751 2.674 4.242 3.749.593.256 1.056.409 1.417.524.595.19 1.137.163 1.565.099.477-.071 1.468-.6 1.674-1.18.207-.579.207-1.075.145-1.18-.062-.103-.228-.185-.476-.31z"/></svg>`;

function afficherProspects(prospects) {
    const container = document.getElementById('prospect-list');

    if (prospects.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                <strong>Aucun prospect ici pour l'instant</strong>
                Utilise le bouton <strong>+</strong> pour ajouter ton premier contact.
            </div>`;
        return;
    }

    const todayStr = new Date().toISOString().split('T')[0];

    container.innerHTML = prospects.map((p, index) => {
        const statutInfo = STATUTS[p.statut] || STATUTS.nouveau;

        // Pipeline ou label perdu
        const pipelineHtml = statutInfo.step === -1
            ? `<div class="pipeline-label lost">❌ Prospect perdu</div>`
            : construirePipeline(statutInfo.step) + `<div class="pipeline-label">${statutInfo.label}</div>`;

        // WhatsApp
        const cleanPhone = p.telephone ? p.telephone.replace(/\D/g, '') : '';
        const waUrl = cleanPhone ? `https://wa.me/${cleanPhone}` : null;

        // Méta-données
        const metaItems = [];
        if (p.telephone) {
            metaItems.push(waUrl
                ? `<a href="${waUrl}" target="_blank" rel="noopener noreferrer" class="meta-link" title="Ouvrir WhatsApp">📞 ${escapeHtml(p.telephone)}</a>`
                : `📞 ${escapeHtml(p.telephone)}`
            );
        }
        if (p.email) metaItems.push(`✉️ ${escapeHtml(p.email)}`);
        if (p.source) metaItems.push(`🔗 ${escapeHtml(p.source)}`);
        const metaHtml = metaItems.join('<span class="meta-sep">·</span>');

        // Badge présentation
        let presentationBadge = '';
        if (p.date_presentation) {
            presentationBadge = Number(p.presentation_faite)
                ? `<span class="badge badge-presentation-faite">✅ Présentation faite le ${formaterDate(p.date_presentation)}</span>`
                : `<span class="badge badge-presentation-prevue">📢 Présentation prévue le ${formaterDate(p.date_presentation)}</span>`;
        }

        // Badge relance
        let relanceBadge = '';
        if (p.prochaine_relance && p.statut !== 'inscrit' && p.statut !== 'perdu') {
            relanceBadge = p.prochaine_relance < todayStr
                ? `<span class="badge badge-overdue">⚠️ Relance en retard · ${formaterDate(p.prochaine_relance)}</span>`
                : `<span class="badge badge-relance-ok">📅 Relance le ${formaterDate(p.prochaine_relance)}</span>`;
        }

        // Bouton WhatsApp
        const waBtnHtml = waUrl
            ? `<a href="${waUrl}" target="_blank" rel="noopener noreferrer" class="btn-icon btn-whatsapp" title="Écrire sur WhatsApp" aria-label="WhatsApp">${WHATSAPP_ICON}</a>`
            : '';

        // Options du sélecteur de statut
        const statutOptions = Object.entries(STATUTS)
            .map(([key, val]) => `<option value="${key}" ${key === p.statut ? 'selected' : ''}>${val.label}</option>`)
            .join('');

        return `
        <div class="prospect-card" style="--i:${index}">
            <div class="prospect-identity">
                <div class="prospect-name">${escapeHtml(p.prenom)} ${escapeHtml(p.nom)}</div>
                <div class="prospect-meta">${metaHtml}</div>
                <div class="prospect-badges">${presentationBadge}${relanceBadge}</div>
            </div>

            <div class="pipeline-wrap">
                ${pipelineHtml}
            </div>

            <select class="status-select ${escapeHtml(p.statut)}" onchange="changerStatut(${Number(p.id)}, this.value)" aria-label="Statut">
                ${statutOptions}
            </select>

            <div class="prospect-actions">
                ${waBtnHtml}
                <button class="btn-icon" title="Modifier" onclick="ouvrirModal(${Number(p.id)})" aria-label="Modifier">✎</button>
                <button class="btn-icon btn-delete" title="Supprimer" onclick="supprimerProspect(${Number(p.id)})" aria-label="Supprimer">🗑</button>
            </div>
        </div>`;
    }).join('');
}

function construirePipeline(currentStep) {
    return `<div class="pipeline">` + PIPELINE_STEPS.map((_, i) => {
        const dot = `<div class="step ${i < currentStep ? 'done' : ''} ${i === currentStep ? 'current' : ''}"></div>`;
        const bar = i < PIPELINE_STEPS.length - 1 ? `<div class="bar ${i < currentStep ? 'done' : ''}"></div>` : '';
        return dot + bar;
    }).join('') + `</div>`;
}

/* ---------------- Modale / Formulaire ---------------- */

async function ouvrirModal(id = null) {
    editingId = id;
    const form = document.getElementById('prospect-form');
    form.reset();
    document.getElementById('f-invitation-faite').checked = false;
    document.getElementById('f-presentation-faite').checked = false;

    if (id) {
        document.getElementById('modal-title').textContent = 'Modifier le prospect';
        try {
            const res = await fetch(`${API_URL}?id=${id}`);
            const json = await res.json();
            if (json.success) remplirForm(json.data);
            else { afficherToast('Impossible de charger ce prospect.', true); return; }
        } catch (err) {
            afficherToast('Impossible de charger ce prospect.', true);
            return;
        }
    } else {
        document.getElementById('modal-title').textContent = 'Nouveau prospect';
        document.getElementById('prospect-id').value = '';
    }

    document.getElementById('modal-overlay').classList.add('open');
}

function remplirForm(p) {
    document.getElementById('prospect-id').value = p.id;
    document.getElementById('f-nom').value = p.nom;
    document.getElementById('f-prenom').value = p.prenom;
    document.getElementById('f-telephone').value = p.telephone;
    document.getElementById('f-email').value = p.email || '';
    document.getElementById('f-source').value = p.source || '';
    document.getElementById('f-statut').value = p.statut;
    document.getElementById('f-invitation-faite').checked = !!Number(p.invitation_faite);
    document.getElementById('f-date-invitation').value = p.date_invitation || '';
    document.getElementById('f-presentation-faite').checked = !!Number(p.presentation_faite);
    document.getElementById('f-date-presentation').value = p.date_presentation || '';
    document.getElementById('f-date-inscription').value = p.date_inscription || '';
    document.getElementById('f-prochaine-relance').value = p.prochaine_relance || '';
    document.getElementById('f-notes').value = p.notes || '';
}

function fermerModal() {
    document.getElementById('modal-overlay').classList.remove('open');
    editingId = null;
}

async function soumettreForm(e) {
    e.preventDefault();

    const payload = {
        nom: document.getElementById('f-nom').value.trim(),
        prenom: document.getElementById('f-prenom').value.trim(),
        telephone: document.getElementById('f-telephone').value.trim(),
        email: document.getElementById('f-email').value.trim() || null,
        source: document.getElementById('f-source').value.trim() || null,
        statut: document.getElementById('f-statut').value,
        invitation_faite: document.getElementById('f-invitation-faite').checked,
        date_invitation: document.getElementById('f-date-invitation').value || null,
        presentation_faite: document.getElementById('f-presentation-faite').checked,
        date_presentation: document.getElementById('f-date-presentation').value || null,
        date_inscription: document.getElementById('f-date-inscription').value || null,
        prochaine_relance: document.getElementById('f-prochaine-relance').value || null,
        notes: document.getElementById('f-notes').value.trim() || null,
    };

    // Progression automatique du statut selon date de présentation
    if (payload.statut === 'nouveau' && payload.date_presentation) {
        payload.statut = payload.presentation_faite ? 'presente' : 'invite';
    }

    const id = document.getElementById('prospect-id').value;
    const isEdit = !!id;
    if (isEdit) payload.id = id;

    try {
        const res = await fetch(API_URL, {
            method: isEdit ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();

        if (json.success) {
            afficherToast(json.message);
            fermerModal();
            chargerProspects();
            chargerStats();
        } else {
            afficherToast(json.message || "Erreur lors de l'enregistrement.", true);
        }
    } catch (err) {
        afficherToast('Impossible de contacter le serveur.', true);
        console.error(err);
    }
}

/* ---------------- Utilitaires ---------------- */

function afficherToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3200);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function formaterDate(dateStr) {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-');
    return y && m && d ? `${d}/${m}/${y}` : dateStr;
}

/* ---------------- Registration PWA & Service Worker ---------------- */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then((reg) => console.log('Service Worker PWA enregistré:', reg.scope))
            .catch((err) => console.error('Erreur Service Worker PWA:', err));
    });
}

let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    const installBtn = document.getElementById('btn-pwa-install');
    if (installBtn) {
        installBtn.style.display = 'inline-flex';
        installBtn.addEventListener('click', async () => {
            installBtn.style.display = 'none';
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                console.log(`Résultat installation PWA: ${outcome}`);
                deferredPrompt = null;
            }
        });
    }
});

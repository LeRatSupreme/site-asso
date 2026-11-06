/*
 * AEIC — Aperçu live de la page CMS en cours d'édition (admin › Pages).
 *
 * À l'ouverture de la page, puis à chaque frappe (debounce ~800 ms) et au
 * bouton « Actualiser l'aperçu », les valeurs EN COURS du formulaire —
 * non enregistrées — sont POSTées vers POST /admin/pages/preview. La
 * réponse (rendu public complet, gabarit /p/{slug}, HtmlSanitizer appliqué
 * côté serveur) est injectée dans l'iframe de droite via srcdoc : les
 * feuilles du rendu public sont référencées par URL absolue (/css/…),
 * elles restent donc valides dans le document srcdoc (same-origin).
 *
 * CSRF : le jeton du formulaire (champ _csrf) part dans le corps ET via
 * l'en-tête X-CSRF-Token (cf. Csrf::checkRequest, qui accepte les deux).
 *
 * INVARIANT : le formulaire principal n'est jamais touché — ni action,
 * ni target, ni soumission automatique. « Enregistrer » poste
 * normalement vers /admin/pages/save.
 *
 * États (discrets, jamais d'alert() bloquant) :
 *   - chargement : statut « Chargement… » + voile léger sur l'iframe ;
 *   - succès : statut « À jour », rendu affiché ;
 *   - erreur (403, session expirée, réseau…) : message clair dans le
 *     panneau aperçu ; le dernier rendu réussi reste affiché.
 *
 * Réponses obsolètes ignorées : un compteur séquentiel + AbortController
 * garantissent qu'un rafraîchissement rapide n'écrase jamais un rendu
 * plus récent par une réponse plus ancienne.
 *
 * Contrat DOM (posé par views/admin/pages/form.php) :
 *   .page-edit                    conteneur deux colonnes
 *   #page-edit-form               formulaire (champs id, title, slug,
 *                                 content, meta_description, _csrf)
 *   #page-preview-frame           iframe (srcdoc)
 *   #page-preview-status          indicateur discret (aria-live)
 *   #page-preview-error           panneau d'erreur (hidden par défaut)
 *   #page-preview-refresh         bouton « Actualiser l'aperçu »
 *   window.AEIC_PAGE_PREVIEW = { endpoint }
 */
(function () {
    'use strict';

    var cfg = window.AEIC_PAGE_PREVIEW;
    var root = document.getElementById('page-edit');
    var form = document.getElementById('page-edit-form');
    var frame = document.getElementById('page-preview-frame');
    var statusEl = document.getElementById('page-preview-status');
    var errorEl = document.getElementById('page-preview-error');
    var refreshBtn = document.getElementById('page-preview-refresh');

    if (!root || !form || !frame || !cfg || !cfg.endpoint || typeof window.fetch !== 'function') {
        return; /* Contrat absent ou navigateur trop ancien : pas d'aperçu, le formulaire reste pleinement fonctionnel. */
    }

    var DEBOUNCE_MS = 800;

    /* Champs écoutés = ceux consommés par le contrôleur d'aperçu
       (l'identifiant sert au corps POST mais ne change jamais : pas
       besoin de l'écouter). */
    var EDITABLE_FIELDS = ['title', 'slug', 'content', 'meta_description'];
    var POST_FIELDS = ['id'].concat(EDITABLE_FIELDS);

    var seq = 0;         /* identifiant de requête : les réponses obsolètes sont ignorées */
    var timer = null;    /* debounce */
    var controller = null; /* requête en vol (abandonnée au départ d'une plus récente) */

    function fieldValue(name) {
        var el = form.elements[name];
        return el ? String(el.value == null ? '' : el.value) : '';
    }

    function setStatus(text, state) {
        if (!statusEl) { return; }
        statusEl.textContent = text;
        statusEl.setAttribute('data-state', state);
    }

    function showError(message) {
        root.classList.remove('is-loading');
        root.classList.add('is-error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }
        setStatus('Erreur', 'error');
    }

    function clearError() {
        root.classList.remove('is-error');
        if (errorEl) { errorEl.hidden = true; }
    }

    function requestDone() {
        root.classList.remove('is-loading');
        controller = null;
    }

    function load() {
        if (timer) { clearTimeout(timer); timer = null; }
        if (controller) { controller.abort(); }

        var token = fieldValue('_csrf');
        var body = new URLSearchParams();
        POST_FIELDS.forEach(function (name) { body.append(name, fieldValue(name)); });
        body.append('_csrf', token);

        var id = ++seq;
        controller = typeof AbortController === 'function' ? new AbortController() : null;

        clearError();
        root.classList.add('is-loading');
        setStatus("Chargement de l'aperçu…", 'loading');

        var options = {
            method: 'POST',
            body: body,
            headers: {
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        if (controller) { options.signal = controller.signal; }

        fetch(cfg.endpoint, options)
            .then(function (r) {
                if (id !== seq) { return null; }
                /* Le fetch suit les redirections : arriver ici en étant
                   « redirected » (ex. vers /login) = session expirée,
                   ce n'est pas un rendu à afficher dans l'aperçu. */
                if (r.redirected || !r.ok) {
                    throw new Error(r.redirected ? 'session expirée' : 'erreur HTTP ' + r.status);
                }
                return r.text();
            })
            .then(function (html) {
                if (html === null) { return; }
                frame.srcdoc = html;
                setStatus('À jour', 'ok');
                requestDone();
            })
            .catch(function (err) {
                if (id !== seq) { return; }
                requestDone();
                if (err && err.name === 'AbortError') { return; }
                var detail = err && err.message ? err.message : 'erreur réseau';
                showError("Impossible de charger l'aperçu (" + detail + ")."
                    + " Vérifiez votre connexion, puis rechargez la page si le problème persiste.");
            });
    }

    function schedule() {
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(load, DEBOUNCE_MS);
    }

    /* Rechargement debouncé à la saisie (titre, slug, contenu, méta). */
    EDITABLE_FIELDS.forEach(function (name) {
        var el = form.elements[name];
        if (el) { el.addEventListener('input', schedule); }
    });

    /* Bouton manuel : rechargement immédiat. */
    if (refreshBtn) {
        refreshBtn.addEventListener('click', load);
    }

    /* Premier aperçu dès l'ouverture de la page. */
    load();
})();

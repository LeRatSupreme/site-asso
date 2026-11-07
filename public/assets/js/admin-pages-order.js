/**
 * AEIC — Réordonnancement des pages CMS (liste admin, drag & drop).
 *
 * Adapté du pattern « toujours actif » de la sidebar
 * (admin-sidebar-order.js) : aucune poignée, aucun mode « Réorganiser » —
 * chaque LIGNE du tableau est saisissable dans son intégralité. Pointer
 * Events (souris, tactile, stylet — uniforme) : appui maintenu puis
 * déplacement ≥ 5 px = drag ; en deçà du seuil = clic normal. Les
 * éléments interactifs d'une ligne (Éditer, Supprimer, tout futur
 * bouton/lien) ne déclenchent JAMAIS le drag : l'appui sur eux est
 * ignoré, leurs clics restent à 100 % fonctionnels.
 *
 * À chaque dépôt qui réordonne réellement, la liste COMPLÈTE des ids
 * est envoyée en auto-save (debounce ~350 ms) : POST
 * /admin/pages/reorder en JSON {"ids":[…]} avec CSRF via l'en-tête
 * X-CSRF-Token (cf. Csrf::checkRequest ; $_POST est vide avec un corps
 * JSON). Réponse attendue {ok:true} ; retour discret par mini-toast
 * (« Ordre enregistré » / erreur), sans rechargement — en cas d'échec
 * l'ordre VISUEL local est conservé.
 *
 * Différences assumées avec la sidebar (liste PLATE, pas d'arbre) :
 *   - une seule sorte d'unité (tr[data-id] du tbody) et une seule
 *     cible valide : une autre ligne du MÊME tbody — dropCandidate
 *     vérifie le conteneur, un dépôt ailleurs (thead, hors tableau)
 *     ramène la silhouette à l'origine (snap-back) ;
 *   - la silhouette est un clone <tr> posé dans <body> avec
 *     display:table (rendu stable hors du tableau, cellules alignées
 *     par le contenu) ;
 *   - tactile : la page ne doit pas voler le geste vertical (sinon le
 *     doigt ferait défiler la page au lieu de déplacer la ligne), donc
 *     touch-action:pan-x sur les lignes — le geste VERTICAL appartient
 *     au drag, le défilement HORIZONTAL de .table-wrap (table à
 *     min-width sur mobile) reste au navigateur, qui émet pointercancel
 *     → restitution propre.
 *
 * Contrat DOM (posé par views/admin/pages/index.php) :
 *   tbody#pages-tbody > tr[data-id] × N
 *   window.AEIC_PAGES_ORDER = { csrf, endpoint }
 * (config absente — mode lecture seule — : script inerte.)
 */
(function () {
    'use strict';

    var tbody = document.getElementById('pages-tbody');
    if (!tbody || !window.AEIC_PAGES_ORDER || !window.AEIC_PAGES_ORDER.endpoint) {
        return; // mode lecture seule (config absente) ou DOM sans contrat
    }

    var DRAG_THRESHOLD = 5;  // px de déplacement avant qu'un appui devienne un drag
    var SNAP_BACK_MS = 180;  // retour à l'origine d'un dépôt invalide
    var SAVE_DEBOUNCE_MS = 350;
    var TOAST_MS = 1600;
    var TOAST_ERROR_MS = 3200;

    /* Éléments interactifs d'une ligne : un appui dessus ne devient
       jamais un drag — les actions existantes (Éditer, Supprimer…)
       restent intégralement cliquables. */
    var INTERACTIVE = 'a, button, input, select, textarea, label, form';

    var pending = null;         // saisie sous le seuil : { row, pointerId, startX, startY }
    var dragging = null;        // ligne en cours de glisser
    var ghost = null;           // silhouette suivant le pointeur (clone de la ligne)
    var grabX = 0;              // décalage pointeur → coin haut-gauche de la ligne saisie
    var grabY = 0;
    var originLeft = 0;         // position d'origine : destination du snap-back
    var originTop = 0;
    var activePointerId = null; // pointer capturé ; tout autre pointerdown est ignoré
    var clickGuard = false;     // avale le click émis juste après un vrai drag
    var indicator = null;       // dernière ligne marquée pour l'insertion

    var saveTimer = null;       // debounce de l'auto-save
    var saving = false;         // POST en cours
    var dirty = false;          // un drop est arrivé pendant un POST : re-sauver après
    var lastSaved = null;       // payload (JSON) déjà confirmé par le serveur

    var toast = null;
    var toastTimer = null;

    /* Ligne déplaçable depuis la cible du pointerdown : un tr[data-id]
       ENFANT du tbody (une ligne d'un autre tableau de la page, ou la
       silhouette, n'est jamais une unité). */
    function rowFromTarget(target) {
        if (!target || !target.closest) return null;
        var row = target.closest('tr[data-id]');
        if (!row || row.parentNode !== tbody) return null;
        return row;
    }

    /* ---- Glisser-déposer Pointer Events (toujours actif) ----------------- */

    tbody.addEventListener('pointerdown', function (e) {
        clickGuard = false; // nouveau presser : le garde de click d'un drag précédent est périmé
        if (activePointerId !== null) return; // drag en cours : pointer secondaire ignoré
        if (e.button !== 0 || e.isPrimary === false) return; // bouton principal / doigt principal
        if (e.target.closest && e.target.closest(INTERACTIVE)) return; // actions : clics intacts

        var row = rowFromTarget(e.target);
        if (!row) return;

        pending = { row: row, pointerId: e.pointerId, startX: e.clientX, startY: e.clientY };
        activePointerId = e.pointerId;
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
    });

    function onPointerMove(e) {
        if (e.pointerId !== activePointerId || !pending) return;
        if (!dragging) {
            /* Seuil : en dessous c'est un clic normal, pas un drag. */
            if (Math.hypot(e.clientX - pending.startX, e.clientY - pending.startY) < DRAG_THRESHOLD) return;
            beginDrag();
        }
        moveGhost(e.clientX, e.clientY);
        updateDropIndicator(e.clientX, e.clientY);
    }

    /* Démarre le drag : capture du pointeur (les événements continuent
       d'arriver même si le pointeur quitte le tableau ou la fenêtre),
       silhouette ancrée là où la ligne a été saisie. */
    function beginDrag() {
        dragging = pending.row;
        removeGhost(); // snap-back éventuel d'un drag précédent : nettoyé
        dragging.classList.add('dragging');
        var rect = dragging.getBoundingClientRect();
        originLeft = rect.left;
        originTop = rect.top;
        grabX = pending.startX - rect.left;
        grabY = pending.startY - rect.top;
        ghost = buildGhost(rect);
        document.documentElement.classList.add('aeic-pages-drag');
        try { dragging.setPointerCapture(activePointerId); } catch (err) {}
    }

    /* Silhouette = clone de la ligne, hors flux (position:fixed, cf.
       .drag-ghost global + .pages-ghost-row scopé à la vue) et ignorée
       par le pointeur (pointer-events:none — elementFromPoint la
       traverse). Clonée dans <body> (pas dans le tbody : elle ne
       fausse ni le rendu du tableau ni le payload). */
    function buildGhost(rect) {
        var g = dragging.cloneNode(true);
        /* Aucun id dupliqué dans le DOM pendant le drag. */
        g.removeAttribute('id');
        Array.prototype.forEach.call(g.querySelectorAll('[id]'), function (el) {
            el.removeAttribute('id');
        });
        g.classList.remove('dragging', 'drag-over-above', 'drag-over-below');
        g.classList.add('drag-ghost', 'pages-ghost-row');
        g.style.width = rect.width + 'px';
        g.style.left = rect.left + 'px';
        g.style.top = rect.top + 'px';
        document.body.appendChild(g);
        return g;
    }

    function moveGhost(x, y) {
        if (!ghost) return;
        ghost.style.left = (x - grabX) + 'px';
        ghost.style.top = (y - grabY) + 'px';
    }

    /* Cible valide = une AUTRE ligne du MÊME tbody (liste plate : toutes
       les lignes sont interchangeables, aucune notion de conteneur ni de
       type). La silhouette ne compte pas : hors tbody. */
    function dropCandidate(x, y) {
        if (!dragging) return null;
        var hit = document.elementFromPoint(x, y);
        var candidate = hit && hit.closest ? hit.closest('tr[data-id]') : null;
        if (!candidate || candidate === dragging) return null;
        if (candidate.parentNode !== tbody) return null;
        return candidate;
    }

    function updateDropIndicator(x, y) {
        clearIndicator();
        var candidate = dropCandidate(x, y);
        if (!candidate) return; // cible invalide : pas de marqueur (snap-back assuré)
        var rect = candidate.getBoundingClientRect();
        var before = (y - rect.top) < rect.height / 2;
        candidate.classList.add(before ? 'drag-over-above' : 'drag-over-below');
        indicator = candidate;
    }

    function onPointerUp(e) {
        if (e.pointerId !== activePointerId) return;
        var didDrag = !!dragging;
        var moved = false;
        if (dragging) {
            var candidate = dropCandidate(e.clientX, e.clientY);
            if (candidate) {
                var rect = candidate.getBoundingClientRect();
                var before = (e.clientY - rect.top) < rect.height / 2;
                var next = before ? candidate : candidate.nextSibling;
                /* Insertion seulement si elle change réellement la
                   position (drop sur sa propre place = non-événement ;
                   l'égalité stricte avec lastSaved barre la porte au
                   POST inutile). */
                if (next !== dragging && dragging.nextSibling !== next) {
                    dragging.parentNode.insertBefore(dragging, next);
                    moved = true;
                }
            }
        }
        endDrag(!moved); // pas de déplacement effectif → snap-back à l'origine
        if (didDrag) suppressNextClick();
        if (moved) scheduleSave();
    }

    /* Interruption (pointercancel : pan horizontal pris en main par le
       navigateur, appel entrant, Alt+Tab…) : restitution immédiate,
       l'ordre ne bouge pas. */
    function onPointerCancel(e) {
        if (e.pointerId !== activePointerId) return;
        endDrag(false);
    }

    function endDrag(snapBack) {
        if (dragging) dragging.classList.remove('dragging');
        dragging = null;
        pending = null;
        activePointerId = null;
        clearIndicator();
        document.documentElement.classList.remove('aeic-pages-drag');
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        if (snapBack && ghost) snapBackGhost();
        else removeGhost();
    }

    /* Dépôt invalide : la silhouette regagne la position d'origine de
       la ligne puis disparaît (transition CSS, retrait par le JS à la
       fin — aucun reflow du tableau, la ligne réelle n'a pas bougé). */
    function snapBackGhost() {
        var g = ghost;
        ghost = null;
        g.classList.add('drag-ghost-snapback');
        g.style.left = originLeft + 'px';
        g.style.top = originTop + 'px';
        setTimeout(function () {
            if (g.parentNode) g.parentNode.removeChild(g);
        }, SNAP_BACK_MS);
    }

    function removeGhost() {
        if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
        ghost = null;
    }

    /* Après un drag, le navigateur émet quand même un click : on
       l'avale une fois (il ne doit jamais retomber sur les actions). */
    function suppressNextClick() {
        clickGuard = true;
        setTimeout(function () { clickGuard = false; }, 0);
    }

    tbody.addEventListener('click', function (e) {
        if (!clickGuard) return;
        clickGuard = false;
        e.preventDefault();
        e.stopPropagation();
    }, true);

    function clearIndicator() {
        if (indicator) {
            indicator.classList.remove('drag-over-above', 'drag-over-below');
            indicator = null;
        }
    }

    /* ---- Auto-save ------------------------------------------------------- */

    /* Liste complète des ids dans l'ordre du DOM réordonné — exactement
       le payload attendu par POST /admin/pages/reorder. La silhouette
       vit dans <body>, jamais dans le tbody. */
    function buildOrder() {
        var ids = [];
        Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-id]'), function (row) {
            if (row === ghost) return; // défensif
            var id = row.getAttribute('data-id');
            if (id) ids.push(id);
        });
        return { ids: ids };
    }

    /* POST JSON : CSRF dans l'en-tête X-CSRF-Token ($_POST est vide avec
       un corps JSON). Réponse attendue {ok:true} ; toute autre réponse
       (403/400 compris) rejette avec un message français. */
    function post(payload) {
        return fetch(window.AEIC_PAGES_ORDER.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': window.AEIC_PAGES_ORDER.csrf || ''
            },
            body: JSON.stringify(payload)
        }).catch(function (err) {
            /* Échec réseau (hors-ligne, coupure…) : message français
               propre, détail brut en second entre parenthèses. */
            var raw = err && err.message ? err.message : '';
            throw new Error('réseau indisponible' + (raw ? ' (' + raw + ')' : ''));
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok || data.ok !== true) {
                    var msg = data && data.error ? data.error : 'erreur HTTP ' + r.status;
                    throw new Error(msg);
                }
                return data;
            });
        });
    }

    /* Débounce léger : des drops rapprochés ne produisent qu'un envoi,
       avec l'ordre final. Un drop pendant un POST en cours est retenu
       (dirty) et re-sauvegardé à la complétion — aucune perte. */
    function scheduleSave() {
        if (saveTimer) return;
        saveTimer = setTimeout(function () {
            saveTimer = null;
            saveNow();
        }, SAVE_DEBOUNCE_MS);
    }

    function saveNow() {
        var snapshot = JSON.stringify(buildOrder());
        if (snapshot === lastSaved) return; // rien n'a réellement changé
        if (saving) { dirty = true; return; }
        saving = true;
        post(JSON.parse(snapshot)).then(function () {
            lastSaved = snapshot;
            showToast('Ordre enregistré', false);
        }).catch(function (err) {
            /* Échec : l'ordre VISUEL local est conservé — l'admin peut
               réessayer en re-déposant, ou recharger pour revenir à
               l'ordre serveur. */
            showToast('Ordre non enregistré (' + (err && err.message ? err.message : 'réessaie') + ')', true);
        }).then(function () {
            saving = false;
            if (dirty) {
                dirty = false;
                saveNow();
            }
        });
    }

    /* ---- Toast discret (auto-save) --------------------------------------- */
    /* Même classe .aeic-sidebar-toast que la sidebar : même style, déjà
       chargé par admin.css (élément posé par le JS, aria-live pour les
       lecteurs d'écran). */

    function showToast(message, isError) {
        var host = document.body || document.documentElement;
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'aeic-sidebar-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            host.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.toggle('is-error', !!isError);
        toast.classList.add('is-visible');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            if (toast) toast.classList.remove('is-visible');
        }, isError ? TOAST_ERROR_MS : TOAST_MS);
    }

    /* Ordre de départ = celui rendu par le serveur (positions en base) :
       le premier POST ne part que si un drag le change réellement. */
    lastSaved = JSON.stringify(buildOrder());

    /* Auto-save en attente ou en cours (ou dernier envoi échoué) : le
       rechargement perdrait l'ordre local — on avertit. */
    window.addEventListener('beforeunload', function (e) {
        if (saveTimer || saving || dirty || JSON.stringify(buildOrder()) !== lastSaved) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();

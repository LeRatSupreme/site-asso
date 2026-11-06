/**
 * AEIC — Réorganisation de la sidebar admin (drag permanent + auto-save).
 *
 * Plus de mode « Réorganiser » : aucun bouton, aucune barre — le
 * glisser-déposer est TOUJOURS disponible. Maintien du clic (Pointer
 * Events : souris, tactile, stylet — uniforme) sur une unité puis
 * déplacement ≥ 5 px = drag ; en deçà du seuil = clic normal
 * (navigation des liens, repli des panneaux), strictement intact.
 *
 * À chaque dépôt qui réordonne RÉELLEMENT, l'ordre complet est envoyé
 * en auto-save : POST /admin/settings/sidebar-order en JSON, CSRF via
 * l'en-tête X-CSRF-Token (cf. Csrf::checkRequest, qui accepte ce
 * header ; $_POST est vide avec un corps JSON). Retour discret par
 * mini-toast (« Ordre enregistré » / erreur), sans rechargement.
 *
 * INVARIANT STRUCTUREL (exigence explicite) : une unité ne sort JAMAIS
 * de son conteneur — un lien reste dans son conteneur (nav de sa
 * section ou div de son sous-groupe), un sous-groupe dans la nav de sa
 * section, une section dans la sidebar. Une cible d'un autre type ou
 * d'un autre conteneur n'est jamais marquée ni acceptée ; un dépôt
 * invalide ramène l'élément à sa position d'origine (la silhouette
 * « snap-back » regagne sa place puis disparaît). L'ordre change,
 * JAMAIS la structure : la visibilité ne dépend que des gardes PHP du
 * layout.
 *
 * Contrat DOM (posé par views/layouts/admin.php) :
 *   #admin-sidebar
 *     ├─ .admin-nav-section[data-sidebar-section] × N
 *     │    ├─ button.admin-group + nav
 *     │    │    ├─ a.admin-link[data-sidebar-link] (liens directs)
 *     │    │    └─ .admin-nav-subgroup[data-sidebar-subgroup]
 *     │    │         └─ button.admin-subgroup + div > a.admin-link…
 *     └─ .admin-sidebar-foot (HORS périmètre : liens sans
 *        data-sidebar-link, jamais des unités)
 *   window.AEIC_SIDEBAR = { csrf, endpoint }
 *
 * Curseur : pointer par défaut (ce sont des liens qui naviguent) ; le
 * feedback grab/grabbing n'apparaît qu'une fois le drag réellement
 * démarré (appui maintenu ≥ seuil), via une classe posée sur <html>
 * pour la durée du drag seulement — jamais au simple survol.
 *
 * Le drag passe par les Pointer Events (pointerdown → seuil →
 * pointermove/pointerup/pointercancel), pas le HTML5 drag & drop :
 * celui-ci est capricieux sur des boutons/rows imbriqués et inégal
 * desktop/tactile. touch-action reste inchangé : le scroll tactile de
 * la sidebar continue de fonctionner, le navigateur prend la main et
 * émet pointercancel → restitution propre, rien ne bouge.
 */
(function () {
    'use strict';

    var sidebar = document.getElementById('admin-sidebar');
    if (!sidebar || !window.AEIC_SIDEBAR || !window.AEIC_SIDEBAR.endpoint) {
        return; // rôle non autorisé (config absente) ou layout sans contrat
    }

    var DRAG_THRESHOLD = 5;  // px de déplacement avant qu'un appui devienne un drag
    var SNAP_BACK_MS = 180;  // retour à l'origine d'un dépôt invalide
    var SAVE_DEBOUNCE_MS = 350;
    var TOAST_MS = 1600;
    var TOAST_ERROR_MS = 3200;

    /* Sélecteur d'une unité déplaçable, par type. Le type d'une unité
       détermine ses cibles valides : même type ET même conteneur — c'est
       l'invariant structurel, garanti par dropCandidate(). */
    var UNIT_SELECTOR = {
        section: '.admin-nav-section',
        subgroup: '.admin-nav-subgroup',
        link: 'a[data-sidebar-link]'
    };

    var pending = null;         // saisie sous le seuil : { unit, pointerId, startX, startY }
    var dragging = null;        // unité en cours de glisser
    var ghost = null;           // silhouette suivant le pointeur (clone de l'unité)
    var grabX = 0;              // décalage pointeur → coin haut-gauche de l'unité saisie
    var grabY = 0;
    var originLeft = 0;         // position d'origine : destination du snap-back
    var originTop = 0;
    var activePointerId = null; // pointer capturé ; tout autre pointerdown est ignoré
    var clickGuard = false;     // avale le click émis juste après un vrai drag
    var indicator = null;       // dernier élément marqué pour l'insertion

    var saveTimer = null;       // debounce de l'auto-save
    var saving = false;         // POST en cours
    var dirty = false;          // un drop est arrivé pendant un POST : re-sauver après
    var lastSaved = null;       // payload (JSON) déjà confirmé par le serveur

    var toast = null;
    var toastTimer = null;

    /* Type d'une unité déplaçable (null = pas une unité). tagName mis en
       majuscules par robustesse (HTML: 'A', certains DOM de test: 'a'). */
    function typeOf(unit) {
        if (!unit || !unit.classList || !unit.tagName) return null;
        if (unit.classList.contains('admin-nav-section')) return 'section';
        if (unit.classList.contains('admin-nav-subgroup')) return 'subgroup';
        if (String(unit.tagName).toUpperCase() === 'A' && unit.hasAttribute('data-sidebar-link')) return 'link';
        return null;
    }

    /* Résolution de l'unité saisie depuis la cible du pointerdown :
       d'abord le lien (sinon on attraperait sa section/sous-groupe),
       puis le sous-groupe, puis la section. Les liens du pied de
       sidebar n'ont pas data-sidebar-link : jamais des unités. */
    function unitFromTarget(target) {
        if (!target || !target.closest) return null;
        var link = target.closest(UNIT_SELECTOR.link);
        if (link) return link;
        var sub = target.closest(UNIT_SELECTOR.subgroup);
        if (sub) return sub;
        var sec = target.closest(UNIT_SELECTOR.section);
        if (sec) return sec;
        return null;
    }

    /* ---- Glisser-déposer Pointer Events (toujours actif) ----------------- */

    sidebar.addEventListener('pointerdown', function (e) {
        clickGuard = false; // nouveau presser : le garde de click d'un drag précédent est périmé
        if (activePointerId !== null) return; // drag en cours : pointer secondaire ignoré
        if (e.button !== 0 || e.isPrimary === false) return; // bouton principal / doigt principal
        var unit = unitFromTarget(e.target);
        if (!unit || !typeOf(unit)) return;

        pending = { unit: unit, pointerId: e.pointerId, startX: e.clientX, startY: e.clientY };
        activePointerId = e.pointerId;
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
    });

    function onPointerMove(e) {
        if (e.pointerId !== activePointerId || !pending) return;
        if (!dragging) {
            /* Seuil : en dessous c'est un clic normal (navigation des
               liens, repli des panneaux), pas un drag. */
            if (Math.hypot(e.clientX - pending.startX, e.clientY - pending.startY) < DRAG_THRESHOLD) return;
            beginDrag();
        }
        moveGhost(e.clientX, e.clientY);
        updateDropIndicator(e.clientX, e.clientY);
    }

    /* Démarre le drag : capture du pointeur (les événements continuent
       d'arriver même si le pointeur sort de la sidebar ou de la
       fenêtre), silhouette ancrée là où l'unité a été saisie. */
    function beginDrag() {
        dragging = pending.unit;
        removeGhost(); // snap-back éventuel d'un drag précédent : nettoyé
        dragging.classList.add('dragging');
        var rect = dragging.getBoundingClientRect();
        originLeft = rect.left;
        originTop = rect.top;
        grabX = pending.startX - rect.left;
        grabY = pending.startY - rect.top;
        ghost = buildGhost(rect);
        document.documentElement.classList.add('aeic-sidebar-drag');
        try { dragging.setPointerCapture(activePointerId); } catch (err) {}
    }

    /* Silhouette = clone de l'unité, hors flux (position:fixed) et
       ignorée par le pointeur (pointer-events:none). Posée DANS la
       sidebar pour garder les styles scopés (#admin-sidebar …). */
    function buildGhost(rect) {
        var g = dragging.cloneNode(true);
        /* Aucun id dupliqué dans le DOM pendant le drag. */
        g.removeAttribute('id');
        Array.prototype.forEach.call(g.querySelectorAll('[id]'), function (el) {
            el.removeAttribute('id');
        });
        g.classList.remove('dragging');
        g.classList.add('drag-ghost');
        g.style.width = rect.width + 'px';
        g.style.left = rect.left + 'px';
        g.style.top = rect.top + 'px';
        sidebar.appendChild(g);
        return g;
    }

    function moveGhost(x, y) {
        if (!ghost) return;
        ghost.style.left = (x - grabX) + 'px';
        ghost.style.top = (y - grabY) + 'px';
    }

    /* Cible valide = même type ET même conteneur (parentNode) que
       l'unité glissée — INVARIANT STRUCTUREL : un lien ne quitte jamais
       son conteneur, un sous-groupe jamais sa section, une section
       jamais la sidebar. La silhouette ne compte pas : pointer-events:
       none, elementFromPoint la traverse. */
    function dropCandidate(x, y) {
        if (!dragging) return null;
        var type = typeOf(dragging);
        var hit = document.elementFromPoint(x, y);
        var candidate = hit && hit.closest ? hit.closest(UNIT_SELECTOR[type]) : null;
        if (!candidate || candidate === dragging) return null;
        if (candidate.parentNode !== dragging.parentNode) return null;
        return candidate;
    }

    function updateDropIndicator(x, y) {
        clearIndicator();
        var candidate = dropCandidate(x, y);
        if (!candidate) return; // cible invalide : pas de marqueur (déjà snap-back assuré)
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
                if (next === ghost) next = candidate; // jamais derrière la silhouette
                /* Insertion seulement si elle change réellement la
                   position (drop sur sa propre place = non-événement). */
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

    /* Interruption (pointercancel : scroll tactile pris en main par le
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
        document.documentElement.classList.remove('aeic-sidebar-drag');
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        if (snapBack && ghost) snapBackGhost();
        else removeGhost();
    }

    /* Dépôt invalide : la silhouette regagne la position d'origine de
       l'unité puis disparaît (transition CSS, retrait par le JS à la
       fin — aucun reflow de la liste, l'unité réelle n'a pas bougé). */
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

    /* Après un drag, le navigateur émet quand même un click (lien =
       navigation, titre = repli de panneau) : on l'avale une fois. */
    function suppressNextClick() {
        clickGuard = true;
        setTimeout(function () { clickGuard = false; }, 0);
    }

    sidebar.addEventListener('click', function (e) {
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

    /* Construit {sections, items, subgroups} depuis le DOM réordonné.
       Clés = libellés EXACTS des data-attributs : ce sont eux que le
       serveur réapplique à son arbre (SidebarOrder::apply). */
    function buildOrder() {
        var sections = [];
        var items = {};
        var subgroups = {};

        Array.prototype.forEach.call(sidebar.querySelectorAll('.admin-nav-section'), function (sec) {
            if (sec.closest('.drag-ghost')) return; // silhouette (drag ou snap-back) : jamais dans le payload
            var title = sec.getAttribute('data-sidebar-section') || '';
            sections.push(title);

            var direct = [];
            var subgroupEls = [];
            Array.prototype.forEach.call(sec.querySelectorAll('a[data-sidebar-link]'), function (a) {
                var sg = a.closest ? a.closest('.admin-nav-subgroup') : null;
                if (sg) {
                    if (subgroupEls.indexOf(sg) === -1) subgroupEls.push(sg);
                } else {
                    direct.push(a.getAttribute('data-sidebar-link') || '');
                }
            });
            items[title] = direct;

            Array.prototype.forEach.call(subgroupEls, function (sg) {
                var key = title + '|' + (sg.getAttribute('data-sidebar-subgroup') || '');
                var labels = [];
                Array.prototype.forEach.call(sg.querySelectorAll('a[data-sidebar-link]'), function (a) {
                    labels.push(a.getAttribute('data-sidebar-link') || '');
                });
                subgroups[key] = labels;
            });
        });

        return { order: { sections: sections, items: items, subgroups: subgroups } };
    }

    /* POST JSON : CSRF dans l'en-tête X-CSRF-Token ($_POST est vide avec
       un corps JSON). Réponse attendue {ok:true} ; toute autre réponse
       (403 compris) rejette avec un message français. */
    function post(payload) {
        return fetch(window.AEIC_SIDEBAR.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': window.AEIC_SIDEBAR.csrf || ''
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

    /* Ordre de départ = celui rendu par le serveur : le premier POST ne
       part que si un drag le change réellement. */
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

/**
 * AEIC — Réorganisation de la sidebar admin (glisser-déposer + ▲▼).
 *
 * Permet à un admin/fondateur de réordonner sections, sous-groupes et
 * liens de la sidebar, par glisser-déposer Pointer Events (souris,
 * tactile, stylet — uniforme) ou boutons ▲▼, puis de
 * sauvegarder l'ordre : POST /admin/settings/sidebar-order en JSON,
 * CSRF via l'en-tête X-CSRF-Token (cf. Csrf::checkRequest, qui accepte
 * ce header ; $_POST est vide avec un corps JSON).
 *
 * Contrat DOM (posé par views/layouts/admin.php) :
 *   #admin-sidebar
 *     ├─ .admin-nav-section[data-sidebar-section] × N
 *     │    ├─ button.admin-group + nav
 *     │    │    ├─ a.admin-link[data-sidebar-link] (liens directs)
 *     │    │    └─ .admin-nav-subgroup[data-sidebar-subgroup]
 *     │    │         └─ button.admin-subgroup + div > a.admin-link…
 *     ├─ .admin-reorder (#admin-reorder-toggle / #admin-reorder-bar)
 *     └─ .admin-sidebar-foot (HORS périmètre)
 *   window.AEIC_SIDEBAR = { csrf, endpoint }
 *
 * En mode édition la navigation est neutralisée (preventDefault sur les
 * liens du menu) : impossible de quitter la page par erreur et de perdre
 * l'ordre en cours. L'ordre change, JAMAIS la structure : un lien reste
 * dans son conteneur, un sous-groupe dans sa section — la visibilité ne
 * dépend que des gardes PHP du layout.
 *
 * Le drag passe par les Pointer Events (pointerdown → seuil de 5 px →
 * pointermove/pointerup/pointercancel), pas le HTML5 drag & drop :
 * celui-ci est capricieux sur des boutons/rows imbriqués (dragstart
 * perdu selon la cible) et inégal desktop/tactile. Cf. section dédiée.
 */
(function () {
    'use strict';

    var sidebar = document.getElementById('admin-sidebar');
    var toggleBtn = document.getElementById('admin-reorder-toggle');
    var bar = document.getElementById('admin-reorder-bar');
    if (!sidebar || !toggleBtn || !bar || !window.AEIC_SIDEBAR || !window.AEIC_SIDEBAR.endpoint) {
        return; // rôle non autorisé ou layout sans contrat : rien à faire
    }

    var saveBtn = document.getElementById('admin-reorder-save');
    var resetBtn = document.getElementById('admin-reorder-reset');
    var cancelBtn = document.getElementById('admin-reorder-cancel');
    var statusEl = document.getElementById('admin-reorder-status');

    var editing = false;
    var rows = [];        // lignes flex créées en mode édition (à défaire en sortie)
    var moveBoxes = [];   // boutons ▲▼ : { box, unit, up, down }
    var grips = [];       // poignées ⋮⋮ posées sur les lignes en mode édition
    var dragging = null;  // unité en cours de glisser
    var indicator = null; // dernier élément marqué pour l'insertion

    var ROW_CLASS = {
        section: 'admin-nav-section',
        subgroup: 'admin-nav-subgroup',
        link: 'admin-link-row'
    };

    /* Type d'une unité déplaçable (null = pas une unité). */
    function typeOf(unit) {
        if (!unit || !unit.classList) return null;
        if (unit.classList.contains('admin-nav-section')) return 'section';
        if (unit.classList.contains('admin-nav-subgroup')) return 'subgroup';
        if (unit.classList.contains('admin-link-row')) return 'link';
        return null;
    }

    /* Déplacement ▲▼ : échange avec le voisin DOM immédiat, mais
       uniquement s'il est de MÊME TYPE (même règle que dropCandidate) —
       un lien plat s'arrête au premier sous-groupe voisin, et
       inversement, comme le glisser-déposer l'impose déjà et comme le
       serveur sérialise (liens plats avant sous-groupes). */
    function moveUnit(unit, dir) {
        var type = typeOf(unit);
        var neighbor = dir < 0 ? unit.previousElementSibling : unit.nextElementSibling;
        if (!type || !neighbor || typeOf(neighbor) !== type) return;
        unit.parentNode.insertBefore(unit, dir < 0 ? neighbor : neighbor.nextSibling);
        refreshMoveButtons();
    }

    /* Enveloppe un élément dans une ligne flex (pour poser les boutons
       ▲▼ à côté du titre ou du lien sans toucher à leur style). */
    function wrapInRow(el, className) {
        var row = document.createElement('div');
        row.className = className;
        el.parentNode.insertBefore(row, el);
        row.appendChild(el);
        rows.push(row);
        return row;
    }

    /* Poignée ⋮⋮ : affordance de saisie posée sur chaque ligne en mode
       édition. Décorative (aria-hidden) — la saisie reste possible sur
       toute la ligne, la poignée ne fait que le signaler. */
    function addGrip(row) {
        var grip = document.createElement('span');
        grip.className = 'sidebar-grip';
        grip.textContent = '⋮⋮';
        grip.setAttribute('aria-hidden', 'true');
        row.insertBefore(grip, row.firstChild);
        grips.push(grip);
    }

    function addMoveButtons() {
        Array.prototype.forEach.call(rows, function (row) {
            var isTitle = row.classList.contains('admin-title-row');
            var unit = isTitle ? row.parentNode : row;
            var kind = isTitle
                ? (unit.classList.contains('admin-nav-section') ? 'la section' : 'le sous-groupe')
                : 'le lien';
            var link = unit.querySelector('a[data-sidebar-link]');
            var name = unit.getAttribute('data-sidebar-section')
                || unit.getAttribute('data-sidebar-subgroup')
                || (link ? link.getAttribute('data-sidebar-link') : '');

            var box = document.createElement('div');
            box.className = 'sidebar-move';

            var up = document.createElement('button');
            up.type = 'button';
            up.className = 'sidebar-move-btn';
            up.textContent = '▲';
            up.setAttribute('aria-label', 'Monter ' + kind + ' ' + name);

            var down = document.createElement('button');
            down.type = 'button';
            down.className = 'sidebar-move-btn';
            down.textContent = '▼';
            down.setAttribute('aria-label', 'Descendre ' + kind + ' ' + name);

            /* Pas de drag depuis les boutons : le handler pointerdown
               ignore .sidebar-move (cf. plus bas). */
            up.addEventListener('click', function () { moveUnit(unit, -1); });
            down.addEventListener('click', function () { moveUnit(unit, 1); });

            box.appendChild(up);
            box.appendChild(down);
            row.insertBefore(box, row.firstChild);
            addGrip(row); // poignée en tête de ligne : [⋮⋮][▲▼][libellé]
            moveBoxes.push({ box: box, unit: unit, up: up, down: down });
        });
    }

    function refreshMoveButtons() {
        Array.prototype.forEach.call(moveBoxes, function (m) {
            /* ▲▼ désactivés aux frontières : bord du conteneur OU voisin
               immédiat d'un autre type (lien plat ↔ sous-groupe) — même
               règle que moveUnit et dropCandidate. */
            var type = typeOf(m.unit);
            var prev = m.unit.previousElementSibling;
            var next = m.unit.nextElementSibling;
            m.up.disabled = !type || !prev || typeOf(prev) !== type;
            m.down.disabled = !type || !next || typeOf(next) !== type;
        });
    }

    function enterEdit() {
        if (editing) return;
        editing = true;
        rows = [];
        moveBoxes = [];
        grips = [];
        sidebar.classList.add('is-reordering');

        /* Lignes flex : titres de sections/sous-groupes et liens du MENU
           uniquement (jamais les liens du pied de sidebar : sans
           data-sidebar-link, ils ne sont pas sélectionnés). */
        Array.prototype.forEach.call(
            sidebar.querySelectorAll('button.admin-group, button.admin-subgroup'),
            function (btn) { wrapInRow(btn, 'admin-title-row'); }
        );
        Array.prototype.forEach.call(
            sidebar.querySelectorAll('a[data-sidebar-link]'),
            function (a) {
                /* Le drag part de la LIGNE, pas du lien natif :
                   pointerdown résout l'unité par closest(). */
                wrapInRow(a, 'admin-link-row');
            }
        );

        /* Plus d'attribut draggable : les unités déplaçables (sections,
           sous-groupes, lignes de liens) sont résolues au pointerdown
           par closest(), et l'affordance (curseur, touch-action) vient
           du seul état .is-reordering en CSS. */

        addMoveButtons();
        refreshMoveButtons();

        toggleBtn.hidden = true;
        bar.hidden = false;
        setStatus('Glisse les éléments, ou utilise ▲▼.', false);
        window.addEventListener('beforeunload', unloadGuard);
    }

    /* Restaure le DOM hors édition (boutons et poignées retirés, lignes
       déballées). Utilisé avant l'annulation. */
    function exitEdit() {
        if (!editing) return;
        editing = false;
        window.removeEventListener('beforeunload', unloadGuard);

        Array.prototype.forEach.call(moveBoxes, function (m) {
            if (m.box.parentNode) m.box.parentNode.removeChild(m.box);
        });
        Array.prototype.forEach.call(grips, function (g) {
            if (g.parentNode) g.parentNode.removeChild(g);
        });
        Array.prototype.forEach.call(rows.slice(), function (row) {
            while (row.firstChild) {
                row.parentNode.insertBefore(row.firstChild, row);
            }
            if (row.parentNode) row.parentNode.removeChild(row);
        });
        sidebar.classList.remove('is-reordering');
        endDrag(); // libère aussi silhouette/listeners si un drag traînait
        rows = [];
        moveBoxes = [];
        grips = [];
        bar.hidden = true;
        toggleBtn.hidden = false;
        setStatus('', false);
    }

    /* Avertissement avant de quitter la page en cours d'édition. */
    function unloadGuard(e) {
        e.preventDefault();
        e.returnValue = '';
    }

    /* ---- Glisser-déposer Pointer Events (délégué sur la sidebar) -------
       Remplace le drag & drop HTML5 (dragstart/dragover/drop/dragend),
       capricieux sur des boutons/rows imbriqués. Mécanique uniforme
       souris/tactile/stylet : pointerdown → seuil de 5 px (en dessous =
       clic normal, laissé intact) → drag : l'unité saisie reste en
       place estompée (.dragging), une silhouette (clone) suit le
       pointeur, la position d'insertion candidate est marquée comme
       auparavant (drag-over-above/below). */

    var DRAG_THRESHOLD = 5;     // px de déplacement avant de démarrer le drag

    var pending = null;         // saisie sous le seuil : { unit, pointerId, startX, startY }
    var ghost = null;           // silhouette suivant le pointeur (clone de l'unité)
    var grabX = 0;              // décalage pointeur → coin haut-gauche de l'unité saisie
    var grabY = 0;
    var activePointerId = null; // pointer capturé ; tout autre pointerdown est ignoré
    var clickGuard = false;     // avale le click émis juste après un vrai drag

    sidebar.addEventListener('pointerdown', function (e) {
        clickGuard = false; // nouveau presser : le click résiduel d'un drag précédent est périmé
        if (!editing || activePointerId !== null) return; // drag en cours : pointer secondaire ignoré
        if (e.button !== 0 || e.isPrimary === false) return; // bouton principal / doigt principal
        var target = e.target;
        if (!target || !target.closest) return;
        if (target.closest('.sidebar-move')) return; // ▲▼ : des boutons, jamais un drag
        var unit = target.closest('.admin-nav-section, .admin-nav-subgroup, .admin-link-row');
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
            /* Seuil : en dessous c'est un clic normal (neutralisé sur les
               liens, repli de panneau sur les titres), pas un drag. */
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
        dragging.classList.add('dragging');
        var rect = dragging.getBoundingClientRect();
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

    /* Cible valide = même type ET même conteneur que l'unité glissée
       (la silhouette ne compte pas : pointer-events:none). */
    function dropCandidate(x, y) {
        if (!dragging) return null;
        var type = typeOf(dragging);
        var hit = document.elementFromPoint(x, y);
        var candidate = hit && hit.closest ? hit.closest('.' + ROW_CLASS[type]) : null;
        if (!candidate || candidate === dragging) return null;
        if (candidate.parentNode !== dragging.parentNode) return null;
        return candidate;
    }

    function updateDropIndicator(x, y) {
        clearIndicator();
        var candidate = dropCandidate(x, y);
        if (!candidate) return; // cible invalide : pas de marqueur
        var rect = candidate.getBoundingClientRect();
        var before = (y - rect.top) < rect.height / 2;
        candidate.classList.add(before ? 'drag-over-above' : 'drag-over-below');
        indicator = candidate;
    }

    function onPointerUp(e) {
        if (e.pointerId !== activePointerId) return;
        var didDrag = !!dragging;
        if (dragging) {
            var candidate = dropCandidate(e.clientX, e.clientY);
            if (candidate) {
                var rect = candidate.getBoundingClientRect();
                var before = (e.clientY - rect.top) < rect.height / 2;
                dragging.parentNode.insertBefore(dragging, before ? candidate : candidate.nextSibling);
                refreshMoveButtons();
            }
        }
        endDrag();
        if (didDrag) suppressNextClick();
    }

    /* Interruption (pointercancel : appel entrant, geste récupéré par le
       navigateur…) : restitution propre, l'ordre ne bouge pas. */
    function onPointerCancel(e) {
        if (e.pointerId !== activePointerId) return;
        endDrag();
    }

    function endDrag() {
        if (dragging) dragging.classList.remove('dragging');
        dragging = null;
        pending = null;
        activePointerId = null;
        clearIndicator();
        removeGhost();
        document.documentElement.classList.remove('aeic-sidebar-drag');
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
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

    /* Mode édition : neutralise la navigation des liens du menu (capture :
       coupe aussi le repli du drawer mobile déclenché par les <a>). */
    sidebar.addEventListener('click', function (e) {
        if (!editing) return;
        var a = e.target && e.target.closest ? e.target.closest('a[data-sidebar-link]') : null;
        if (a) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    /* ---- Sauvegarde ------------------------------------------------------ */

    /* Construit {sections, items, subgroups} depuis le DOM réordonné.
       Clés = libellés EXACTS des data-attributs : ce sont eux que le
       serveur réapplique à son arbre (SidebarOrder::apply). */
    function buildOrder() {
        var sections = [];
        var items = {};
        var subgroups = {};

        Array.prototype.forEach.call(sidebar.querySelectorAll('.admin-nav-section'), function (sec) {
            if (sec.closest('.drag-ghost')) return; // silhouette de drag : jamais dans le payload
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
            /* Échec réseau (hors-ligne, coupure…) : le navigateur rejette
               avec un message brut anglais (« Failed to fetch ») — message
               français propre, détail brut en second entre parenthèses. */
            var raw = err && err.message ? err.message : '';
            throw new Error('Réseau indisponible — réessaie' + (raw ? ' (' + raw + ')' : ''));
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

    function setStatus(message, isError) {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.classList.toggle('is-error', !!isError);
    }

    function setBusy(busy) {
        if (saveBtn) saveBtn.disabled = busy;
        if (resetBtn) resetBtn.disabled = busy;
    }

    /* Réussite → rechargement : la page est rendue avec l'ordre appliqué
       côté serveur (SidebarOrder::apply au layout). */
    function onSaved(message) {
        editing = false; // pas d'avertissement au rechargement
        window.removeEventListener('beforeunload', unloadGuard);
        setStatus(message, false);
        setTimeout(function () { location.reload(); }, 400);
    }

    toggleBtn.addEventListener('click', enterEdit);

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            /* Annuler = abandon de l'ordre non enregistré : retour à
               l'ordre serveur par rechargement (les manipulations locales
               ne sont jamais persistées sans « Enregistrer »). */
            exitEdit();
            location.reload();
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            setBusy(true);
            setStatus('Enregistrement…', false);
            post(buildOrder()).then(function () {
                onSaved('✔ Enregistré');
            }).catch(function (err) {
                setBusy(false);
                setStatus("Échec de l'enregistrement (" + (err && err.message ? err.message : 'réessaie') + ').', true);
            });
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            setBusy(true);
            setStatus('Réinitialisation…', false);
            post({ reset: true }).then(function () {
                onSaved('Réinitialisé');
            }).catch(function (err) {
                setBusy(false);
                setStatus('Échec de la réinitialisation (' + (err && err.message ? err.message : 'réessaie') + ').', true);
            });
        });
    }
})();

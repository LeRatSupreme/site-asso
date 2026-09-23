/**
 * AEIC — Modal de confirmation réutilisable.
 * Usage : mettre data-confirm="Message de confirmation" sur un <form> ou <button>.
 * Le modal s'affiche au lieu du confirm() natif du navigateur.
 * Libellé du bouton de confirmation : data-confirm-button="…" sur l'élément
 * (ex. « 🚫 Plus en vente ») ; sinon « 🗑️ Supprimer » si le message parle de
 * suppression, sinon « Confirmer » (style non destructif).
 */
(function () {
    if (document.getElementById('confirm-modal')) return;

    // Crée le modal une seule fois.
    var overlay = document.createElement('div');
    overlay.id = 'confirm-modal';
    overlay.className = 'confirm-overlay';
    overlay.hidden = true;
    overlay.innerHTML = 
        '<div class="confirm-box">' +
            '<div class="confirm-icon">⚠️</div>' +
            '<p class="confirm-text" id="confirm-text"></p>' +
            '<div class="confirm-buttons">' +
                '<button type="button" class="btn btn-outline" id="confirm-cancel">Annuler</button>' +
                '<button type="button" class="btn btn-primary" id="confirm-yes">Confirmer</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var textEl = document.getElementById('confirm-text');
    var cancelBtn = document.getElementById('confirm-cancel');
    var yesBtn = document.getElementById('confirm-yes');
    var pendingForm = null;
    var pendingHref = null;

    function open(message, form, href, confirmLabel) {
        textEl.textContent = message;
        pendingForm = form || null;
        pendingHref = href || null;
        // Libellé : surcharge explicite (data-confirm-button), sinon
        // « 🗑️ Supprimer » si le message parle de suppression, sinon
        // « Confirmer » — style rouge réservé aux actions destructives.
        var label = confirmLabel;
        var danger = true;
        if (!label) {
            if (/supprim/i.test(message)) {
                label = '🗑️ Supprimer';
            } else {
                label = 'Confirmer';
                danger = false;
            }
        }
        yesBtn.textContent = label;
        yesBtn.classList.toggle('btn-danger', danger);
        yesBtn.classList.toggle('btn-primary', !danger);
        overlay.hidden = false;
    }
    function close() {
        overlay.hidden = true;
        pendingForm = null;
        pendingHref = null;
    }

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) close();
    });

    yesBtn.addEventListener('click', function () {
        if (pendingForm) {
            pendingForm.submit();
        } else if (pendingHref) {
            window.location.href = pendingHref;
        }
        close();
    });

    // Intercepte tous les formulaires et liens avec data-confirm.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (!msg) return;
        e.preventDefault();
        open(msg, form, null, form.getAttribute('data-confirm-button'));
    });

    // Intercepte les liens avec data-confirm.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-confirm]');
        if (!link) return;
        e.preventDefault();
        open(link.getAttribute('data-confirm'), null, link.href, link.getAttribute('data-confirm-button'));
    });
})();

/**
 * AEIC — Préservation de la position de scroll après une action POST.
 * Usage : ajouter data-preserve-scroll sur un <form> qui POSTe puis redirige
 * vers la même page (ex : suppression d'une ligne dans une longue liste).
 * La position est sauvegardée au submit (phase capture, donc avant le modal
 * data-confirm) puis restaurée au chargement suivant de la même page.
 * NB : le modal data-confirm re-soumet via form.submit(), qui ne déclenche
 * pas d'événement submit — le scroll n'est donc sauvegardé qu'une fois, ici.
 */
(function () {
    var PREFIX = 'aeic_scroll:';

    function storageKey() {
        return PREFIX + location.pathname;
    }

    function saveScroll() {
        try {
            sessionStorage.setItem(storageKey(), String(window.scrollY));
        } catch (e) { /* stockage indisponible (navigation privée…) */ }
    }

    function clearScroll() {
        try {
            sessionStorage.removeItem(storageKey());
        } catch (e) { /* ignoré */ }
    }

    function restoreScroll() {
        var raw = null;
        try {
            raw = sessionStorage.getItem(storageKey());
        } catch (e) { /* ignoré */ }
        if (raw === null) return;
        try {
            sessionStorage.removeItem(storageKey());
        } catch (e) { /* ignoré */ }
        var y = parseInt(raw, 10);
        if (!isNaN(y) && y > 0) window.scrollTo(0, y);
    }

    // Phase capture : passe avant le handler data-confirm (phase bulle),
    // qui fait preventDefault() puis ré-affiche/re-soumet le formulaire en JS.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.getAttribute && form.hasAttribute('data-preserve-scroll')) {
            saveScroll();
        }
    }, true);

    // Annulation du modal de confirmation : on jette la position sauvegardée
    // pour ne pas restaurer un scroll obsolète lors d'une navigation ultérieure.
    document.addEventListener('click', function (e) {
        var modal = document.getElementById('confirm-modal');
        if (!modal || modal.hidden) return;
        var t = e.target;
        if (t === modal || (t && t.id === 'confirm-cancel')) clearScroll();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var modal = document.getElementById('confirm-modal');
        if (modal && !modal.hidden) clearScroll();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScroll);
    } else {
        restoreScroll();
    }
})();

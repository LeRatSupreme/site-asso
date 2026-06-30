/**
 * AEIC — Modal de confirmation réutilisable.
 * Usage : mettre data-confirm="Message de confirmation" sur un <form> ou <button>.
 * Le modal s'affiche au lieu du confirm() natif du navigateur.
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
                '<button type="button" class="btn btn-danger" id="confirm-yes">🗑️ Supprimer</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var textEl = document.getElementById('confirm-text');
    var cancelBtn = document.getElementById('confirm-cancel');
    var yesBtn = document.getElementById('confirm-yes');
    var pendingForm = null;
    var pendingHref = null;

    function open(message, form, href) {
        textEl.textContent = message;
        pendingForm = form || null;
        pendingHref = href || null;
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
        open(msg, form, null);
    });

    // Intercepte les liens avec data-confirm.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-confirm]');
        if (!link) return;
        e.preventDefault();
        open(link.getAttribute('data-confirm'), null, link.href);
    });
})();

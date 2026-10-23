/*
 * AEIC — Révélation des liens kiosque masqués (partial _kiosk_link.php).
 *
 * Même mécanique que la page Kiosques : le HTML initial ne contient
 * AUCUN jeton. L'URL complète n'arrive qu'en réponse d'un POST CSRF
 * vers /admin/kiosques/reveal (chaque affichage est tracé dans le
 * journal d'audit). Classes dédiées « kl- » : aucune collision avec le
 * script inline de la page /admin/kiosques (« kq- »).
 */
(function () {
    'use strict';

    function csrfOf(row) {
        var input = row.querySelector('input[name="_csrf"]');
        return input ? input.value : '';
    }

    function fetchUrl(row) {
        var fd = new FormData();
        fd.append('_csrf', csrfOf(row));
        fd.append('scope', row.getAttribute('data-kl-scope') || 'membres');
        fd.append('path', row.getAttribute('data-kl-path') || '');
        return fetch(row.getAttribute('data-kl-reveal') || '/admin/kiosques/reveal', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                return (d && d.ok && d.url) ? String(d.url) : null;
            })
            .catch(function () { return null; });
    }

    function applyUrl(row, url) {
        row.querySelector('input').value = url;
        row.querySelector('.kl-copy').setAttribute('data-url', url);
        var open = row.querySelector('.kl-open');
        if (open) {
            open.setAttribute('href', url);
            open.removeAttribute('hidden');
        }
    }

    function clearUrl(row, btn) {
        row.querySelector('input').value = '••••••••';
        row.querySelector('.kl-copy').removeAttribute('data-url');
        var open = row.querySelector('.kl-open');
        if (open) {
            open.removeAttribute('href');
            open.setAttribute('hidden', '');
        }
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = 'Afficher le lien';
    }

    function flash(btn, label) {
        var old = btn.textContent;
        btn.textContent = label;
        setTimeout(function () { btn.textContent = old; }, 1500);
    }

    /* Révélation / masquage : POST CSRF, l'URL complète n'arrive qu'en
       réponse — jamais dans le HTML initial. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.kl-reveal');
        if (!btn) return;
        var row = btn.closest('.kl-row');
        if (!row) return;

        if (btn.getAttribute('aria-expanded') === 'true') {
            clearUrl(row, btn);
            return;
        }

        fetchUrl(row).then(function (url) {
            if (!url) {
                flash(btn, 'Indisponible');
                return;
            }
            applyUrl(row, url);
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Masquer le lien';
        });
    });

    /* Copie : après révélation ; sinon révèle d'abord, copie ensuite. */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.kl-copy');
        if (!b) return;
        var url = b.getAttribute('data-url');

        var copy = function (u) {
            var done = function () { flash(b, 'Copié ✓'); };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(u).then(done, done);
            } else {
                var input = b.closest('.kl-row').querySelector('input');
                input.select();
                try { document.execCommand('copy'); } catch (err) {}
                done();
            }
        };

        if (url) {
            copy(url);
            return;
        }

        var row = b.closest('.kl-row');
        if (!row) return;
        flash(b, 'Révélation…');
        fetchUrl(row).then(function (u) {
            if (!u) {
                flash(b, 'Indisponible');
                return;
            }
            var reveal = row.querySelector('.kl-reveal');
            if (reveal) {
                applyUrl(row, u);
                reveal.setAttribute('aria-expanded', 'true');
                reveal.textContent = 'Masquer le lien';
            }
            copy(u);
        });
    });
})();

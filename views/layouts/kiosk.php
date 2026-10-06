<?php

declare(strict_types=1);

/**
 * Layout « kiosque » : coquille minimale pour les accès par lien secret
 * (ex. Réapprovisionnement sur le téléphone).
 *
 * Contrairement au layout admin, il n'affiche AUCUNE navigation : pas de
 * sidebar, pas de menu, pas de déconnexion, pas de lien vers le site.
 * Seul le contenu de la vue est rendu — rien d'autre n'est visible ni
 * accessible depuis un lien kiosque.
 *
 * @var string $content
 * @var string $title
 */

use App\Models\Setting;

$siteName = Setting::get('site_name', 'AEIC');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Consultation') ?> — <?= e($siteName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
    <!-- Toujours chargée : les pages kiosque sont des pages compta. -->
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/compta.css')) ?>">
</head>
<body class="admin-body">
    <!-- Pastille profil : qui est connecté sur ce kiosque (identité stockée
         sur l'appareil, envoyée avec chaque enregistrement pour la trace). -->
    <button type="button" id="kWhoBtn" class="kwho" aria-label="Indiquer qui tu es">?</button>

    <div id="kWhoModal" class="kwho-modal" hidden>
        <div class="kwho-card" role="dialog" aria-modal="true" aria-labelledby="kWhoTitle">
            <h2 id="kWhoTitle" class="kwho-title">Qui fais le comptage ?</h2>
            <p class="kwho-sub">Obligatoire pour enregistrer : ton nom sera attaché à chaque modification.</p>
            <label class="kwho-field"><span>Prénom (obligatoire)</span>
                <input type="text" id="kWhoPrenom" autocomplete="given-name" maxlength="60"></label>
            <label class="kwho-field"><span>Nom (obligatoire)</span>
                <input type="text" id="kWhoNom" autocomplete="family-name" maxlength="60"></label>
            <label class="kwho-field"><span>Rôle (obligatoire — ex. vice-trésorier)</span>
                <input type="text" id="kWhoAlias" maxlength="60" placeholder="vice-trésorier, président…"></label>
            <p class="kwho-error" id="kWhoError" hidden>Prénom, nom et rôle sont obligatoires pour enregistrer.</p>
            <div class="kwho-actions">
                <button type="button" class="btn btn-primary btn-sm" id="kWhoSave">Enregistrer</button>
            </div>
        </div>
    </div>

    <style>
        .kwho {
            position: fixed; top: 10px; right: 12px; z-index: 60;
            width: 44px; height: 44px; border-radius: 50%;
            border: 2px solid var(--primary, #48bdd3);
            background: rgba(72, 189, 211, 0.14);
            color: var(--primary, #48bdd3); font-weight: 900; font-size: 1rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; user-select: none; -webkit-tap-highlight-color: transparent;
        }
        .kwho-modal {
            position: fixed; inset: 0; z-index: 70;
            background: rgba(4, 10, 20, 0.72);
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .kwho-modal[hidden] { display: none; }
        .kwho-card {
            width: 100%; max-width: 340px; padding: 1.2rem 1.2rem 1rem;
            background: #0e2036; border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.45);
        }
        .kwho-title { margin: 0 0 0.2rem; font-size: 1.15rem; font-weight: 900; }
        .kwho-sub { margin: 0 0 0.9rem; font-size: 0.82rem; color: var(--muted, #8892a6); }
        .kwho-field { display: block; margin-bottom: 0.7rem; }
        .kwho-field span {
            display: block; font-size: 0.75rem; font-weight: 700;
            color: var(--muted, #8892a6); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .kwho-field input {
            width: 100%; padding: 0.6rem 0.7rem;
            border: 1px solid var(--border, rgba(255,255,255,0.15));
            border-radius: 10px; background: rgba(255, 255, 255, 0.05);
            color: var(--foreground, inherit); font-size: 1rem; font-weight: 600;
        }
        .kwho-error { margin: 0 0 0.6rem; font-size: 0.8rem; color: #ff8f8f; }
        .kwho-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.4rem; }
    </style>

    <script>
    (function () {
        'use strict';
        var KEY = 'aeic_kiosque_who';
        var btn = document.getElementById('kWhoBtn');
        var modal = document.getElementById('kWhoModal');
        var prenom = document.getElementById('kWhoPrenom');
        var nom = document.getElementById('kWhoNom');
        var alias = document.getElementById('kWhoAlias');
        var err = document.getElementById('kWhoError');

        function read() {
            try { return JSON.parse(localStorage.getItem(KEY) || 'null') || null; }
            catch (e) { return null; }
        }
        function complete(w) {
            return !!(w && (w.prenom || '').trim() !== '' && (w.nom || '').trim() !== '' && (w.alias || '').trim() !== '');
        }
        function label(w) {
            if (!complete(w)) return '';
            var base = ((w.prenom || '') + ' ' + (w.nom || '')).trim();
            return base + ' (' + (w.alias || '').trim() + ')';
        }
        function initials(w) {
            if (!w) return '?';
            var s = ((w.prenom || '').charAt(0) + (w.nom || '').charAt(0)).trim();
            return (s === '' ? (w.alias || '?').charAt(0) : s).toUpperCase();
        }
        /* Champs cachés d'identité injectés dans chaque formulaire : envoyés
           avec chaque enregistrement (comptage caisse, inventaire, pause). */
        function syncForms() {
            var w = read() || {};
            var v = label(w);
            Array.prototype.forEach.call(document.querySelectorAll('form'), function (f) {
                [['who', v], ['who_prenom', w.prenom || ''], ['who_nom', w.nom || ''], ['who_alias', w.alias || '']]
                    .forEach(function (pair) {
                        var input = f.querySelector('input[name="' + pair[0] + '"]');
                        if (!input) {
                            input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = pair[0];
                            f.appendChild(input);
                        }
                        input.value = pair[1];
                    });
            });
        }
        function render() {
            var w = read();
            btn.textContent = initials(w);
            syncForms();
        }
        function open() {
            var w = read() || {};
            prenom.value = w.prenom || '';
            nom.value = w.nom || '';
            alias.value = w.alias || '';
            err.hidden = true;
            modal.hidden = false;
            try { (prenom.value ? nom : prenom).focus(); } catch (e) {}
        }
        /* Fermable uniquement une fois l'identité complète. */
        function close() { if (complete(read())) modal.hidden = true; }
        function save() {
            var w = {
                prenom: prenom.value.trim(),
                nom: nom.value.trim(),
                alias: alias.value.trim()
            };
            if (!complete(w)) {
                err.hidden = false;
                return;
            }
            localStorage.setItem(KEY, JSON.stringify(w));
            close();
            render();
            document.dispatchEvent(new CustomEvent('kiosque-who-saved'));
        }

        btn.addEventListener('click', open);
        document.getElementById('kWhoSave').addEventListener('click', save);
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); save(); }
        });

        /* Toute soumission de formulaire est bloquée tant que l'identité
           complète (prénom, nom, rôle) n'est pas renseignée. */
        document.addEventListener('submit', function (e) {
            if (!complete(read())) {
                e.preventDefault();
                e.stopPropagation();
                open();
                return;
            }
            syncForms();
        }, true);

        /* API pour les vues (autosave, boutons pause) : refuser d'écrire
           sans identité. */
        window.KiosqueWho = {
            ok: function () { return complete(read()); },
            open: open,
            fields: function () {
                var w = read() || {};
                return {
                    who: label(w),
                    who_prenom: (w.prenom || '').trim(),
                    who_nom: (w.nom || '').trim(),
                    who_alias: (w.alias || '').trim()
                };
            }
        };

        /* Les formulaires sont plus bas dans le DOM : on se synchronise au
           chargement. Identité incomplète = la fenêtre s'ouvre d'office. */
        var needWho = !complete(read());
        document.addEventListener('DOMContentLoaded', function () {
            render();
            if (needWho) open();
        });
        document.addEventListener('submit', syncForms, true);
    })();
    </script>

    <main class="admin-main" style="max-width: 1100px; margin: 0 auto; padding: 1.1rem 1rem 2.5rem;">
        <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
        <div class="admin-content">
            <?= $content ?>
        </div>
        <p class="card-meta" style="text-align: center; margin-top: 1.75rem;">
            <?= e($siteName) ?> — accès limité à cette page
        </p>
    </main>
</body>
</html>

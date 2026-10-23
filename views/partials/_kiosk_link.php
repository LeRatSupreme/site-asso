<?php

declare(strict_types=1);

/**
 * Lien kiosque masqué avec révélation à la demande — même mécanique que
 * la page Kiosques (docs/refonte-limites.md §16) : le jeton ne figure
 * JAMAIS dans le HTML initial, l'URL complète n'arrive qu'en réponse
 * d'un POST CSRF vers /admin/kiosques/reveal (tracé dans l'audit).
 *
 * Utilisé par les encarts « Accès téléphone sans connexion » des pages
 * Réappro, Liste de courses, Comptage de caisse et Comptage inventaire.
 * Le comportement est porté par public/assets/js/kiosk-reveal.js
 * (chargé par le layout admin).
 *
 * Variables attendues :
 *  @var string $kioskPath  Chemin kiosque (ex. '/kiosque/liste/')
 *  @var string $kioskLabel Libellé du lien (aria-label)
 */
?>
<div class="kl-row" data-kl-scope="membres" data-kl-path="<?= e($kioskPath) ?>"
     data-kl-reveal="<?= e(url('/admin/kiosques/reveal')) ?>">
    <form method="post" style="display:none" aria-hidden="true"><?= csrf_field() ?></form>
    <input type="text" readonly value="••••••••" onclick="this.select()"
           aria-label="Lien <?= e($kioskLabel) ?> — masqué, utilise le bouton « Afficher le lien »">
    <button type="button" class="btn btn-outline btn-sm kl-reveal" aria-expanded="false">Afficher le lien</button>
    <button type="button" class="btn btn-outline btn-sm kl-copy">Copier</button>
    <a class="btn btn-ghost btn-sm kl-open" hidden>Ouvrir ↗</a>
</div>

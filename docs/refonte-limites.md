# Limites connues après la refonte (fin lot 7 + passe qualité)

Liste factuelle de ce qui reste imparfait après les lots 1 à 7. Chaque
ligne : constat, impact, préconisation (lot d'origine ou proposition).
Ce document ne liste pas les défauts corrigés — voir `docs/ui-audit.md`
(diagnostic d'entrée) et les commits des lots 1 à 7. Les entrées marquées
« Traité » ont été résolues par la passe qualité du 23/10/2026 et sont
gardées pour mémoire (le numéro sert de référence).

## Interface

1. **Pages publiques et espace élève non migrées au design system.**
   Constat : `/` (accueil), événements, association, sondages, galerie,
   jeux, auth et l'espace `/eleve` rendent encore avec l'ancien thème
   sombre (`base.css` + `pages.css` + `kit.css`) ; `tokens.css` /
   `components.css` ne s'appliquent qu'aux pages admin migrées.
   Impact : deux chartes cohabitent ; le thème sombre automatique du lot 7
   (`prefers-color-scheme`) ne concerne que les pages `html.ds`.
   Préconisation : migration page par page au design system (suite du
   lot 1), même mécanisme que les pages admin.

2. **Marges de progression design system, page par page (admin).**
   Constat : ≈ 371 attributs `style="` et de nombreux blocs `<style>`
   restent dans les vues admin et kiosque (audit §1 : 375 ; le lot n'a pas
   vocation à tous les traiter). `compta.css` concentre encore ≈ 2 000
   lignes locales. Impact : dette de style, risques de divergence entre
   pages soeurs. Préconisation : poursuivre la migration (styles
   locaux → `compta.css`/`components.css`) espace par espace.

3. **Traité (favicon/icônes) — reste le thème public.**
   Constat d'origine : favicon et icônes aux anciennes couleurs, le 404
   signalé était obsolète (fichiers fournis dès la phase 1). Traitement :
   `favicon.svg`, `icon.svg`, `icon-192.png` et `icon-512.png` repassés
   à l'accent du design system (`#1f7a99`, à plat). `manifest.json`
   reste en `#08172d` : cohérent avec le thème sombre actuel des pages
   publiques, à revoir avec la migration de l'entrée 1.

4. **Traité (président lecture seule).**
   Constat d'origine : le rôle PRESIDENT voyait des boutons qui
   répondaient 403 au clic. Traitement : drapeau `body.is-readonly` posé
   par le layout admin (`Permissions::isReadOnly()`), bandeau
   « Mode lecture seule » et masquage des `submit` des formulaires POST
   dans `.admin-content` (le routeur reste le garde réel — 403 —, la vue
   ne montre plus que des actions autorisées). Test :
   `RolesMenuTest::test_president_drapeau_lecture_seule_dans_le_layout`.

5. **Emails avec styles dupliqués.**
   Constat : chaque template d'email embarque ses styles (≈ 148 `style="`
   et des blocs `<style>` par gabarit, audit §1) sans feuille commune.
   Impact : toute retouhe visuelle doit être répétée gabarit par gabarit.
   Préconisation : un gabarit de base (header/footer/bouton) mutualisé,
   variables de couleurs centralisées côté PHP.

6. **Traité (confirmations unifiées).**
   Constat d'origine : la modale globale `data-confirm` coexistait avec
   des `window.confirm()` natifs. Traitement : API programmatique
   `window.aeicConfirm(message, libellé)` (Promise) ajoutée à
   `confirm.js` et appliquée aux derniers cas (grand livre — envoi groupé,
   achats — application facture). Repli `window.confirm` conservé dans
   les vues kiosque, dont le layout ne charge pas `confirm.js`.

7. **Traité (marqueur `is-mine`, sondages).**
   Constat d'origine : la classe `is-mine` restait posée sans règle CSS
   associée. Traitement : la barre « mon vote » est remplie avec l'accent
   (`var(--primary)`) — la variante gagnante garde la priorité (déclarée
   après dans `polls.css`).

8. **Dégradés restants assumés (donnée, pas décoration).**
   Constat : `polls.css` garde le dégradé de remplissage des barres de
   résultats (et le variant « gagnant »), `base.css` garde `.aeic-gradient`
   (logo pied de page, couvertures d'événement sans image, maintenance) et
   le fond `dot-grid`. Impact : purement visuel, décidé hors périmètre
   sobriété (éléments porteurs de sens ou identitaires).
   Préconisation : arbitrage design system lors de la migration des pages
   publiques.

## Performance

9. **Poids de Chart.js.**
   Constat : `chart.umd.min.js` pèse 201,1 Ko — le plus gros actif JS
   (détail et marges : `docs/refonte-perf.md`). Impact : page Analytics à
   251,4 Ko de JS, sous la cible 300 Ko mais la marge (48,6 Ko) absorbera
   mal une montée de version majeure. Préconisation : envisager les
   builds partiels (tree-shaking via registres Chart.js) si la cible
   devait se resserrer, ou un chargement conditionnel hors page Analytics.

10. **`compta.css` monolithique.**
    Constat : 81,4 Ko chargés en entier par toutes les pages compta,
    analytics et kiosques alors qu'une page n'utilise qu'une partie des
    règles. Impact : CSS transit plus lourd que nécessaire sur mobile.
    Préconisation : découpage par espace ou purge des règles mortes lors
    de la migration design system.

## Données et API

11. **Traité (clés camelCase, payload kiosque).**
    Constat d'origine : `cartMean`/`deltaCa` côtoyaient le snake_case du
    reste du payload. Traitement : renommés `cart_mean`/`delta_ca` dans
    `KioskComptageController` et `kiosk-jour.php` (`margin` était déjà
    conforme).

12. **Rôle `EVENEMENTS` mort en base.**
    Constat : présent dans l'ENUM `users.role` mais sans libellé, menu ni
    module (audit §2, D11). La suppression demandait une migration SQL,
    non tranchée (audit §10.3). Impact : un rôle attribuable en base mais
    sans effet. Préconisation : migration de nettoyage (retirer de
    l'ENUM) validée côté exploitation.

## Tests

13. **Traité (couverture kiosque).**
    Constat d'origine : aucune couverture d'intégration dédiée pour
    4 pages kiosque. Traitement : `tests/Integration/KiosquePagesRenduTest.php`
    couvre le rendu des 4 pages (hub, comptage du jour, période admin,
    récap membre), le refus des jetons invalides (403) et l'absence de
    données financières dans le récap membre.

14. **Obsolète (mojibake).**
    Le docblock double-encodé signalé dans `app/core/Compta/Kiosk.php`
    avait déjà été corrigé avant la passe qualité (aucune trace restante) :
    entrée conservée pour mémoire, aucune action.

15. **Thème sombre défini en deux blocs.**
    Constat : `tokens.css` duplique les surcharges sombres (choix manuel
    `data-theme="dark"` et automatique `prefers-color-scheme`), le CSS
    pur ne permettant pas de factoriser. Impact : les deux blocs doivent
    rester synchronisés (mentionné en commentaire). Préconisation : build
    CSS (PostCSS) si la duplication devient pénible.

## Sécurité UI (audit §6 — traité par le lot jetons, restes assumés)

16. **Traité (masquage des liens téléphone).**
    Constat d'origine : les encarts « accès téléphone » des pages
    Réappro, Liste, Caisse et Comptage à l'aveugle affichaient le lien
    en clair (contrairement à `/admin/kiosques`). Traitement : partial
    réutilisable `_kiosk_link.php` (valeur masquée
    « •••••••• », boutons Afficher/Copier/Ouvrir) + `kiosk-reveal.js`
    (POST CSRF vers `/admin/kiosques/reveal`, audité) ; plus aucun jeton
    n'est envoyé dans le HTML de ces pages. Tests :
    `KiosqueSecuriteTest::test_encarts_kiosque_des_pages_admin_masques`
    et `KiosqueAffichageTest` mis à jour.

17. **Pas de rotation/expiration des jetons.**
    Constat : `Referrer-Policy: no-referrer` (globale) et
    `Cache-Control: no-store` (toutes les pages à jeton, point central
    `Kiosk::sendNoStore()`) sont posés ; les jetons restent permanents
    jusqu'à régénération manuelle et vivent dans l'URL (le Referer ne les
    fuite plus, mais ils restent exposés aux favoris/historique déjà
    créés). Impact : un jeton compromis reste valable jusqu'à révocation.
    Préconisation : expiration + rotation douce lors d'une prochaine
    passe sécurité (audit §6, dernier point ouvert).

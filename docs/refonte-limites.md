# Limites connues après la refonte (fin lot 7)

Liste factuelle de ce qui reste imparfait après les lots 1 à 7. Chaque
ligne : constat, impact, préconisation (lot d'origine ou proposition).
Ce document ne liste pas les défauts corrigés — voir `docs/ui-audit.md`
(diagnostic d'entrée) et les commits des lots 1 à 7.

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

3. **Favicon et logo aux anciennes couleurs — et 404.**
   Constat : tous les layouts référencent `img/favicon.svg` et
   `img/icon.svg` mais le dossier `public/img/` n'existe pas dans le dépôt
   → chaque page déclenche une requête favicon en 404. L'ancienne couleur
   `#48bdd3` reste par ailleurs la couleur de marque des favicon/logos
   quand ils sont fournis côté serveur (audit §1 : 106 occurrences).
   Impact : bruit dans les logs, identité visuelle datée, pas d'onglet
   iconifié. Préconisation : fournir `public/img/favicon.svg` + icon.png
   (PWA) aux couleurs du design system et mettre à jour `manifest.json`.

4. **Boutons visibles mais refusés pour le rôle président.**
   Constat : le rôle PRESIDENT (lot 2) voit dans sa navigation des actions
   (boutons d'enregistrement, formulaires) qui répondent 403 au clic :
   le menu filtre les liens mais les pages restent en lecture seule sans
   masquage fin des boutons. Impact : parcours frustrant, appels réseau
   inutiles. Préconisation : passer un drapeau « lecture seule » du
   contrôleur à la vue (masquer ou désactiver les `submit`), côté espaces
   Ventes/Stock/Comptabilité.

5. **Emails avec styles dupliqués.**
   Constat : chaque template d'email embarque ses styles (≈ 148 `style="`
   et des blocs `<style>` par gabarit, audit §1) sans feuille commune.
   Impact : toute retouhe visuelle doit être répétée gabarit par gabarit.
   Préconisation : un gabarit de base (header/footer/bouton) mutualisé,
   variables de couleurs centralisées côté PHP.

6. **Confirmations non unifiées.**
   Constat : la modale globale `data-confirm` coexiste avec ≈ 11 appels
   `window.confirm()` natifs dans les vues (audit §2 : à unifier).
   Impact : deux ergonomies de confirmation selon la page.
   Préconisation : convertir les `window.confirm()` restants en
   `data-confirm` (mission §2, reste du lot en cours de migration).

7. **Marqueur `is-mine` devenu sans style (sondages).**
   Constat : le glow de la barre « mon vote » a été retiré (sobriété,
   lot 7) ; la classe `is-mine` reste posée dans `views/polls/show.php`
   sans règle CSS associée. Impact : aucun visuel (marqueur mort).
   Préconisation : soit supprimer la classe, soit la styler en gras
   discret — au choix lors d'une prochaine passe sondages.

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

11. **Clés camelCase dans le payload kiosque.**
    Constat : `cartMean`, `deltaCa`, `margin` (lot 4D) côtoient le
    snake_case du reste du payload (`total_debit`, `sales_profit`…).
    Impact : hétérogénéité de convention pour les consommateurs JS.
    Préconisation : arbitrage unique (snake_case recommandé) lors d'une
    prochaine retouche de ces écrans, avec tests ajustés.

12. **Rôle `EVENEMENTS` mort en base.**
    Constat : présent dans l'ENUM `users.role` mais sans libellé, menu ni
    module (audit §2, D11). La suppression demandait une migration SQL,
    non tranchée (audit §10.3). Impact : un rôle attribuable en base mais
    sans effet. Préconisation : migration de nettoyage (retirer de
    l'ENUM) validée côté exploitation.

## Tests

13. **Couverture kiosque incomplète.**
    Constat : aucune couverture d'intégration dédiée pour 4 pages kiosque :
    `/kiosque/admin` (hub), `/kiosque/comptage/jour`, `/kiosque/admin/periode`
    et `/kiosque/comptage/jour-membre` (signalé dès le lot 4D ; une fumée
    manuelle de 37 contrôles avait été jouée). Impact : régressions
    possibles sur ces pages sans filet. Préconisation : un test de rendu
    par page sur le modèle `KiosqueAffichageTest`.

14. **Mojibake résiduel hors tests.**
    Constat : un commentaire docblock double-encodé subsiste dans
    `app/core/Compta/Kiosk.php:33` (hors périmètre « tests » du lot 7).
    Impact : nul fonctionnel (commentaire). Préconisation : correction
    opportuniste au prochain passage sur ce fichier.

15. **Thème sombre défini en deux blocs.**
    Constat : `tokens.css` duplique les surcharges sombres (choix manuel
    `data-theme="dark"` et automatique `prefers-color-scheme`), le CSS
    pur ne permettant pas de factoriser. Impact : les deux blocs doivent
    rester synchronisés (mentionné en commentaire). Préconisation : build
    CSS (PostCSS) si la duplication devient pénible.

## Sécurité UI (audit §6 — traité par le lot jetons, restes assumés)

16. **Cartes « accès téléphone » encore visibles hors `/admin/kiosques`.**
    Constat : `/admin/kiosques` masque désormais ses liens par défaut
    (« •••••••• », révélation à la demande par POST CSRF audité
    `kiosques.reveal`) ; les pages Réappro/Liste (encart SUPERADMIN),
    Caisse et Comptage à l'aveugle gardent le lien en clair avec bouton
    Copier — usage légitime de copie pour le bureau, pages désormais
    servies en `Cache-Control: no-store`. Impact : ces liens restent à
    l'écran sur session admin active seulement. Préconisation : même
    masquage que `/admin/kiosques` lors d'une prochaine retouche de ces
    écrans.

17. **Pas de rotation/expiration des jetons.**
    Constat : `Referrer-Policy: no-referrer` (globale) et
    `Cache-Control: no-store` (toutes les pages à jeton, point central
    `Kiosk::sendNoStore()`) sont posés ; les jetons restent permanents
    jusqu'à régénération manuelle et vivent dans l'URL (le Referer ne les
    fuite plus, mais ils restent exposés aux favoris/historique déjà
    créés). Impact : un jeton compromis reste valable jusqu'à révocation.
    Préconisation : expiration + rotation douce lors d'une prochaine
    passe sécurité (audit §6, dernier point ouvert).

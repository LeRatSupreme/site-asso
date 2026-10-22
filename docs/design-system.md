# Design system AEIC

Système de design unique de l'interface d'administration (lot 1 de la
refonte UI). Il remplace progressivement les deux systèmes de tokens
concurrents (`base.css` et `admin.css`) : les anciennes feuilles restent
en place le temps de la migration, page par page.

Fichiers :

- `public/assets/css/tokens.css` — variables (couleurs, typo, espacements) ;
- `public/assets/css/components.css` — composants (uniquement des `var(--...)`) ;
- `public/assets/js/design-system.js` — interactions (tri, filtre, onglets, menu, ligne extensible) ;
- `views/admin/design_system.php` — page de démonstration (`/admin/design-system`).

## Principes

1. **Sobre** : aucune décoration. Pas de dégradé, pas de glow, pas
   d'ombre hormis `--shadow` réservée aux popovers et fenêtres. Les
   états de couleur (vert/orange/rouge) sont réservés au sens :
   bon / à surveiller / problème.
2. **Dense mais lisible** : base 15 px, texte secondaire 13 px,
   interligne 1,45, largeur de contenu bornée à 1160 px.
3. **Contraste AA** : texte principal `--n-900` sur fond `--n-50`,
   texte secondaire `--n-500` conforme AA sur blanc, focus visible net
   (contour 2 px accent, décalage 2 px) partout.
4. **Mobile d'abord** : grilles `auto-fit`, zones tactiles d'au moins
   44 px (boutons, champs), tableaux qui deviennent des cartes sous
   640 px (uniquement ceux marqués `data-mobile-cards`).
5. **Chiffres tabulaires** : toute valeur numérique porte la classe
   `.num` (alignement vertical des colonnes de chiffres).
6. **Montants en français** : toujours via `formatPrice()` côté PHP
   (ex. `1 234,56 €`), jamais formatés à la main en HTML ou JS.
7. **Périodes toujours étiquetées** : chaque chiffre porte son `.kpi-hint`
   (ex. « du 1er au 21 octobre ») et, si pertinent, sa comparaison
   (« vs … sur la même période en septembre »).
8. **Pas de style en ligne** : zéro attribut `style="`, zéro bloc
   `<style>` dans les vues migrées.
9. **Aucune couleur hors tokens** : components.css ne contient aucun
   hex ; une nouvelle couleur passe d'abord par tokens.css.
10. **Pas d'emoji** dans les interfaces d'administration.
11. **Progressive enhancement** : sans JavaScript, tous les contenus
    restent lisibles (onglets affichés, lignes de détail visibles,
    menus déroulants dépliés).
12. **Vouvoiement et français courant** : pas de jargon, pas de code
    interne (codes de règles, noms de colonnes brutes) dans les libellés.

## Tokens (tokens.css)

Tous les tokens sont portés par `html.ds` ; le thème sombre surcharge
les mêmes variables via `html.ds[data-theme="dark"]` (avec
`color-scheme: dark`). Le thème clair est le défaut.

### Neutres

| Token | Clair | Rôle |
|---|---|---|
| `--n-0` | `#ffffff` | surface (cartes, tableaux) |
| `--n-50` | `#f7f8f9` | fond de page |
| `--n-100` | `#eef0f2` | fonds discrets, squelette |
| `--n-200` | `#dfe3e8` | bordures |
| `--n-300` | `#c7ccd3` | bordures appuyées (champs) |
| `--n-500` | `#6b7280` | texte secondaire (AA sur blanc) |
| `--n-700` | `#3a4149` | texte appuyé secondaire |
| `--n-900` | `#1a202c` | texte principal |

Thème sombre : fond `#0f141a`, surface `#161d26`, bordures `#2a3340`,
texte `#e6eaf0` / `#9aa3ad`.

### Accent unique

| Token | Clair | Sombre | Rôle |
|---|---|---|---|
| `--accent` | `#1f7a99` | `#4aa3c2` | action principale, onglet actif, liens |
| `--accent-strong` | `#185f79` | `#6cb8d3` | survol |
| `--accent-soft` | `#e8f3f7` | translucide | fond discret accentué |

### États (réservés au sens bon / à surveiller / problème)

| Sens | Texte | Fond | Bordure |
|---|---|---|---|
| Bon (`--ok`) | `#1e7d46` | `#e8f5ec` | `#bfe3cc` |
| À surveiller (`--warn`) | `#935400` | `#fcf3e2` | `#eedeb8` |
| Problème (`--ko`) | `#b3261e` | `#fbeceb` | `#f2c6c3` |

Le thème sombre éclaircit les textes (`#4caf7d`, `#d29a3a`, `#e5736e`)
et passe les fonds en translucide.

### Typographie

| Token | Valeur | Usage |
|---|---|---|
| `--font` | "Segoe UI", -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif | aucune webfont |
| `--fs-1` | 13 px | texte secondaire |
| `--fs-2` | 15 px | texte courant (base) |
| `--fs-3` | 19 px | titres de bloc |
| `--fs-4` | 24 px | titres de page et valeurs KPI |
| `--lh` | 1,45 | interligne |

`.num` applique `font-variant-numeric: tabular-nums`.

### Espacements, formes, divers

`--sp-1` 4 px, `--sp-2` 8 px, `--sp-3` 12 px, `--sp-4` 16 px,
`--sp-6` 24 px, `--sp-8` 32 px ; `--radius-s` 6 px, `--radius-m` 10 px ;
`--border` = `1px solid var(--n-200)` ; `--shadow` (popovers/dialogues
uniquement) ; `--maxw` 1160 px.

`@media (prefers-reduced-motion: reduce)` coupe animations et
transitions (le squelette devient statique).

## Composants (components.css)

| Classe | Usage | Exemple |
|---|---|---|
| `.ds-page` | conteneur de page (largeur bornée, centré) | `<div class="ds-page">…</div>` |
| `.ds-breadcrumb` | fil d'Ariane (`nav > ol`, séparateur « / » en CSS) | `<nav class="ds-breadcrumb"><ol><li><a …>Administration</a></li><li><span aria-current="page">Ventes</span></li></ol></nav>` |
| `.ds-tabs` | liste d'onglets (boutons `role="tab"`, soulignement de l'actif) | `<div class="ds-tabs" role="tablist"><button role="tab" aria-selected="true">Semaine</button></div>` |
| `.kpi` (+ `.kpi--ok/--warn/--ko`) | carte KPI ; `.kpi-label`, `.kpi-value`, `.kpi-hint` | `<div class="kpi kpi--ok"><span class="kpi-label">Argent encaissé</span><span class="kpi-value num">1 234,56 €</span><span class="kpi-hint">du 1er au 21 octobre</span></div>` |
| `.card`, `.card-title`, `.card-body` | carte générique | `<div class="card"><h2 class="card-title">TVA</h2><div class="card-body">…</div></div>` |
| `.table-wrap` (+ `--sticky`) | défilement horizontal, en-tête collant | `<div class="table-wrap table-wrap--sticky"><table class="ds-table">…` |
| `.ds-table` | tableau (bordures horizontales légères, `th.num`/`td.num` alignés à droite) | `<table class="ds-table" data-mobile-cards>…` |
| `th[aria-sort]` | colonne triable (indicateur par `::after`, géré par le JS) | `<th aria-sort="none">Montant</th>` |
| `tfoot .total` | ligne de total (gras, bordure haute 2 px) | `<tfoot><tr><td class="total">Total</td>…` |
| `button.ds-expand` + `tr.ds-row-detail` | ligne extensible (`aria-expanded`, ligne masquée par le JS) | `<button type="button" class="ds-expand">Détail</button>` puis `<tr class="ds-row-detail"><td colspan="4">…</td></tr>` |
| `.badge` (+ `--ok/--warn/--ko/--neutral/--accent`) | pastille d'état | `<span class="badge badge--ok">Payé</span>` |
| `.alert` (+ `--ok/--warn/--ko/--info`) | bandeau (bordure gauche 3 px, fond doux ; `role="status"` ou `role="alert"` dans le HTML) | `<div class="alert alert--warn" role="alert">Import en retard…</div>` |
| `.btn` (+ `--primary/--secondary/--ghost/--danger/--sm`) | boutons (min-height 44 px, 32 px en `--sm`) | `<button class="btn btn--primary">Enregistrer</button>` |
| `.ds-form`, `.field`, `.field__label`, `.field__hint`, `.field__error` | formulaire en grille responsive, champs 44 px | `<form class="ds-form"><div class="field"><label class="field__label" for="m">Montant</label><input id="m"><span class="field__hint">format 12,50</span></div></form>` |
| `.ds-menu`, `.ds-menu-trigger`, `.ds-menu-list`, `.ds-menu-item` | menu déroulant (fermeture au clic extérieur et Échap) | `<div class="ds-menu"><button class="btn btn--secondary ds-menu-trigger" aria-expanded="false">Actions</button><ul class="ds-menu-list">…</ul></div>` |
| `.ds-help` + `.ds-help-tip` | info-bulle « ? » (révélée au survol et au focus, CSS pur) | `<button type="button" class="ds-help">?<span class="ds-help-tip" role="tooltip">Explication…</span></button>` |
| `.ds-empty`, `.ds-empty-title` | état vide | `<div class="ds-empty"><p class="ds-empty-title">Pas de données pour cette période.</p></div>` |
| `.ds-skeleton` | bloc de chargement pulsé (statique si animations réduites) | `<div class="ds-skeleton"></div>` |
| `.ds-dialog` | fenêtre de confirmation (élément `<dialog>`, `::backdrop` sombre) | `<dialog class="ds-dialog">…</dialog>` |

Utilitaires d'assemblage : `.ds-grid` (grille responsive de cartes) et
`.ds-toolbar` (rangée filtre/recherche/actions).

`confirm.js` (existant) reste le mécanisme de confirmation : un élément
porteur de `data-confirm` ouvre le modal ; ses boutons sont stylés par
components.css quand la feuille est chargée.

## Règles d'adoption (migration d'une page)

1. Ajouter `class="ds"` sur la balise `<html>` de la vue (le layout
   concerné évolue au fil des lots ; en attendant, une page autonome
   porte elle-même son `<html class="ds">`).
2. Charger `tokens.css` puis `components.css` (et `design-system.js`
   en `defer` si la page utilise tri, onglets, menus ou lignes
   extensibles).
3. Migrer la vue vers les classes du design system : remplacer les
   styles locaux, supprimer tout `style="` et tout bloc `<style>`,
   remplacer les couleurs littérales par des tokens.
4. Les anciennes feuilles (`base.css`, `admin.css`) restent chargées
   le temps de la migration : elles ne doivent plus être sollicitées
   par les pages migrées. Ne jamais modifier les anciennes feuilles
   pour « faire coller » le design system.
5. Aucun calcul dans la vue : les montants arrivent prêts à afficher
   (`formatPrice()` côté contrôleur), les périodes sont étiquetées.

## Page de démonstration

`/admin/design-system` (route `Admin\AdminController#designSystem`,
garde de l'espace admin, rendu autonome sans layout). Elle présente un
exemple de chaque composant avec les libellés officiels et sert de
référence visuelle ; elle n'apparaît pas dans le menu tant que la
navigation n'a pas été refaite (lot 2).

## Tests qui verrouillent le système

- `tests/Unit/DesignSystemCssTest.php` : components.css sans aucune
  couleur hex ; aucun dégradé dans les deux feuilles ; variables
  imposées présentes dans tokens.css (`--accent`, `--ok`, `--warn`,
  `--ko`, `--fs-1` à `--fs-4`, `--sp-1` à `--sp-8`, `--maxw`) ;
  portée `html.ds` et thème sombre présents.
- `tests/Integration/DesignSystemPageTest.php` : la page répond 200
  pour un admin, porte `lang="fr"` et `class="ds"`, contient toutes
  les sections attendues, et ne contient ni style en ligne, ni bloc
  `<style>`, ni dégradé, ni emoji, ni jargon interne (WAPE, codes de
  règles). Les tests d'intégration sont sautés si la base `aeic_test`
  n'est pas disponible.

Toute évolution du design system passe par ces deux tests : ils
doivent rester verts.

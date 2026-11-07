# Performance — poids des actifs par page clé (lot 7)

Mesures réalisées le 22/10/2026 sur l'arbre de travail du lot 7
(branche `experiments`, après nettoyage du code mort), via `Get-Item … .Length`
(poids des fichiers) et extraction des blocs `<script>` inline des
layouts/vues (poids source ; le rendu PHP varie de quelques octets).
1 Ko = 1 024 octets.

## Avant (audit UI du 22/10/2026, §1)

> « 7 feuilles CSS, ≈ 5 685 lignes … ≈ 375 `style="` dans les vues …
> ≈ 53 blocs `<style>` en page … `analytics/index.php` 1 991 lignes
> (~1 050 de JS inline, 11 graphiques Chart.js) ; Chart.js chargé via CDN
> non versionné ×3. »

Source : `docs/ui-audit.md` §1 (22 octobre 2026). Le JS principal était
dispersé en ≈ 60 blocs inline dans les vues ; l'essentiel du poids JS des
pages Analytics était donc de l'inline non mesurable fichier par fichier.

## Après (mesures réelles, lot 7)

Fichiers de référence (Ko) :

| Fichier | Rôle | Taille |
|---|---|---:|
| `public/assets/css/base.css` | feuille de base (public + admin + kiosque) | 46,6 |
| `public/assets/css/pages.css` | pages publiques | 14,3 |
| `public/assets/css/kit.css` | accueil/CTA publics | 7,7 |
| `public/assets/css/tokens.css` | design system (variables) | 4,5 |
| `public/assets/css/components.css` | design system (composants) | 16,8 |
| `public/css/admin.css` | espace admin | 32,3 |
| `public/css/compta.css` | compta/analytics (et kiosque) | 81,4 |
| `public/css/payments.css` | bandeau paiements | 1,1 |
| `public/assets/js/confirm.js` | confirmations globales | 6,0 |
| `public/assets/js/kit.js` | interactions publiques | 9,0 |
| `public/assets/js/table-tools.js` | tri/filtre/colonnes des tableaux | 18,9 |
| `public/assets/js/analytics.js` | graphiques Analytics (extrait lot 4) | 37,4 |
| `public/assets/vendor/chart.umd.min.js` | Chart.js 4.4.7 vendorisé (lot 4) | 201,1 |

### Poids par page (CSS + JS chargés)

| Page | CSS chargés | Total CSS | JS chargés | Total JS |
|---|---|---:|---|---:|
| Accueil public `/` | base 46,6 + pages 14,3 + kit 7,7 + payments 1,1 | **69,7 Ko** | confirm 6,0 + kit 9,0 + inline 11,5 (10 blocs) | **26,5 Ko** |
| `/admin` (accueil) | base 46,6 + admin 32,3 + payments 1,1 | **80,0 Ko** | confirm 6,0 + inline 6,7 (3 blocs) | **12,7 Ko** |
| `/admin/compta` (Aujourd'hui) | base 46,6 + admin 32,3 + payments 1,1 + compta 81,4 | **161,4 Ko** | confirm 6,0 + inline 6,7 (3 blocs) | **12,7 Ko** |
| `/admin/analytics` (Analyses) | base 46,6 + admin 32,3 + payments 1,1 + compta 81,4 | **161,4 Ko** | chart.umd 201,1 + analytics 37,4 + confirm 6,0 + inline 6,9 (4 blocs) | **251,4 Ko** |
| Kiosque hub `/kiosque/comptage/{token}` | base 46,6 + admin 32,3 + payments 1,1 + compta 81,4 | **161,4 Ko** | inline 6,2 (1 bloc : pastille d'identité) | **6,2 Ko** |

Notes :
- `/admin/analytics` embarque en plus l'îlot de données
  `<script type="application/json">` (60,1 Ko mesurés) : c'est le
  **payload de données** consommé par analytics.js, pas du code exécutable.
- Les pages compta ajoutant `table-tools.js` (18,9 Ko) à la demande
  (journal des ventes, produits, inventaire…) restent sous la cible :
  12,7 + 18,9 = 31,6 Ko.
- Le nettoyage du lot 7 (recherche globale morte du layout public, halos
  et règles CSS mortes de `base.css`) retire ≈ 2,4 Ko de JS inline et
  ≈ 4,9 Ko de CSS (`base.css` 51,5 → 46,6 Ko) sur **toutes** les pages
  publiques/admin/kiosque.

## Cible mission : JS principal < 300 Ko

Page la plus lourde = `/admin/analytics` :

```
chart.umd.min.js   201,1 Ko
analytics.js        37,4 Ko
confirm.js           6,0 Ko
inline               6,9 Ko
─────────────────────────────
TOTAL              251,4 Ko  <  300 Ko  (marge : 48,6 Ko)
```

**Cible respectée** — marge de 48,6 Ko (16 %). Aucun correctif nécessaire.
Le total « code exécutable » hors payload de données reste inchangé depuis
la vendorisation de Chart.js (lot 4) ; la marge absorbe une future montée
de version de Chart.js ou l'ajout d'un graphique.

## Suites possibles (hors lot 7, sans impact sur la cible)

- `compta.css` (81,4 Ko) est chargée en entier par toutes les pages
  compta/kiosque alors qu'une page n'utilise qu'une fraction des règles ;
  un découpage par espace ou une purge des règles mortes réduirait le CSS.
- Le CSS/JS public (base/pages/kit) reste l'ancien thème, non migré au
  design system : la consolidation tokens/components n'aura d'effet
  mesurable qu'après la migration des pages publiques (voir
  `docs/refonte-limites.md`).

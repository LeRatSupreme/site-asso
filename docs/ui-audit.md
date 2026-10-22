# Audit UI — avant refonte de l'interface

**Date :** 22 octobre 2026 · **Périmètre :** `site_final/` — espace admin, kiosques (jetons), pages bénévoles, pages publiques/élèves. **Statut :** diagnostic exigé par la mission (§1) avant tout code. **Aucune implémentation n'est lancée avant validation de ce document et de l'arborescence cible (§8).**

Méthode : lecture systématique du code (routes `app/config/routes.php`, guards `app/controllers/Admin/AdminBaseController.php`, permissions `app/core/Permissions.php`, layouts `views/layouts/*.php`, vues `views/**`, CSS `public/**`, tests `tests/**`). Les comptages d'éléments sont des ordres de grandeur issus du code (≈), pas des mesures en production.

---

## 1. Synthèse du diagnostic

| Constat | Détail |
|---|---|
| Menu trop lourd | **41 entrées cliquables** pour un fondateur/admin (sidebar `views/layouts/admin.php` L27-148), 32 pour la trésorerie ; 7 groupes + sous-groupes hétérogènes (« Produits & coûts », « Analyse », « Stock »…). La mission demande 6 sections. |
| 7 feuilles CSS, ≈ 5 685 lignes | Deux systèmes de tokens concurrents (`public/assets/css/base.css` vs `public/css/admin.css`). ≈ 200 couleurs codées en dur. **3 fonds navy différents** : `#0a1628` (base), `#0a1b33` (admin), `#08172d` (meta theme-color, emails). `--surface` utilisé avec fallback `#0f1f3a` mais **jamais défini**. |
| Ancienne couleur fantôme | `#48bdd3` (ancien `--primary`) : **106 occurrences** (fallbacks `var(--primary, #48bdd3)`, Chart.js, emails, favicon) alors que le token actuel vaut `#3a9bb8`. |
| Décoration sur écrans de gestion | Fond **étoilé animé** (`public/assets/js/stars.js` + `.starfield`) monté sur les layouts public, étudiant **et admin** (`layouts/admin.php:191`). Halos dans ≈ 18 vues, ≥ 61 dégradés, ≥ 80 box-shadows dont des glows colorés. |
| Styles éparpillés | ≈ **375 `style="`** dans les vues (174 admin, 148 emails, 41 jeux) + **53 blocs `<style>`** en page (ex. `analytics/index.php` L1753-1990 : ~240 lignes recodant le CSS commun avec l'ancienne couleur). |
| Composants réinventés | **7 styles de tableaux** pour 88 `<table>` ; **5 systèmes de badges** ; **4 systèmes d'onglets** ; **3 systèmes de confirmation** (`data-confirm` global ×52, `window.confirm()` natif ×10, modales maison) ; 4 familles d'accordéons `<details>`. |
| Jargon et code interne visibles | « WAPE modèle/backtest », « baseline B2 », « dispersion r », « borne quasi-Poisson », « couverture intervalle » (pages Analytics) ; colonnes brutes `product_key`, `transaction_ref`, `sumup_ref` ; badge de code de règle affiché sur les pages Idées. Détail §5. |
| Calculs en vue (interdits par la mission) | Sommes de profits et marges recalculées en PHP dans `categories.php:29-31`, `annual.php:16-17`, `dashboard.php:229-230`, `kiosk-jour.php:132-134` (panier moyen dupliqué PHP **et** JS), `kiosk-admin-hub.php:14-17`, `products.php:71`, `evenements.php:166`, `analytics/index.php:739-740`. |
| Rôles | 8 rôles en base dont **1 mort** (`EVENEMENTS` : défini dans l'ENUM, absent de `Permissions::roles()`). « président (lecture) » et « bénévole de caisse » **n'existent pas** comme rôles ; les bénévoles passent par jetons kiosque sans compte. |
| CI | **Aucun workflow GitHub Actions** (`.github/workflows` absent). Lint très partiel (`run_lint.ps1` : 1 test JS + `php -l` sur 6 fichiers). Suite de tests solide : ≈ 130 fichiers PHPUnit 10 (Unit + Integration avec rendu HTML et base dédiée `aeic_test`), skip si base absente, jamais `config.env`. |
| Sécurité UI | `Referrer-Policy: strict-origin-when-cross-origin` présent ; **pas de `Cache-Control: no-store`** sur les pages kiosque à jeton ; jetons affichés/copiés en clair sur `/admin/kiosques` ; journal d'audit affiché (10 dernières lignes, sans filtre ni pagination) aux seuls Fondateur/ADMIN. Détail §6. |
| Accessibilité | Bonne base (lang dynamique, `<title>`, skip-links, focus-visible, `prefers-reduced-motion`) mais : pas de `prefers-color-scheme`, infos portées par `title` seul, inputs sans label, modale kiosque sans piège de focus. Détail §4. |
| Vues monolithiques | `analytics/index.php` 1 991 lignes (~1 050 de JS inline, 11 graphiques Chart.js) ; `ledger/index.php` 1 697 ; `analytics/kiosk.php` 1 674 ; `purchases.php` 1 279. Chart.js chargé **via CDN non versionné** ×3. |
| Code mort | Recherche globale du layout public : JS (`layouts/public.php:418-445`) + CSS `.nav-search*` ciblent des éléments **inexistants**. Contrôleurs+vues Articles (`AdminArticleController`, `ArticleController`, `views/articles/`) non routés. Rôle `EVENEMENTS` mort. |

---

## 2. Rôles : existant → cible

### 2.1 Rôles existants (`app/core/Auth.php:15-22`, `app/core/Permissions.php`, `database/schema.sql:21`)

| Rôle | Libellé | Accès UI actuel |
|---|---|---|
| SUPERADMIN | Fondateur | Tout (compta, events, content, cafeteria, games + groupe Système) |
| ADMIN | Administrateur | Idem, sauf Système réservé aux emails listés dans `SYSTEM_ADMINS` (ou pages attribuées `users.extra_pages`) |
| TRESORERIE | Trésorerie | compta, content, events, cafeteria, games (pas Inventaire ni Système) |
| COMMUNICATION | Communication | content, events, cafeteria |
| CAFETERIA | Cafétéria | cafeteria |
| JEUX | Jeux | games, cafeteria |
| ELEVE | Élève | Espace `/eleve` uniquement (aucun accès `/admin`) |
| EVENEMENTS | — | **Rôle mort** : présent en ENUM mais sans libellé ni module ni menu |

2FA obligatoire : SUPERADMIN, ADMIN, TRESORERIE. Kiosques : deux jetons en settings (`reappro_kiosk_token` « membres », `admin_kiosk_token` « admin ») passés **dans l'URL**, POST sans CSRF (le jeton est l'authentification), identité bénévole saisie via la pastille du layout kiosque sur chaque enregistrement.

### 2.2 Écarts avec les rôles cibles de la mission

| Rôle cible (mission §3) | État | Proposition |
|---|---|---|
| admin | SUPERADMIN + ADMIN | Inchangé ; menu complet (6 sections) |
| trésorier | TRESORERIE | Menu recentré : Accueil, Ventes, Stock, Comptabilité (aujourd'hui il a aussi contenu/événements/cafétéria/jeux — simplification à valider) |
| président (lecture) | **Absent** | Nouveau rôle lecture seule : Accueil + Ventes/Stock/Comptabilité en consultation + Association. **Décision à valider** |
| bénévole de caisse | **Absent en tant que rôle** ; aujourd'hui = jeton kiosque sans compte (ou rôle Cafétéria qui voit les produits) | Soit nouveau rôle sans chiffres financiers (menu : accueil simplifié + outils de comptage), soit maintien du modèle jeton seul. **Décision à valider** |
| membre | ELEVE | Espace élève inchangé (hors périmètre admin) |

---

## 3. Inventaire des pages

Codes de notes (référencés dans les tableaux) :
- **Doublons** (détail §3.5) : D1 à D11.
- **Termes techniques / code interne** (détail §5) : T1 colonnes brutes (`product_key`…) ; T2 jargon statistique (WAPE…) ; T3 messages techniques internes ; T4 badge code de règle (R#) ; T5 colonnes de prévision brutes (`safety_demand`, `lo/hi`).
- **Accessibilité** (détail §4) : A1 info portée par `title` seul ; A2 inputs sans label ; A3 modale sans piège de focus ; A4 input hors écran (wordle) ; A5 canvas sans alternative ; A6 global (pas de `prefers-color-scheme`).

### 3.1 Admin — accueil et contenu

| Page | Route | Public visé | Question à laquelle elle répond | Éléments affichés (≈) | Notes |
|---|---|---|---|---|---|
| Tableau de bord admin | `/admin` | tout le bureau | « Qui fait quoi ? Combien de membres et d'événements ? » | 2 compteurs (membres actifs, événements publiés) + journal d'audit (10 lignes, Fondateur/ADMIN) | À remplacer par l'accueil « À faire aujourd'hui » (mission §4) ; le journal d'audit doit devenir une vraie page filtrable (mission §10) |
| Wiki admin | `/admin/wiki` | tout le bureau | « Comment utiliser l'admin ? » | Guide ~887 lignes, sections filtrées par rôle | OK, à replacer sous « Aide » |
| Événements | `/admin/events` + form | Communication+ | « Quels événements, en quel état ? » | Liste brouillons/publiés + formulaire riche | → Association |
| Inscriptions événement | `/admin/events/{slug}/registrations` | Communication+ | « Qui s'inscrit ? » | Tableau inscrits + liste d'attente | → Association > Événements |
| Check-in | `/admin/events/{slug}/checkin` | Communication+ | « Qui est présent ? » | Scan QR + bascules manuelles | → Association > Événements |
| Pages CMS | `/admin/pages` + form | Communication+ | « Quelles pages publiées ? » | Liste + formulaire | → Association > Site web |
| Équipe | `/admin/team` + form | Communication+ | « Qui présente l'équipe ? » | Liste + formulaire | → Association > Site web |
| Sondages | `/admin/sondages` + form | Communication+ | « Quels sondages, quels résultats ? » | Liste + formulaire + résultats | → Association |
| Promotions | `/admin/promotions` + form | Communication+ | « Quelles promotions à l'accueil ? » | Liste + formulaire | → Association > Site web |
| Médias | `/admin/media` | Communication+ | « Quels fichiers, lesquels nettoyer ? » | Bibliothèque + upload + suppression | → Association > Site web |

### 3.2 Admin — cafétéria et jeux

| Page | Route | Public visé | Question | Éléments (≈) | Notes |
|---|---|---|---|---|---|
| Produits cafétéria | `/admin/cafeteria` + form | Cafétéria/Jeux/Trésorerie | « Quels produits vendus, quel stock ? » | Catalogue CRUD avec emoji produit + sync | D9, T3 ; à fusionner avec produits compta |
| Catégories cafétéria | `/admin/cafeteria/categories` | idem | « Comment les ventes tombent-elles en compta ? » | Catégories + mapping compta | D9 → Stock > Produits (onglet Catégories) |
| Commandes élèves | `/admin/cafeteria/commandes` | idem | « Quelles commandes en attente ? » | Tableau commandes + statuts | → Ventes > Commandes élèves |
| Jeux : vue d'ensemble | `/admin/jeux` | Jeux | « État des jeux ? » | Cartes par jeu | → Association > Jeux |
| Jeux : joueurs | `/admin/jeux/scores` | Jeux | « Qui joue, quels pseudos ? » | Tableau joueurs + reset | A2 (toolbar en style inline) |
| Jeux : mots Wordle / énigmes | `/admin/jeux/wordle/*`, `/enigmes/*` | Jeux | « Quels contenus de jeu ? » | CRUD par jour | → Association > Jeux |

### 3.3 Admin — comptabilité (`/admin/compta/*`, rôle compta : Fondateur/Admin/Trésorerie)

| Page | Route | Public visé | Question | Éléments (≈) | Notes |
|---|---|---|---|---|---|
| Dashboard compta | `/admin/compta` (+ alias `/admin/sumup`) | compta | « On gagne de l'argent ? Que faire ? » | Verdict + 4 cartes KPI + bloc À faire (≤7) + sections repliées (catégories, top produits, paiements, frais) + TVA + repères | **D1, D2** ; déjà conforme à l'esprit mission §4 — devient l'onglet « Aujourd'hui » de l'espace Ventes et la base de l'accueil admin |
| Importer CSV SumUp | `/admin/compta/import` | compta | « Les ventes sont-elles à jour ? » | Formulaire d'import + rapport (insérées/doublons/invalides) | → Ventes > Importer |
| Journal des ventes | `/admin/compta/ventes` | compta | « Que vend-on, ligne par ligne ? » | Tableau paginé + filtres période/catégorie + export CSV | T1 ; → Ventes > Journal |
| Bénéfice par produit | `/admin/compta/produits` | compta | « Sur quel produit gagne-t-on ? » | Tableau produit × marge | D9 ; → Ventes > Par produit (onglet) |
| Bénéfice par catégorie | `/admin/compta/categories` | compta | « Quelle famille de produits rapporte ? » | Tableau + totaux | **calculs en vue** (L29-31) ; → Ventes > Par catégorie (onglet) |
| Coûts de revient | `/admin/compta/couts` | Système + page `costs` | « Combien coûte chaque produit ? » | Grille coûts + saisie en lot + fusion | → Stock > Produits & coûts |
| Mapping libellés (alias) | `/admin/compta/aliases` | compta | « Comment les libellés SumUp deviennent-ils des produits ? » | Tableau mapping + fusion produits | T1 ; → Stock > Produits (onglet Libellés) |
| Ajouter une appellation | `/admin/compta/aliases/new` | compta | « Comment rattacher un nouveau libellé ? » | Formulaire série | → même onglet |
| Auto-détection doublons alias | `/admin/compta/aliases/auto` | compta | « Quels libellés sont probablement le même produit ? » | Suggestions + application | → même onglet |
| Réappro | `/admin/compta/reappro` | compta | « Quoi commander, en quelle quantité ? » | Tableau lecture seule ~8 colonnes (demande, bornes, stock, à commander, packs) + copie lien kiosque | **D6**, T5, A1 ; mission §8 : à unifier avec la liste de courses |
| Liste de courses | `/admin/compta/liste` | compta | « Quoi acheter aujourd'hui ? » | Cases à cocher partagées | D6 ; même vue que le kiosque |
| Opérations (5 onglets) | `/admin/compta/depenses`, `/achats`, `/pertes`, `/evenements`, `/inventaire-tablette` | compta | « Quelles écritures saisir ? » | Une seule vue `operations.php` avec onglets : Dépenses, Achats & stock, Pertes, Événements, Inventaire tablette | **D4** ; déjà en onglets — à répartir entre Comptabilité (Dépenses, Événements) et Stock (Achats, Pertes, Inventaire tablette) |
| Détail événement compta | `/admin/compta/evenements/{id}` | compta | « Cet événement a-t-il rapporté ? » | Ventes rattachées + coûts + bénéfice | T1 ; → Comptabilité > Événements |
| Budgets | `/admin/compta/budgets` | compta | « Respecte-t-on les budgets ? » | Tableau budgets + prorata | → Comptabilité |
| Inventaire | `/admin/compta/inventaire` | Système + page `inventory` | « Le stock théorique est-il juste ? » | Stock théorique + écarts + fusion de clés | D8, T1, T3 ; → Stock > Inventaire |
| Comptage à l'aveugle | `/admin/compta/inventaire/comptage` | tout le bureau | « Que comptons-nous aujourd'hui ? » | Saisie quantités, écart révélé à l'enregistrement | A2 ; → Stock > Inventaire (comptage) |
| Comptage de caisse | `/admin/compta/caisse` | tout le bureau | « La caisse est-elle juste ? » | Formulaire comptage (historique/écarts réservés à Système > Caisses) | D8 ; → Comptabilité > Caisses |
| Bilan annuel | `/admin/compta/annuel` | compta | « L'année est-elle meilleure que N-1 ? » | 12 mois × (CA, bénéfice, dépenses, résultat) vs N-1 + TVA + paniers | **calculs en vue** (L16-17) ; **D3** ; → onglet de l'espace Ventes |
| Analytics | `/admin/analytics` | compta | « Quelles tendances, quelles prévisions ? » | ≥ 8 KPI + **11 graphiques** + heatmap horaire + objectif hebdo + carte idées + tableau tendance produit ~10 colonnes + blocs qualité modèle | **D2, D5**, T2, T4, T5 ; ~1 990 lignes de vue ; → onglet « Analyses » |
| Idées appliquées (reco) | `/admin/reco/historique` | compta | « Qu'avons-nous appliqué, avec quel effet ? » | Historique + effet mesuré (~4 sem.) + idées en attente + seuils | **D5**, T4 ; → Ventes > Idées > Historique |

### 3.4 Admin — groupe Système (Fondateur/SYSTEM_ADMINS ou page attribuée)

| Page | Route | Public visé | Question | Éléments (≈) | Notes |
|---|---|---|---|---|---|
| Utilisateurs | `/admin/users` | Système | « Qui a quel rôle ? » | Tableau comptes + rôles + « Pages + » + reset | D10 ; → Association > Membres (onglet Comptes) |
| Adhésions | `/admin/memberships` | Système | « Qui a payé sa cotisation ? » | Tableau cotisations + marquage | D10, T1 (`sumup_ref`) ; → Association > Membres |
| Caisses | `/admin/caisses` | Système | « Le liquide est-il cohérent ? » | Solde théorique + mouvements + comptages + écarts 30 j | D8 ; → Comptabilité > Caisses |
| Kiosques | `/admin/kiosques` | Système | « Quels liens téléphone, quels jetons ? » | 2 liens affichés en clair + copie + régénération | **Jetons visibles/copiables** ; confirmation présente ; → Réglages > Kiosques |
| Livre comptable | `/admin/ledger` | Système | « Quelles écritures au journal ? » | Saisie express + scan ticket + tableau Débit/Crédit | ~1 700 lignes, 24 styles inline, bloc scanner dupliqué ×2 ; miroir kiosque D6 ; → Comptabilité |
| Notifications SMS | `/admin/notifications` | Système | « Quels SMS programmés ? » | Programmes + destinataires | → Réglages |
| Paramètres | `/admin/settings` | Système | « Comment le site est-il configuré ? » | Whitelist de paramètres + test email | → Réglages |

### 3.5 Kiosques (liens secrets sans session) et pages bénévoles

| Page | Route | Jeton | Public visé | Question | Éléments (≈) | Notes |
|---|---|---|---|---|---|---|
| Hub comptage | `/kiosque/comptage/{token}` | membres | bénévole | « Que dois-je compter ? » | 4 tuiles (caisse, inventaire, liste, ventes du jour) | D7 ; bonne base « 1 tâche par écran » |
| Comptage caisse | `/kiosque/comptage/caisse/{token}` | membres/admin | bénévole | « Combien d'espèces en caisse ? » | Formulaire + identité | D8 ; A3 |
| Comptage inventaire | `/kiosque/comptage/inventaire/{token}` | membres/admin | bénévole | « Combien il en reste ? » | Saisie à l'aveugle + pause produit | A2 |
| Ventes du jour (membres) | `/kiosque/comptage/jour-membre/{token}` | membres | bénévole | « Combien vendu aujourd'hui ? » (CA sans bénéfice) | Récap jour | D7 ; **panier moyen et delta calculés en vue** |
| Liste de courses kiosque | `/kiosque/liste/{token}` | membres/admin | bénévole | « Quoi acheter ? » | Cases partagées | D6 |
| Réappro kiosque | `/kiosque/reappro/{token}` | membres/admin | bénévole | « Quoi réapprovisionner ? » | Tableau lecture seule | D6, T5 ; incohérence réappro/liste signalée → unification lot 5 |
| Hub admin kiosque | `/kiosque/admin/{token}` | admin | bureau (téléphone) | « Ça marche aujourd'hui ? » | KPI jour/semaine/mois + 8 tuiles | **marges calculées en vue** ; miroir des écrans admin |
| Période kiosque | `/kiosque/admin/periode/{token}` | admin | bureau | « Le mois/semaine en détail ? » | Récap 7/14/30 j + détail jour par jour | calculs en vue ; emoji décoratifs |
| Jour complet kiosque | `/kiosque/comptage/jour/{token}` | admin | bureau | « Le jour complet avec bénéfice ? » | CA + bénéfice + paiements + par heure | D7 |
| Livre comptable kiosque | `/kiosque/admin/ledger/{token}` | admin | bureau | « Noter une dépense sur le vif ? » | Même vue que `/admin/ledger` | D6 |
| Analytics kiosque | `/kiosque/admin/analytics/{token}` | admin | bureau | « Les analyses sur téléphone ? » | 7 graphiques + mêmes KPI | **T2** (WAPE, baseline) ; vue sœur dupliquée (~240 lignes CSS/JS) |
| Idées kiosque | `/kiosque/admin/reco/{token}` | admin | bureau | « Quelles idées maintenant ? » | Idées du moment + décision | **T4** (badge code règle) ; D5 |

Pages bénévoles côté admin : `/admin/compta/caisse` et `/admin/compta/inventaire/comptage` (tout le bureau) ; `/admin/compta/liste` (compta) — voir §3.3. La mission « bénévole ne voit AUCUN chiffre financier » est respectée côté kiosque membres (CA sans bénéfice sur jour-membre) mais **pas** testée par un test de rôle dédié à créer.

### 3.6 Public et élèves (périmètre secondaire de la refonte)

Accueil `/` (événements à la une, carte cafétéria, promotions — halos + emoji produit), événements, association, équipe, sondages, galerie, jeux (6 pages), auth (5 pages), espace élève (5 pages, layout `student`), compte RGPD, 2FA, erreurs 403/404/maintenance. Ces pages héritent du fond étoilé et de la charte actuelle ; leur migration visuelle suivra le même design system, mais sans refonte fonctionnelle.

### 3.7 Doublons et recouvrements (D1-D11)

- **D1** `/admin/sumup` = alias exact de `/admin/compta` (fusion « Dashboard SumUp » déjà faite au niveau route ; reste le libellé et les réflexes).
- **D2** Dashboard compta vs Analytics : deux réponses au même « on gagne de l'argent ? » (l'un verdict/cartes, l'autre graphiques/prévisions) → onglets d'un même espace.
- **D3** Bilan annuel recouvre Dashboard/Analytics en agrégé mensuel N-1 → onglet du même espace.
- **D4** Opérations : 5 routes rendent **la même vue** avec un onglet actif — bon pattern à généraliser.
- **D5** Idées présentes en 3 endroits : carte dans Analytics, page kiosque « Idées du moment », historique `/admin/reco/historique` → un seul espace « Idées » + bloc sur l'accueil.
- **D6** Miroirs admin/kiosque (réappro, liste, ledger, analytics) : mêmes données, deux présentations → mêmes composants, version compacte.
- **D7** Deux récaps « jour » (admin complet vs membres sans bénéfice) : base commune `jourStats`/`jourMembreStats` → un composant, un filtre de périmètre.
- **D8** Caisse en triple (comptage bureau, Système > Caisses, kiosque) ; inventaire en quadruple (inventaire, comptage à l'aveugle, tablette SumUp, kiosque) → clarifier saisie vs consultation.
- **D9** Produits à 3 endroits (catalogue cafétéria, bénéfice produit/catégorie compta, mapping catégories) → espace « Produits & coûts » en onglets.
- **D10** Utilisateurs + Adhésions sous la même garde → onglets d'un espace « Membres ».
- **D11** Code orphelin non routé : contrôleurs/vues Articles, recherche globale morte du layout public, rôle `EVENEMENTS` → suppression au lot 7.

---

## 4. Défauts d'accessibilité

**Globaux** : A6 — aucun `prefers-color-scheme` (thème manuel uniquement) ; titres de page et `lang` corrects partout (point positif) ; skip-links et `aria-expanded` présents sur nav mobile/sidebar.

**Ponctuels** :
- **A1** — informations portées par l'attribut `title` seul (inaccessible clavier/tactile) : `reorder.php:330-355`, badges `inventory.php:296`, etc.
- **A2** — inputs sans label nominal (dépendent de l'en-tête de colonne) : `blind_count.php:50`, `budgets.php:122-123`, `inventory.php:271`, grilles en lot `costs.php:275-311`, `aliases.php:65`.
- **A3** — modale d'identité kiosque sans piège de focus Tab/Échap, pas d'`aria-live` sur l'erreur (`layouts/kiosk.php:77-215`).
- **A4** — input hors écran `left:-9999px` sans label (wordle) : `game/wordle.php:70`.
- **A5** — canvas de jeux sans alternative (acceptable pour un jeu, à assumer).
- Divers : boutons `<span class="btn">` non cliquables et pagination `span` non focusable ; `onerror` inline (`home.php:186`) ; sélecteur de langue mobile sans label (`public.php:206`).

---

## 5. Vocabulaire et code interne visibles (mission §7)

| Code | Ce que voit l'utilisateur | Où |
|---|---|---|
| T1 | Colonnes brutes : `product_key` (ex. « coca_33cl » affiché), `transaction_ref`, `sumup_ref` | `compta/aliases.php:62`, `alias_new.php:105`, `inventory.php:92/110/350`, `pertes.php:179`, `products.php:80`, `sales.php:120-121`, `inventaire_tablette_detail.php:160`, `evenement_detail.php:156-158`, `memberships/index.php:96`, placeholder `coca_33cl` dans `promotions/form.php:27-28` |
| T2 | Jargon statistique : « WAPE modèle X % vs baseline B2 Y % », « dispersion r = … (borne quasi-Poisson) », « couverture intervalle : X % », « Baseline naïve active » | `analytics/index.php:272-296`, `analytics/kiosk.php:254-278, 329-330`, `reco/historique.php:14/114` |
| T3 | Messages techniques internes : « clé inconnue — vérifie l'orthographe », « Stock théorique négatif (survente) » | `inventory.php:573`, `analytics/index.php:726` |
| T4 | Badge de code de règle affiché sur les pages Idées (`.reco-badge.is-rule` avec le code de la règle) | `reco/carte.php:148, 198`, `reco/historique.php:101` |
| T5 | Colonnes de prévision brutes (`safety_demand`, `lo/hi`, `stock`) dans les tableaux réappro/tendance | `analytics/index.php:720-728`, `reorder.php` |

À noter : la mission cite « 22 idées affichées malgré un maximum de 5 » et des boutons « Appliquer » sur des tâches de saisie comme symptômes observés en production. Dans le code actuel : le moteur croupe déjà à **3 idées max** (`Hyper::MAX_IDEES=3`, 2 max par règle, 5 corrections max), les corrections n'ont **pas** de bouton Appliquer, et un historique avec effet mesuré existe. La refonte doit donc porter sur : la présentation en cartes (chiffre justificatif, gain en fourchette, confiance en mots, hypothèses, mesure), le regroupement par nature, la suppression du badge de code de règle (T4), et la vérification en production que les caps sont réellement appliqués (à contrôler au lot 3).

---

## 6. Sécurité de l'interface — état des lieux (mission §10)

- **Échappement** : helper `e()` systématique, `HtmlSanitizer` sur contenus riches, CSRF central (hors kiosque par design), 0 `var_dump` dans les vues. Points positifs à conserver.
- **Jetons kiosque** : générés `bin2hex(random_bytes(20))`, vérifiés `hash_equals` ; **mais** affichés/copiés en clair sur `/admin/kiosques` et `reorder.php`, servis dans l'URL par le layout kiosque, sans `Cache-Control: no-store` (seul `/sms-chart/{token}` l'a), `Referrer-Policy` à `strict-origin-when-cross-origin` (à passer en `no-referrer` sur ces pages), pas de rotation/expiration.
- **Confirmations** : `data-confirm` global correct mais 10 `window.confirm()` natifs subsistent et une modale maison kiosque coexiste → à unifier (mission §2 : fenêtre de confirmation unique).
- **Journal d'audit** : table `audit_logs` bien alimentée (contrôleurs admin, kiosque avec `user_id` null) mais l'affichage se limite à 10 lignes sur le dashboard, réservé Fondateur/ADMIN, sans filtre → en faire une page dédiée avec recherche/filtre/export.

---

## 7. Ce qui existe déjà et sera conservé (non négociable)

- **Moteurs purs** : `ForecastEngine` (pur, sans DB, testé), `RecommendationEngine` (caps + guardrails + effets mesurés), `ComptaCalc`, sous-moteurs `Dashboard\Verdict|Cartes|Afaire`, résolveurs `StockBook`/`PackResolver`/`BesoinListe` (règle anti-régression de `AGENTS.md`). **Aucun calcul ne sera déplacé dans les vues** : les calculs aujourd'hui en vue (§1) remontent dans les contrôleurs/moteurs.
- **Bloc « À faire »** : déjà complet côté contrôleur (`AdminComptaController::dashboard()` L192-290 : verdict, ruptures, écarts d'inventaire, coûts manquants, import SumUp en retard, caisse non comptée, tri/cap par `Afaire::trier()`). Il alimente la future page d'accueil §4 de la mission.
- **Rôles et guards** : matrice `Permissions` centralisée, 6 niveaux de guards, menus déjà filtrés par rôle (jamais de lien interdit).
- **Tests** : ~130 fichiers dont 14 tests d'intégration avec rendu HTML (`DashboardPageTest`, `RecoPagesTest`, `AdminPagesSmokeTest`…) sur base `aeic_test` dédiée — chaque nouveau composant recevra son test de rendu (libellés attendus, absence de code interne).
- **Sécurité** : échappement, CSRF, en-têtes, confirmations, audit log.

Manques à créer : CI (workflow GitHub Actions : lint + phpunit), `Cache-Control: no-store` + `Referrer-Policy: no-referrer` sur pages à jeton, test de rôle « bénévole sans chiffre financier », mesure de performance avant/après (< 1 s sur 4G, < 300 Ko JS), glossaire (`docs/glossaire.md`), design system (`docs/design-system.md` + page de démonstration).

---

## 8. Arborescence cible proposée (mission §3) — À VALIDER

6 sections. Principe : le détail passe aux niveaux 2/3, rien n'est supprimé (mission : contrainte non négociable). Libellés en français courant, vouvoiement.

```
Administration
├── 1. Accueil
│   ├── À faire aujourd'hui        (ex /admin — verdict + 4 KPI + à faire, adapté au rôle)
│   │   └── Niveau 2 : catégories, top produits, paiements, frais (sections repliées)
│   │   └── Niveau 3 : liens vers Analyses, Bilan, Journal
│   ├── Journal d'audit            (nouvelle page filtrable, ex carte dashboard)
│   └── Aide & wiki                (ex /admin/wiki)
│
├── 2. Ventes            (rôles compta)
│   ├── Aujourd'hui                (ex Dashboard compta — onglet 1)
│   ├── Analyses                   (ex Analytics — onglet 2 ; graphiques + prévisions)
│   │   └── Idées                  (cartes « À corriger » + « Idées pour vendre plus » + Historique ex /admin/reco/historique)
│   ├── Bilan annuel               (onglet 3)
│   ├── Journal des ventes         (ex /admin/compta/ventes)
│   ├── Par produit / Par catégorie (ex produits + catégories compta, fusionnés en onglets)
│   ├── Importer un rapport SumUp  (ex /admin/compta/import)
│   └── Commandes élèves           (ex /admin/cafeteria/commandes)
│
├── 3. Stock             (rôles compta + cafétéria)
│   ├── Produits & coûts           (fusion catalogue cafétéria + coûts de revient + catégories + mapping libellés, en onglets)
│   ├── Réappro & liste de courses (unifiés : même source stock + demande, base de calcul affichée)
│   ├── Inventaire                 (stock théorique + écarts + comptages : à l'aveugle, tablette SumUp)
│   ├── Achats                     (ex Opérations > Achats)
│   └── Pertes
│
├── 4. Comptabilité      (rôles compta)
│   ├── Opérations                 (Dépenses / Événements en onglets)
│   ├── Livre comptable            (ex /admin/ledger)
│   ├── Budgets
│   ├── TVA & frais de paiement    (ex sections du dashboard, promues niveau 2)
│   └── Caisses                    (ex /admin/caisses + comptage : solde, mouvements, écarts)
│
├── 5. Association
│   ├── Événements                 (+ inscriptions, check-in)
│   ├── Membres                    (fusion Utilisateurs + Adhésions en onglets)
│   ├── Sondages
│   ├── Site web                   (Pages, Équipe, Médias, Promotions)
│   └── Jeux                       (vue d'ensemble, joueurs, mots, énigmes)
│
└── 6. Réglages         (Système)
    ├── Paramètres
    ├── Kiosques & jetons          (régénération confirmée, jetons masqués par défaut)
    └── Notifications SMS
```

Kiosques (hors menu, liens secrets) : mêmes composants que l'admin en version compacte ; hub bénévole (aucun chiffre financier) et hub admin (compact). Pages bénévoles : 1 tâche par écran, gros boutons, ~44 px de zone tactile.

### Menus par rôle

| Rôle | Sections visibles |
|---|---|
| admin (Fondateur/ADMIN listé) | Les 6 |
| trésorier | Accueil, Ventes, Stock, Comptabilité |
| président (lecture, nouveau rôle) | Accueil + consultation Ventes/Stock/Comptabilité + Association |
| bénévole de caisse (nouveau rôle ou jeton) | Accueil simplifié (aucun chiffre financier) + outils (liste de courses, comptages) |
| Communication | Accueil simplifié + Association (Événements, Site web, Sondages) |
| Cafétéria | Accueil simplifié + Stock (Produits) + Ventes (Commandes) |
| Jeux | Accueil simplifié + Association (Jeux) + Stock (Produits, si maintenu) |
| membre (ÉLÈVE) | Hors admin — espace élève inchangé |

### Transverses

- **Recherche globale** (produit, vente, membre) dans la barre admin — le backend `/search` existe côté public, le JS mort du layout public (`#global-search-input`) sera remplacé par une vraie implémentation.
- **Fil d'Ariane** sur chaque page admin/kiosque.
- **Design system unique** : tokens clair (défaut) + sombre sobre, composants partagés (carte KPI, tableau avec tri/filtre/recherche/ligne extensible/export CSV, badge d'état, bandeau d'alerte, boutons, formulaire, onglets, menu déroulant, info-bulle « ? », état vide, squelette, fil d'Ariane, confirmation), montants fr-FR, chiffres tabulaires.
- **Vocabulaire** : glossaire unique (`docs/glossaire.md`) ; jargon relégué en section « Détails techniques » repliée.

---

## 9. Impact par lot (mission §11)

| Lot | Contenu | Points d'attention issus de l'audit |
|---|---|---|
| 1 | Audit (ce document) + design system + page de démo | Consolider base.css/admin.css en tokens uniques ; tuer les 106 `#48bdd3`, les 3 navy, `--surface` fantôme ; thème clair défaut + sombre sobre ; `prefers-color-scheme` |
| 2 | Navigation 6 sections, rôles, accueil « À faire » | S'appuyer sur Dashboard\Verdict/Afaire ; audit journal en page ; recherche globale ; fil d'Ariane ; création des rôles manquants (si validés) |
| 3 | Idées (cartes, regroupement, historique) | Supprimer T4 ; vérifier caps en prod ; cartes avec chiffre + fourchette + confiance en mots ; boutons contextuels |
| 4 | Ventes en onglets, tableaux, graphiques | Remonter les calculs de vue ; remplacer Chart.js CDN non versionné (vendoring) ; fourchettes en bande discrète ; titres-conclusions |
| 5 | Réappro + liste de courses unifiés | Même source (`ReapproDemande`/`BesoinListe`), base de calcul affichée ; supprimer A1 (title seul) |
| 6 | Bénévoles et kiosques, mobile | 360 px, ~44 px ; bénévole sans chiffres financiers (test par rôle) ; no-store/no-referrer sur pages à jeton ; jetons masqués par défaut |
| 7 | A11y, performance, nettoyage | A2-A6 ; code mort D11 (articles, recherche morte, rôle EVENEMENTS) ; mesures avant/après |

Chaque lot : captures avant/après (bureau + mobile), tests de rendu, CI verte, déploiement `--ff-only`, retour arrière documenté.

---

## 10. Points ouverts à trancher avant le lot 1

1. **Rôle « président (lecture) »** : création d'un nouveau rôle lecture seule (migration + guards) — oui/non ?
2. **Bénévole de caisse** : nouveau rôle sans chiffres financiers, ou maintien du modèle jeton seul (sans compte) — quel choix ?
3. **Rôle `EVENEMENTS` mort** : suppression (migration SQL) ou câblage dans `Permissions` ?
4. **Périmètre trésorier** : recentré finance (proposition ci-dessus) — il perd l'accès contenu/jeux qu'il a aujourd'hui.
5. **Association absorbe-t-elle les pages « site web »** (Pages, Équipe, Médias, Promotions, Sondages, Jeux) ?
6. **Fusion produits** : catalogue cafétéria + produits/coûts compta dans un seul espace « Produits & coûts » (onglets).
7. **Thème clair par défaut partout** (mission §2) — le site est aujourd'hui sombre par défaut ; le public non-admin bascule aussi en clair ?
8. **Kiosques** : maintien de l'accès par jeton (téléphone, sans login) avec durcissement (no-store, no-referrer, masquage, rotation) — à confirmer.

---

*Fin de l'audit. Prochaine étape : validation de ce document et de l'arborescence §8 avant le lot 1 (design system + page de démonstration).*

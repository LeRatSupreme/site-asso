# Prévision du dashboard Analytics (cafétéria AEIC)

Documentation de la refonte du moteur de prévision (mission « étapes 0-10 »).
Cœur pur : `app/core/Compta/Forecast/` — extraction : `app/models/AnalyticsForecastData.php`
— intégration : `app/controllers/Admin/AdminAnalyticsController.php` — vues :
`views/admin/analytics/index.php` et `kiosk.php`.

## 1. Objectif et honnêteté statistique

Le modèle est dimensionné pour **~4 semaines de données** (la cafétéria vient
d'ouvrir) : pas de Holt-Winters ni de régression, mais une structure simple
et une **quantification d'incertitude systématique** (intervalles 80 %
Monte Carlo calibrés conformalement). Principes :

- **Une seule source de vérité** : `ForecastEngine::forecast()` (étape 9).
  Le contrôleur ne calcule AUCUNE prévision : il mappe les sorties du moteur
  vers le payload JSON. Les vues n'affichent que des valeurs du payload —
  aucun widget ne recalcule un chiffre « attendu ».
- **Une seule fenêtre d'apprentissage** (GET `fw` = 7/14/30 jours finissant
  aujourd'hui) alimente le dataset unique : prévision du jour, horaire,
  jours à venir, semaine, 30 jours, bénéfice et produits sortent du même appel.
- **Masquage plutôt qu'invention** : trop tôt dans la journée, données
  insuffisantes ou base nulle ⇒ le % / l'écart est `null` et n'est pas affiché.
- **Jours fermés jamais comptés 0** : un jour sans transaction du flux courant
  est absent du dataset (ni moyenne ni tendance faussée).

## 2. Étapes 0-9 → implémentation

### Étape 0 — Nettoyage et flux courant (`Clean`, `AnalyticsForecastData`)
Séparation flux courant / exceptionnel : une ligne de vente appartient au flux
courant ssi `is_custom_amount = 0` ET catégorie hors `Événement`
(`AnalyticsForecastData::EXCEPTIONAL_CATEGORIES`) ET `price_ttc > 0`
(les lignes ≤ 0 sont des tests, comptées dans l'audit uniquement).
Jours de semaine ouverts habituels : un dow est ouvert si observé sur
≥ `max(1, ceil(0.5 × n_semaines))` lundis couverts. Jours atypiques :
z robuste MAD `z = (CA − médiane) / (1.4826 × MAD)`, `|z| > 3.5` ⇒ poids 0.3
(la chaîne de niveau n'absorbe qu'un tiers de l'écart du jour).

### Étapes 1-2 — Modèle de demande N × P (`DemandModel`)
CA = **N × P** (transactions × panier moyen), modèle multiplicatif en log :
`y_d = log(max(N_d, 1))`. Effets de jour de semaine **rétrécis** (partial
pooling) : `e_j = (w_j·m_j + k·m_g) / (w_j + k)` avec `k = 2` ; moins de
3 occurrences du dow ⇒ `e_j = m_g` (moyenne globale) et confiance faible.
Niveau local : SES `α = 0.3` sur la série démeanée par la **médiane** du dow
(base robuste), tendance amortie `φ = 0.9` :
`l_t = α·w·ỹ_t + (1−α·w)(l_{t−1} + φ·b_{t−1})`, `b_t = φ·(l_t−l_{t−1}) + (1−φ)·b_{t−1}`.
Prévision : `exp(l_T + b_T·φ(1−φ^h)/(1−φ) + a_j)` (tendance bornée par `b_T/(1−φ)`).
Panier P : **moyenne tronquée 10 %** par dow, rétrécie vers le panier global
pareil. Dispersion binomiale négative par **méthode des moments** :
`r̂ = Σw·μ̂² / (Σw(x−μ̂)² − Σw·μ̂)`, clampée **[2, 200]**.

### Étape 3 — Profil horaire (`HourlyProfile`)
Profil de parts horaires S(h) : comptes globaux lissés **Dirichlet α = 1**
(`g(h) = (G(h)+α)/(ΣG+24α)`), rétrécissement par dow `s_j(h) = (c_j(h) + k·g(h)·C_j)/(C_j+k)`,
normalisation, **cumul S_j(h)** puis **régression isotonique (PAVA)** non
décroissante, clamp [0,1], S(23) = 1. Dow jamais observé ⇒ profil uniforme
`(h+1)/24`. « Trop tôt » : S_h < **0.10** (moins de 10 % de la journée écoulée).
Mise à jour bayésienne du restant du jour : **Gamma-Poisson** avec prior
θ ~ Gamma(8, 8) ⇒ **postérieur θ = (8 + N(h)) / (8 + E[N]·S(h))** ;
`n_final = N(h) + θ·E[N]·(1 − S_h)`.

### Étape 4 — Monte Carlo et calibration conforme (`MonteCarlo`, `Conformal`)
**5 000 tirages** par journée : N ~ NegBin(moyenne μ(date), dispersion r) ×
panier P par **bootstrap** (moyenne de min(30, |pool|) paniers tirés avec
remise ; pool du dow si ≥ 8 paniers, sinon pool global ; pool vide ⇒ P du
modèle). Résumé : moyenne + quantiles 0.10/0.90 (intervalle central 80 %).
**Calibration conforme** : sur les résidus de backtest
`score = |y − pred| / max(pred, 1)`, facteur
`f = quantile(scores, 0.90) / demi-largeur relative moyenne`, clampé **[0.5, 3]**,
appliqué autour de la prévision centrale. Moins de **15** résidus ⇒ pas de
calibration (intervalles MC conservés).

### Étape 5 — Backtest et sélection (`Backtest`)
**Origine glissante dès le 10ᵉ jour ouvert** : pour chaque jour i ≥ 9, réajustement
sur [0..i−1] (poids atypiques recalculés) et prévision du jour i. Baselines :
B1 = même dow la semaine précédente (sinon moyenne globale), B2 = moyenne des
occurrences passées du dow, B3 = moyenne des 7 derniers jours ouverts.
Métriques : **WAPE** (principal, B2…), **biais** moyen, **MASE** vs B1,
**couverture** des intervalles (cible 70-90 %), **pinball loss** sur les
quantiles, WAPE hebdomadaire sur semaines lun-dim complètes (5 jours ouverts).
**Règle de sélection** : le modèle principal reste actif ssi
`WAPE_main < WAPE_B2` **strictement** ; sinon baseline B2 affichée
(`modele = baseline_B2`, bandeau visible).

### Étape 6 — Objectif hebdomadaire (`Goal`)
Objectif enregistré (setting **`analytics_weekly_goal`**, défaut **500 €**),
consultable via GET `goal` (borné [10 ; 100 000]) et modifiable par
POST `/admin/analytics/objectif`. Semaine = lun-dim en cours : réel déjà
encaissé + tirages des jours restants (restant du jour multiplié par
`(1 − S_h)·θ`) ⇒ **P(atteindre l'objectif)** = fréquence des tirages ≥ objectif,
**manquant/jour** = `max(0, objectif − attendu) / max(1, jours restants)`
(null si 0 jour restant et objectif non atteint). Horizon 30 jours : attendu +
intervalle 80 % des 29 jours ouverts suivants.

### Étape 7 — Bénéfice prévu (`ProfitForecast`)
Marge à 7 jours, **produits à coût valide uniquement** (coût inconnu, coût ≤ 0
ou prix < coût = « suspects », exclus). Répartition du CA prévu au prorata des
quantités fiables ; `marge_i = ca_7j × part_i × (marge_i/prix_i)` ; agrégat
par catégorie (marge %). Bandeau « **X % du CA sans coût fiable** » : le total
est une borne basse honnête.

### Étape 8 — Réapprovisionnement (`ProductForecast`)
Parts de marché lissées **Dirichlet (+1)** : `part_i = (qty_i + 1)/(Σqty + K)`.
**Unités 7 j** : `λ = meanN × unités/transaction × 7`, tirages **Poisson** sur
le total répartis par parts ⇒ lo/hi = quantiles 0.10/0.90 ;
**stock sécurité = q90** de la demande ; **suggestion = max(0, q90 − stock)**
(null si stock inconnu ou « infini »). Tri par suggestion décroissante.

### Étape 9 — Moteur et intégration (`ForecastEngine`, contrôleur, vues)
`ForecastEngine::forecast(dataset, now, heure, opts)` enchaîne 0-8 et rend
le contrat complet (prevision_jour, intervalle_80, horaire, jours, semaine,
mois30, benefice, produits, anomalies, notes, métriques backtest…).
Le contrôleur assemble le dataset (`AnalyticsForecastData::dataset`), appelle
le moteur UNE fois, mappe vers `day_track` / `weekday` / `hourly` / payload
JSON ; routes `/admin/analytics` (GET), `/admin/analytics/objectif` (POST,
CSRF centralisé), `/kiosque/admin/analytics/{token}` (GET, jeton admin).

## 3. Hyperparamètres FIXES (et pourquoi)

**Volontairement non optimisés sur ~4 semaines de données** : toute recherche
d'hyperparamètres sur si peu de points surapprendrait. Valeurs choisies a priori :

| Paramètre | Valeur | Pourquoi |
|---|---|---|
| `K_POOL` (rétrécissement) | 2 | 2 pseudo-observations : un dow rare reste proche de la moyenne globale sans l'écraser. |
| `ALPHA_SES` | 0.3 | SES lent : le niveau bouge doucement, robuste au bruit de petit volume. |
| `PHI_DAMP` | 0.9 | Tendance amortie : extrapolation prudente, converge vers b/(1−φ). |
| `A_PRIOR` (Gamma θ) | 8 | Prior θ ~ Gamma(8,8) : moyenne 1, écart-type ≈ 35 % — assez informative pour ~8 h d'historique. |
| `Z_ATYPICAL` | 3.5 | Seuil d'Iglewicz-Hoaglin sur le z robuste MAD : flag standard des outliers. |
| `W_ATYPICAL` | 0.3 | Un jour atypique ne transmet que ~30 % de son écart au niveau. |
| `TRIM` | 0.10 | Moyenne tronquée 10 % du panier : résiste aux très gros/ petits paniers isolés. |
| `N_DRAWS` | 5000 | Erreur Monte Carlo ≪largeur des intervalles ; ~2 s de calcul acceptable. |
| `S_MIN` | 0.10 | Avant 10 % de la journée écoulée, aucune comparaison réel/attendu fiable. |
| `CONFORMAL_MIN` | 15 | Il faut ≥ 15 résidus pour un quantile 0.90 crédible. |
| `CONFORMAL_F_MIN/MAX` | 0.5 / 3 | Bornes de sanité du facteur conforme (ni trop étroit, ni délirant). |
| `INSUFFICIENT_DAYS` | 7 | < 7 jours ouverts : aucune % d'écart affiché (« données insuffisantes »). |
| `RARE_DOW` | 3 | < 3 occurrences d'un dow : effet non estimable ⇒ moyenne globale, confiance faible. |
| `BACKTEST_START` | 10 | Il faut ~9 jours d'apprentissage avant le premier point de backtest honnête. |
| `R_MIN` / `R_MAX` | 2 / 200 | Dispersion NegBin bornée : surdispersion max r=2, r=200 ≈ Poisson. |
| `WEEKLY_GOAL_DEFAULT` | 500 € | Ordre de grandeur du CA hebdo de la cafétéria, modifiable en UI. |

## 4. Démarrage à froid et insuffisance

- **< 7 jours ouverts** (`INSUFFICIENT_DAYS`) : confiance « insuffisant »,
  bandeau « Données insuffisantes (< 7 jours ouverts) », **aucun % d'écart**,
  aucun statut fourchette.
- **Jour de semaine < 3 occurrences** : effet dow = moyenne globale, confiance
  **faible** (badge dans l'info-bulle du modèle).
- **Jours fermés** : jamais comptés 0 (absents du dataset) ; un jour fermé
  prévu affiche des zéros explicites (`is_open = false`) dans les graphiques.
- Sans historique du tout : prévisions nulles, cartes vides, aucune erreur.

## 5. Événements ponctuels

Les ventes de catégorie « **Événement** » (et les montants personnalisés)
sont **exclues du flux courant** : elles ne faussent ni N ni P.
Point d'extension prévu : `ForecastEngine::forecast(..., ['events' =>
['Y-m-d' => montant€, …]])` — chaque événement **saisi manuellement** est
additionné aux tirages du jour concerné comme constante (sans incertitude)
et signalé dans les notes. Aucune interface de saisie n'existe encore.

## 6. Évolutions prévues (documentées, NON implémentées)

- **≥ 12 semaines de données** : remplacer le SES + dow par Holt-Winters ou
  une régression avec variables externes (**calendrier scolaire**, météo) ;
  re-considérer l'optimisation des hyperparamètres.
- **≥ 1 an de données** : saisonnalité **annuelle** (rentrée, examens,
  vacances) par Fourier ou profils mensuels ; recalibrage conforme glissant.

## 7. Anomalies de données détectées / surveillées

Affichées dans le bandeau repliable « **Qualité des données** » des deux vues :

| Anomalie | Détection | Effet sur les chiffres |
|---|---|---|
| Coût = 0 | `costSuspect` (coût ≤ 0) | Produit exclu de la marge (borne basse). |
| Prix < coût | `costSuspect` | Idem + listé « produits à coût suspect ». |
| Coût inconnu (lot absent) | `unitCost = null` | Idem ; compte dans « X % du CA sans coût fiable ». |
| Transactions 0 € de test | `price_ttc <= 0` (audit `ventes_zero`) | Exclues des séries (comptées à part). |
| Doublons d'import | `Σ import_batches.rows_skipped` (audit `doublons_ignores`) | Déjà écartées à l'import ; compteur informatif. |
| Jours atypiques | z MAD > 3.5 | Poids 0.3 (atténués, pas supprimés) ; listés. |

## 8. Tests

Lancer : `composer install` puis `php vendor/bin/phpunit` (tout) ou
`php vendor/bin/phpunit --testsuite Unit`. Les tests **Integration exigent
MySQL** avec la base `aeic_test` (cf. `phpunit.xml`) : sans MySQL ils sont
**skipped**, pas en échec — à jouer en CI ou sur le VPS.

Carte des fichiers (cas de la mission étape 10) :

- `tests/Unit/Forecast/StatsTest.php` — moyenne tronquée, quantiles type 7,
  PAVA isotonique, Dirichlet, samplers (Poisson/NegBin/Gamma) déterministes.
- `tests/Unit/Forecast/CleanTest.php` — jours ouverts (samedi exclu),
  jour courant non compté, poids atypiques z MAD, séparation passé/aujourd'hui.
- `tests/Unit/Forecast/DemandModelTest.php` — motif hebdo sans bruit, samedi
  fermé, croissance amortie sans explosion, jour atypique amorti, rétrécissement
  vers le global, insuffisance/confiances, dispersion bornée.
- `tests/Unit/Forecast/HourlyProfileTest.php` — profils valides, dow inconnu,
  fermeture exacte, « trop tôt », θ manuel, cas dégénérés.
- `tests/Unit/Forecast/MonteCarloTest.php` — quantiles, somme de distributions,
  probabilité d'atteinte, déterminisme, calibration conforme (borne, < 15 points).
- `tests/Unit/Forecast/Conformal` — couvert via MonteCarloTest (facteur clampé,
  apply autour de pred).
- `tests/Unit/Forecast/BacktestTest.php` — historique insuffisant, couverture
  sur série NegBin simulée, semaines complètes, déterminisme.
- `tests/Unit/Forecast/GoalTest.php` — probabilité décroissante avec
  l'objectif, manquant/jour cohérent, aucun jour restant, horizon.
- `tests/Unit/Forecast/ProfitForecastTest.php` — répartition marge, suspects,
  tout fiable, cas limites.
- `tests/Unit/Forecast/ProductForecastTest.php` — unités Poisson avec
  incertitude, déterminisme sans rng, filtres qty/suggestion.
- `tests/Unit/Forecast/EngineTest.php` — série plate 28 j, jour atypique,
  confiance, projection fin de journée = réel à 23 h, événements additifs,
  déterminisme par graine, dataset vide sans erreur.
- `tests/Unit/AnalyticsForecastDataTest.php` — extraction flux courant,
  stats produits, audit (0 €, doublons, coûts), assemblage dataset
  (skipped sans MySQL).
- `tests/Integration/ComptaConsistencyTest.php` — Analytics affiche les mêmes
  chiffres que le bilan (MySQL requis).

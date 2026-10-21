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
  aujourd'hui, **défaut 30** — ≈ 20 jours ouverts, au-dessus du seuil
  d'activation de la tendance) alimente le dataset unique : prévision du
  jour, horaire, jours à venir, semaine, 30 jours, bénéfice et produits
  sortent du même appel.
- **Une seule source d'unités, calibrée sur le moteur** : les unités par
  produit du réapprovisionnement sont calibrées sur le CA prévu 7 j
  (invariant **Σᵖ unitésᵖ × prixᵖ ≈ CA prévu 7 j**), et le bénéfice est
  DÉRIVÉ de cette même liste — plus d'échelle indépendante entre les cartes
  Réapprovisionnement et Bénéfice (audit : le réappro non calibré affichait
  ×2,5 à ×2,9 le CA du moteur, cause = `meanN × unités/transaction × 7 jours
  CALENDAIRES` — jours fermés inclus, tendance amortie ignorée).
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
≥ `max(1, ceil(0.5 × n_semaines))` occurrences, où **n_semaines compte les
semaines lun–dim ayant AU MOINS UN jour ouvert** — correctif du 21/10/2026
(WAPE modèle 94 % en prod) : le comptage par span calendaire gonflait le
seuil avec une fermeture complète (été : juin→octobre = 21 semaines ⇒ 11
occurrences/dow exigées, plus qu'aucun dow n'en avait) et `openWeekdays`
revenait vide ⇒ `expectedN = 0`, prédictions de backtest nulles. Les
semaines de fermeture totale ne comptent plus. Jours atypiques :
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
**Porte anti-bruit sur la tendance** : tant que `openDaysCount()` <
`TREND_MIN_OPEN_DAYS` (30 jours ouverts ≈ 6 semaines lun–ven), `b` est
**forcé à 0 à chaque pas** — SES niveau seul. Une tendance estimée sur
~9 jours ouverts est du pur bruit (dump prod : −5,26 %/jour appris sur du
bruit alterné, qui amputait toutes les prévisions) ; l'erreur est
asymétrique — sous-estimer coûte quelques euros de préparation,
sur-estimer fait jeter — et le niveau seul est le choix classique à faible
effectif. Au-delà du seuil, `b` est **plafonné** à ±`TREND_CAP_DAILY`
(±1 %/jour, échelle log) après chaque mise à jour.
Prévision : `exp(l_T + b_T·φ(1−φ^h)/(1−φ) + a_j)` (tendance bornée par `b_T/(1−φ)`).
Panier P : **moyenne tronquée 10 %** par dow, rétrécie vers le panier global
pareil. Dispersion binomiale négative par **méthode des moments** :
`r̂ = Σw·μ̂² / (Σw(x−μ̂)² − Σw·μ̂)`, clampée **[2, 200]**.
**La dispersion est une propriété GLOBALE du modèle** : `DemandModel::fit(…, r)`
peut IMPOSER r (clampé [2, 200]) au lieu de le recalculer. Le refit « hier »
(réajustement sans le jour expliqué) réutilise le r du modèle principal —
sinon son estimateur local s'effondre vers R_MAX quasi-Poisson et la
fourchette « Hier » devient plus étroite que « Aujourd'hui » à tort
(dump prod : r principal 18,0 vs refit sans hier 200 → ±18 % contre ±35 %).

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
appliqué autour de la prévision centrale (scores et demi-largeurs = méthode
sélectionnée avec SES intervalles, cf. étape 5). Moins de **15** résidus ⇒ pas de
calibration (intervalles MC conservés).

### Étape 5 — Backtest et sélection (`Backtest`)
**Origine glissante dès le 10ᵉ jour ouvert** : pour chaque jour i ≥ 9, réajustement
sur [0..i−1] (poids atypiques recalculés) et prévision du jour i. **Depuis la
passe « backtest historique complet », le backtest tourne sur un 2ᵉ dataset
HISTORIQUE COMPLET** (`Analytics::firstSaleDate()` → aujourd'hui, opts
`backtest_days` du moteur) : `BACKTEST_START = 10` n'est plus consommé par la
fenêtre d'apprentissage — même avec fw = 14 j (≈ 9 jours ouverts, backtest
jamais lancé avant), le WAPE est calculé dès ≥ 2 mois de ventes. Budget temps :
troncature aux **90 derniers jours ouverts** (`BACKTEST_MAX_DAYS`) et tirages
plafonnés `BACKTEST_DRAWS_MAX = 2000` (défaut moteur : `min(draws, 2000)`).
Le MODÈLE d'apprentissage reste sur la fenêtre fw (inchangé) ; le facteur de
calibration conforme vient du backtest complet. Baselines :
B1 = même dow la semaine précédente (sinon moyenne globale), B2 = moyenne des
occurrences passées du dow, B3 = moyenne des 7 derniers jours ouverts.
Métriques : **WAPE** (principal, B2…), **biais** moyen, **MASE** vs B1,
**couverture** des intervalles (cible 70-90 %), **pinball loss** sur les
quantiles, WAPE hebdomadaire sur semaines lun-dim complètes (5 jours ouverts).
**Intervalles PAR MÉTHODE** (correctif du 21/10/2026 — l'ancienne convention
« demi-largeur MC du main recentrée sur le sélectionné » est supprimée : elle
produisait des fourchettes dégénérées `[b2 ; b2]` quand le main prévoyait 0,
d'où la couverture 11,6 % observée en prod) : le modèle principal rapporte SES
intervalles **MC** (quantiles 0.10/0.90 des tirages) ; **B2 et B3** rapportent
un intervalle **empirique déterministe**, `pred ± q0.80` des `|résidus|`
PASSÉS de la méthode (rolling à chaque origine ; aucun résidu passé ⇒
intervalle ponctuel assumé, borne basse clampée à 0). `coverage` = couverture
de la méthode **sélectionnée** avec SES intervalles ; `coverage_main`,
`coverage_b2`, `coverage_b3` (résultat du backtest) et
`coverage_main_backtest` (payload moteur) exposent chaque méthode.
**Robustesse des premières origines** : `BACKTEST_START = 10` conservé (choix
de mission), et `wape_modele_recent` / `wape_baseline_recent` (anomalies)
exposent le WAPE sur les **20 derniers points** pour vérifier que les origines
précoces (sous-ensembles courts) ne pèsent pas seuls sur la moyenne.
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
(null si 0 jour restant et objectif non atteint). **Requis/jour** =
`(objectif − réel) / max(1, jours restants)` : le RYTHME journalier pour
VISER la cible, indépendant de l'attendu du modèle — affiché dès que
réel < objectif, même si le modèle prévoit d'atteindre (les deux libellés
sont distincts : « Requis/jour pour viser l'objectif » vs « Manque attendu
par jour restant »). **Horizon 30 jours = 30 jours CALENDAIRES** : restant
du jour (si ouvert) + jours ouverts des 29 jours suivants — JAMAIS 30
jours ouverts (l'ancienne boucle couvrait ≈ 42 dates : dump prod 21/10 →
01/12, mois30 = 2 924 € au lieu de ~1 871 €). Payload `mois30` :
`{attendu, lo, hi, debut, fin, jours_ouverts, jours_creuse}` — debut/fin
bornent une fenêtre d'exactement 30 dates, `jours_ouverts`/`jours_creuse`
comptent les occurrences de vente dans la fenêtre (aujourd'hui inclus s'il
est ouvert). Libellé de vue : « Horizon 30 jours (du 21/10 au 19/11 ·
21 jours ouverts, dont 9 en période creuse) » (index + kiosque).

**Choc de niveau commun** : chaque somme de chemin Monte Carlo (semaine et
horizon) est multipliée chemin par chemin par `f_path ~ Gamma(8, 1/8)`
(moyenne 1, σ ≈ 35 % — même prior que la mise à jour horaire, rng seedé du
moteur, flux « N ») AVANT d'ajouter le réel (jamais choqué). **Le niveau ne
se moyennise pas sur 30 jours** : une météo/affluence commune à toute la
période déplace le total — sans ce choc, la fourchette 30 j serait
absurdement étroite (±7 %) alors que l'incertitude de niveau domine.
Attendu après choc : mois30 ≈ ±30-45 % (honnête), semaine élargie aussi.

### Étape 7 — Bénéfice prévu (`ProfitForecast`)
Marge à 7 jours, **dérivée des MÊMES unités que le réapprovisionnement**
(une seule source d'unités, calibrée sur le CA prévu par le moteur, cf.
étape 8) : `ca_p = unités_p × prix_p`, `marge_p = unités_p × (prix_p − coût_p)`
pour les produits à coût valide uniquement (coût inconnu, coût ≤ 0 ou
prix < coût = « suspects », exclus). Agrégats par catégorie
(`marge_pct = marge / ca` des MÊMES unités) ⇒ **comparables à la marge
observée** du tableau « Détail par produit ». INVARIANT : Σ caᵖ ≈ CA prévu
7 j du moteur (±0,5 % pour les prix connus) — testé par le test de
réconciliation du moteur. Bandeau « **X % du CA sans coût fiable** » :
le total est une borne basse honnête.

### Étape 8 — Réapprovisionnement (`ProductForecast`)
Parts de marché lissées **Dirichlet (+1)** : `part_i = (qty_i + 1)/(Σqty + K)`.
**Unités BRUTES 7 j** : `λ = meanN × unités/transaction × 7`, tirages
**Poisson** sur le total répartis par parts ⇒ lo/hi = quantiles 0.10/0.90.
**CALIBRATION SUR LE MOTEUR** (une seule source d'unités) : un facteur
unique `f = CA prévu 7 j / Σᵢ(unités_brutesᵢ × prixᵢ)` recale unités, lo, hi
(dénominateur ≤ 0 ⇒ pas de calibration) — **INVARIANT : Σᵢ unitésᵢ × prixᵢ ≈
CA prévu 7 j**. La part de marché `part_i` reste brute (inchangée).
**Stock sécurité = q90** de la demande finale ; **suggestion =
max(0, q90 − stock)** (null si stock inconnu ou « infini »). Tri par
suggestion décroissante. Les cartes Réapprovisionnement et Bénéfice sont
étiquetées « **horizon 7 jours ouverts** ».

### Étape 9 — Moteur et intégration (`ForecastEngine`, contrôleur, vues)
`ForecastEngine::forecast(dataset, now, heure, opts)` enchaîne 0-8 et rend
le contrat complet (prevision_jour, intervalle_80, horaire, jours, semaine
avec `requis_par_jour`, mois30 {attendu, lo, hi, debut, fin, jours_ouverts,
jours_creuse}, benefice, produits, anomalies, notes,
métriques backtest, `dispersion_r`…). Options : `backtest_days` (historique
complet pour le backtest, cf. étape 5), `backtest_draws`, `slow_periods`
(périodes creuses, cf. infra), `events`, `goal_weekly`, `seed`, `draws`, `k`.
Le contrôleur assemble le dataset (`AnalyticsForecastData::dataset`), appelle
le moteur UNE fois, mappe vers `day_track` / `weekday` / `hourly` / payload
JSON ; routes `/admin/analytics` (GET), `/admin/analytics/objectif` (POST,
CSRF centralisé), `/kiosque/admin/analytics/{token}` (GET, jeton admin).

**Transparence de la sélection** : `anomalies` expose `wape_modele` (modèle
principal) et `wape_baseline` (B2) en plus de `wape_backtest` (sélectionné) —
l'info-bulle des cartes explique POURQUOI la baseline gagne :
« WAPE modèle X % vs baseline B2 Y % → baseline retenue (règle : le modèle
actif doit battre la naïve) », ou « WAPE modèle X % (bat la baseline Y %) »
(index + kiosque). Le bloc `debug` expose aussi `dispersion_r_borne` (vrai
quand l'estimateur a saturé `R_MAX` quasi-Poisson) : l'info-bulle affiche
« dispersion r = 200 (borne quasi-Poisson) » au lieu d'une valeur choisie.

**Insight « Meilleur jour »** (`Analytics::insights`, jour PARTIEL exclu) :
le classement s'arrête HIER et n'est émis que s'il reste **≥ 2 jours
distincts ET ≥ 2 semaines calendaires distinctes** avec CA > 0 (helper pur
`Analytics::meilleurJourSemaine`, testé sans base). Sinon pas d'insight —
une part « Lundi 60 % » calculée sur un jour et demi est tautologique.

## 3. Hyperparamètres FIXES (et pourquoi)

**Volontairement non optimisés sur ~4 semaines de données** : toute recherche
d'hyperparamètres sur si peu de points surapprendrait. Valeurs choisies a priori :

| Paramètre | Valeur | Pourquoi |
|---|---|---|
| `K_POOL` (rétrécissement) | 2 | 2 pseudo-observations : un dow rare reste proche de la moyenne globale sans l'écraser. |
| `ALPHA_SES` | 0.3 | SES lent : le niveau bouge doucement, robuste au bruit de petit volume. |
| `PHI_DAMP` | 0.9 | Tendance amortie : extrapolation prudente, converge vers b/(1−φ). |
| `TREND_MIN_OPEN_DAYS` | 30 | Porte anti-bruit : < 30 jours ouverts, tendance DÉSACTIVÉE (b forcé à 0, SES niveau seul) — une pente estimée sur ~9 jours ouverts est du bruit (prod : −5,26 %/jour fictifs) et l'erreur est asymétrique (sous-commander ≪ sur-commander). |
| `TREND_CAP_DAILY` | 0.01 | Plafond de la tendance ACTIVE : b clampé à ±1 %/jour (échelle log) après chaque mise à jour — toute pente réaliste passe, l'extrapolation reste bornée. |
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
| `BACKTEST_MAX_DAYS` | 90 | Borne du backtest sur l'historique complet : budget temps borné. |
| `BACKTEST_DRAWS_MAX` | 2000 | Plafond des tirages du backtest : couverture 80 % valide à 2000 tirages. |
| `R_MIN` / `R_MAX` | 2 / 200 | Dispersion NegBin bornée : surdispersion max r=2, r=200 ≈ Poisson. |
| `WEEKLY_GOAL_DEFAULT` | 500 € | Ordre de grandeur du CA hebdo de la cafétéria, modifiable en UI. |

## 4. Démarrage à froid et insuffisance

- **< 7 jours ouverts** (`INSUFFICIENT_DAYS`) : confiance « insuffisant »,
  bandeau « Données insuffisantes (< 7 jours ouverts) », **aucun % d'écart**,
  aucun statut fourchette.
- **Jour de semaine < 3 occurrences** : effet dow = moyenne globale, confiance
  **faible** (badge dans l'info-bulle du modèle).
- **Règle de confiance finale** (`ForecastEngine::confianceFinale`, APRÈS le
  backtest) : la « élevée » du modèle (≥ 14 jours ouverts ET dow vu ≥ 3 fois)
  est **conditionnée à un backtest validé** — backtest **actif**
  (`n_points ≥ BACKTEST_START`), **modèle principal retenu** et **couverture
  ∈ [0,70 ; 0,90]** — sinon plafond « moyenne ». « faible » (dow < 3) et
  « insuffisant » restent inchangés et prioritaires. La confiance reflète la
  qualité MESURÉE, pas seulement le volume d'historique (ex. prod 21/10/2026
  avant correctif : baseline retenue + couverture hors bornes → « moyenne »).
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

## 5 bis. Calendrier saisi à la main (période creuse)

Certaines baisses d'affluence sont **connues à l'avance mais non apprenables**
à moins de 12 semaines d'historique (Toussaint, vacances scolaires…) : elles
se SAISISSENT au lieu d'être devinées. Setting **`analytics_periode_creuse`**,
format `facteur;AAAA-MM-JJ/AAAA-MM-JJ;…` (ex. `0.8;2026-10-17/2026-11-02`),
champ éditable dans la carte Objectif du dashboard (même POST
`/admin/analytics/objectif`, CSRF centralisé) ; validation : facteur ∈
[0,1 ; 1], dates valides, **10 plages max** (valeur stockée invalide ⇒ ignorée).

**Sémantique du drapeau : « ces journées-là tournent à f × le niveau
normal ».** Il est appliqué EXACTEMENT UNE FOIS, en deux moitiés :

1. **À l'ENTRÉE (désaisonnalisation)** — dans `ForecastEngine`, après
   `Clean::splitPast` et le calcul des poids d'atypie (qui restent calculés
   sur les CA BRUTS : c'est le CA réel qui définit l'atypie), chaque jour
   passé DONT la date est en plage est ramené au niveau normal AVANT tout
   fit : `ca ÷ f`, `n ÷ f` (arrondi au transaction près), `hourlyN[h] ÷ f`
   (les parts horaires sont invariantes) ; les **paniers restent INCHANGÉS**
   — le drapeau modélise moins de PASSAGES, pas un panier différent. Le
   dataset désaisonnalisé nourrit `DemandModel::fit`, `HourlyProfile::fit`
   ET `Backtest::run` : le niveau et la tendance n'apprennent **jamais** la
   baisse des vacances, et le backtest mesure sur l'échelle normalisée.
2. **En SORTIE (prévisions)** — ×f UNIQUEMENT si la date cible est en
   plage : jours J+1..J+7, semaine restante, mois 30, projection du jour
   (tirages MC et intervalles suivent) ; les dates hors plage restent à
   l'échelle normale. « Hier » compare la prévision ×f(hier) au réel BRUT
   (l'observé n'est jamais ajusté) ; le facteur conforme s'applique à des
   intervalles normalisés, re-multipliés par f à l'affichage des dates en
   plage.

SANS la moitié « entrée », la baisse comptait DEUX FOIS (le niveau appris
voyait les jours bas, puis ×f réduisait encore les prévisions) :
sous-estimation ~50 % en prod pendant les vacances du 21/10/2026
(prevision_jour mercredi 60,39 € au lieu de ~80 €, jours_semaine modèle
36-55 € contre ~64-84 € attendus). Note moteur : « Historique
désaisonnalisé avant apprentissage : N jours de période creuse ramenés au
niveau normal (÷0,8). » — remplace l'ancien avertissement « double-comptage ».

**Transparence** : le bloc `debug` du contrat moteur expose le niveau et la
tendance appris — `niveau_eur` = exp(lastLevel) × panier global (€ par jour
MOYEN, hors effet dow, sur données désaisonnalisées), `tendance_pct_jour` =
(exp(lastTrend) − 1) × 100 (%/jour), `tendance_active` (bool :
openDaysCount ≥ TREND_MIN_OPEN_DAYS — sous 30 jours ouverts le % vaut 0 par
construction), `dispersion_r` — affichés dans l'info-bulle des cartes
prévisions (« Niveau ≈ X € · Tendance ≈ Y %/jour » ou, tendance désactivée,
« Tendance désactivée (historique < 6 semaines) »).
La carte « Par jour de semaine » distingue l'observé global de l'observé en
vacances : `obs_ca_creuse`/`obs_n_creuse` = moyenne et nb des jours du dow
dont la date est en plage (null si aucun) — tooltip : « Observé : 105,60 €
(n=2) · dont période creuse : 79,60 € (n=1) ».

Cette saisie manuelle est un PALLIATIF documenté : à ≥ 12 semaines de
données, l'apprentissage du calendrier scolaire (variables externes)
remplacera la saisie (cf. Évolutions prévues).

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
  vers le global, insuffisance/confiances, dispersion bornée, **dispersion
  imposée réutilisée et clampée (réutilisation du r principal par le refit
  « hier »)**.
- `tests/Unit/Forecast/HourlyProfileTest.php` — profils valides, dow inconnu,
  fermeture exacte, « trop tôt », θ manuel, cas dégénérés.
- `tests/Unit/Forecast/MonteCarloTest.php` — quantiles, somme de distributions,
  probabilité d'atteinte, déterminisme, calibration conforme (borne, < 15 points).
- `tests/Unit/Forecast/Conformal` — couvert via MonteCarloTest (facteur clampé,
  apply autour de pred).
- `tests/Unit/Forecast/BacktestTest.php` — historique insuffisant, couverture
  sur série NegBin simulée, semaines complètes, déterminisme, **régression
  fermeture estivale** (prédictions vivantes après un trou d'historique,
  correctif 21/10/2026), **couverture par méthode + intervalles B2 non
  dégénérés**, WAPE 20 derniers points.
- `tests/Unit/Forecast/GoalTest.php` — probabilité décroissante avec
  l'objectif, manquant/jour cohérent, **requis/jour depuis le réel (indépendant
  de l'attendu)**, **choc de niveau commun appliqué chemin par chemin**,
  aucun jour restant, horizon.
- `tests/Unit/Forecast/HorizonMois30Test.php` — **horizon 30 j CALENDAIRE**
  : span debut→fin exactement 30 dates (19/10 → 17/11), ensemble des jours
  ouverts inclus sans un de plus (22 occurrences, sam/dim exclus),
  `jours_creuse` (plage 26/10 → 08/11 → 10), somme ≈ Σ prévisions ± MC,
  aujourd'hui fermé sans effet sur la fenêtre.
- `tests/Unit/Forecast/ProfitForecastTest.php` — **bénéfice dérivé des unités
  calibrées (marges catégories = Σ marges variantes, comparables à l'observé)**,
  suspects, tout fiable, régimes de la note, cas limites.
- `tests/Unit/Forecast/ProductForecastTest.php` — unités Poisson avec
  incertitude, **calibration sur le CA cible (invariant Σ unités × prix = cible)**,
  déterminisme sans rng, filtres qty/suggestion.
- `tests/Unit/Forecast/SlowPeriodsTest.php` — parsing/validation du format
  `facteur;début/fin`, bornes du facteur, 10 plages max, facteur par date.
- `tests/Unit/Forecast/DesaisonnalisationTest.php` — **T1** série plate
  sans drapeau ≈ 100 € (verrou anti-45 €), **T2** 6 jours à 100 € + 6 jours
  de vacances à 80 € flagués → EN plage ≈ 80 € / HORS plage ≈ 100 € /
  tendance ≈ 0 (le test qui échoue sans désaisonnalisation), **T3** jour
  courant absent/partiel pendant une plage sans effet sur les jours
  suivants, note désaisonnalisation avec le bon N, `debug` cohérent,
  `obs_ca_creuse`/`obs_n_creuse` sur dataset à la main.
- `tests/Unit/Forecast/TendanceTest.php` — **porte anti-bruit** : bruit
  alterné ±15 € sur 20 jours ouverts → `lastTrend == 0` et prévision à
  10 jours ∈ [90 ; 110] ; **plafond** : déclin fort (−8 %/jour) sur ≥ 30
  jours ouverts → |b| ≤ 1 %/jour et décroissement inter-jours ≤ ~1 % ;
  **sensibilité** : retirer l'extrême ne crée pas de tendance sous le seuil
  et ne change pas le signe de b au-dessus ; tendance active en croissance
  douce (+1 %/jour) ; défaut fw = 30 (helper contrôleur).
- `tests/Unit/Forecast/ConfianceTest.php` — **confiance finale conditionnée
  au backtest** : backtest inactif/baseline retenue/coverage hors
  [0,70 ; 0,90] → max « moyenne » ; backtest validé → « elevee » conservée ;
  « faible » prioritaire ; niveau moteur (14 jours → backtest 5 points <
  BACKTEST_START → « moyenne » ; cohérence payload/confiance).
- `tests/Unit/Forecast/EngineTest.php` — série plate 28 j, jour atypique,
  confiance, projection fin de journée = réel à 23 h, événements additifs,
  **réconciliation |Σ ca produits − Σ jours ca| < 5 %**, **backtest sur
  historique complet même quand fw < 10 jours ouverts**, **période creuse
  (futur ajusté, hier jamais)**, **choc de niveau commun (fourchette 30 j
  plus large que celle d'un jour)**, **« hier » réutilise la dispersion
  principale**, déterminisme par graine, dataset vide sans erreur.
- `tests/Unit/AnalyticsMeilleurJourTest.php` — garde-fous de l'insight
  « meilleur jour » : ≥ 2 jours ET ≥ 2 semaines calendaires avec CA > 0.
- `tests/Unit/AnalyticsForecastDataTest.php` — extraction flux courant,
  stats produits, audit (0 €, doublons, coûts), assemblage dataset
  (skipped sans MySQL).
- `tests/Integration/ComptaConsistencyTest.php` — Analytics affiche les mêmes
  chiffres que le bilan (MySQL requis).

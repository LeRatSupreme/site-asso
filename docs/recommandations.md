# Moteur de recommandations commerciales (cafétéria AEIC)

Documentation du moteur d'idées R1–R11. Le site **PROPOSE**, un humain
**DÉCIDE** : aucune modification automatique de prix ou de stock — chaque idée
attend une décision (appliquée / plus tard / ignorée) dans l'admin, et les
prix affichés ne sont que des propositions chiffrées.

Cœur pur (sans aucun accès DB) : `app/core/Compta/Reco/` — `Hyper.php`
(constantes), `RecoDataset.php`, `RecoProduct.php`, `Basket.php`,
`RecoContext.php`, `GuardRails.php`, `Scoring.php`, `Recommendation.php`,
`RecommendationEngine.php`, `EffetMesure.php`, `R1StockDormant.php` …
`R11QualiteDonnees.php`. Extraction : `app/models/RecoData.php` (assemblage du
dataset depuis la base + lancement du moteur). Intégration :
`app/controllers/Admin/AdminRecoController.php`. Vues : `views/admin/reco/`
et la carte « Idées du moment » des dashboards (`views/admin/analytics/`).
Persistance : table `recommendations` (migration
`database/migrations/2026_recommendations.sql`, modèle `app/models/Recommendation.php`).
Réglages : migration `database/migrations/2026_recommendations_settings.sql`.

## 1. Architecture et source unique

`RecommendationEngine::recommend(RecoDataset $data, DateTimeImmutable $now, array $opts = [])`
est la **SOURCE UNIQUE** du cœur : il enchaîne les règles R1–R9 + R11
(R10 étant assemblé à part, cf. § 3.10), valide chaque candidat via
`GuardRails`, classe via `Scoring` et rend
`['idees' => list<Recommendation>, 'meta' => array]` (audit complet : creuse,
historique, rejets, paramètres résolus, horodatage Europe/Paris). Le moteur
est déterministe : même entrée ⇒ même sortie ; aucune horloge externe, aucun
accès DB.

- **JAMAIS de second modèle de demande** : le moteur consomme la sortie
  `ForecastEngine::forecast()` TELLE QUELLE, portée par le dataset
  (`RecoDataset::$forecast`) — unités prévues 7 j par produit
  (`RecoProduct::$unitesPrevues7j` + intervalle `prevLo/prevHi`), profil
  horaire cumulé `horaire['s']` (parts S(h) dérivées par différence,
  cf. `RecoContext::partsHoraires()`), semaine en cours `semaine`
  (probabilité d'atteinte de l'objectif, manquant/jour, jours restants),
  jours ouverts `jours_semaine`. Aucune règle ne recalcule une prévision.
- **Désaisonnalisation systématique** : tant qu'une période creuse est
  active (`RecoDataset::$periodeCreuseActive`), tous les seuils volumiques
  comparent la quantité DÉSAISONNALISÉE `qty30 ÷ facteurCreuse`
  (`RecoContext::qtyDesaison()` ; facteur ≤ 0 ⇒ pas de division) —
  un produit n'est pas jugé « fin de gamme » parce que c'est les vacances.
- **Surcharges ponctuelles** : tout hyperparamètre est lisible via
  `RecoContext::param('nom', Hyper::NOM)` qui lit
  `$parametres['reco_nom']` si numérique, sinon la constante (méthode
  `Hyper::valeur`). Jamais en base de ventes, jamais dans `.env` ; les 7
  réglages admin sont mappés dans ces paramètres (§ 7).

## 2. Garde-fous (1–7)

| # | Garde-fou | Formule / règle (code) |
|---|---|---|
| 1 | **Plancher de prix** | Tout prix proposé ≥ `prixPlancher = coût × (1 + MARGE_MIN)` avec `MARGE_MIN = 0.15` (`GuardRails::prixPlancher`, tolérance flottante `EPS = 1.0e-9`). R1/R3/R5 soumettent des `prix_effectifs` vérifiés un par un. |
| 2 | **Coût suspect** | Un produit à `coutRaison` non nul (`cout_inconnu`, `cout_zero`, `prix_inferieur_au_cout`) ne peut être ciblé ni par les règles tarifaires R1/R3/R5/R6/R7 (`REGLES_INTERDITES_COUT_SUSPECT`), ni par une reco portant des `prix_effectifs`. En miroir, R11 émet une reco « Renseigner le coût » pour ces produits (§ 3.11). |
| 3 | **Existence & catégorie** | Chaque clé ciblée doit exister dans le catalogue et hors catégories exclues `CATEGORIES_EXCLUES = ['Événement', 'Divers']` (le catalogue filtré `produitsFiltres()` les exclut d'office). |
| 4 | **Seuils d'échantillon** | Volumétrie minimale avant d'oser une reco : `SAMPLE_MIN_UNITES_30J = 20` u./30 j (R1, R3, confiance « haute » de R2), `R4_SAMPLE_MIN = 8` (R4), `ASSOC_PANIERS_MIN = 30` paniers multi-articles (R5 chemin associations), `TENDANCE_VOL_MIN = 15` des deux côtés (R9). |
| 5 | **Désaisonnalisation** | Les seuils volumiques travaillent en `qty30 ÷ facteurCreuse` en période creuse (§ 1) ; R9 (tendance) s'annule carrément en période creuse — la variation peut n'être que saisonnière. |
| 6 | **Fourchette de gain crédible** | `GuardRails::fourchette(bas, median, haut)` : `bas ≥ 0` puis ordre `bas ≤ median ≤ haut` (tri sinon) ; tout candidat hors ordre est refusé. Gains bornés des règles à conversion hypothétique : `bas = 0,5 × médian ≤ médian ≤ 1,5 × médian = haut`. |
| 7 | **Confiance & effort connus, coupe finale** | Confiance ∈ CONFIANCE_FACTEURS (`faible` 0,5 / `moyenne` 0,75 / `haute` 1,0) ; effort entier ∈ [1, 3]. En sortie : cap `MAX_ACTIVES = 5` idées actives, **dédoublonnage par produit** (une clé n'apparaît que dans la première reco retenue) et **refus 30 j** (`REFUS_JOURS`) — cf. § 4. |

## 3. Les règles R1–R11

### 3.1 R1 — Stock dormant (`R1StockDormant`, effort 2)

**Déclenchement** (par produit à coût utilisable, stock géré) :

- stock `> reco_stock_dormant_min` (**10** unités) ;
- ET semaines de stock = stock ÷ `unitesPrevues7j` `> reco_stock_dormant_semaines` (**8** semaines) — la PRÉVISION domine le passé : si aucune demande n'est prévue (≤ 0), le stock est qualifié dormant dès lors que la quantité désaisonnalisée est strictement positive ;
- ET quantité désaisonnalisée `≥ reco_echantillon_min_unites` (**20** u./30 j).

**Action** : remise cible `REMISE_CIBLE = 15 %` clampée dans
[`reco_remise_dormant_bas` (**10 %**) .. `reco_remise_dormant_haut` (**20 %**)],
puis plafonnée par le garde-fou 1 : `remiseMax = 1 − coût × (1 + MARGE_MIN) ÷ prix`.
Si `remiseMax ≥ 10 %` : promo « −X % » avec `X = min(remiseMax, cible)` et
`prix_effectifs = prix × (1 − X)`. Sinon « **lot 3 pour 2** »
(`PRIX_LOT_3POUR2 = 2/3` du prix par unité) si `2/3 ≥ (1 + MARGE_MIN) × coût ÷ prix`.
Sinon aucune sortie possible sans casser le plancher : **aucun candidat**.

**Gain hebdo (€)** = surplus ÷ durée × marge remisée, où
`surplus = max(stock − 8 × prévision, 0)` supposé écoulé en
`DUREE_ECOULEMENT_SEMAINES = 4` semaines : médian avec `unitesPrevues7j`,
**bas avec `prevHi`** (moins d'excédent), **haut avec `prevLo`**.

**Hypothèses affichées** : vérifier les dates de péremption ; surplus vendu en 4 semaines grâce à la remise. Confiance « moyenne ».

### 3.2 R2 — Rupture / stock critique (`R2Rupture`, effort 1)

**Déclenchement** : prévision 7 j strictement positive ET stock géré nul OU
`stock < ceil(unitesPrevues7j × reco_secu_stock_jours (3) ÷ 7)` — le seuil de
sécurité couvre **3 jours** de demande prévue. Aucune condition de coût :
R2 n'est pas tarifaire, le candidat part **sans `prix_effectifs`**.

**Gain hebdo (€)** = demande prévue × marge unitaire (médian `prev × marge`,
bas `prevLo × marge`, haut `prevHi × marge`) ; coût inconnu ⇒ marge 0
(gain non chiffré, hypothèse explicite). **Action** : commander
`max(seuil − stock, 1)` unités (liste de courses).

**Confiance** : « haute » si quantité désaisonnalisée ≥ **20** u./30 j,
sinon « moyenne » avec l'hypothèse « demande estimée » affichée.

### 3.3 R3 — Marge faible sur fort volume (`R3MargeFaible`, effort 2)

**Déclenchement** (par produit filtré) : clé dans le « top CA »
(`topCaKeys()` = `max(1, ceil(TOP_CA_PART = 0.20 × n))` produits par CA 30 j
décroissant), coût utilisable, taux de marge `< reco_marge_faible_seuil
(0,25)`, quantité désaisonnalisée `≥ 20`.

**Action** : hausse testée `DELTA_FORT = 0,20 €` si volume désaisonnalisé
`≥ SEUIL_QTY_DELTA (40 u./30 j)`, sinon `DELTA_FAIBLE = 0,10 €`, pendant
`TEST_PRIX_JOURS = 14` jours (`prix_effectifs = prix + Δ`). L'action affiche
la perte de volume maximale admissible :
`perteVolumeMax(prix, coût, Δ) = 1 − ((prix−coût)/prix) ÷ ((prix+Δ−coût)/(prix+Δ))`
— au-delà, le test détruit de la marge totale.

**Gain hebdo (€)** : bas = **0** (aucune hausse de volume) ; médian =
`Δ × prévision × PART_VOLUME_CONSERVE (0,75)` ; haut = `Δ × prévision +
prévision × coût × HAUSSE_VOLUME_ESPEREE (0,075)`. Hypothèses : élasticité-prix
inconnue ; piste complémentaire « renégocier le prix d'achat (−5 à −10 %) »
(`COUT_ACHAT_BAS = 0.05` / `COUT_ACHAT_HAUT = 0.10`). Confiance « moyenne ».

### 3.4 R4 — Forte marge sous-vendue (`R4MargeSousVendue`, effort 1)

**Déclenchement** : coût utilisable, taux de marge **>** médiane des taux de
marge (strict), volume désaisonnalisé **<** médiane des volumes (strict),
stock non géré ou `≥ STOCK_MIN_MISE_EN_AVANT (5)` unités, et volume
`≥ reco_r4_sample_min (8)`. Médiane indisponible (moins de 3 produits à coût
exploitable) ⇒ aucun candidat. Pas de remise : visibilité (affiche,
emplacement caisse, suggestion du jour).

**Gain hebdo (€)** = `prévision × reco_r4_gain_vol (0,20) × marge unitaire` ;
bas = 0,5 × médian, haut = 1,5 × médian. **Hypothèses** : « +20 % de volume =
hypothèse », mise en avant sans coût (marge unitaire inchangée). Confiance « moyenne ».

### 3.5 R5 — Duos / lots (`R5Duos`, effort 2, un seul candidat)

**Chemin (a) associations** (si `≥ reco_assoc_paniers_min (30)` paniers
multi-articles) : pour chaque paire co-occurrente, `support = co ÷ nPaniers`,
`confiance = co ÷ paniers(a)`, `lift = confiance ÷ P(b)` ; retenues si
`lift > reco_assoc_lift_min (1,2)`, `support ≥ reco_assoc_support_min (0,02)`
et `confiance ≥ reco_assoc_conf_min (0,05)` ; **meilleure paire par lift
décroissant** (départages support, confiance, clés — déterministe) ;
accroche = plus fort volume désaisonnalisé des deux. **Chemin (b)
heuristique** (sinon) : accroche = produit à coût utilisable de plus fort
volume ; cible = plus fort taux de marge, stock non géré ou
`> STOCK_CIBLE_MIN (10)`, catégorie différente ; confiance « faible ».

**Prix duo** : `rMax = 1 − (ca + cb) ÷ ((1 + MARGE_MIN) × (pa + pb))` ;
`r = min(reco_duo_remise_max (0,10), rMax, rPlancher)` avec
`rPlancher = min par produit (1 − ci × (1 + MARGE_MIN) ÷ pi)` — le garde-fou 1
est garanti sur **chacun** des deux `prix_effectifs`. `rMax ≤ 0`, `r ≤ 0` ou
marge duo ≤ 0 ⇒ rien.

**Gain hebdo (€)** : `margeDuo = (pa+pb) × (1−r) − ca − cb` ; (a)
`CONVERSION_ANALYSE (25 %)` × (paniers « accroche sans cible » ÷ 4,33) ×
marge duo, confiance « moyenne » ; (b) `CONVERSION_HEURISTIQUE (15 %)` ×
unités prévues de l'accroche × marge duo, confiance « faible » ; bas 0,5× /
haut 1,5×.

### 3.6 R6 — Seuil cadeau (`R6Panier`, effort 1, un seul candidat)

**Déclenchement** : part de paniers à 1 ligne `> reco_panier_1article_seuil
(0,85)`. **Seuil** = quantile `reco_panier_percentile (0,75)` des montants de
tous les paniers. **Cadeau** = produit filtré le moins cher à coût connu et
`≤ reco_cout_cadeau_max (0,15)`, stock non géré ou `> STOCK_CADEAU_MIN (10)`.
Pas de `prix_effectifs` (aucun prix proposé).

**Gain hebdo (€)** = (paniers proches ÷ 4,33) × taux de franchissement ×
(marge moyenne des articles proches − coût du cadeau), où « proches » = montant
dans `[seuil − FENETRE_PROXIMITE_EUR (2 €) ; seuil]` et
`TAUX_FRANCHISSEMENT = 0,10` (+10 % des paniers proches franchissent) ;
bas 0,5× / haut 1,5×. **Confiance** : « moyenne » si `≥ 30` paniers observés,
sinon « faible » (hypothèse affichée).

### 3.7 R7 — Rythme horaire (`R7Rythme`, effort 1, un seul candidat)

À partir des parts horaires dérivées du cumul `forecast['horaire']['s']` :
une heure est « creuse » sous `reco_seuil_creux_part (0,4)` × moyenne des parts
positives, « pic » au-dessus de `reco_seuil_pic_part (1,5)` × moyenne.
**Un seul candidat combiné** si au moins un creux ET un pic existent : happy
hour aux heures creuses sur les 3 meilleurs produits par taux de marge
(`PRODUITS_MIS_AVANT = 3`), préparation renforcée aux pics. Pas de `prix_effectifs`.

**Gain hebdo (€)** = CA 7 j prévu (Σ `unitesPrevues7j × prix` des produits à
coût utilisable) × part de demande des heures creuses × `UPLIFT_CREUX (10 %)`
× taux de marge médian (secours `TAUX_MARGE_SECOURS = 0,30` faute de 3 produits
à coût fiable — hypothèse affichée) ; bas 0,5× / haut 1,5×. Confiance « moyenne ».

### 3.8 R8 — Fin de gamme (`R8Gamme`, effort 2)

**Déclenchement** (par produit filtré à coût utilisable) : stock géré
`≥ 1`, demande désaisonnalisée `< reco_gamme_unites_max (3 u./30 j)` ET
valeur immobilisée `stock × coût > reco_gamme_immobilise_seuil_eur (30 €)`.
**Action** : réduire ou écouler (décommander, promo de fin de série). Pas de `prix_effectifs`.

**Gain hebdo (€)** = `stock × marge × hypothèse d'écoulement` :
`ECOULEMENT_PCT_BAS = 0` / `ECOULEMENT_PCT_MEDIAN = 0,25` /
`ECOULEMENT_PCT_HAUT = 0,50` du stock. Hypothèses affichées : écoulement
médian 25 % (fourchette 0–50 %), marge unitaire constante. Confiance « moyenne ».

### 3.9 R9 — Tendance (`R9Tendance`, effort 1)

**Aucun candidat si période creuse active** (garde-fou 5). Par produit :
historique `qtyPrec` connu (> 0), volumes suffisants des deux côtés
(`≥ reco_tendance_vol_min (15)`), variation relative au-delà de
`reco_tendance_seuil (±0,50)` : `|qty/prec − 1| > 0,5`.

- **Croissance** : suivre le stock ; gain médian =
  `(qty − prec) ÷ 4,33 × marge` (coût inconnu ⇒ 0 €, suivi quand même),
  bas 0, haut 1,5×.
- **Déclin** : investigation (ruptures, concurrence, saisonnalité), gain
  chiffré **0/0/0**.

Confiance « moyenne ».

### 3.10 R10 — Objectif hebdo en risque (`R10Objectif`, effort 1, assemblé)

R10 n'a **pas** d'`evaluer()` (classe utilitaire) : le moteur l'assemble
**après** validation des candidats sources `R1/R4/R5/R6`, si
`forecast['semaine']['prob_atteinte'] < reco_prob_objectif_min (0,50)` : il
fusionne les **deux meilleures actions** par gain médian décroissant (gains
additionnés composante à composante, `prix_effectifs` fusionnés sans doublon,
hypothèses fusionnées ordonnées et dédupliquées, max `HYPOTHESES_MAX = 4`).
Action affichée : « Il manque ~X €/jour sur N jours restants ; prioriser : … ».
Moins de 2 sources éligibles ou semaine absente ⇒ rien. Confiance « moyenne ».

### 3.11 R11 — Qualité des données (`R11QualiteDonnees`, effort 1, prioritaire)

Sans `prix_effectifs`, gains **0/0/0** (c'est de la donnée, pas du CA),
confiance « haute ». Trois familles de candidats, dans cet ordre, plafonnées à
`reco_r11_max (3)` au total :

1. **Coûts suspects** — produit à `coutRaison ∈ {cout_inconnu, cout_zero, prix_inferieur_au_cout}` et (`qty30 > 0` ou prévu par le forecast) ⇒ « Renseigner le coût de … » ;
2. **Doublons de noms** — libellés égaux après normalisation (trim, minuscules, espaces réduits) sur ≥ 2 fiches ⇒ « Fusionner les fiches « … » » ;
3. **Produits sans stock** — labels de `forecast['anomalies']['produits_sans_stock']` ⇒ « Vérifier le stock de … » (clé du produit si trouvé au catalogue, sinon clé préfixée `sans_stock:` — les clés vides étant refusées par le garde-fou 3).

## 4. Score et classement (`Scoring`)

`score = gain_hebdo['median'] × CONFIANCE_FACTEURS[confiance] ÷ effort` —
facteurs de confiance `faible 0,5 / moyenne 0,75 / haute 1,0`, efforts par
règle `EFFORTS : R1=2, R2=1, R3=2, R4=1, R5=2, R6=1, R7=2, R8=2, R9=1, R10=1,
R11=1` (confiance inconnue ⇒ facteur 0, effort invalidé à ≥ 1).

`Scoring::classe()` applique ensuite, dans l'ordre :

1. **Historique** : exclut les candidats dont l'empreinte sha1
   (`fingerprint = sha1('règle:clés triées uniques')`) est dans les idées
   actives, ou dans les refus de moins de `REFUS_JOURS = 30` jours ;
2. **Tri** : les **R11 d'abord** (cap `R11_MAX = 3` candidats R11 — la donnée
   avant le CA), puis score décroissant, égalités par numéro de règle croissant ;
3. **Dédoublonnage par produit** : un produit ne figure que dans la première
   reco retenue qui le contient (les clés déjà prises sont retirées du
   candidat, lui-même coupé s'il ne reste rien d'unique) ;
4. **Cap global** `MAX_ACTIVES = 5` idées maximum.

## 5. Format de sortie (`Recommendation::toArray()`)

Les **13 clés exactes**, dans cet ordre :

| Clé | Type |
|---|---|
| `id` | string (empreinte sha1 règle+produits) |
| `regle` | string (`'R1'` … `'R11'`) |
| `titre` | string |
| `pourquoi` | string (données observées) |
| `action` | string (action concrète proposée) |
| `gain_hebdo` | `array{bas:float, median:float, haut:float}` (€/semaine) |
| `confiance` | string (`'faible' \| 'moyenne' \| 'haute'`) |
| `effort` | int (1–3) |
| `hypotheses` | list&lt;string&gt; |
| `produits` | list&lt;array{key:string, label:string}&gt; |
| `garde_fous_ok` | bool (toujours true en sortie : les rejets ne passent pas) |
| `comment_mesurer` | string (protocole de mesure d'effet) |
| `statut` | string (`'proposée'` à la génération) |

`meta` accompagne les idées : `creuse_active`, `facteur_creuse`,
`historique_insuffisant`, `jours_ouverts_fenetre`, `n_paniers`, `genere_le`
(Europe/Paris), `parametres` (marge_min, prob_objectif_min résolus),
`rejets` (candidats silencieusement refusés par les garde-fous).

## 6. Boucle d'apprentissage

1. **Proposition** : le moteur génère les idées du jour ; l'intégration les
   upserte dans `recommendations` (clé = empreinte ; re-upsert ne duplique
   pas la ligne et ne remet JAMAIS à zéro statut, décision, baseline ni
   effet).
2. **Décision humaine** : `proposee` → `appliquee` / `plus_tard` / `ignoree`
   (statuts en base, `Recommendation::decision()`). À l'application, la
   **baseline d'effet** est photographiée : `RecommendationEngine::baseline()`
   produit le CA attendu par jour OUVERT pour les produits de la reco —
   `dowRatio = n(dow) ÷ moyenne(n des jours ouverts de
   forecast['jours_semaine'])` (repli 1,0), `attenduJour = Σ unités × prix ÷ 7
   × dowRatio`, `lo/hi = mêmes ratios produits (prevLo/prevHi ÷ attendu,
   pondérés par l'attendu)`, `facteur = facteurCreuse` du dataset.
   **Aucun nouveau modèle** : la baseline recombine les unités du forecast.
3. **Mesure après 14 jours ouverts** (`EFFET_DECLANCHEMENT_JOURS_OUVERTS`) :
   `EffetMesure::calculer(baseline, réelParJour, joursOuvertsEcoules,
   chevauchement)` compare le CA réel au jour à l'attendu — le réel est
   **désaisonnalisé** (`réel ÷ facteur`, facteur < 1 en creuse ramené à la
   hausse) ; `effet = réel − attendu`, intervalle
   `[réel − hi ; réel − lo]` ; verdict `concluant` si zéro est HORS de
   l'intervalle, sinon `non_concluant`. Le résultat est écrit via
   `Recommendation::saveEffet()` (`effet_json`, `effet_conclusion`,
   `effet_checked_at`). `EffetMesure::libelle()` formate en français :
   « Effet mesuré +210,00 € [70,00 ; 350,00] €/période » ou « Non concluant
   (l'intervalle contient zéro) ».
4. **Minimums** : la mesure ne démarre **jamais** avant
   `EFFET_MIN_JOURS_OUVERTS = 10` jours ouverts couverts (« insuffisant »
   en dessous). Un **chevauchement** avec une autre promo sur les mêmes
   produits ne change pas l'état mais porte la note « Effet non attribuable ».
5. **Blocage 30 jours** : `Recommendation::FENETRE_BLOCAGE_JOURS = 30` — une
   application reste « active » 30 jours (alimente l'exclusion d'empreintes),
   un refus (`ignoree`) bloque le re-proposage de la même idée 30 jours
   (`refusRecents()`), miroir exact de `Hyper::REFUS_JOURS` du cœur.

## 7. Interface

- **Carte « Idées du moment »** sur les dashboards analytics et kiosque :
  les idées actives (issues de `Recommendation::proposees()` / upserts du
  jour) avec règle, gain en fourchette et confiance ; aucun chiffre recalculé
  côté vue (même principe que la prévision).
- **Page `/admin/reco/historique`** (`AdminRecoController`,
  `views/admin/reco/`) : historique des applications avec « ce qui a
  marché » — libellé d'effet mesuré par idée (`EffetMesure::libelle`),
  dates de décision/application, motifs.
- **Seuils admin > Paramètres** (migration `2026_recommendations_settings.sql`,
  groupe « recommandations », 7 clés) :

| Clé | Défaut | Unité | Rôle |
|---|---|---|---|
| `reco_marge_min` | 0.15 | fraction (15 %) | Marge unitaire minimale → plancher des prix (garde-fou 1). |
| `reco_stock_dormant_semaines` | 8 | semaines | Seuil « stock dormant » (R1). |
| `reco_stock_dormant_min` | 10 | unités | Stock minimal pour « dormant » (R1). |
| `reco_echantillon_min_unites` | 20 | unités / 30 j | Échantillon minimal (R1, R3 ; confiance R2). |
| `reco_duo_remise_max` | 0.10 | fraction (10 %) | Remise maximale d'un duo (R5). |
| `reco_immobilise_seuil_eur` | 30 | € | Seuil de valeur immobilisée fin de gamme (R8). |
| `reco_max_actives` | 5 | idées | Nombre maximal d'idées actives simultanées. |

Ces clés sont chargées telles quelles dans `RecoDataset::$parametres` par
`RecoData::parametres()` (valeurs numériques valides uniquement) ; le cœur
les lit ensuite au format `reco_<nom>` via `Hyper::valeur` — toute clé sans
correspondance exacte (ex. `reco_immobilise_seuil_eur` vs
`reco_gamme_immobilise_seuil_eur` lu par R8, `reco_max_actives` consommé
directement comme constante) retombe sur la constante `Hyper` du cœur.

- **Mode « idées limitées, historique insuffisant »** : si la fenêtre analysée
  compte `< HIST_MIN_JOURS_OUVERTS = 14` jours ouverts, seules les règles
  **R2 / R8 / R11** sont évaluées (`REGLES_HISTORIQUE_COURT`) — rupture,
  fin de gamme et qualité de données ne demandent pas d'historique de
  comportement ; `meta['historique_insuffisant']` l'expose et l'interface
  affiche le bandeau correspondant.

## 8. Ce qui reste incertain

Le moteur affiche ses hypothèses dans chaque idée et ne les optimise pas
automatiquement (même choix de mission que `Forecast\Hyper`). Les chiffres de
gain reposent sur des hypothèses NON mesurées à ce stade :

- **Élasticité-prix inconnue** : R3 propose un test **+0,10 €** (ou +0,20 € si
  ≥ 40 u./30 j) pendant 14 jours précisément pour la MESURER ; le gain médian
  suppose 75 % du volume conservé, la borne haute un effet volume espéré de
  +7,5 % du coût — les deux sont des choix a priori, pas des observations.
- **+20 % de volume** en R4 (mise en avant) : pure hypothèse ; la fourchette
  0,5×–1,5× ne couvre que l'imprécision autour de cette conversion, pas sa
  validité.
- **Adoption des duos 25 % / 15 %** (R5) : 25 % des paniers « accroche sans
  cible » en mode associations, 15 % des unités prévues de l'accroche en
  heuristique — aucune donnée de conversion réelle tant que le premier duo
  n'a pas été testé.
- **Franchissement du seuil cadeau 10 %** (R6) : le taux de paniers proches
  (montant dans [seuil − 2 € ; seuil]) qui « sautent » grâce au cadeau est
  estimé, pas mesuré.
- **Uplift happy hour 10 %** (R7) : le CA des heures creuses est supposé
  augmenter de 10 % ; le secours de taux de marge 0,30 dégrade l'estimation
  quand les coûts sont trop peu fiables.
- **Écoulement dormant en 4 semaines** (R1) : le surplus est supposé vendu en
  `DUREE_ECOULEMENT_SEMAINES = 4` grâce à la remise ; l'écoulement réel peut
  être bien plus lent (d'où « vérifier les dates de péremption »).
- **dowRatio de baseline** (§ 6) : la saisonnalité hebdomadaire de l'effet
  mesuré est approximée par l'occurrence observée de chaque jour de semaine —
  fidèle au modèle, mais sans correction météo/événements.
- **Élasticité marginale des paniers** (R6) : la fenêtre ±2 € est une
  constante, pas un seuil comportemental observé.
- **R8 (écoulement 25 %)** et **R9 (croissance linéaire sur 7 jours)** :
  scénarios médians assumés ; le déclin R9 n'est d'ailleurs volontairement
  pas chiffré (investigation avant action).

Aucun de ces paramètres n'est appris en ligne : les mesures d'effet (§ 6)
sont le mécanisme prévu pour les recalibrer à la main, avec des données.

## 9. Tests

Où : `tests/Unit/Reco/` (une classe de test par règle —
`R1StockDormantTest` … `R11QualiteDonneesTest` — plus `EngineTest`,
`EffetMesureTest`, `BaselineTest`, `GuardRailsTest`, `HyperTest`,
`ScoringTest`, `RecommendationTest` et le jeu de données partagé
`RecoScenario.php`), `tests/Unit/RecommendationModelTest.php` (modèle et
fenêtre 30 j) et `tests/Integration/RecoPagesTest.php` (pages admin, livrée
avec l'intégration contrôleur/vues ; MySQL requis — `aeic_test`, skipped
sans base, comme les autres tests d'intégration).

Ce qui est couvert :

- **Chaque règle × au moins 3 cas** : déclenchement au seuil exact vs juste
  en dessous (ex. R1 : 8 semaines exactement ⇒ non ; 8,05 ⇒ oui ; stock 10 ⇒
  non ; 11 avec prévision faible ⇒ oui), produits non éligibles (coût
  suspect, sous-échantillon), gain toujours ordonné et positif.
- **Garde-fous au dép près** (`EPS = 1.0e-9`) : plancher de prix, fourchette
  ordonnée, confiance/effort bornés — testés candidat par candidat ET
  globalement sur des datasets variés (`test_garde_fou_global_sur_datasets_varies`).
- **Cohérence avec le VRAI ForecastEngine** (`EngineTest::test_coherence_forecastengine_unites_prevues_alimentent_r1`,
  seed 42 / 1000 tirages) : les unités prévues 7 j alimentent `RecoProduct`
  telles quelles et le gain médian R1 se recalcule à l'identique à 1e-9 —
  aucune seconde source d'unités.
- **Période creuse** : R9 neutralisé, seuils volumiques comparés en
  désaisonnalisé (`creneau_actif_meta_et_r9_neutralise`).
- **Coupe finale** : cap 5, dédoublonnage par produit, refus 30 jours
  (`test_max_cinq_dedoublonnage_et_refus_trente_jours`), format 13 clés +
  empreinte, historique insuffisant → R2/R8/R11 seulement, assemblage R10
  (gain somme, titres des sources).
- **Effet mesuré** : baseline 14 j à 100 €/j, réel 115 €/j ⇒ `concluant`,
  effet **+15 %** (210 €), intervalle `[70 ; 350]` à 1e-9 ; réel = attendu ⇒
  `non_concluant` ; 9 jours ⇒ insuffisant ; désaisonnalisation creuse
  (réel ramené à la hausse, effet nul si cohérent) ; chevauchement ; libellés FR.

Commande : `composer install` puis `php vendor/bin/phpunit` (tout) ou
`php vendor/bin/phpunit --testsuite Unit`. Les tests d'intégration exigent
MySQL avec la base `aeic_test` (cf. `phpunit.xml`) : sans MySQL ils sont
skipped, pas en échec.

Dernière vérification code ↔ doc : 21/10/2026.

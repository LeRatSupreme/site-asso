# Analyse T1 — Rapprochement de produits par embeddings (« AEIC Intelligence »)

> Cadrage Trim 1 · Spécification fonctionnelle pour le chef d'orchestre
> Auteur : dev-analyste · Date : 2026-10-27 · Statut : proposé (aucune décision engageante prise)
> Toutes les données chiffrées proviennent d'un inventaire **lecture seule** de la base de
> production (2026-10-27) : script `SELECT`-only, sortie archivée en annexe de travail.

---

## 0. Synthèse décisionnelle (1 page)

**Objectif T1** : un modèle de similarité sémantique entre libellés produits qui suggère
automatiquement les doublons de clés à fusionner, **à valider par un humain** — en complément
de la fusion manuelle actuelle (`ProductKeyMerge`) et de la détection déterministe existante
(`AliasSuggester` : normalisation casse/accents/séparateurs + near-miss à 1 édition).

**Constat central (chiffres réels)** : le problème est **vrai et récurrent** — 156 fusions
auditées en 7 semaines (54 + 49 + 29 + 24 par action d'audit), des doublons résiduels
identifiés au 27/10 malgré tout (« Surfizz Fruit Graine »/« Surfizz Fruits gaine »,
« Oasis Thémangue Passion » ×2, « Madel Coquille »/« Madeleine » **recréé après** la fusion
du 23/09). Mais le volume de vérité terrain est **petit** : ~270 paires positives brutes
(156 fusions + 113 alias actuels), dont ~60-80 réellement informatives sémantiquement (hors
variantes de casse que la normalisation capte déjà). **C'est trop peu pour un fine-tuning
lourd** ; la stratégie recommandée est donc en trois paliers mesurés :

1. **Baseline zéro-shot** : sentence-transformer multilingue compact (type
   `paraphrase-multilingual-MiniLM-L12-v2`, ~118 Mo quantifié int8 — conforme à la contrainte
   CPU-only du VPS) + calibration d'un seuil de similarité. Aucun entraînement.
2. **Fine-tuning léger conditionnel** : loss contrastive `MultipleNegativesRankingLoss`
   sur paires (libellé, clé canonique) **augmentées synthétiquement** à partir de la
   typologie d'erreurs réelles observées (casse, `_-.()`→espace, concaténation, faute 1-2
   éditions, suffixe goût ajouté/retiré, libellé facture long → clé courte). GO seulement si
   la baseline mesurée au jalon S4 est en dessous des cibles.
3. **Enrichissement continu** : chaque validation/refus humain dans l'écran de fusion
   devient une paire étiquetée → le jeu grossit de ~2-10 paires/semaine observées
   (moyenne réelle : 156 fusions / 7 semaines ≈ 22/semaine en période de nettoyage).

**Critère d'acceptation proposé** (jeu figé, split par produit, voir §5) :
rappel des fusions sémantiques connues ≥ 90 % en **recall@3** avec précision@seuil ≥ 85 %,
et **strictement meilleur que la baseline déterministe** sur les paires où elle échoue
(« pepper »→« Dr Pepper », « Cristaline »→« Eau », « monster »→« monster noire »).

**Décisions attendues du chef d'orchestre** : (D1) validation des cibles chiffrées ou
révision après mesure S4 ; (D2) qualification humaine des doublons résiduels listés §1.3
(sont-ils de vrais doublons ?) avant entrée au jeu d'évaluation ; (D3) protocole
d'anonymisation pour l'export Kaggle (libellés seuls recommandé).

---

## 1. Inventaire des données (base réelle, 2026-10-27)

### 1.1 Volumes

| Table | Lignes | Clés produits distinctes | Rôle pour T1 |
|---|---:|---:|---|
| `products` (fiches cafétéria) | 79 | — | Catalogue canonique (nom, prix, catégorie, stock) |
| `product_categories` | 12 | — | Caractéristique produit (Boissons, Snacks, Redbull, Monster, Spécial, Événement) |
| `product_aliases` | 113 | 76 | **Paires positives** : `raw_description` → `product_key` (96 `label` + 17 `appellation` facture) |
| `product_stocks` | 67 | 67 | Clés suivies en stock |
| `sales` (SumUp) | 4 070 | 77 | Libellés bruts de vente (103 descriptions distinctes), fenêtre **2026-06-01 → 2026-10-23** |
| `purchases` | 97 | 56 | Clés **saisies librement** (source n°1 des doublons), 2026-09-09 → 2026-10-14 |
| `losses` | 5 | 5 | Marginal |
| `inventory_counts` | 173 | 66 | Comptages physiques |
| `inventory_tablet_lines` | 231 | — | Relevés tablette |
| `product_costs` (lots) | 156 | 64 | Coût de revient par lot daté |
| `product_packs` | 39 | 39 | Taille de pack (caractéristique annexe) |
| `scan_labels` (OCR non rattachés) | 1 | — | File quasi vide aujourd'hui |
| `product_discontinued` | 18 | 18 | Produits en pause |
| `audit_logs` | 1 055 | — | **Vérité terrain des fusions** (156 événements) |

### 1.2 Qualité des libellés

- **Courts** : `sales.description` = 10,7 caractères en moyenne (min 3, max 46) ;
  `product_aliases.raw_description` = 14,4 (max 46) ; `purchases.product_key` = 11,6 (max 44).
  → Risk majeur pour les embeddings : peu de contexte, forte similarité intra-famille.
- **Typologie d'erreurs réelles** (extraite des 156 fusions auditées et des alias) :
  - casse/espaces : « schweppes »→« Schweppes », « Coca cherry »→« Coca Cherry » (≈ la moitié des événements, captés par la normalisation) ;
  - séparateurs : « Bueno_white »→« Bueno white », « Coca_cherry », « MinuteMaid_Orange », « Lion_Peanut » ;
  - **concaténation/suppression d'espaces** : « cocacola »→« Coca Cola », « fuzetea »→« Fuze Tea », « MMS »→« M&Ms » ;
  - **abrègement** : « pepper »→« Dr Pepper », « monster »→« monster noire », « Fanta »→« Fanta Orange », « Oasis »→« Oasis Tropical », « Lipton »→« Lipton Ice Tea » ;
  - **variante commerciale** : « Cristaline »→« Eau », « Coca sans sucre »→« Coca Zero », « Pepsi sans sucre »→« Pepsi Zero », « red bull myrtille »→« Red Bull ice » (édition limitée renommée) ;
  - **libellé facture → clé courte** (les 17 `appellation`) : « MONSTER ULTRA ZERO BTE 50CL »→« Monster Blanche », « PULCO CITRONNADE SLIM 33CL »→« Pulco », « COCACOLA CHERRY SLIM 33CL »→« Coca Cherry » ;
  - fautes de frappe : « Monster Vleu »→« Monster Bleu », « Fauffres Liegeoise », « Bourdon Gauffre LI ».
- **Fournisseurs** : quasi mono-source (achats : metro 87/97, Auchan 4, carrefour 1) —
  caractéristique peu discriminante aujourd'hui ; les libellés « METRO » de factures
  serviront surtout en T2.
- **17 clés de ventes sans ligne de stock** (orphelines résiduelles du mapping).

### 1.3 Doublons résiduels détectés au 27/10 (preuve que le besoin est vivant)

Non fusionnés à ce jour, candidats au jeu d'évaluation après qualification humaine (D2) :
« Oasis ice tea the mangue passion » vs « Oasis Thémangue Passion » · « Surfizz Fruit Graine »
vs « Surfizz Fruits gaine » · « Fauffres Liegeoise »/« Bourdon Gauffre LI » vs « gaufre » ·
« Madel Coquille » vs « Madeleine » (recréé **après** la fusion du 2026-09-23 — la fusion
corrige, elle ne prévient pas la réapparition) · « Kinder Bueno » vs « Bueno » · « Caprisun »
vs « Caprisun tropical » · « cafe » vs « cafe allonge » (153 ventes réparties sur 2 clés) ·
clé parasite « . » avec stock 120.

---

## 2. Énoncé du problème ML

- **Entrée** : un libellé produit libre (libellé SumUp, clé d'achat saisie à la main, ou
  ligne de facture OCR du futur T2).
- **Sortie** : un score de similarité cosinus ∈ [−1, 1] contre chaque clé canonique du
  catalogue (~80-120 identités : union fiches + clés vivantes), exploitée sous deux formes :
  (a) classement **top-k** des candidats de fusion ; (b) décision binaire « doublon probable »
  au-delà d'un seuil calibré.
- **Tâche formelle** : *entity matching / record linkage* sur chaînes courtes françaises.
- **Pourquoi ça bat l'existant** : la détermination actuelle (`AliasSuggester` :
  normalisation + retrait de suffixes couleur/goût + near-miss 1 édition) est **exacte mais
  aveugle au sens**. Échecs réels documentés : « pepper »→« Dr Pepper » (mot absent de la
  cible), « Cristaline »→« Eau » (marque ≠ générique), « monster »→« monster noire »
  (sous-spécification), « cocacola » (concaténation ≠ 1 édition), « MONSTER ULTRA ZERO BTE
  50CL » (bruit facture). L'embedding capture la proximité sémantique marque/variante/taille
  que la distance d'édition ne voit pas ; l'humain garde la décision finale (aucune fusion
  automatique).

---

## 3. Construction du jeu de données

### 3.1 Paires positives (vérité terrain)

1. **156 fusions auditées** (`audit_logs` : `inventory.merge` 54, `compta.aliases.merge` 49,
   `compta.product.merge` 29, `compta.repair.merge_cle` 24) — champs `details` JSON
   (`source`/`target` ou `from`/`to` ou `merged[]`).
2. **113 alias actuels** (`product_aliases.raw_description` → `product_key`), y compris les
   17 appellations facture.
3. Après dédoublonnage (~90-100 paires distinctes) et **retrait des variantes de casse
   pure** (déjà résolues par la normalisation, elles gonfleraient artificiellement les
   scores) : **≈ 60-80 paires sémantiquement informatives**.

### 3.2 Paires négatives

- Univers fermé (~80-120 identités) → C(100, 2) ≈ **5 000 paires possibles**, dont < 5 %
  positives : les négatifs sont abondants.
- **Dur négatif natif** — le point fort du domaine : les familles partagent leur
  vocabulaire avec des produits DISTINCTS : 12 Redbull (Blanche/Bleu/Ice/Peach/Pomme/Summer/
  Winter/Zero/Seablue/Abricot/Red/Pomme), 5 Monster, 6 Oasis, 5 Fanta, 4 Minute Maid, 3
  Bueno/sucette, 2 Chips. « Redbull Blanche » vs « Redbull Bleu » est LE contre-exemple à ne
  jamais fusionner alors que la distance d'édition est minime.
- Minage : pour chaque clé, les k plus proches voisins selon le modèle zéro-shot **non
  appariés** dans la vérité terrain = dur négatif (stratégie standard de *hard negative
  mining*, itérée à chaque ré-entraînement).

### 3.3 Volume disponible vs nécessaire — verdict honnête

- Disponible : ~270 paires positives brutes, ~60-80 exploitables.
- Un fine-tuning contrastif « classique » vise 10⁴-10⁵ paires. **Écart d'un facteur 100+.**
- Conséquence dans l'approche (§4) : l'augmentation synthétique à partir de la typologie
  réelle (§1.2) est **au cœur** du plan, le fine-tune reste léger, et l'évaluation zéro-shot
  décide d'abord s'il est même nécessaire.

### 3.4 Split — le point méthodologique critique

**Split par produit (groupes)**, pas aléatoire ni purement temporel : toutes les variantes
d'un même produit (ses alias, ses fusions passées) doivent être dans le **même** côté
(train ou test), sinon la fuite de données fausse tout (le modèle reconnaît « sa » famille
et non la tâche). Le fenêtrage temporel seul (ventes juin-oct.) est insuffisant à ce volume.
Jeu de test figé = ~30 % des produits, soit ~25-30 paires positives sémantiques + tous les
doublons résiduels qualifiés (§1.3). Le jeu est **figé et versionné** avant tout
entraînement (fichier signé par hachage).

---

## 4. Approche recommandée

| Palier | Contenu | Déclencheur |
|---|---|---|
| **P0 — Baseline déterministe** | `AliasSuggester` + near-miss existant, mesuré sur le jeu figé | Toujours (référence à battre) |
| **P1 — Zéro-shot + seuil** | `paraphrase-multilingual-MiniLM-L12-v2` (multilingue, 384 dims, ~118 Mo en int8 ONNX — conforme « quelques dizaines de Mo » sur CPU 4 cœurs). Embedding des ~100 clés canoniques (index de ~100 vecteurs : similarité = microsecondes), seuil calibré sur le train (quantile des positifs) | Toujours |
| **P2 — Fine-tune léger (conditionnel)** | `MultipleNegativesRankingLoss` (MNRL) sur paires (anchor = libellé augmenté, positive = clé canonique), in-batch negatives + 2-4 dur négatifs minés par produit ; données augmentées ×100 via la typologie §1.2 (deterministic seeders, reproductibles) ; entraînement **Kaggle GPU gratuit**, jamais le VPS | Uniquement si P1 < cibles au jalon S4 |
| **P3 — Boucle humaine** | Chaque clic « Fusionner » / « Ignorer » de l'écran §6 journalise une paire étiquetée → ré-entraînement trimestriel de quelques minutes | Après démo S11 |

- **Pourquoi MNRL** : conçue pour le classement avec peu de labels, n'exige pas de scores
  gradués, bénéficie gratuitement des in-batch negatives (les 99 autres clés du batch sont
  des négatifs — parfait pour un catalogue fermé).
- **Seuil vs top-k** : les deux. Top-3 pour l'affichage (l'humain scane vite), seuil calibré
  pour ne *suggérer* que les paires ≥ seuil et pour éteindre la suggestion quand le score
  est bas (évite le bruit). Le seuil est recalibré à chaque ré-entraînement.
- **Anti-régression (règle projet)** : à l'intégration future, les scores passeront par un
  **point d'assemblage unique** (même principe que `StockBook`/`PackResolver`) ; les
  suggestions sémantiques ne remplaceront jamais les résolveurs existants — elles les
  alimentent en candidats.

---

## 5. Critères d'acceptation et protocole d'évaluation

**Jeu figé** (§3.4) : paires positives sémantiques (hors casse pure) + dur négatifs minés
+ doublons résiduels qualifiés D2. Protocole documenté, exécutable en une commande,
reproductible (graines fixées, versions figées du modèle et du jeu).

| # | Critère | Cible | Mesure |
|---|---|---|---|
| A1 | Rappel des fusions sémantiques connues | **≥ 90 % en recall@3** | La clé canonique correcte dans le top-3 du classement cosinus |
| A2 | Précision à seuil | **≥ 85 %** au rappel ≥ 90 % | Paires ≥ seuil qui sont de vrais doublons (courbe P/R sur le train figé, seuil reporté sur le test) |
| A3 | Supériorité sur la baseline | **> P0 sur les paires où P0 échoue** | Macro-comparaison P0/P1/P2 sur le même jeu ; aucune régression sur les paires que P0 réussit |
| A4 | Dur négatif | **0 fusion suggérée** entre variantes distinctes d'une même famille à seuil (Redbull ×12, Monster ×5, Oasis ×6…) | Taux de faux positifs sur les paires intra-famille |
| A5 | Inférence VPS | Score de 1 libellé vs 100 clés **< 50 ms** CPU 4 cœurs (modèle int8) | Benchmark sur le VPS, hors charge |
| A6 | Repro | Ré-entraînement + éval rejouables depuis un notebook Kaggle unique (données anonymisées versionnées) | Ré-exécution à l'identique |

Honnêteté de cadrage : les cibles A1/A2 sont des **hypothèses raisonnables fixées avant
mesure** ; le jalon S4 (mesure des baselines P0/P1) peut les réviser — décision D1. Elles ne
sont PAS validées aujourd'hui.

---

## 6. Intégration future (esquisse UX — hors périmètre de code T1)

L'écran existe déjà : **Inventaire → carte « Fusionner des clés produits »**
(`views/admin/compta/inventory.php`, ancre `#fusion-doublons`), qui propose aujourd'hui les
« doublons probables » par clé normalisée + « Tout fusionner ». Proposition :

- Nouvelle carte **« Suggestions intelligentes »** juste au-dessus : liste triée par score
  décroissant, chaque ligne = « `libellé A` ↔ `libellé B` — 93 % » avec aperçu du volume
  déplacé (ventes/achats/stock, comme l'aperçu existant) et deux boutons :
  **Fusionner** (1 clic → POST existant `/admin/compta/inventaire/merge`, action auditée
  comme aujourd'hui) / **Ignorer** (paire mise en liste noire persistante, journalisée comme
  négatif → alimente P3).
- **L'humain reste décisionnaire** : aucune fusion automatique, aucun bouton « tout
  fusionner » sur les suggestions sémantiques (contrairement aux doublons de casse,
  déterministes et sûrs à 100 %).
- Calcul des scores : périodique (cron nocturne ou à l'ouverture de la page, ~100×100 ≈
  5 000 similarités = trivial), mis en cache ; affichage masqué sous le seuil calibré.
- Conformité glossaire : parler de « suggestions » / « rapprochement », jamais de « score
  IA » dans les libellés ; pourcentage affiché comme « ressemblance ».

---

## 7. Risques et parades

| Risque | Réalité mesurée | Parade |
|---|---|---|
| Données insuffisantes | ~60-80 paires utiles vs 10⁴+ usuelles | Paliers P1→P2 conditionnels ; augmentation synthétique typée ; boucle humaine P3 |
| Libellés trop courts | 10,7 caractères moyen (min 3) | Injection de contexte canonique au fine-tune (catégorie + marque : « Redbull — Boisson énergisante ») ; A4 protège |
| Dur négatif intra-famille | 12 Redbull, 5 Monster quasi identiques lexicalement | A4 (0 FP intra-famille à seuil) ; hard negatives explicites |
| Fuite train/test | Variantes d'un même produit partout | Split **par produit** (§3.4), jeu figé versionné |
| Dérive temporelle | Éditions limitées saisonnières (Redbull Summer/Winter vues en oct.) | Seuil recalibré à chaque ré-entraînement trimestriel ; suggestions = file d'attente humaine, pas une vérité figée |
| Multilingue | Libellés FR + marques globales (Red Bull, Monster, Minute Maid) | Modèle multilingue dès P1 ; libellés 100 % gérés aujourd'hui |
| Anonymisation Kaggle | Un libellé contient un prénom probable (« Décoration halloween \| les creation de ju… »), fournisseurs identifiables (METRO) | D3 : export **libellés seuls** (ni prix, ni dates, ni refs), fournisseurs codés, prénoms masqués ; registre RGPD mis à jour |
| Surcoût ops VPS | — | Aucun service ML sur le VPS en T1 ; si intégration : fichier de scores pré-calculés + lecture PHP |

---

## 8. Découpage hebdomadaire T1 (12 semaines)

| Sem. | Jalon | Livrable vérifiable |
|---|---|---|
| 1 | Setup Kaggle (GPU T4 gratuit) + fast.ai leçons 1-2 ; export **anonymisé** du dataset (script lecture seule, D3) | Notebook Kaggle exécuté ; fichier dataset v1 signé |
| 2 | fast.ai 3-4 ; extraction paires positives (audit + alias), filtrage casse, dédoublonnage | `paires_positives.csv` (n attendu : 60-80) |
| 3 | fast.ai fin ; dur négatifs minés + qualification D2 des résiduels §1.3 | `negatifs.csv` + PV de qualification humaine |
| 4 | **Jeu figé + split par produit + mesure P0 (déterministe) et P1 (zéro-shot)** → décision D1 GO/NO-GO fine-tune | Rapport baselines vs cibles A1-A4 |
| 5 | Pipeline d'entraînement Kaggle (MNRL) + generateur d'augmentation (typologie §1.2) | Notebook ré-entraînable (A6) |
| 6-7 | Itérations fine-tune : hard negatives, LR, époques, contexte canonique | Courbes recall@k par itération |
| 8 | Sélection checkpoint + calibration du seuil + quantification int8 ONNX | Modèle compact + benchmark A5 sur VPS |
| 9 | Évaluation finale sur jeu figé (A1-A4) | Rapport d'éval signé |
| 10 | Backtest terrain : les suggestions retrouvent-elles les résiduels §1.3 ? Outil CLI local d'inférence (hors prod) | Liste candidats vs vérité |
| 11 | Spéc d'intégration UX (§6) + maquette statique de la carte « Suggestions intelligentes » | Doc spec prête pour un pôle dev |
| 12 | Démo end-to-end (notebook → candidats → validation humaine simulée) ; bilan T1 et décision T2 | Compte rendu de trimestre |

---

## Annexe — commandes de vérification (lecture seule)

Les chiffres de §1 sont reproductibles par : comptages par table, `audit_logs` filtré
`action LIKE '%merge%'` (156 lignes), longueurs `CHAR_LENGTH`, cardinalités distinctes —
script complet conservé : `/tmp/t1_inventaire_lecture_seule.sql`, sortie
`/tmp/t1_inventaire_sortie.txt` (738 lignes, 2026-10-27).

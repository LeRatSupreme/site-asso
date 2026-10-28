# Rapport S4 — Baseline P1 zéro-shot (embeddings) vs cibles A1–A6

> Mission T1-P0 · Lot B (dev-factures) · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§4 P1, §5 cibles).
> Jeu figé `data/eval_t1/` (verdict amont A2 : CONFORME, SHA256SUMS vérifiés). Aucune requête SQL.
> Généré par : `~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer` (A6, une seule commande).

## Environnement et modèle

| Élément | Valeur |
|---|---|
| Modèle | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` |
| Binaire chargé | export ONNX **int8** `Xenova/paraphrase-multilingual-MiniLM-L12-v2/onnx/model_int8.onnx` (mêmes poids), via fastembed `specific_model_path` |
| Taille réelle int8 | 118.1 Mo (`model_optimized.onnx`) ; cache total 129M (`du -sh data/eval_t1/.cache`) |
| Bibliothèque | fastembed 0.9.1 (onnxruntime 1.31.1, numpy 2.5.4, sans torch) |
| Python | 3.12.3 |
| CPU | Intel Core Processor (Haswell, no TSX) — 4 cœurs, session onnxruntime `threads=4` |
| Pooling/sortie | mean pooling masqué (fastembed) puis L2-normalisation ; 384 dimensions |
| Graine | 42 (pipeline déterministe ; embeddings reproductibles) |

Écart documenté : fastembed télécharge par défaut le fp16 (235 Mo) du dépôt Qdrant ; la contrainte
« int8, ≤ ~130 Mo, CPU » impose l'export int8 (~118 Mo) ci-dessus — obtenu des MÊMES poids, aucune
conversion maison (aucun entraînement, zéro-shot strict).

## Verdicts A1–A6 (cibles fixées avant mesure, spec §5)

| # | Critère | Cible | Mesuré | Verdict |
|---|---|---|---|---|
| A1 | Rappel fusions sémantiques (recall@3, TEST) | ≥ 90 % | 75,0 % (15/20) | MANQUÉ |
| A2 | Précision à seuil au rappel ≥ 90 % (TEST au seuil calibré sur TRAIN) | ≥ 85 % | précision 33,3 % au rappel 55,0 % (11 TP / 22 FP), seuil 0.6618 | MANQUÉ |
| A3 | Supériorité sur P0 (ses échecs → P1 strictement mieux ; zéro régression) | gain > 0, régression = 0 | 38 échecs P0 rattrapés par P1, 1 régression(s) ; P0 8/50 vs P1 45/50 | MANQUÉ |
| A4 | Durs négatifs intra-famille ≥ seuil | 0 | 57 / 199 (28,6 %) | MANQUÉ |
| A5 | Inférence 1 libellé vs ~120 clés (CPU) | < 50 ms | moyenne 4 ms, p50 4 ms, p95 5 ms (200 runs) | ATTEINT |
| A6 | Repro : une commande régénère tout, sorties stables | rejouable | `--evaluer` régénère rapport + candidats ; tri déterministe ; graine 42 | ATTEINT |

Un critère MANQUÉ déclenche le palier P2 (fine-tune léger) — ce n'est pas un échec du lot (§5, décision D1).

## A1 — recall@3 par split (détail)

| Split | Paires | recall@1 | recall@3 |
|---|---:|---|---|
| train | 30 | 90,0 % (27/30) | 100,0 % (30/30) |
| test | 20 | 50,0 % (10/20) | 75,0 % (15/20) |

Manquants TEST au recall@3 (libellé brut → cible, meilleur rang obtenu, score cible) :
- « MONSTER ULTRA ZERO » → « Monster Blanche » : rang > 3 (meilleur candidat « Monster Noire » à 0.794 ; cible à 0.495)
- « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » : rang > 3 (meilleur candidat « Monster Noire » à 0.676 ; cible à 0.399)
- « RED BULL BLUE BOITE 25CL » → « Redbull Bleu » : rang > 3 (meilleur candidat « Redbull Blue » à 0.785 ; cible à 0.565)
- « Redbull blue » → « Redbull Bleu » : rang > 3 (meilleur candidat « Redbull Seablue » à 0.746 ; cible à 0.609)
- « Soiree bowling (2 parties / 1 personne) » → « bowling » : rang > 3 (meilleur candidat « Soiree bowling ( 2 personnes ) » à 0.943 ; cible à 0.734)

Règle d'exclusion : l'identité du libellé lui-même (même forme normalisée). Exclure le groupe de split
entier écarterait la cible par construction (le groupe EST la composante union-find des paires positives).

## A2 — calibration du seuil (TRAIN uniquement, une seule règle)

- Seuil calibré : **0.6618** — précision TRAIN 47,4 % au rappel TRAIN 90,0 % (≥ 90 %).
- Reporté tel quel sur TEST : précision 33,3 %, rappel 55,0 % (11 TP, 22 FP).
- Espace de notation : chaque libellé brut des positifs × toutes les identités de l'univers (moins soi-même) ;
  TP = paire positive sémantique du split ; FP = paire ≥ seuil hors vérité terrain, hors résiduels §1.3
  (non qualifiables, affichés dans l'autre section de l'écran).
- Courbe P/R TRAIN (seuil, rappel, précision, TP, FP) :

| seuil | rappel | précision | TP | FP |
|---|---|---|---:|---:|
| 0.5416 | 1.000 | 0.353 | 30 | 55 |
| 0.5488 | 0.967 | 0.349 | 29 | 54 |
| 0.6314 | 0.933 | 0.452 | 28 | 34 |
| 0.6618 | 0.900 | 0.474 | 27 | 30 |
| 0.6758 | 0.867 | 0.500 | 26 | 26 |
| 0.7158 | 0.833 | 0.568 | 25 | 19 |
| 0.7256 | 0.800 | 0.558 | 24 | 19 |
| 0.7389 | 0.767 | 0.561 | 23 | 18 |
| 0.7771 | 0.733 | 0.710 | 22 | 9 |
| 0.7892 | 0.700 | 0.700 | 21 | 9 |
| 0.7969 | 0.633 | 0.704 | 19 | 8 |
| 0.8074 | 0.600 | 0.750 | 18 | 6 |
| 0.8172 | 0.600 | 0.818 | 18 | 4 |
| 0.8187 | 0.567 | 0.850 | 17 | 3 |
| 0.8232 | 0.500 | 0.882 | 15 | 2 |
| 0.8322 | 0.500 | 0.882 | 15 | 2 |
| 0.8338 | 0.467 | 0.875 | 14 | 2 |
| 0.8395 | 0.433 | 0.867 | 13 | 2 |
| 0.8433 | 0.400 | 0.857 | 12 | 2 |
| 0.8501 | 0.367 | 0.846 | 11 | 2 |
| 0.8516 | 0.300 | 0.900 | 9 | 1 |
| 0.8626 | 0.300 | 0.900 | 9 | 1 |
| 0.8731 | 0.267 | 0.889 | 8 | 1 |
| 0.8755 | 0.200 | 0.857 | 6 | 1 |
| 0.8841 | 0.200 | 0.857 | 6 | 1 |
| 0.8889 | 0.167 | 0.833 | 5 | 1 |
| 0.8936 | 0.133 | 0.800 | 4 | 1 |
| 0.9066 | 0.100 | 0.750 | 3 | 1 |
| 0.9260 | 0.067 | 0.667 | 2 | 1 |
| 0.9737 | 0.000 | 0.000 | 0 | 0 |

## A3 — P0 (port fidèle AliasSuggester) vs P1 sur le même jeu figé (50 paires)

- P0 réussit 8/50 ; P1 (recall@3) réussit 45/50.
- Échecs de P0 rattrapés par P1 : **38** (strictement > 0 → cible).
- Régressions (P0 ok, P1 échoue) : **1** (cible : 0).

P0 = égalité de forme normalisée + near-miss 1 édition (`AliasSuggester::groupDuplicates`, ensemble non
plafonné — lecture la plus favorable à P0). Le retrait de suffixes (`suggest`) est un suggéreur de clé,
pas un détecteur de doublons ; l'inclure contredirait les échecs documentés §2 que A3 doit couvrir.
Port Python validé différentiellement contre le PHP réel (réflexion sur `AliasSuggester::isNearMiss`) :
22 049 paires de formes normalisées du jeu, 0 divergence (normalisation via le port fidèle, pas de
strtolower ad hoc — règle projet AGENTS.md).

Échecs P0 documentés (spec §2), couverture explicite :

| Cas documenté | Paire en jeu | P0 | P1 (rang, score cible) |
|---|---|---|---|
| « pepper »→« Dr Pepper » | « Pepper » → « Dr Pepper » | échoue | réussit (rang 1, 0.785) |
| « Cristaline »→« Eau » | « Cristaline » → « Eau » | échoue | réussit (rang 2, 0.549) |
| « monster »→« monster noire » | « monster » → « monster noire » | échoue | réussit (rang 1, 0.852) |
| « cocacola »→« Coca Cola » (concaténation — analogue en jeu : abrègement « Coca ») | « Coca » → « Coca Cola » | échoue | réussit (rang 1, 0.923) |
| « MONSTER ULTRA ZERO BTE 50CL »→« Monster Blanche » | « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » | échoue | échoue (cible 0.399) |

Détail des échecs P0 rattrapés par P1 :
- « Bonbon Haribo » → « haribo » (rang 2, 0.676)
- « Bowling ( 2 parties ) » → « bowling » (rang 2, 0.836)
- « CACOLAC BOITE 25CL X6 » → « Cacolac » (rang 1, 0.777)
- « COCA COLA BOITE FAT 33CL » → « Coca Cola » (rang 1, 0.489)
- « COCACOLA CHERRY SLIM 33CL » → « Coca Cherry » (rang 1, 0.726)
- « CRISTALI 5OCL PET » → « Eau » (rang 1, 0.542)
- « Coca » → « Coca Cola » (rang 1, 0.923)
- « Coca sans sucre » → « Coca Zero » (rang 1, 0.795)
- « Cristaline » → « Eau » (rang 2, 0.549)
- « Fanta » → « Fanta Orange » (rang 1, 0.894)
- « Lion_Peanut » → « Lion » (rang 1, 0.789)
- « Lipton » → « Lipton Ice Tea » (rang 1, 0.649)
- « MMS » → « M&Ms » (rang 1, 0.662)
- « Minute Maid Multi Vitamines » → « Minute Maid MultiVitamine » (rang 1, 0.819)
- « MinuteMaid_Pomme » → « Minute Maid Nect Pomme » (rang 1, 0.676)
- « Monster rose (punch) » → « Monster Rose » (rang 1, 0.907)
- « Monster verte (energy) » → « Monster Verte » (rang 1, 0.781)
- « OASIS POM CASS FRAM SLIM 33CL » → « Oasis Pomme Cassis Framboise » (rang 2, 0.616)
- « OASIS TROPICAL SLIM BTE 33CL » → « Oasis Tropical » (rang 1, 0.807)
- « Oasis » → « Oasis Tropical » (rang 1, 0.863)
- « PERRIER BOITE SLIM 33CL » → « Perrier » (rang 1, 0.716)
- « PULCO CITRONNADE SLIM 33CL » → « Pulco » (rang 1, 0.558)
- « Pepper » → « Dr Pepper » (rang 1, 0.785)
- « Pepsi sans sucre » → « Pepsi Zero » (rang 1, 0.840)
- « Pulco Citronnade » → « Pulco » (rang 1, 0.816)
- « RED BULL ICE BOITE 25CL » → « Redbull Ice » (rang 1, 0.834)
- « RED BULL PEACH BTE 25CL » → « Redbull Peach » (rang 1, 0.817)
- « RED BULL RED EDITION 25CL » → « redbull red » (rang 1, 0.739)
- « RED BULL SEA BLUE BOITE 25CL » → « Redbull Seablue » (rang 1, 0.850)
- « Redbull Myrtille » → « Redbull Ice » (rang 1, 0.631)
- « Redbull_Blanche » → « Redbull » (rang 2, 0.783)
- « Soiree bowling ( 2 personnes ) » → « bowling 2personnes » (rang 2, 0.788)
- « Sucette 1 sucette » → « sucette » (rang 2, 0.876)
- « TROPICO L'ORIG BTE CL SLIM » → « Tropico » (rang 1, 0.797)
- « chips » → « Chips Nature » (rang 1, 0.889)
- « minutemaid » → « Minute Maid Orange » (rang 1, 0.832)
- « monster » → « monster noire » (rang 1, 0.852)
- « schweppes » → « schweppes agrumes » (rang 1, 0.843)
Détail des régressions P1 (à traiter en P2) :
- « Redbull blue » → « Redbull Bleu » (meilleur candidat « Redbull Seablue », cible 0.609)

## A4 — durs négatifs intra-famille (cible : 0 paire ≥ seuil)

- 57 / 199 paires ≥ seuil 0.6618 (28,6 %).
- Paires fautives (score décroissant) :
  - « Soiree bowling (2 parties / 1 personne) » / « Soiree bowling ( 2 personnes ) » — 0.9377
  - « Sucette 1 » / « Sucette 1 sucette » — 0.9321
  - « Minute Maid Orange » / « Minute Maid Pomme » — 0.8931
  - « Minute Maid » / « Minute Maid Pomme » — 0.8854
  - « Fanta » / « Fanta Exotique » — 0.8617
  - « Redbull » / « redbull red » — 0.8586
  - « Oasis Ice Tea » / « Oasis ice tea the mangue passion » — 0.8511
  - « sucette » / « sucette 3 sucettes » — 0.8497
  - « Sucette 1 » / « Sucette 3 » — 0.8471
  - « Bowling ( 2 parties ) » / « bowling 2personnes » — 0.8370
  - « Minute Maid » / « Minute Maid Nect Pomme » — 0.8294
  - « Minute Maid Nect Pomme » / « Minute Maid Orange » — 0.8201
  - « Pepsi » / « Pepsi sans sucre » — 0.8168
  - « Fanta Exotique » / « Fanta Orange » — 0.8161
  - « Sucette 3 » / « sucette 3 sucettes » — 0.8107
  - « Sucette 1 sucette » / « Sucette 3 » — 0.8060
  - « Oasis » / « Oasis pomme poire » — 0.8051
  - « Fanta » / « Fanta Citron » — 0.8007
  - « Bonbon » / « Bonbon Haribo » — 0.7953
  - « Chips BBQ » / « Chips Nature » — 0.7863
  - « bowling » / « bowling 2personnes » — 0.7841
  - « Coca Cherry » / « Coca Cola » — 0.7744
  - « sucette » / « Sucette 1 » — 0.7715
  - « Pepsi » / « Pepsi Zero » — 0.7679
  - « Coca Cola » / « Coca sans sucre » — 0.7560
  - « Redbull Blue » / « Redbull Seablue » — 0.7530
  - « Oasis » / « Oasis Thémangue Passion » — 0.7485
  - « sucette » / « Sucette 3 » — 0.7426
  - « Redbull » / « Redbull Pomme » — 0.7401
  - « Redbull » / « Redbull Winter » — 0.7400
  - « Fanta Citron » / « Fanta Orange » — 0.7385
  - « Redbull Ice » / « Redbull Winter » — 0.7382
  - « Redbull » / « Redbull_Bleue » — 0.7378
  - « Oasis pomme poire » / « Oasis Tropical » — 0.7358
  - « Redbull » / « Redbull Summer » — 0.7283
  - « Fanta » / « Fanta Cassis » — 0.7236
  - « Coca Cola » / « Coca Zero » — 0.7144
  - « Redbull » / « Redbull Ice » — 0.7140
  - « Fanta Cassis » / « Fanta Citron » — 0.7135
  - « gaufre » / « Gaufre choco » — 0.7128
  - « Redbull » / « Redbull Bleu » — 0.7118
  - « Gauffre » / « Gauffre choco » — 0.7108
  - « Oasis Thémangue Passion » / « Oasis Tropical » — 0.7075
  - « Oasis pomme poire » / « Oasis Thémangue Passion » — 0.7059
  - « Monster Noire » / « Monster Verte » — 0.7047
  - « Fanta Citron » / « Fanta Exotique » — 0.7047
  - « Minute Maid » / « Minute Maid MultiVitamine » — 0.7022
  - « Sucette 1 » / « sucette 3 sucettes » — 0.6986
  - « Fanta Cassis » / « Fanta Orange » — 0.6966
  - « Monster Bleue » / « Monster Verte » — 0.6964
  - « Fanta » / « Fanta Mangue Dragon » — 0.6940
  - « Monster Rose » / « Monster Verte » — 0.6924
  - « redbull red » / « Redbull Winter » — 0.6899
  - « Minute Maid » / « Minute Maid Multi Vitamines » — 0.6797
  - « Redbull Summer » / « Redbull Winter » — 0.6743
  - « Redbull » / « Redbull zero » — 0.6721
  - « Monster Blanche » / « Monster Verte » — 0.6686

## A5 — benchmark inférence CPU

- 200 runs (après 10 échauffements) : embed(« Coca Cola ») + cosinus contre les 120 vecteurs de l'univers.
- moyenne 3.9 ms · p50 3.7 ms · p95 4.8 ms · min 2.9 ms · max 8.0 ms.
- CPU : Intel Core Processor (Haswell, no TSX) (4 cœurs). Rejouer : `scripts/t1_similarity.py --bench`.
- NB : timing CPU par nature variable (±1 ms) — c'est la seule mesure non strictement idempotente du rapport.

## A6 — reproductibilité

```bash
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer   # A1–A6 + rapport + candidats
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --bench      # A5 isolé
python3 -m py_compile scripts/t1_similarity.py                    # syntaxe
jq . data/eval_t1/candidats_fusion.json                           # contrat Lot C valide
```

- Sorties triées de façon déterministe (score décroissant, puis libellés) ; plafond 50 candidats.
- Candidats ≥ seuil avant plafond : 30 ; retenus : 30.
- Dont étiquetés durs négatifs intra-famille (cf. A4) : 25/30
  — le seuil P1 zéro-shot étant bas, la plupart des candidats sont des variantes de même famille ;
  le fichier respecte le contrat Lot C (exclusions : positives + résiduels uniquement), l'humain décide.
- Deux exécutions de `--evaluer` produisent des rapports aux chiffres identiques (vérifié par diff :
  seule la ligne A5 « moyenne/p50/p95 » peut bouger de ±1 ms).

## Limites documentées (spec §7)

- **Libellés courts** : moyenne ~10,7 caractères (min 3) — peu de contexte sémantique ; les scores
  absolus de ce modèle sont bas (famille « proches » ≈ 0,3–0,7), d'où un seuil calibré bas et l'importance
  du garde-fou A4 (durs négatifs intra-famille).
- Vérité terrain petite (50 paires sémantiques informatives) : la calibration du seuil reste fragile ;
  les suggestions sont une file d'attente humaine, jamais une fusion automatique (§6).
- Le seuil calibré sur la tâche brut→cible est appliqué tel quel aux paires identité×identité du fichier
  de candidats (même espace de scores, même modèle) — hypothèse documentée.
- Zéro-shot strict : aucun entraînement, aucune donnée envoyée hors VPS (Kaggle interdit ici).


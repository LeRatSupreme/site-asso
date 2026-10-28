# Rapport S4 — Baseline P1 zéro-shot (embeddings) vs cibles A1–A6

> Mission T1-P0 · Lot B (dev-factures) · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§4 P1, §5 cibles).
> Jeu figé `data/eval_t1/` (verdict amont A2 : CONFORME, SHA256SUMS vérifiés). Aucune requête SQL.
> Généré par : `~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer` (A6, une seule commande).

## Environnement et modèle

| Élément | Valeur |
|---|---|
| Modèle | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` |
| Binaire chargé | export ONNX **int8** local `/home/ubuntu/AEIC/data/eval_t1/p2/v2/convert/model-int8` (fine-tuné P2, mêmes poids d'architecture), via fastembed `specific_model_path` |
| Taille réelle int8 | 118.1 Mo (`model_optimized.onnx`) |
| Bibliothèque | fastembed 0.9.1 (onnxruntime 1.31.1, numpy 2.5.4, sans torch) |
| Python | 3.12.3 |
| CPU | Intel Core Processor (Haswell, no TSX) — 4 cœurs, session onnxruntime `threads=4` |
| Pooling/sortie | mean pooling masqué (fastembed) puis L2-normalisation ; 384 dimensions |
| Graine | 42 (pipeline déterministe ; embeddings reproductibles) |

Poids fine-tunés (P2, kernel Kaggle offline — MNRL sur le split TRAIN du jeu figé + variantes
synthétiques, anti-fuite vérifié amont) exportés localement en ONNX fp32 puis quantifiés int8
(quantization dynamique onnxruntime, même méthode que l'export Xenova de référence).

## Verdicts A1–A6 (cibles fixées avant mesure, spec §5)

| # | Critère | Cible | Mesuré | Verdict |
|---|---|---|---|---|
| A1 | Rappel fusions sémantiques (recall@3, TEST) | ≥ 90 % | 75,0 % (15/20) | MANQUÉ |
| A2 | Précision à seuil au rappel ≥ 90 % (TEST au seuil calibré sur TRAIN) | ≥ 85 % | précision 47,4 % au rappel 45,0 % (9 TP / 10 FP), seuil 0.7741 | MANQUÉ |
| A3 | Supériorité sur P0 (ses échecs → P1 strictement mieux ; zéro régression) | gain > 0, régression = 0 | 38 échecs P0 rattrapés par P1, 1 régression(s) ; P0 8/50 vs P1 45/50 | MANQUÉ |
| A4 | Durs négatifs intra-famille ≥ seuil | 0 | 27 / 199 (13,6 %) | MANQUÉ |
| A5 | Inférence 1 libellé vs ~120 clés (CPU) | < 50 ms | moyenne 3 ms, p50 3 ms, p95 6 ms (200 runs) | ATTEINT |
| A6 | Repro : une commande régénère tout, sorties stables | rejouable | `--evaluer` régénère rapport + candidats ; tri déterministe ; graine 42 | ATTEINT |

Un critère MANQUÉ déclenche le palier P2 (fine-tune léger) — ce n'est pas un échec du lot (§5, décision D1).

## A1 — recall@3 par split (détail)

| Split | Paires | recall@1 | recall@3 |
|---|---:|---|---|
| train | 30 | 90,0 % (27/30) | 100,0 % (30/30) |
| test | 20 | 60,0 % (12/20) | 75,0 % (15/20) |

Manquants TEST au recall@3 (libellé brut → cible, meilleur rang obtenu, score cible) :
- « MONSTER ULTRA ZERO » → « Monster Blanche » : rang > 3 (meilleur candidat « Monster Noire » à 0.805 ; cible à 0.516)
- « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » : rang > 3 (meilleur candidat « Monster Noire » à 0.755 ; cible à 0.440)
- « RED BULL BLUE BOITE 25CL » → « Redbull Bleu » : rang > 3 (meilleur candidat « Redbull Blue » à 0.885 ; cible à 0.539)
- « Redbull blue » → « Redbull Bleu » : rang > 3 (meilleur candidat « Redbull Seablue » à 0.781 ; cible à 0.582)
- « Soiree bowling (2 parties / 1 personne) » → « bowling » : rang > 3 (meilleur candidat « Soiree bowling ( 2 personnes ) » à 0.946 ; cible à 0.730)

Règle d'exclusion : l'identité du libellé lui-même (même forme normalisée). Exclure le groupe de split
entier écarterait la cible par construction (le groupe EST la composante union-find des paires positives).

## A2 — calibration du seuil (TRAIN uniquement, une seule règle)

- Seuil calibré : **0.7741** — précision TRAIN 71,1 % au rappel TRAIN 90,0 % (≥ 90 %).
- Reporté tel quel sur TEST : précision 47,4 %, rappel 45,0 % (9 TP, 10 FP).
- Espace de notation : chaque libellé brut des positifs × toutes les identités de l'univers (moins soi-même) ;
  TP = paire positive sémantique du split ; FP = paire ≥ seuil hors vérité terrain, hors résiduels §1.3
  (non qualifiables, affichés dans l'autre section de l'écran).
- Courbe P/R TRAIN (seuil, rappel, précision, TP, FP) :

| seuil | rappel | précision | TP | FP |
|---|---|---|---:|---:|
| 0.5708 | 1.000 | 0.390 | 30 | 47 |
| 0.6524 | 0.967 | 0.483 | 29 | 31 |
| 0.7040 | 0.900 | 0.551 | 27 | 22 |
| 0.7741 | 0.900 | 0.711 | 27 | 11 |
| 0.7751 | 0.867 | 0.703 | 26 | 11 |
| 0.7787 | 0.833 | 0.694 | 25 | 11 |
| 0.7854 | 0.800 | 0.686 | 24 | 11 |
| 0.8030 | 0.767 | 0.742 | 23 | 8 |
| 0.8148 | 0.700 | 0.724 | 21 | 8 |
| 0.8315 | 0.700 | 0.840 | 21 | 4 |
| 0.8332 | 0.633 | 0.864 | 19 | 3 |
| 0.8520 | 0.633 | 0.905 | 19 | 2 |
| 0.8523 | 0.600 | 0.900 | 18 | 2 |
| 0.8627 | 0.567 | 0.895 | 17 | 2 |
| 0.8699 | 0.533 | 0.941 | 16 | 1 |
| 0.8734 | 0.500 | 0.938 | 15 | 1 |
| 0.8838 | 0.467 | 0.933 | 14 | 1 |
| 0.8875 | 0.433 | 0.929 | 13 | 1 |
| 0.8979 | 0.367 | 0.917 | 11 | 1 |
| 0.8999 | 0.367 | 0.917 | 11 | 1 |
| 0.9033 | 0.300 | 0.900 | 9 | 1 |
| 0.9046 | 0.300 | 0.900 | 9 | 1 |
| 0.9129 | 0.267 | 0.889 | 8 | 1 |
| 0.9171 | 0.233 | 0.875 | 7 | 1 |
| 0.9174 | 0.200 | 0.857 | 6 | 1 |
| 0.9205 | 0.167 | 0.833 | 5 | 1 |
| 0.9301 | 0.100 | 1.000 | 3 | 0 |
| 0.9304 | 0.067 | 1.000 | 2 | 0 |
| 0.9385 | 0.067 | 1.000 | 2 | 0 |
| 0.9792 | 0.033 | 1.000 | 1 | 0 |

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
| « pepper »→« Dr Pepper » | « Pepper » → « Dr Pepper » | échoue | réussit (rang 1, 0.802) |
| « Cristaline »→« Eau » | « Cristaline » → « Eau » | échoue | réussit (rang 2, 0.571) |
| « monster »→« monster noire » | « monster » → « monster noire » | échoue | réussit (rang 1, 0.775) |
| « cocacola »→« Coca Cola » (concaténation — analogue en jeu : abrègement « Coca ») | « Coca » → « Coca Cola » | échoue | réussit (rang 1, 0.922) |
| « MONSTER ULTRA ZERO BTE 50CL »→« Monster Blanche » | « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » | échoue | échoue (cible 0.440) |

Détail des échecs P0 rattrapés par P1 :
- « Bonbon Haribo » → « haribo » (rang 2, 0.779)
- « Bowling ( 2 parties ) » → « bowling » (rang 2, 0.822)
- « CACOLAC BOITE 25CL X6 » → « Cacolac » (rang 1, 0.873)
- « COCA COLA BOITE FAT 33CL » → « Coca Cola » (rang 1, 0.596)
- « COCACOLA CHERRY SLIM 33CL » → « Coca Cherry » (rang 1, 0.774)
- « CRISTALI 5OCL PET » → « Eau » (rang 1, 0.652)
- « Coca » → « Coca Cola » (rang 1, 0.922)
- « Coca sans sucre » → « Coca Zero » (rang 1, 0.806)
- « Cristaline » → « Eau » (rang 2, 0.571)
- « Fanta » → « Fanta Orange » (rang 1, 0.903)
- « Lion_Peanut » → « Lion » (rang 1, 0.803)
- « Lipton » → « Lipton Ice Tea » (rang 1, 0.635)
- « MMS » → « M&Ms » (rang 1, 0.785)
- « Minute Maid Multi Vitamines » → « Minute Maid MultiVitamine » (rang 1, 0.832)
- « MinuteMaid_Pomme » → « Minute Maid Nect Pomme » (rang 1, 0.729)
- « Monster rose (punch) » → « Monster Rose » (rang 1, 0.913)
- « Monster verte (energy) » → « Monster Verte » (rang 1, 0.799)
- « OASIS POM CASS FRAM SLIM 33CL » → « Oasis Pomme Cassis Framboise » (rang 2, 0.730)
- « OASIS TROPICAL SLIM BTE 33CL » → « Oasis Tropical » (rang 1, 0.870)
- « Oasis » → « Oasis Tropical » (rang 1, 0.905)
- « PERRIER BOITE SLIM 33CL » → « Perrier » (rang 1, 0.852)
- « PULCO CITRONNADE SLIM 33CL » → « Pulco » (rang 1, 0.636)
- « Pepper » → « Dr Pepper » (rang 1, 0.802)
- « Pepsi sans sucre » → « Pepsi Zero » (rang 1, 0.887)
- « Pulco Citronnade » → « Pulco » (rang 1, 0.795)
- « RED BULL ICE BOITE 25CL » → « Redbull Ice » (rang 1, 0.917)
- « RED BULL PEACH BTE 25CL » → « Redbull Peach » (rang 1, 0.920)
- « RED BULL RED EDITION 25CL » → « redbull red » (rang 1, 0.863)
- « RED BULL SEA BLUE BOITE 25CL » → « Redbull Seablue » (rang 1, 0.917)
- « Redbull Myrtille » → « Redbull Ice » (rang 1, 0.704)
- « Redbull_Blanche » → « Redbull » (rang 1, 0.781)
- « Soiree bowling ( 2 personnes ) » → « bowling 2personnes » (rang 2, 0.777)
- « Sucette 1 sucette » → « sucette » (rang 2, 0.898)
- « TROPICO L'ORIG BTE CL SLIM » → « Tropico » (rang 1, 0.815)
- « chips » → « Chips Nature » (rang 1, 0.884)
- « minutemaid » → « Minute Maid Orange » (rang 1, 0.852)
- « monster » → « monster noire » (rang 1, 0.775)
- « schweppes » → « schweppes agrumes » (rang 1, 0.833)
Détail des régressions P1 (à traiter en P2) :
- « Redbull blue » → « Redbull Bleu » (meilleur candidat « Redbull Seablue », cible 0.582)

## A4 — durs négatifs intra-famille (cible : 0 paire ≥ seuil)

- 27 / 199 paires ≥ seuil 0.7741 (13,6 %).
- Paires fautives (score décroissant) :
  - « Soiree bowling (2 parties / 1 personne) » / « Soiree bowling ( 2 personnes ) » — 0.9474
  - « Sucette 1 » / « Sucette 1 sucette » — 0.9170
  - « Oasis Ice Tea » / « Oasis ice tea the mangue passion » — 0.8694
  - « Sucette 1 » / « Sucette 3 » — 0.8685
  - « Minute Maid Orange » / « Minute Maid Pomme » — 0.8670
  - « Fanta » / « Fanta Exotique » — 0.8650
  - « sucette » / « sucette 3 sucettes » — 0.8584
  - « Minute Maid » / « Minute Maid Pomme » — 0.8526
  - « Redbull » / « redbull red » — 0.8477
  - « Fanta Exotique » / « Fanta Orange » — 0.8442
  - « Pepsi » / « Pepsi sans sucre » — 0.8438
  - « Bowling ( 2 parties ) » / « bowling 2personnes » — 0.8417
  - « Oasis » / « Oasis pomme poire » — 0.8387
  - « Sucette 1 sucette » / « Sucette 3 » — 0.8260
  - « Fanta » / « Fanta Citron » — 0.8135
  - « Pepsi » / « Pepsi Zero » — 0.8072
  - « Chips BBQ » / « Chips Nature » — 0.8029
  - « Sucette 3 » / « sucette 3 sucettes » — 0.8019
  - « Oasis pomme poire » / « Oasis Tropical » — 0.7952
  - « Minute Maid » / « Minute Maid Nect Pomme » — 0.7914
  - « Minute Maid Nect Pomme » / « Minute Maid Orange » — 0.7904
  - « sucette » / « Sucette 1 » — 0.7888
  - « Coca Cherry » / « Coca Cola » — 0.7863
  - « Bonbon » / « Bonbon Haribo » — 0.7862
  - « Redbull Blue » / « Redbull Seablue » — 0.7835
  - « bowling » / « bowling 2personnes » — 0.7827
  - « sucette » / « Sucette 3 » — 0.7752

## A5 — benchmark inférence CPU

- 200 runs (après 10 échauffements) : embed(« Coca Cola ») + cosinus contre les 120 vecteurs de l'univers.
- moyenne 3.5 ms · p50 3.1 ms · p95 5.8 ms · min 2.4 ms · max 12.8 ms.
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
- Candidats ≥ seuil avant plafond : 13 ; retenus : 13.
- Dont étiquetés durs négatifs intra-famille (cf. A4) : 12/13
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
- Modèle fine-tuné (P2) : entraîné sur le split TRAIN du même jeu figé (+ variantes synthétiques)
  — les mesures sur TRAIN sont optimistes par construction (données vues) ; le split TEST
  (groupes jamais vus, anti-fuite vérifié amont) reste la mesure honnête.


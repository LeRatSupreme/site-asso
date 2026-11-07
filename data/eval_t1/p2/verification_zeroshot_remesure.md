# Rapport S4 — Baseline P1 zéro-shot (embeddings) vs cibles A1–A6

> Mission T1-P0 · Lot B (dev-factures) · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§4 P1, §5 cibles).
> Jeu figé `data/eval_t1/` (verdict amont A2 : CONFORME, SHA256SUMS vérifiés). Aucune requête SQL.
> Généré par : `~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer` (A6, une seule commande).

## Environnement et modèle

| Élément | Valeur |
|---|---|
| Modèle | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` |
| Binaire chargé | export ONNX **int8** local `/home/ubuntu/AEIC/data/eval_t1/p2/convert/model-int8-zeroshot` (fine-tuné P2, mêmes poids d'architecture), via fastembed `specific_model_path` |
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
| A1 | Rappel fusions sémantiques (recall@3, TEST) | ≥ 90 % | 70,0 % (14/20) | MANQUÉ |
| A2 | Précision à seuil au rappel ≥ 90 % (TEST au seuil calibré sur TRAIN) | ≥ 85 % | précision 3,3 % au rappel 90,0 % (18 TP / 534 FP), seuil 0.3088 | MANQUÉ |
| A3 | Supériorité sur P0 (ses échecs → P1 strictement mieux ; zéro régression) | gain > 0, régression = 0 | 32 échecs P0 rattrapés par P1, 1 régression(s) ; P0 8/50 vs P1 39/50 | MANQUÉ |
| A4 | Durs négatifs intra-famille ≥ seuil | 0 | 185 / 199 (93,0 %) | MANQUÉ |
| A5 | Inférence 1 libellé vs ~120 clés (CPU) | < 50 ms | moyenne 4 ms, p50 4 ms, p95 6 ms (200 runs) | ATTEINT |
| A6 | Repro : une commande régénère tout, sorties stables | rejouable | `--evaluer` régénère rapport + candidats ; tri déterministe ; graine 42 | ATTEINT |

Un critère MANQUÉ déclenche le palier P2 (fine-tune léger) — ce n'est pas un échec du lot (§5, décision D1).

## A1 — recall@3 par split (détail)

| Split | Paires | recall@1 | recall@3 |
|---|---:|---|---|
| train | 30 | 53,3 % (16/30) | 83,3 % (25/30) |
| test | 20 | 55,0 % (11/20) | 70,0 % (14/20) |

Manquants TEST au recall@3 (libellé brut → cible, meilleur rang obtenu, score cible) :
- « Bowling ( 2 parties ) » → « bowling » : rang > 3 (meilleur candidat « Soiree bowling (2 parties / 1 personne) » à 0.922 ; cible à 0.869)
- « COCA COLA BOITE FAT 33CL » → « Coca Cola » : rang > 3 (meilleur candidat « Fanta Cassis » à 0.415 ; cible à 0.259)
- « Gauffre choco » → « Gaufre choco » : rang > 3 (meilleur candidat « Gauffre » à 0.749 ; cible à 0.614)
- « MONSTER ULTRA ZERO » → « Monster Blanche » : rang > 3 (meilleur candidat « Monster Bleu » à 0.660 ; cible à 0.432)
- « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » : rang > 3 (meilleur candidat « Redbull zero » à 0.541 ; cible à 0.283)
- « Soiree bowling (2 parties / 1 personne) » → « bowling » : rang > 3 (meilleur candidat « Soiree bowling ( 2 personnes ) » à 0.936 ; cible à 0.818)

Règle d'exclusion : l'identité du libellé lui-même (même forme normalisée). Exclure le groupe de split
entier écarterait la cible par construction (le groupe EST la composante union-find des paires positives).

## A2 — calibration du seuil (TRAIN uniquement, une seule règle)

- Seuil calibré : **0.3088** — précision TRAIN 2,5 % au rappel TRAIN 93,3 % (≥ 90 %).
- Reporté tel quel sur TEST : précision 3,3 %, rappel 90,0 % (18 TP, 534 FP).
- Espace de notation : chaque libellé brut des positifs × toutes les identités de l'univers (moins soi-même) ;
  TP = paire positive sémantique du split ; FP = paire ≥ seuil hors vérité terrain, hors résiduels §1.3
  (non qualifiables, affichés dans l'autre section de l'écran).
- Courbe P/R TRAIN (seuil, rappel, précision, TP, FP) :

| seuil | rappel | précision | TP | FP |
|---|---|---|---:|---:|
| 0.2547 | 1.000 | 0.018 | 30 | 1684 |
| 0.3044 | 0.967 | 0.025 | 29 | 1128 |
| 0.3088 | 0.933 | 0.025 | 28 | 1072 |
| 0.3433 | 0.867 | 0.033 | 26 | 774 |
| 0.3615 | 0.867 | 0.038 | 26 | 654 |
| 0.4649 | 0.800 | 0.109 | 24 | 196 |
| 0.5164 | 0.767 | 0.184 | 23 | 102 |
| 0.5709 | 0.767 | 0.277 | 23 | 60 |
| 0.5812 | 0.733 | 0.301 | 22 | 51 |
| 0.5840 | 0.667 | 0.282 | 20 | 51 |
| 0.6020 | 0.667 | 0.317 | 20 | 43 |
| 0.6249 | 0.633 | 0.328 | 19 | 39 |
| 0.6351 | 0.567 | 0.327 | 17 | 35 |
| 0.6417 | 0.533 | 0.327 | 16 | 33 |
| 0.6874 | 0.533 | 0.381 | 16 | 26 |
| 0.6908 | 0.467 | 0.350 | 14 | 26 |
| 0.6948 | 0.467 | 0.359 | 14 | 25 |
| 0.7097 | 0.400 | 0.333 | 12 | 24 |
| 0.7535 | 0.367 | 0.393 | 11 | 17 |
| 0.7825 | 0.367 | 0.458 | 11 | 13 |
| 0.8155 | 0.333 | 0.526 | 10 | 9 |
| 0.8711 | 0.300 | 0.692 | 9 | 4 |
| 0.8737 | 0.267 | 0.667 | 8 | 4 |
| 0.8878 | 0.233 | 0.636 | 7 | 4 |
| 0.8957 | 0.200 | 0.600 | 6 | 4 |
| 0.9036 | 0.167 | 0.625 | 5 | 3 |
| 0.9092 | 0.133 | 0.667 | 4 | 2 |
| 0.9300 | 0.100 | 0.750 | 3 | 1 |
| 0.9355 | 0.033 | 0.500 | 1 | 1 |
| 0.9569 | 0.033 | 1.000 | 1 | 0 |

## A3 — P0 (port fidèle AliasSuggester) vs P1 sur le même jeu figé (50 paires)

- P0 réussit 8/50 ; P1 (recall@3) réussit 39/50.
- Échecs de P0 rattrapés par P1 : **32** (strictement > 0 → cible).
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
| « pepper »→« Dr Pepper » | « Pepper » → « Dr Pepper » | échoue | réussit (rang 1, 0.806) |
| « Cristaline »→« Eau » | « Cristaline » → « Eau » | échoue | échoue (cible 0.343) |
| « monster »→« monster noire » | « monster » → « monster noire » | échoue | réussit (rang 3, 0.871) |
| « cocacola »→« Coca Cola » (concaténation — analogue en jeu : abrègement « Coca ») | « Coca » → « Coca Cola » | échoue | réussit (rang 1, 0.887) |
| « MONSTER ULTRA ZERO BTE 50CL »→« Monster Blanche » | « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » | échoue | échoue (cible 0.283) |

Détail des échecs P0 rattrapés par P1 :
- « Bonbon Haribo » → « haribo » (rang 2, 0.691)
- « COCACOLA CHERRY SLIM 33CL » → « Coca Cherry » (rang 1, 0.516)
- « Coca » → « Coca Cola » (rang 1, 0.887)
- « Coca sans sucre » → « Coca Zero » (rang 3, 0.749)
- « Fanta » → « Fanta Orange » (rang 3, 0.695)
- « Lion_Peanut » → « Lion » (rang 1, 0.642)
- « Lipton » → « Lipton Ice Tea » (rang 1, 0.593)
- « MMS » → « M&Ms » (rang 1, 0.584)
- « Minute Maid Multi Vitamines » → « Minute Maid MultiVitamine » (rang 1, 0.571)
- « MinuteMaid_Pomme » → « Minute Maid Nect Pomme » (rang 1, 0.598)
- « Monster rose (punch) » → « Monster Rose » (rang 1, 0.909)
- « Monster verte (energy) » → « Monster Verte » (rang 1, 0.775)
- « OASIS POM CASS FRAM SLIM 33CL » → « Oasis Pomme Cassis Framboise » (rang 1, 0.514)
- « OASIS TROPICAL SLIM BTE 33CL » → « Oasis Tropical » (rang 1, 0.635)
- « Oasis » → « Oasis Tropical » (rang 1, 0.815)
- « PERRIER BOITE SLIM 33CL » → « Perrier » (rang 2, 0.465)
- « PULCO CITRONNADE SLIM 33CL » → « Pulco » (rang 1, 0.403)
- « Pepper » → « Dr Pepper » (rang 1, 0.806)
- « Pepsi sans sucre » → « Pepsi Zero » (rang 2, 0.782)
- « Pulco Citronnade » → « Pulco » (rang 1, 0.834)
- « RED BULL BLUE BOITE 25CL » → « Redbull Bleu » (rang 1, 0.721)
- « RED BULL ICE BOITE 25CL » → « Redbull Ice » (rang 1, 0.687)
- « RED BULL RED EDITION 25CL » → « redbull red » (rang 3, 0.581)
- « RED BULL SEA BLUE BOITE 25CL » → « Redbull Seablue » (rang 1, 0.710)
- « Redbull_Blanche » → « Redbull » (rang 3, 0.789)
- « Soiree bowling ( 2 personnes ) » → « bowling 2personnes » (rang 1, 0.943)
- « Sucette 1 sucette » → « sucette » (rang 2, 0.754)
- « TROPICO L'ORIG BTE CL SLIM » → « Tropico » (rang 1, 0.625)
- « chips » → « Chips Nature » (rang 1, 0.888)
- « minutemaid » → « Minute Maid Orange » (rang 3, 0.602)
- « monster » → « monster noire » (rang 3, 0.871)
- « schweppes » → « schweppes agrumes » (rang 1, 0.936)
Détail des régressions P1 (à traiter en P2) :
- « Gauffre choco » → « Gaufre choco » (meilleur candidat « Gauffre », cible 0.614)

## A4 — durs négatifs intra-famille (cible : 0 paire ≥ seuil)

- 185 / 199 paires ≥ seuil 0.3088 (93,0 %).
- Paires fautives (score décroissant) :
  - « Minute Maid » / « Minute Maid Pomme » — 0.9589
  - « Soiree bowling (2 parties / 1 personne) » / « Soiree bowling ( 2 personnes ) » — 0.9405
  - « Monster Bleu » / « Monster Verte » — 0.9273
  - « Sucette 1 » / « Sucette 1 sucette » — 0.9251
  - « Monster Noire » / « Monster Verte » — 0.8963
  - « Minute Maid » / « Minute Maid Nect Pomme » — 0.8822
  - « Bowling ( 2 parties ) » / « bowling 2personnes » — 0.8770
  - « Monster Bleu » / « Monster Noire » — 0.8615
  - « Monster Bleue » / « Monster Verte » — 0.8575
  - « Redbull » / « Redbull Pomme » — 0.8483
  - « Fanta » / « Fanta Exotique » — 0.8168
  - « Oasis Ice Tea » / « Oasis ice tea the mangue passion » — 0.8082
  - « Oasis » / « Oasis Thémangue Passion » — 0.8068
  - « Pepsi » / « Pepsi sans sucre » — 0.7981
  - « bowling » / « bowling 2personnes » — 0.7869
  - « Redbull Bleu » / « Redbull Pomme » — 0.7828
  - « gaufre » / « Gaufre choco » — 0.7758
  - « Monster Bleue » / « Monster Noire » — 0.7728
  - « Minute Maid Orange » / « Minute Maid Pomme » — 0.7702
  - « Redbull_Bleue » / « Redbull Pomme » — 0.7685
  - « Bonbon » / « Bonbon Haribo » — 0.7660
  - « Redbull Pomme » / « redbull red » — 0.7639
  - « Chips BBQ » / « Chips Nature » — 0.7602
  - « Coca Cola » / « Coca sans sucre » — 0.7598
  - « Coca Cherry » / « Coca Cola » — 0.7522
  - « Gauffre » / « Gauffre choco » — 0.7499
  - « Redbull » / « redbull red » — 0.7475
  - « Sucette 1 » / « Sucette 3 » — 0.7414
  - « Monster Blanche » / « Monster Noire » — 0.7382
  - « Redbull Abricot » / « Redbull Pomme » — 0.7366
  - « Monster Blanche » / « Monster Bleu » — 0.7350
  - « Monster Bleu » / « Monster verte (energy) » — 0.7341
  - « sucette » / « sucette 3 sucettes » — 0.7336
  - « Minute Maid Nect Pomme » / « Minute Maid Orange » — 0.7292
  - « Monster Blanche » / « Monster Verte » — 0.7243
  - « Pepsi » / « Pepsi Zero » — 0.7217
  - « Redbull » / « Redbull Bleu » — 0.7186
  - « Fanta » / « Fanta Citron » — 0.7180
  - « Sucette 1 sucette » / « Sucette 3 » — 0.7141
  - « Redbull Bleu » / « redbull red » — 0.7110
  - « RedBull Blanche » / « Redbull Bleu » — 0.7081
  - « Oasis Thémangue Passion » / « Oasis Tropical » — 0.7061
  - « Minute Maid MultiVitamine » / « Minute Maid Pomme » — 0.7032
  - « RedBull Blanche » / « Redbull Pomme » — 0.6993
  - « Fanta Citron » / « Fanta Exotique » — 0.6993
  - « Minute Maid MultiVitamine » / « Minute Maid Nect Pomme » — 0.6976
  - « Minute Maid » / « Minute Maid MultiVitamine » — 0.6971
  - « Monster Rose » / « Monster Verte » — 0.6967
  - « Coca Cola » / « Coca Zero » — 0.6955
  - « Fanta Exotique » / « Fanta Orange » — 0.6951
  - « Fanta Cassis » / « Fanta Citron » — 0.6950
  - « Fanta Exotique » / « Fanta Mangue Dragon » — 0.6855
  - « Coca Cherry » / « Coca sans sucre » — 0.6838
  - « Sucette 3 » / « sucette 3 sucettes » — 0.6837
  - « sucette » / « Sucette 1 » — 0.6797
  - « Monster Bleue » / « Monster verte (energy) » — 0.6768
  - « RedBull Blanche » / « redbull red » — 0.6767
  - « Redbull » / « Redbull_Bleue » — 0.6721
  - « Monster Bleu » / « Monster Rose » — 0.6696
  - « Monster Noire » / « Monster verte (energy) » — 0.6694
  - « Fanta Cassis » / « Fanta Exotique » — 0.6641
  - « Fanta » / « Fanta Cassis » — 0.6623
  - « Redbull Abricot » / « Redbull Bleu » — 0.6570
  - « Monster Rose (punch) » / « Monster Verte » — 0.6471
  - « sucette » / « Sucette 3 » — 0.6458
  - « Fanta Cassis » / « Fanta Orange » — 0.6395
  - « Redbull Ice » / « Redbull Winter » — 0.6371
  - « Redbull Abricot » / « Redbull_Bleue » — 0.6369
  - « Monster Noire » / « Monster Rose » — 0.6368
  - « Redbull Bleu » / « Redbull Seablue » — 0.6285
  - « Monster Bleu » / « Monster Rose (punch) » — 0.6220
  - « Monster Blanche » / « Monster Rose » — 0.6219
  - « Monster Blanche » / « Monster Bleue » — 0.6181
  - « Redbull » / « Redbull Abricot » — 0.6174
  - « Coca Cherry » / « Coca Zero » — 0.6146
  - « Monster Noire » / « Monster Rose (punch) » — 0.6083
  - « Fanta Citron » / « Fanta Orange » — 0.6073
  - « Redbull Pomme » / « Redbull Seablue » — 0.6022
  - « Fanta » / « Fanta Mangue Dragon » — 0.6020
  - « Redbull_Bleue » / « redbull red » — 0.6010
  - « Redbull » / « Redbull Winter » — 0.5893
  - « Oasis » / « Oasis pomme poire » — 0.5887
  - « Redbull Blue » / « Redbull Summer » — 0.5882
  - « RedBull Blanche » / « Redbull_Bleue » — 0.5858
  - « Redbull Blue » / « redbull red » — 0.5854
  - « Gauffre » / « Gauffre + boissons (hors boissons energisante) » — 0.5807
  - « Fanta Mangue Dragon » / « Fanta Orange » — 0.5774
  - « redbull red » / « Redbull Summer » — 0.5686
  - « Redbull_Bleue » / « Redbull Seablue » — 0.5671
  - « Monster Bleue » / « Monster Rose » — 0.5656
  - « Monster Rose (punch) » / « Monster verte (energy) » — 0.5594
  - « Redbull » / « Redbull Summer » — 0.5587
  - « Monster Rose » / « Monster verte (energy) » — 0.5563
  - « Redbull Myrtille » / « Redbull Pomme » — 0.5530
  - « Redbull Summer » / « Redbull Winter » — 0.5529
  - « Gauffre + boissons (hors boissons energisante) » / « Gauffre choco » — 0.5518
  - « RedBull Blanche » / « Redbull Seablue » — 0.5505
  - « Sucette 1 » / « sucette 3 sucettes » — 0.5488
  - « Redbull » / « Redbull Seablue » — 0.5474
  - « Minute Maid Multi Vitamines » / « Minute Maid Pomme » — 0.5472
  - « Monster Blanche » / « Monster Rose (punch) » — 0.5468
  - « Oasis » / « Oasis Ice Tea » — 0.5456
  - « Oasis ice tea the mangue passion » / « Oasis pomme poire » — 0.5451
  - « Monster Bleue » / « Monster Rose (punch) » — 0.5443
  - « Fanta Citron » / « Fanta Mangue Dragon » — 0.5434
  - « Monster Blanche » / « Monster verte (energy) » — 0.5371
  - « Redbull Pomme » / « Redbull zero » — 0.5369
  - « Minute Maid Multi Vitamines » / « Minute Maid Nect Pomme » — 0.5332
  - « Fanta Cassis » / « Fanta Mangue Dragon » — 0.5315
  - « Crunch » / « crunch Snak Barres » — 0.5298
  - « Redbull Peach » / « Redbull Pomme » — 0.5266
  - « Redbull » / « Redbull Myrtille » — 0.5245
  - « Redbull_Bleue » / « Redbull zero » — 0.5241
  - « Oasis pomme poire » / « Oasis Thémangue Passion » — 0.5206
  - « Redbull » / « Redbull Blue » — 0.5164
  - « Redbull Abricot » / « Redbull Seablue » — 0.5162
  - « Oasis » / « Oasis Pomme Cassis Framboise » — 0.5140
  - « Minute Maid » / « Minute Maid Multi Vitamines » — 0.5118
  - « Redbull » / « Redbull Peach » — 0.5118
  - « Redbull Ice » / « Redbull Summer » — 0.5110
  - « Minute Maid Multi Vitamines » / « Minute Maid Orange » — 0.5089
  - « gaufre » / « Gaufre + boissons (hors boissons energisante) » — 0.5084
  - « Oasis Ice Tea » / « Oasis Thémangue Passion » — 0.5073
  - « redbull red » / « Redbull Winter » — 0.4972
  - « Oasis ice tea the mangue passion » / « Oasis Tropical » — 0.4970
  - « Redbull » / « Redbull Ice » — 0.4931
  - « redbull red » / « Redbull Seablue » — 0.4914
  - « Redbull Abricot » / « redbull red » — 0.4912
  - « Redbull Pomme » / « Redbull Winter » — 0.4885
  - « Redbull Bleu » / « Redbull Peach » — 0.4882
  - « Redbull Abricot » / « RedBull Blanche » — 0.4877
  - « Redbull » / « Redbull zero » — 0.4841
  - « Minute Maid MultiVitamine » / « Minute Maid Orange » — 0.4832
  - « Redbull Bleu » / « Redbull Myrtille » — 0.4828
  - « Redbull Ice » / « redbull red » — 0.4801
  - « Oasis Pomme Cassis Framboise » / « Oasis Tropical » — 0.4787
  - « Oasis » / « Oasis ice tea the mangue passion » — 0.4725
  - « Oasis Ice Tea » / « Oasis Tropical » — 0.4724
  - « Redbull Blue » / « Redbull Ice » — 0.4704
  - « Redbull Peach » / « redbull red » — 0.4666
  - « Redbull Myrtille » / « redbull red » — 0.4662
  - « Oasis Pomme Cassis Framboise » / « Oasis Thémangue Passion » — 0.4640
  - « Gaufre + boissons (hors boissons energisante) » / « Gaufre choco » — 0.4616
  - « Oasis pomme poire » / « Oasis Tropical » — 0.4609
  - « Redbull Bleu » / « Redbull zero » — 0.4607
  - « Redbull Bleu » / « Redbull Summer » — 0.4587
  - « Redbull Bleu » / « Redbull Winter » — 0.4570
  - « RedBull Blanche » / « Redbull Blue » — 0.4566
  - « Bueno » / « Bueno white » — 0.4542
  - « Redbull Peach » / « Redbull Summer » — 0.4449
  - « redbull red » / « Redbull zero » — 0.4408
  - « Redbull Abricot » / « Redbull Myrtille » — 0.4366
  - « Oasis Pomme Cassis Framboise » / « Oasis pomme poire » — 0.4337
  - « Redbull Abricot » / « Redbull zero » — 0.4313
  - « Redbull Blue » / « Redbull Peach » — 0.4273
  - « RedBull Blanche » / « Redbull Peach » — 0.4268
  - « Redbull_Bleue » / « Redbull Myrtille » — 0.4266
  - « RedBull Blanche » / « Redbull Myrtille » — 0.4116
  - « Oasis Ice Tea » / « Oasis pomme poire » — 0.4110
  - « Redbull Seablue » / « Redbull Winter » — 0.4054
  - « Redbull Blue » / « Redbull Winter » — 0.4049
  - « Redbull Blue » / « Redbull Seablue » — 0.4020
  - « Redbull Pomme » / « Redbull Summer » — 0.3987
  - « Redbull Blue » / « Redbull Pomme » — 0.3970
  - « Redbull Ice » / « Redbull Seablue » — 0.3965
  - « Redbull Abricot » / « Redbull Peach » — 0.3907
  - « Redbull Peach » / « Redbull Winter » — 0.3889
  - « Oasis ice tea the mangue passion » / « Oasis Pomme Cassis Framboise » — 0.3877
  - « Redbull Ice » / « Redbull Pomme » — 0.3804
  - « Redbull Bleu » / « Redbull Ice » — 0.3803
  - « RedBull Blanche » / « Redbull Summer » — 0.3706
  - « Redbull Abricot » / « Redbull Winter » — 0.3679
  - « Redbull_Bleue » / « Redbull Blue » — 0.3664
  - « Redbull Ice » / « Redbull Peach » — 0.3616
  - « Redbull_Bleue » / « Redbull Summer » — 0.3599
  - « Redbull_Bleue » / « Redbull Winter » — 0.3589
  - « RedBull Blanche » / « Redbull Winter » — 0.3588
  - « Redbull Seablue » / « Redbull zero » — 0.3568
  - « Redbull_Bleue » / « Redbull Peach » — 0.3491
  - « RedBull Blanche » / « Redbull zero » — 0.3475
  - « Redbull Myrtille » / « Redbull Seablue » — 0.3472
  - « Redbull Winter » / « Redbull zero » — 0.3450
  - « Redbull Seablue » / « Redbull Summer » — 0.3260
  - « Redbull Myrtille » / « Redbull zero » — 0.3226
  - « Oasis Ice Tea » / « Oasis Pomme Cassis Framboise » — 0.3146

## A5 — benchmark inférence CPU

- 200 runs (après 10 échauffements) : embed(« Coca Cola ») + cosinus contre les 120 vecteurs de l'univers.
- moyenne 4.3 ms · p50 4.1 ms · p95 5.6 ms · min 3.6 ms · max 7.6 ms.
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
- Candidats ≥ seuil avant plafond : 1537 ; retenus : 50.
- Dont étiquetés durs négatifs intra-famille (cf. A4) : 32/50
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


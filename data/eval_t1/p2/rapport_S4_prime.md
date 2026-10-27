# Rapport S4' — Fine-tuné P2 (ONNX int8) vs zéro-shot, À PAREIL À PAREIL

> Mission T1-P2/P4 (dev-factures) · briefs `/tmp/p2_briefs/P4_eval.md` + contraintes `00_CONTRAINTES.md`.
> Comparaison **à pareil à pareil** (leçon #7, contrainte 6) : MÊME jeu figé `data/eval_t1/`
> (SHA256SUMS vérifiés avant/après, 12/12), MÊME script `scripts/t1_similarity.py`, MÊME
> protocole A1→A6, MÊMES cibles, MÊME graine (42), MÊME environnement de mesure
> (`.venv-t1` : fastembed 0.9.1 / onnxruntime 1.31.1 / numpy 2.5.4, CPU 4 cœurs, Python 3.12.3).
> **Seule variable : le binaire ONNX int8 chargé** (`--model`, option ajoutée sans toucher au
> comportement par défaut — preuve : anti-régression ci-dessous).

## Commandes exactes (reproductibles)

```bash
# 1) zéro-shot (défaut, inchangé — régénère rapport_S4.md + candidats_fusion.json)
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer
# 2) fine-tuné P2 (converti dans data/eval_t1/p2/convert/, sorties redirigées)
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
    --model data/eval_t1/p2/convert/model-int8 \
    --rapport-sortie data/eval_t1/p2/rapport_S4_prime_brut.md \
    --candidats-sortie data/eval_t1/p2/candidats_fusion_prime.json
```

Rapports détaillés générés par la machine : `data/eval_t1/rapport_S4.md` (zéro-shot, inchangé)
et `data/eval_t1/p2/rapport_S4_prime_brut.md` (fine-tuné). Le présent document est la
COMPARAISON des deux ; tout chiffre cité provient de ces deux sorties machine.

## Anti-régression du protocole (criterion P4 n°4)

Deux exécutions `--evaluer` (modèle par défaut) APRÈS l'ajout de `--model` :
- seuil 0,3088 ; A1 70,0 % (14/20) ; A2 3,3 % (rappel 90,0 %) ; A3 39/50 (32 rattrapés,
  1 régression) ; A4 185/199 ; A5 ~3,6 ms → **identiques au S4 d'origine** ;
- `candidats_fusion.json` identique octet près (hors `generated_at`) ;
- seuls écarts de fichiers : lignes A5 (±1 ms, tolérance documentée A6) et l'arrondi `du`
  d'un cache inchangé (130M→129M, aucune écriture dans `.cache`, sha256 du binaire constant).
Le protocole par défaut n'a donc PAS bougé : le zéro-shot reste re-mesurable à l'identique.

## Chaîne de conversion (étape 1 du brief) — `data/eval_t1/p2/convert/`

- Source : `p2/kernel_output/model/` (safetensors fp32 470 Mo, BERT 12 couches/384 dim,
  epoch 5, artefacts laissés intacts — SHA256SUMS kernel_output 12/12 OK).
- Export torch → ONNX **fp32** (`BertModel`, attention eager, **opset 11 = celui de la
  référence Xenova**, `do_constant_folding=False`, paramètres sans grad) ; signature
  EXACTEMENT celle du modèle de production : entrées `input_ids`/`attention_mask`/
  `token_type_ids` (int64, batch/seq dynamiques), sortie unique `last_hidden_state`
  [batch, seq, 384], sans pooler.
- Folding local exact de 72 `Transpose(initializer)` → poids alimentant directement les
  MatMul (sinon `quantize_dynamic` ne quantifie que les embeddings : 182 Mo). Même
  répartition que la référence Xenova : 72 poids de couches quantifiés.
- Quantification **dynamique int8** (`onnxruntime.quantization.quantize_dynamic`,
  `weight_type=QInt8`, per_channel=False — méthode des exports Xenova) avec
  onnxruntime **1.31.1** (même version que l'environnement de mesure).
- **Taille finale : 118,1 Mo (118 111 289 o) vs référence zéro-shot 118,1 Mo
  (118 054 609 o) — cible ≤ ~130 Mo ATTEINTE sans quantification plus agressive.**
- Dossier assemblé `convert/model-int8/` : `model_optimized.onnx` + `tokenizer.json` +
  `tokenizer_config.json` + `config.json` (du bundle fine-tuné) + `special_tokens_map.json`
  (du bundle de base sha256-vérifié — absent du save ST 5.7, tokenizer identique, le
  fine-tune n'y touche pas) — layout identique au cache de production.
- Empreintes : `convert/SHA256SUMS_model_int8_finetune` (5 fichiers).

Contrôles embarqués (script `convert/export_onnx.py`, relançable) :

| Contrôle | Résultat |
|---|---|
| Parité tokenizer zéro-shot ↔ fine-tuné (ids, masques, types) | **OK sur les 241 libellés du jeu figé** (truncation 128, padding droite, pad `<pad>` — réglages fastembed) |
| Signature entrées/sorties vs référence | identique (noms, types, axes dynamiques) |
| Fidélité cos(int8, fp32 torch) sur le jeu figé | min 0,866 / moy 0,983 |
| Fidélité de la RÉFÉRENCE prod (Xenova int8 vs base fp32) | min 0,833 / moy 0,980 → **l'export nouveau est légèrement PLUS fidèle que le modèle actuel** (bruit int8 dynamique inhérent à la méthode, des deux côtés) |
| Poids réellement changés | cos(int8 fine-tuné, int8 zéro-shot) min 0,381 / moy 0,869 |

## Tableau A1–A6 — ancien (S4 zéro-shot) vs nouveau (S4' fine-tuné)

Toutes les mesures TEST sont hors échantillon d'entraînement (le fine-tune n'a vu que le
split TRAIN + variantes synthétiques filtrées anti-fuite — vérification amont P2 : zéro
libellé TEST dans train/val). Les mesures TRAIN du fine-tuné sont optimistes par
construction (données vues) : le protocole les conserve telles quelles (règle inchangée),
elles sont signalées ci-dessous.

| # | Critère (cible) | S4 zéro-shot | S4' fine-tuné | Écart |
|---|---|---|---|---|
| A1 | Rappel fusions sémantiques recall@3 TEST (≥ 90 %) | 70,0 % (14/20) — MANQUÉ | **75,0 % (15/20)** — MANQUÉ | **MEILLEUR (+5 pts)** ; recall@1 info : 11/20 → 10/20 (−1) |
| A1 détail | recall@3 / recall@1 — TRAIN (info, vu par le fine-tune) | 83,3 % / 53,3 % | 100 % / 90,0 % | (optimiste par construction — non décisionnel) |
| A2 | Précision TEST au seuil calibré sur TRAIN (≥ 85 %) | 3,3 % au rappel 90,0 % (18 TP / 534 FP), seuil 0,3088 — MANQUÉ | **33,3 %** au rappel 55,0 % (11 TP / 22 FP), seuil **0,6618** — MANQUÉ | **Précision ×10** (MEILLEURE) ; **rappel au seuil en retrait** (55 % vs 90 %) — voir analyse |
| A2 détail | Calibration TRAIN (rappel ≥ 90 %) | seuil 0,3088 : précision 2,5 %, 28 TP / 1072 FP | seuil 0,6618 : précision 47,4 %, 27 TP / 30 FP | FP TRAIN ÷ 36 à rappel équivalent |
| A3 | Supériorité sur P0 (50 paires) | P1 39/50, rattrapés 32, régressions 1 — MANQUÉ | **P1 45/50, rattrapés 38, régressions 1** — MANQUÉ | **MEILLEUR strict** (+6 paires, +6 rattrapés, régressions inchangées) |
| A4 | Durs négatifs intra-famille ≥ seuil (0) | 185/199 (93 %) — MANQUÉ | **57/199 (29 %)** — MANQUÉ | **NETTEMENT MEILLEUR (−69 %)** ; cible 0 toujours manquée |
| A5 | Inférence 1 libellé vs 120 clés, CPU (< 50 ms) | moyenne ~3,6 ms — ATTEINT | moyenne ~3,1 ms — ATTEINT | ÉGAL (même architecture int8, même taille) |
| A6 | Repro une commande, sorties stables | ATTEINT | ATTEINT (anti-régression ci-dessus) | ÉGAL |

### A2 — analyse honnête du recul de rappel au seuil

- La RÈGLE de calibration est identique (précision max sous rappel TRAIN ≥ 0,90, égalité →
  seuil le plus haut). Aucun seuil n'a été retouché pour le nouveau modèle.
- Le fine-tuné séparant très bien ses paires TRAIN (vues), son seuil calibré monte
  (0,6618) ; les paires TEST (groupes jamais vus) y passent moins souvent (55 %) que sous
  le seuil bas du zéro-shot (90 %). Conséquence sur la file `candidats_fusion.json` :
  30 paires ≥ seuil (au lieu de 1537, plafonnées à 50) — une file plus sélective, moins
  de bruit à qualifier humainement, mais qui n'affiche plus automatiquement les ~45 % de
  paires réelles TEST sous le seuil. L'écran de suggestion (top-3 par libellé, espace de
  CLASSEMENT, pas de seuil) bénéficie lui du meilleur moteur (A1/A3).
- Rappel mission §6 : les suggestions restent une file d'attente humaine, jamais une
  fusion automatique. Le seuil ne gate QUE le fichier de candidats.

### A1/A3 — paires gagnées et perdues (TEST, hors échantillon)

- Rattrapés par le fine-tuné (échouaient en zéro-shot) : « Bowling ( 2 parties ) » →
  « bowling », « COCA COLA BOITE FAT 33CL » → « Coca Cola », « Gauffre choco » →
  « Gaufre choco ».
- Perdus (réussis en zéro-shot, échouent en fine-tuné) : « RED BULL BLUE BOITE 25CL » →
  « Redbull Bleu » et « Redbull blue » → « Redbull Bleu » (confondus avec « Redbull
  Seablue »/« Redbull Blue » — seule régression A3, déjà régression-exempte côté P0).
- Toujours manqués des deux côtés (cas sémantiquement ambigus) : « MONSTER ULTRA ZERO »
  et « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » (le modèle rapproche
  « Monster Noire »), « Soiree bowling (2 parties / 1 personne) » → « bowling ».

### A4 — top paires dures encore ≥ seuil 0,6618

« Soiree bowling (2 parties / 1 personne) »/« Soiree bowling ( 2 personnes ) » 0,9377 ·
« Sucette 1 »/« Sucette 1 sucette » 0,9321 · « Minute Maid Orange »/« Minute Maid Pomme »
0,8931 · « Minute Maid »/« Minute Maid Pomme » 0,8854 · « Fanta »/« Fanta Exotique »
0,8617 — majoritairement des variantes de conditionnement/famille que seul l'humain doit
départager (25/30 candidats de la file sont étiquetés durs).

## Courbes P/R TRAIN complètes (machine-générées, 30 seuils = 30 paires TRAIN chacune)

### Zéro-shot (extrait — seuils à rappel ≥ 0,90)

| seuil | rappel | précision | TP | FP |
|---|---|---|---:|---:|
| 0,2547 | 1,000 | 0,018 | 30 | 1684 |
| 0,3044 | 0,967 | 0,025 | 29 | 1128 |
| 0,3088 | 0,933 | 0,025 | 28 | 1072 |

### Fine-tuné (extrait — seuils à rappel ≥ 0,90)

| seuil | rappel | précision | TP | FP |
|---|---|---|---:|---:|
| 0,5416 | 1,000 | 0,353 | 30 | 55 |
| 0,5488 | 0,967 | 0,349 | 29 | 54 |
| 0,6314 | 0,933 | 0,452 | 28 | 34 |
| 0,6618 | 0,900 | 0,474 | 27 | 30 |

Courbes complètes (30 lignes chacune) : sections « A2 — calibration » des deux rapports
machine (`rapport_S4.md`, `p2/rapport_S4_prime_brut.md`). À rappel TRAIN égal, le
fine-tuné divise les faux positifs par ~25 à 36.

## SEUIL CALIBRÉ NOUVEAU : **0,6618** (vs 0,3088 zéro-shot)

Même règle, mêmes données TRAIN, même espace de notation. Le seuil n'a jamais été
optimisé sur TEST (anti cherry-picking).

## Conclusion — S4' **MEILLEUR**, remplacement **OUI**

Justification chiffrée, à pareil à pareil sur le même jeu figé :

1. **A1 +5 pts** (75,0 % vs 70,0 % recall@3 TEST) et **A3 strictement meilleur**
   (45/50 vs 39/50, +6 rattrapés, régressions identiques : 1) — l'écran de suggestion
   (usage principal, classement top-3) s'améliore sans nouvelle régression.
2. **A4 −69 %** (57 vs 185 paires dures ≥ seuil) — le garde-fou anti « 5 cannettes au
   lieu d'un pack » se renforce nettement.
3. **A2 précision ×10 au seuil calibré** (33,3 % vs 3,3 %) ; la cible 85 % reste manquée
   des deux côtés (palier suivant), et le rappel au seuil recule (55 % vs 90 %) —
   trade-off documenté ci-dessus, assumé : file plus sélective, classement meilleur,
   décision finale humaine (§6).
4. **Coût identique** : 118,1 Mo vs 118,1 Mo, ~3 ms vs ~3,6 ms par requête, aucune
   dépendance nouvelle côté production (même fastembed/onnxruntime).

Mise en production locale réalisée (contrainte 4 levée après validation) :
- nouveau binaire installé dans `data/eval_t1/.cache/model-int8/` ;
- `candidats_fusion.json` régénéré avec le nouveau modèle (30 candidats, tri décroissant,
  plafond 50, mêmes exclusions Lot C) — run conservé dans `p2/rapport_S4_prime_prod.md` ;
- `rapport_S4.md` (baseline zéro-shot) conservé tel quel comme document historique — le
  zéro-shot reste re-mesurable à l'identique via `--model data/eval_t1/p2/convert/model-int8-zeroshot`
  (PREUVE exécutée après le swap : `p2/verification_zeroshot_remesure.md` reproduit
  exactement le S4 d'origine — seuil 0,3088, A1 70,0 %, A2 3,3 %/90 %, A3 39/50 (32/1),
  A4 185/199) et `p2/rapport_S4_prime_prod.md` confirme que le binaire installé en
  production donne les mêmes chiffres que le run de mesure (30 candidats identiques).

### Rollback en une commande

```bash
cp -a data/eval_t1/p2/convert/model-int8-zeroshot/. data/eval_t1/.cache/model-int8/ \
  && ~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
       --rapport-sortie data/eval_t1/p2/rollback_zeroshot_rapport.md \
       --candidats-sortie data/eval_t1/candidats_fusion.json
```

(restore le binaire zéro-shot vérifié sha256 + régénère les candidats zéro-shot ;
le nouveau modèle reste disponible dans `p2/convert/model-int8/`.)

## Vérifications d'intégrité (relançables)

- `sha256sum -c data/eval_t1/SHA256SUMS` : **12/12 OK** (jeu figé intact, avant ET après).
- `sha256sum -c data/eval_t1/p2/SHA256SUMS` : 5/5 OK (uploads Kaggle intacts).
- `sha256sum -c data/eval_t1/p2/kernel_output/SHA256SUMS` : 12/12 OK (artefacts du run intacts).
- `sha256sum -c data/eval_t1/p2/convert/SHA256SUMS_model_int8_finetune` : 5/5 OK.
- `python3 -m py_compile scripts/t1_similarity.py` : OK.
- Aucun git commit/push (contrainte P2 n°1) ; aucune écriture hors `~/AEIC` ; `.venv-t1`
  inchangé (conversion dans `.venv-p2conv` séparé, ignoré par git).

STATUT : OK — S4' **MEILLEUR** (A1 +5 pts, A3 +6 paires sans régression nouvelle, A4 −69 %,
A2 précision ×10 au seuil avec rappel au seuil en retrait — documenté ; remplacement effectué, rollback en une commande).

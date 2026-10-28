# Rapport S4″ — Fine-tuné V2 (kernel v4, vague 2 « P2-ter ») vs S4′ (v1) vs S4 (zéro-shot)

> Mission vague 2, brief `/tmp/t42_briefs/C_kaggle_v2.md` · contraintes `/tmp/t42_briefs/00_CONTRAINTES.md`
> + `/tmp/p2_briefs/00_CONTRAINTES.md` (inchangées). Amont : V2-A (4 595/400 paires, validé) +
> verdict B dev-secu CONFORME (pushs autorisés ; diff kernel v4 re-vérifié par l'orchestrateur
> sur `git diff` — trace : `diff_kernel_v4.diff`).
> Comparaison **à pareil à pareil** (leçon #7) : MÊME jeu figé `data/eval_t1/` (SHA256SUMS
> vérifiés avant/après, 12/12), MÊME script `scripts/t1_similarity.py`, MÊME protocole A1→A6,
> MÊMES cibles, MÊME graine (42), MÊME environnement de mesure (`.venv-t1` : fastembed 0.9.1 /
> onnxruntime 1.31.1 / numpy 2.5.4, CPU, Python 3.12). **Seule variable : le binaire ONNX int8
> chargé** (`--model`), entraîné sur les paires v2 (dataset versioné, anti-triche prouvé).

## 1. Chaîne d'exécution (commandes exactes, relançables)

```bash
export PATH="$HOME/.local/bin:$PATH"
# 1) NOUVELLE VERSION du dataset pairs (même id — PAS un nouveau dataset), tentative 1/2 :
kaggle datasets version -p data/eval_t1/p2/v2/kaggle_upload_v2/ \
    -m "v2: 4595 paires train + 400 val (typologies etendues vague 2 ...)" --dir-mode zip
kaggle datasets status adrienremond/aeic-t1-p2-pairs     # -> ready (fichiers 155 213 / 13 859 o = tailles locales exactes)
# 2) kernel v4 (diff vs v3 : MAX_EPOCHS 5->8, TIME_BUDGET_S 3300->3600, RIEN d'autre) :
python3 -m py_compile data/eval_t1/p2/kernel/train.py     # OK
kaggle kernels push -p data/eval_t1/p2/kernel/            # -> "Kernel version 4 successfully pushed" (tentative 1/2)
kaggle kernels status adrienremond/aeic-t1-p2-finetune    # RUNNING... -> COMPLETE observé ~48 min après le push
kaggle kernels output adrienremond/aeic-t1-p2-finetune -p data/eval_t1/p2/v2/kernel_output_v4/
# 3) conversion int8 (pipeline P4 réutilisé, chemins v2) :
~/AEIC/.venv-p2conv/bin/python3 data/eval_t1/p2/v2/convert/export_onnx_v2.py
# 4) mesure S4″ à pareil à pareil (mêmes commandes que S4/S4', seul --model change) :
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
    --model data/eval_t1/p2/v2/convert/model-int8 \
    --rapport-sortie data/eval_t1/p2/v2/rapport_S4_seconde_brut.md \
    --candidats-sortie data/eval_t1/p2/v2/candidats_fusion_seconde.json
# 5) anti-régression (modèle par défaut = v1 d'alors, sorties redirigées, rien écrasé) :
~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
    --rapport-sortie data/eval_t1/p2/v2/antiregression_S4prime_rerun.md \
    --candidats-sortie data/eval_t1/p2/v2/antiregression_candidats_v1.json
```

Rapports machine : `rapport_S4_seconde_brut.md` (mesure S4″), `rapport_S4_seconde_prod.md`
(run PROD après installation), `antiregression_S4prime_rerun.md` (v1 re-mesuré). Le présent
document est la COMPARAISON ; tout chiffre cité provient de ces sorties machine.

## 2. Run kernel v4 — résumé (metrics.json complet dans kernel_output_v4/)

| Élément | Valeur |
|---|---|
| Données | **4 595 train / 400 val** (dataset v2, D3, anti-triche OK) — kernel v3 : 1 783 |
| Config | batch 64, LR 2e-5, MNRL, graine 42, MIN_EPOCHS 3, MAX_EPOCHS 8, PATIENCE 2, budget 3 600 s |
| Epochs exécutées | **7** (max 8) — early stopping déclenché (R@1 sans gain strict depuis 2 epochs) |
| Meilleur checkpoint | **epoch 5** — val R@1 **0,9825**, R@5 0,9975, MRR 0,9887 |
| val zero-shot (avant epoch 1) | R@1 0,70 → gain **+0,2825** |
| Durée totale | **2 809,1 s** (budget 3 600 s NON atteint ; ~351 s/epoch) |
| Intégrité | sha256 model.safetensors de base vérifié dans le run ; offline strict (versions ST 5.7.0 / transformers 5.16.1 / torch 2.11.0+cpu) |

Tentatives Kaggle consommées : **dataset 1/2, kernel 1/2** (aucun retry nécessaire). Polling :
statut consulté toutes les 60 s, COMPLETE constaté ~48 min après le push (≤ budget 120 min).
Empreintes artefacts : `kernel_output_v4/SHA256SUMS` (11 fichiers, vérifiés).

## 3. Conversion int8 — pipeline P4 réutilisé (détails : `convert/tailles_v2.txt`)

- Export torch→ONNX **fp32** (BertModel sans pooler, attention eager, **opset 11** = référence,
  `do_constant_folding=False`), signature EXACTEMENT celle de prod (3 entrées int64 dynamiques,
  sortie unique `last_hidden_state` [batch, seq, 384]) ; **72 Transpose de poids foldées** (comme P4).
- Quantification **dynamique int8** (`quantize_dynamic`, QInt8, per_channel=False, onnxruntime 1.31.1).
- **Taille finale : 118 111 289 o (118,1 Mo)** — identique à v1 à l'octet près (même architecture,
  même structure de quantification), vs référence zéro-shot 118 054 609 o.
- Contrôles : parité tokenizer prod↔v2 **OK sur les 241 libellés** du jeu figé ; signature identique ;
  fidélité cos(int8 v2, fp32 v2) **min 0,936 / moy 0,985** (≥ v1 : 0,866/0,983 ; ≥ réf. Xenova :
  0,833/0,980 → export au moins aussi fidèle que le modèle actuel) ; poids bien déplacés
  cos(int8 v2, int8 v1 prod) moy 0,945 / cos(int8 v2, int8 zéro-shot) moy 0,819.
- Dossier `convert/model-int8/` (layout fastembed, 5 fichiers) — `SHA256SUMS_model_int8_v2`.

## 4. Anti-régression du protocole (brief n°4) — PARFAITE

`--evaluer` par défaut avec le modèle d'alors (v1, cache) APRÈS toutes les étapes ci-dessus :
seuil **0,6618** ; A1 **75,0 %** (15/20, recall@1 10/20) ; A2 **33,3 %** au rappel **55,0 %**
(11 TP / 22 FP) ; A3 **45/50** (38 rattrapés, 1 régression) ; A4 **57/199** ; A5 ~3,9 ms —
**IDENTIQUES au S4′ d'origine** (`antiregression_S4prime_rerun.md` vs `p2/rapport_S4_prime_brut.md`).
Candidats v1 re-générés **octet/octet identiques** à la prod d'alors, hors `generated_at`
(30 candidats, 25 durs). Le protocole par défaut n'a pas bougé.

## 5. Tableau A1–A6 — S4 (zéro-shot) / S4′ (fine-tune v1) / S4″ (fine-tune v2)

| # | Critère (cible) | S4 zéro-shot | S4′ v1 (kernel v3) | S4″ v2 (kernel v4) | S4″ vs S4′ |
|---|---|---|---|---|---|
| A1 | Rappel fusions sémantiques recall@3 TEST (≥ 90 %) | 70,0 % (14/20) | 75,0 % (15/20) | **75,0 % (15/20)** | **ÉGAL** ; recall@1 info : 10/20 → **12/20** (+2) |
| A1 détail | recall@3 / recall@1 — TRAIN (info, vu par le fine-tune) | 83,3 % / 53,3 % | 100 % / 90,0 % | 100 % / 90,0 % | ÉGAL |
| A2 | Précision TEST au seuil calibré sur TRAIN (≥ 85 %) | 3,3 % au rappel 90,0 % (18 TP / 534 FP), seuil 0,3088 | 33,3 % au rappel 55,0 % (11 TP / 22 FP), seuil 0,6618 | **47,4 % au rappel 45,0 % (9 TP / 10 FP), seuil 0,7741** | **MEILLEUR précision** (+14,1 pts, ×1,42) ; **rappel au seuil en retrait** (45 % vs 55 %) — analyse §6 |
| A2 détail | Calibration TRAIN (rappel ≥ 90 %) | seuil 0,3088 : préc. 2,5 % (28/1072) | seuil 0,6618 : préc. 47,4 % (27/30) | seuil 0,7741 : préc. **71,1 %** (27/11) | FP TRAIN ÷ ~2,7 à rappel égal |
| A3 | Supériorité sur P0 (50 paires) | 39/50, 32 rattrapés, 1 régression | 45/50, 38 rattrapés, 1 régression | **45/50, 38 rattrapés, 1 régression** | **ÉGAL strict** (même unique régression « Redbull blue » → « Redbull Bleu ») |
| A4 | Durs négatifs intra-famille ≥ seuil (0) | 185/199 (93 %) | 57/199 (29 %) | **27/199 (13,6 %)** | **MEILLEUR (−53 % vs v1, −85 % vs zéro-shot)** |
| A5 | Inférence 1 libellé vs 120 clés, CPU (< 50 ms) | ~3,6 ms | ~3,1 ms | **~3,2-3,5 ms** | **ÉGAL** (même archi, même taille) |
| A6 | Repro une commande, sorties stables | ATTEINT | ATTEINT | **ATTEINT** (§4 + §8) | ÉGAL |

Verdict par critère (S4″ vs S4′) : **A1 ÉGAL** (recall@1 info +2) · **A2 MEILLEUR en précision,
rappel au seuil en retrait** (trade-off assumé, §6) · **A3 ÉGAL** · **A4 MEILLEUR** ·
**A5 ÉGAL** · **A6 ÉGAL**. Aucun critère de CLASSEMENT (A1/A3) ne recule.

## 6. Analyse honnête

- **Objectif déclaré de la vague 2 (les 5 paires TEST ratées en recall@3) : NON atteint.** Les 5
  manquantes sont **les mêmes** que S4′ (« MONSTER ULTRA ZERO »×2 → Monster Blanche,
  « RED BULL BLUE BOITE 25CL » et « Redbull blue » → Redbull Bleu, « Soiree bowling (2 parties /
  1 personne) » → bowling). Cause structurelle documentée D'AVANCE par V2-A : le split étant par
  groupe, ces identités TEST n'ont AUCUN ancrage TRAIN — la v2 enseigne des formes sur familles
  voisines, sans garantie de généralisation. Les scores des cibles progressent légèrement
  (Monster Blanche 0,495→0,516 et 0,399→0,440) sans entrer au top-3. En revanche **recall@1 TEST
  monte de 10/20 à 12/20** : deux paires passent du rang 2-3 au rang 1.
- **A2 — trade-off du seuil calibré (même règle pour tous, jamais optimisé sur TEST)** : le v2
  sépare encore mieux ses paires TRAIN vues (précision TRAIN 71,1 % au rappel 0,90), le seuil
  monte à 0,7741 ; sur TEST : précision 47,4 % (+14,1 pts vs v1, ×14 vs zéro-shot) mais 9 TP au
  lieu de 11 (rappel au seuil 45 % vs 55 %). File `candidats_fusion.json` : **13 candidats dont
  12 durs négatifs** (vs 30 dont 25 durs en v1, plafond 50) — file PLUS sélective et PLUS propre
  à qualifier humainement. Rappel mission §6 : file d'attente humaine, jamais de fusion
  automatique ; le seuil ne gate QUE le fichier de candidats, l'écran de suggestion (top-3 par
  libellé, espace de CLASSEMENT sans seuil) bénéficie du meilleur moteur (A1/A3/recall@1).
- **A4 — garde-fou anti « 5 cannettes au lieu d'un pack » nettement renforcé** : 27 paires dures
  ≥ seuil (13,6 %) contre 57 (29 %) en v1. Têtes inchangées (variantes conditionnement/famille
  que seul l'humain doit départager : Soiree bowling 0,947, Sucette 0,917, Oasis Ice Tea 0,869…).
- Coût identique : 118,1 Mo, ~3 ms/requête, aucune dépendance nouvelle côté production.

## 7. Conclusion — S4″ **MEILLEUR**, remplacement **EFFECTUÉ**

1. **A4 −53 %** (27 vs 57 durs ≥ seuil) et **A2 précision ×1,42 au seuil** (47,4 % vs 33,3 %) —
   les deux garde-fous de la file de candidats se renforcent sans nouvelle charge humaine
   (file plus courte ET plus propre).
2. **Aucun recul de classement** : A1 75,0 % et A3 45/50 (38/1) identiques, recall@1 TEST +2,
   même unique régression A3 que v1 — l'écran de suggestion (usage principal) ne régresse pas.
3. **Coût identique** (118,1 Mo, ~3 ms, mêmes fastembed/onnxruntime).
4. Trade-off documenté et assumé : rappel au seuil 45 % vs 55 % (2 TP TEST de moins listés
   automatiquement), file plus sélective — décision finale humaine (§6) inchangée.

Mise en production locale (comme P4) :
- binaire v2 installé dans `data/eval_t1/.cache/model-int8/` (sha256 `fcbcd1f9…`, §8) ;
- `data/eval_t1/candidats_fusion.json` régénéré avec le modèle installé (13 candidats tri
  décroissant, plafond 50, mêmes exclusions Lot C) — run conservé `rapport_S4_seconde_prod.md` :
  chiffres **identiques** au run de mesure (seuil 0,7741, A1 15/20, A2 47,4 %/45 %, A3 45/50,
  A4 27/199, 13 candidats) ;
- `data/eval_t1/rapport_S4.md` (baseline zéro-shot historique) et `p2/rapport_S4_prime_brut.md`
  (v1) conservés tels quels ; le zéro-shot reste re-mesurable via
  `--model data/eval_t1/p2/convert/model-int8-zeroshot`, le v1 via
  `--model data/eval_t1/p2/convert/model-int8` (preuve §4 exécutée après toutes les étapes).

### Rollback en une commande (retour au v1)

```bash
cp -a data/eval_t1/p2/convert/model-int8/. data/eval_t1/.cache/model-int8/ \
  && ~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
       --rapport-sortie data/eval_t1/p2/v2/rollback_v1_rapport.md \
       --candidats-sortie data/eval_t1/candidats_fusion.json
```

(le binaire v2 reste disponible dans `p2/v2/convert/model-int8/` ; retour zéro-shot :
`cp -a data/eval_t1/p2/convert/model-int8-zeroshot/. data/eval_t1/.cache/model-int8/` + même
régénération — arborescence documentée depuis P4.)

## 8. Vérifications d'intégrité (relançables)

- `sha256sum -c data/eval_t1/SHA256SUMS` : **12/12 OK** (jeu figé intact, avant ET après tout).
- `sha256sum -c data/eval_t1/p2/SHA256SUMS` : 5/5 · `p2/v2/SHA256SUMS` : 5/5 ·
  `p2/v2/kernel_output_v4/SHA256SUMS` : 11/11 · `p2/v2/convert/SHA256SUMS_model_int8_v2` : 5/5 ·
  `p2/v2/SHA256SUMS_V2C` : 9/9 (livrables vague C).
- Anti-triche relancé après tout : `scripts/t1_augment.py --v2 --verifier` → **OK** (4 595 + 400
  lignes, zéro forme TEST/résiduel/collision).
- `python3 -m py_compile data/eval_t1/p2/kernel/train.py` : OK (inventaire kernel = 2 fichiers
  exactement, pas de `__pycache__`).
- Aucun git commit/push (contrainte vague 2 n°1) ; aucune écriture hors `~/AEIC` (CLI kaggle
  excepté, autorisé) ; `.venv-t1` inchangé (conversion dans `.venv-p2conv`).
- Empreintes binaires décisives : prod APRÈS swap = `fcbcd1f9a880233866a5f906b6b7618b1e145d4e04fa7f13f4f3a3fdcf0d11c0`
  (= `p2/v2/convert/model-int8/model_optimized.onnx`) ; rollback v1 = `b79243f9361d3d3b9250c4317756c6d1fb68523fc27793106ad8d79ef47d3934`
  (`p2/convert/model-int8/model_optimized.onnx`, intact).

## 9. Échecs/résidus documentés (honnêteté, contrainte vague 2 n°5)

- Les 5 paires TEST recall@3 restent manquées (§6) — l'échec de CETTE cible n'est pas un échec
  de vague : le run v4 est complet (7 epochs, early stopping, budget respecté) et S4″ est
  globalement MEILLEUR ; la vague livre un modèle meilleur + un diagnostic précis (typologies
  sans ancrage TRAIN = limite du split par groupe au volume actuel de vérité terrain).
- A2 cible 85 % : toujours MANQUÉE (palier suivant) ; rappel au seuil en retrait vs v1 (§6).

STATUT : OK — S4″ **MEILLEUR** que S4′ (A4 27 vs 57 durs ≥ seuil −53 %, précision A2 47,4 % vs
33,3 % au seuil, recall@1 TEST 12/20 vs 10/20, A1/A3 strictement égaux sans nouvelle régression,
coût identique 118,1 Mo / ~3 ms) ; remplacement prod EFFECTUÉ (cache + candidats_fusion.json
13 candidats, rollback v1 en une commande) ; les 5 paires TEST cibles restent manquées —
documenté honnêtement (aucun ancrage TRAIN, split par groupe).

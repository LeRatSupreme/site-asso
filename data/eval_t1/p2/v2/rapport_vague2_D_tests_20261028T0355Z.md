# Rapport final de vague 2 « P2-ter » — validation V2-D (dev-tests), 2026-10-28T03:55Z

Brief : `/tmp/t42_briefs/D_tests_v2.md` — contraintes `/tmp/t42_briefs/00_CONTRAINTES.md`.
Base : `aeic_test` EXCLUSIVEMENT (port 3307, méthode officielle sed-port). Prod jamais touchée.
Jeu figé `data/eval_t1/` vérifié SHA256 **12/12 AVANT et APRÈS** toutes les étapes ci-dessous.
Toutes les commandes sont relançables depuis la racine du dépôt `/home/ubuntu/AEIC`.
**Aucun commit effectué** (contrainte vague 2 n°1) — liste exacte au §6.

## 1. Suite complète sed-port (point 1 du brief)

```bash
sed 's|name="DB_HOST" value="127.0.0.1"|name="DB_HOST" value="127.0.0.1:3307"|' phpunit.xml > .phpunit-t1-local.xml
DB_PASS=aeic php phpunit.phar -c .phpunit-t1-local.xml --no-coverage
rm -f .phpunit-t1-local.xml
```

**État initial (avant correction) : 1380 tests, 7608 assertions, 1 échec.**

```
1) Tests\Integration\AssistantToolsTest::test_doublons_en_attente_expose_les_paires_vivantes_sans_decider
Score 0,8854 → ressemblance 89 (arrondi).
Failed asserting that 85 is identical to 89.
```

**Cause (pas un bug métier)** : le test (T4-P1, commit 7d4996c) figeait `ressemblance = 89`,
dérivé du score v1 (0,8854) du livrable `data/eval_t1/candidats_fusion.json`. Le modèle prod
ayant été remplacé par v2 (swap ordonné, rapport C), le livrable régénéré porte la paire
« Minute Maid / Minute Maid Pomme » à **0,8526 → 85**. La convention du dépôt est explicite
(T1DecideTest : « sans figer le score d'un modèle dans le test ») — le test fautif violait
cette convention.

**Correction (dans `tests/`, mon périmètre)** : `AssistantToolsTest` relit désormais le score
attendu dans le livrable réel (paire identifiée par ses deux côtés triés) et asserte
`ressemblance == (int) round(score × 100)` — strict et pérenne pour tout futur modèle.
+21/−3 lignes, aucun code métier touché.

**État final : OK — 1380 tests, 7613 assertions, 0 échec** (2 min 01 s).

## 2. Livrable candidats_fusion.json (modèle v2) — point 2 du brief

```bash
jq -e '.' data/eval_t1/candidats_fusion.json > /dev/null        # JSON VALIDE
jq '.candidats | length' data/eval_t1/candidats_fusion.json     # 13  (≤ 50 : OK)
jq -e '[.candidats[].score] as $s | ($s == ($s | sort | reverse))' data/eval_t1/candidats_fusion.json  # true → tri desc OK
jq -r '.modele, .seuil, .generated_at' data/eval_t1/candidats_fusion.json
```

Résultat : **JSON valide, 13 candidats (≤ 50), tri descendant strict**, seuil 0,7741,
modèle `paraphrase-multilingual-MiniLM-L12-v2 (ONNX int8 via fastembed 0.9.1)`,
`generated_at` 2026-10-28T03:39:53+00:00.

Sous-ensemble T1 ciblé du brief : `--filter "AssistantToolsTest|T1DecideTest|T1SuggestionsTest"`
→ **OK (39 tests, 229 assertions, 0 échec)** — dont les 20 AssistantTools et le rendu réel
de la page Inventaire par T1DecideTest.

## 3. Fumigène écran inventaire (rendu réel, grep marqueurs) — point 2 du brief

Commande unique rejouable (le script sème `aeic_test` avec garde anti-production, rend la
page via le routeur réel `tests/Integration/runner.php` en session SUPERADMIN, quarantaine
redirigée vers `tests/cache/t15/`, livrable réel lu tel quel) :

```bash
bash data/eval_t1/p2/v2/fume_inventaire_v2.sh
```

**Résultat : FUMIGÈNE OK — 12/12 marqueurs, 0 échec** (HTTP 200 ; carte « Suggestions de
rapprochement » + ancre `#suggestions-rapprochement` + `id="t1-suggestions-card"` ; badge
`ressemblance 85 %` = score v2 du livrable ; paire Minute Maid / Minute Maid Pomme rendue ;
2 formulaires de décision `/t1/decide` avec CSRF ; carte « Fusionner des clés » présente ;
aucune mention interdite « intelligence artificielle »).

## 4. État git (preuve « le moteur ne touche pas au PHP »)

```bash
git status --porcelain=v1
 M data/eval_t1/candidats_fusion.json      # livrable régénéré (swap prod v2, rapport C)
 M data/eval_t1/p2/kernel/train.py         # diff kernel v4 (rapport C)
 M scripts/t1_augment.py                   # --v2 (rapport A)
 M tests/Integration/AssistantToolsTest.php# correction §1 (seul fichier tests/)
?? data/eval_t1/p2/v2/                     # livrables vague 2 (binaires ignorés par .gitignore)
```

Aucun fichier `app/`, `views/`, `app/config/routes.php` modifié : le modèle/moteur ne
touche pas au PHP, seule la donnée du livrable change. NB vs libellé du brief :
`t1_similarity.py` n'est PAS modifié (le port `--model` était déjà commis en vague 1) ;
en revanche `candidats_fusion.json` (swap) et `kernel/train.py` (v4) le sont — les deux
attendus du rapport C.

## 5. Tableau livrables × preuves × commandes (vague 2 complète)

| Livrable | Preuve | Commande relançable |
|---|---|---|
| Dataset v2 (×2-3, typologies étendues) 4 595 train + 400 val, zéro collision TEST | `p2/v2/rapport_augmentation_v2.md`, `p2/v2/train_pairs.csv`, `val_pairs.csv`, SHA256SUMS | `.venv-t1/bin/python3 scripts/t1_augment.py --v2 --verifier` → OK (zéro fuite/résiduel/collision) |
| Analyse d'erreurs (5 paires TEST ratées, typologie cible) | `p2/v2/analyse_erreurs.md` | lecture du rapport (sources : S4′) |
| Revue sécurité dataset+typologies | `p2/v2/REVUE_SECU_V2B_20261028T0126Z.md` → verdict CONFORME | lecture du rapport |
| Kaggle dataset v2 (même id `aeic-t1-p2-pairs`) | version 2 ready, 155 213/13 859 o = tailles locales | `kaggle datasets version -p data/eval_t1/p2/v2/kaggle_upload_v2/ …` (trace §1 rapport_S4_seconde.md) |
| Kernel v4 (epochs 8, early stop, budget 3 600 s) | `p2/v2/diff_kernel_v4.diff` (revu avant push), `kernel_output_v4/metrics.json` : 7 epochs, meilleur epoch 5, val R@1 0,9825, 2 809,1 s | `python3 -m py_compile data/eval_t1/p2/kernel/train.py` ; polling `kaggle kernels status` |
| Conversion int8 v2 | `p2/v2/convert/` 118 111 289 o, fidélité min 0,936/moy 0,985, parité tokenizer 241/241, SHA256SUMS_model_int8_v2 | `.venv-p2conv/bin/python3 data/eval_t1/p2/v2/convert/export_onnx_v2.py` |
| Mesure S4″ à pareil à pareil + anti-régression v1 parfaite | `p2/v2/rapport_S4_seconde.md` (S4/S4′/S4″ + verdicts/critère), `antiregression_S4prime_rerun.md` | `.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer --model data/eval_t1/p2/v2/convert/model-int8 --rapport-sortie … --candidats-sortie …` |
| Verdict S4″ MEILLEUR → swap prod + rollback documenté | cache `data/eval_t1/.cache/model-int8/` = v2 (`fcbcd1f9…`), `rapport_S4_seconde_prod.md` (prod = mesure), rollback §7 | `.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer --rapport-sortie …` (défaut = cache prod) |
| **Validation D (ce rapport)** | suite 1380/0 échec, candidats jq/tri/≤50 OK, fumigène 12/12, jeu figé 12/12 avant/après | §1, §2, §3 ci-dessus |

Échec documenté honnêtement (hérité C) : les 5 paires TEST cibles restent manquées en
recall@3 (groupes TEST sans ancrage TRAIN) — identiques à S4′, aucune régression.

## 6. Liste EXACTE des fichiers à committer (étape dédiée — je n'ai PAS committé)

Fichiers suivis modifiés (4) :
1. `data/eval_t1/candidats_fusion.json`
2. `data/eval_t1/p2/kernel/train.py`
3. `scripts/t1_augment.py`
4. `tests/Integration/AssistantToolsTest.php`

Ajouts `data/eval_t1/p2/v2/` (34 — les binaires .onnx/.safetensors/tokenizer.json sont
exclus automatiquement par .gitignore, déjà en place) :
`REVUE_SECU_V2B_20261028T0126Z.md`, `SHA256SUMS`, `SHA256SUMS_V2C`, `analyse_erreurs.md`,
`antiregression_S4prime_rerun.md`, `antiregression_candidats_v1.json`,
`candidats_fusion_seconde.json`, `convert/SHA256SUMS_model_int8_v2`,
`convert/export_onnx_v2.py`, `convert/model-int8/config.json`,
`convert/model-int8/special_tokens_map.json`, `convert/model-int8/tokenizer_config.json`,
`convert/tailles_v2.txt`, `diff_kernel_v4.diff`, `fume_inventaire_v2.sh`,
`kaggle_upload_v2/dataset-metadata.json`, `kaggle_upload_v2/train_pairs.csv`,
`kaggle_upload_v2/val_pairs.csv`, `kernel_output_v4/SHA256SUMS`,
`kernel_output_v4/metrics.json`, `kernel_output_v4/model/1_Pooling/config.json`,
`kernel_output_v4/model/README.md`, `kernel_output_v4/model/config.json`,
`kernel_output_v4/model/config_sentence_transformers.json`,
`kernel_output_v4/model/modules.json`, `kernel_output_v4/model/sentence_bert_config.json`,
`kernel_output_v4/model/tokenizer_config.json`, `manifeste_v2.md`,
`rapport_S4_seconde.md`, `rapport_S4_seconde_brut.md`, `rapport_S4_seconde_prod.md`,
`rapport_augmentation_v2.md`, `train_pairs.csv`, `val_pairs.csv`
— plus ce rapport : `rapport_vague2_D_tests_20261028T0355Z.md`.

Commande d'ajout équivalente : `git add data/eval_t1/candidats_fusion.json
data/eval_t1/p2/kernel/train.py scripts/t1_augment.py tests/Integration/AssistantToolsTest.php
data/eval_t1/p2/v2/`

Message suggéré (style du dépôt, français, court) :

> `feat(t1): detecteur doublons v2 en prod - dataset x2 anti-triche, kernel v4 early stop, int8 v2 (S4″ meilleur), score non figé dans les tests`

## 7. Risques résiduels

- Le score affiché « ressemblance 85 % » re-figera le fumigène si un futur modèle re-régénère
  le livrable (marqueur explicite « score v2 du livrable affiché (85 %) » à mettre à jour
  dans `fume_inventaire_v2.sh`) ; les tests PHPUnit, eux, sont désormais agnostiques du modèle.
- `.phpunit-t1-local.xml` recréé puis supprimé à chaque commande (jamais committé).
- L'échec des 5 paires TEST (§5) reste le point ouvert de la vague — aucune contre-mesure
  possible au volume de vérité terrain actuel, documenté dans `rapport_S4_seconde.md` §6/§9.

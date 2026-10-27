# Kernel P3 **v3** — Fine-tune AEIC T1-P2 sur Kaggle, 100 % OFFLINE CPU

- Auteur : `dev-factures` (brief `/tmp/p2_briefs/P3B_prep.md`, contraintes `/tmp/p2_briefs/00_CONTRAINTES.md`)
- Date : 2026-10-27 (VPS, `/home/ubuntu/AEIC`)
- Statut : **PRÊT, NON POUSSÉ** — la relecture dev-secu de `kernel/train.py` précède le push
  (contrainte n°2 ; le brief P3B interdit explicitement le push à cette étape).

## 1. Contexte — pourquoi une v3 offline

Tentatives 1/2 (cf. `kernel_README.md` §SUITE DE MISSION) : le worker Kaggle n'a **NI internet
NI GPU** (flags silencieusement ignorés — compte probablement non vérifié par téléphone). Le
dataset privé se monte pourtant bien, et l'image embarque **sentence-transformers 5.7.0 +
transformers 5.16.1 + torch 2.11.0+cpu** préinstallés. v1 morte au `pip install` (DNS KO),
v2 morte au téléchargement HF du modèle de base.

Pivot v3 : **tout offline** — le modèle de base voyage en dataset privé attaché, l'entraînement
tourne sur CPU (1 783 paires ⇒ volume très largement jouable), zéro pip, zéro réseau.

## 2. Modèle de base — `data/eval_t1/p2/base_model/`

- Source EXACTE : repo Hugging Face officiel
  `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`
  (branche `main`), téléchargé le 2026-10-27 depuis le VPS via
  `https://huggingface.co/sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2/resolve/main/<fichier>`.
- **Poids complets fp32 en `model.safetensors`** (470 641 600 octets) — PAS l'ONNX int8
  (dossiers `onnx/` et `openvino/` du repo volontairement exclus), ni `pytorch_model.bin`
  (doublon), ni `tf_model.h5`.
- Bundle minimal au format sentence-transformers (10 fichiers) — `modules.json` référence
  `0` = Transformer à la racine et `1` = Pooling `1_Pooling/` :

| Fichier | Taille | sha256 | Recoupement source |
|---|---|---|---|
| `model.safetensors` | 470 641 600 | `eaa086f0ffee…bd955917b` | **= oid LFS HF** ✓ |
| `tokenizer.json` | 9 081 518 | `2c3387be7655…429076b8` | **= oid LFS HF** ✓ |
| `sentencepiece.bpe.model` | 5 069 051 | `cfc8146abe2a…ce0db2865` | **= oid LFS HF** ✓ |
| `config.json` | 645 | `6300193cb75e…134476e4e` | taille = API HF ✓ |
| `config_sentence_transformers.json` | 122 | `b8c64b5cece…3e6d36b8` | taille = API HF ✓ |
| `modules.json` | 229 | `8f4b264b8020…9b3145460` | taille = API HF ✓ |
| `sentence_bert_config.json` | 53 | `70f4448f3132…b0aa5a1f` | taille = API HF ✓ |
| `special_tokens_map.json` | 239 | `378eb3bf733e…f22ae554d` | taille = API HF ✓ |
| `tokenizer_config.json` | 526 | `5036ea374ffe…a37a6b9b` | taille = API HF ✓ |
| `1_Pooling/config.json` | 190 | `4be450dde3b0…350ffc23` | taille = API HF ✓ |

Empreintes complètes vérifiables : `data/eval_t1/p2/SHA256SUMS_MODELE_BASE`
(`cd /home/ubuntu/AEIC && sha256sum -c data/eval_t1/p2/SHA256SUMS_MODELE_BASE` → 13/13 OK).

Contrôles structurels effectués (python local, sans dépendances) :
- header safetensors parsé : 200 tenseurs (architecture XLM-R MiniLM-L12) ;
- les 8 fichiers JSON chargent ; `modules.json` cohérent avec l'arborescence ;
- `train.py` v3 re-vérifie au runtime le sha256 de `model.safetensors` (`FATAL` si divergence).

## 3. Dataset privé du modèle — `adrienremond/aeic-t1-ml-base`

- Dossier : `data/eval_t1/p2/base_model_upload/` = `dataset-metadata.json`
  (`id: adrienremond/aeic-t1-ml-base`, `isPrivate: true`, licence Apache-2.0 du modèle)
  + copie **octet/octet** de `base_model/` (diff -r : IDENTIQUES).
- C'est un artefact open-source public (poids officiels HF) — **aucune donnée utilisateur**
  (justification posée par le brief P3B, l'envoi était donc autorisé à cette étape).
- **Incident & correction** : premier `kaggle datasets create` a IGNORÉ le sous-dossier
  `1_Pooling/` (« Skipping folder … use '--dir-mode' ») → bundle inutilisable. Dataset
  incomplet **supprimé** (`kaggle datasets delete`), puis recréé avec
  **`--dir-mode zip`** (Kaggle décompresse l'archive côté serveur).
- État final : **`ready`**, privé, 10 fichiers avec `1_Pooling/config.json` présent et
  `model.safetensors` à 470 641 600 octets exacts (`kaggle datasets files` vérifié).

## 4. Kernel v3 — `data/eval_t1/p2/kernel/` (remplace v2, même id)

Inventaire : **EXACTEMENT 2 fichiers** (`kernel-metadata.json`, `train.py`) — pas de README,
pas de `__pycache__` (vérifié après `python3 -m py_compile` + nettoyage).

### kernel-metadata.json

`id: adrienremond/aeic-t1-p2-finetune` (inchangé), privé, **`enable_gpu: false`** (honnête :
le build torch est cpu), **`enable_internet: false`** (offline assumé),
`dataset_sources: ["adrienremond/aeic-t1-p2-pairs", "adrienremond/aeic-t1-ml-base"]`.

### train.py v3 — design

- **Zéro pip / zéro réseau** : imports top-level stdlib uniquement
  (`csv, glob, hashlib, json, os, random, time`) ; aucun `socket/urllib/requests/subprocess` ;
  `HF_HUB_OFFLINE=1` + `TRANSFORMERS_OFFLINE=1` posés avant les imports (ceinture+bretelles).
- **Données inchangées** : uniquement les 2 CSV du dataset pairs (assert colonnes
  `texte_variante,cible`) + le dossier modèle du dataset ml-base ; localisation des datasets
  par les deux layouts Kaggle connus (`/kaggle/input/datasets/<owner>/<slug>` — constaté au
  run v2 — puis `/kaggle/input/<slug>`), sinon `SystemExit` avec inventaire diagnostic.
- **Protocole d'entraînement inchangé vs v2** : MNRL (anchor=`texte_variante`,
  positive=`cible`), batch 64, lr 2e-5, warmup total 10 % de steps×epochs, graine 42 partout,
  epochs min 3 / **max 5** (brief v3 : 3-5 max), early stopping = R@1 d'identification sur val,
  patience 2, seuil min 3 epochs ; zero-shot val mesuré avant epoch 1 ; `cos_loss`
  train+val + R@1/R@5/MRR par epoch ; meilleur modèle (gain strict de R@1) sauvegardé ;
  sorties `/kaggle/working/model` (format sentence-transformers complet, safetensors) +
  `metrics.json` (schéma v2 + champs offline/intégrité/budget).
- **Nouveautés CPU** : `torch.set_num_threads(os.cpu_count())` ; `use_amp=False` ;
  **garde-fou horloge 3 300 s** (arrêt avant epoch, meilleur modèle conservé,
  `time_budget_hit` rapporté).
- Budget estimé : 1 783 paires / batch 64 = 28 steps/epoch × ≤5 epochs = ≤140 steps ;
  à 2-5 s/step CPU (libellés courts, smart batching par lot) ⇒ ~5-15 min d'entraînement +
  encodages d'éval + 2-3 saves ≪ 60 min.

### Compatibilité ST 5.7.0 / transformers 5.16.1 — vérifiée SUR PIECES

La wheel PyPI `sentence-transformers==5.7.0` a été téléchargée (artefact d'audit conservé :
`data/eval_t1/p2/_vendor_st570/sentence_transformers-5.7.0-py3-none-any.whl`) et son code
source inspecté. Constats :

| Point vérifié (fichier:ligne de la wheel) | Décision v3 |
|---|---|
| `sentence_transformer/fit_mixin.py:161` — le nouveau `fit()` **exige la lib `datasets`** (`is_datasets_available()` sinon ImportError) ; `datasets` n'est qu'un extra (`extra=="train"`), pas garantie préinstallée → le `model.fit(...)` de la v2 **aurait échoué** sur ce worker | **Abandonné** |
| `sentence_transformer/fit_mixin.py:465` — `old_fit()` : boucle torch pure, **aucune référence `datasets`/`accelerate`** dans son corps (grep vide) ; signature avec `warmup_steps`, `optimizer_params`, `use_amp`, `show_progress_bar`, `max_grad_norm=1`, AdamW, WarmupLinear | **API d'entraînement retenue**, appelée 1× par epoch (contrôle early-stopping/save identique v2) |
| `sentence_transformer/fit_mixin.py:437` — `smart_batching_collate` existe (appliquée automatiquement par `old_fit` au DataLoader) | DataLoader d'`InputExample` sans collate custom |
| `readers/input_example.py:14` — `InputExample(guid, texts, label)` ; export top-level (`__init__.py:25`) | `from sentence_transformers import InputExample, SentenceTransformer` |
| `sentence_transformer/losses/__init__.py:32` + alias deprecations (`util/deprecated_import.py`) — MNRL importable du chemin canonique 5.x **et** de l'alias historique | try/except double import, les deux validés |
| `losses/multiple_negatives_ranking.py:17,232` — `MNRL(model)` défaut = InfoNCE in-batch (scale 20, cos_sim) ; `labels` non utilisé dans la perte par défaut | sémantique MNRL v2 conservée à l'identique |
| `base/model.py:626` — `save(path, …, safe_serialization=True)` ; `model.py:344+` — `encode(batch_size, convert_to_numpy, normalize_embeddings, show_progress_bar)` | mêmes appels que v2 |

Versions lues au **runtime** (jamais épinglées dans le code v3) et consignées dans
`metrics.json` (`versions_reelles`) ; le kernel rend compte de l'écart éventuel sans en dépendre.

## 5. Auto-audit anonymisation du dossier `kernel/` (batterie P2-SEC, exécutée en local)

| Contrôle | Résultat |
|---|---|
| Inventaire `ls -A kernel/` | **PASSÉ** — 2 fichiers exactement, 0 caché, 0 `__pycache__` |
| Fournisseurs (`metro\|auchan\|carrefour\|lidl\|leclerc\|intermarch\|casino\|sumup`) | **PASSÉ** — 0 hit |
| Prix `[0-9]+[.,][0-9]{2}` | **PASSÉ avec 2 faux positifs code** : `5.16.1` (version transformers en docstring), `0.10` (fraction warmup) — aucun montant |
| Emails / UUID / user_id / password / secret / api_key / `\btoken\b` | **PASSÉ** — 0 hit (word-boundary ne matche pas « tokenizer ») |
| Prénom propriétaire (`adrien\|remond`) | 5 hits = slug Kaggle `adrienremond/…` des ids de datasets/kernel — **déjà présents dans le bundle v1/v2 accepté** (identifiant du compte Kaggle du projet, pas une donnée personnelle de la base AEIC) |
| Libellés produits (univers A2 : coca, pepsi, haribo, cacolac, …) | **PASSÉ** — 0 hit |
| Imports top-level | stdlib uniquement ; torch/ST importés dans `main()` |
| `open()` | 3 appels : sha256 modèle (rb), 2 CSV pairs attendus, écriture metrics.json |
| Réseau/pip | aucun import ni appel réseau ; seules mentions « pip/réseau » = docstring déclarative |

**Contenu de données embarqué dans le kernel : AUCUN.** Le script ne référence que slugs de
datasets privés, nom public du modèle HF, sha256, noms de colonnes, hyperparamètres.

## 6. Commandes (relançables)

```bash
export PATH="$HOME/.local/bin:$PATH"
cd /home/ubuntu/AEIC
# modèle (fait) :
#   téléchargé depuis https://huggingface.co/sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
kaggle datasets status adrienremond/aeic-t1-ml-base      # -> ready
kaggle datasets files adrienremond/aeic-t1-ml-base       # 10 fichiers, 1_Pooling inclus
# kernel (PAS FAIT — attente relecture dev-secu, contrainte n°2) :
kaggle kernels push -p data/eval_t1/p2/kernel/
kaggle kernels status adrienremond/aeic-t1-p2-finetune
kaggle kernels output adrienremond/aeic-t1-p2-finetune -p data/eval_t1/p2/kernel_output/
```

## 7. Risques résiduels connus

- `old_fit` est marquée « deprecated » par ST (elle existe en 5.7.0, vérifiée) ; si l'image
  Kaggle change un jour de version majeure ST, le try/except d'import MNRL ne suffira pas —
  pas de risque pour ce run (versions préinstallées constatées au run v2).
- Si le worker monte le dataset ml-base sous un layout inédit (ni `datasets/<owner>/<slug>`
  ni `<slug>`), `dataset_root()` sortira proprement avec l'inventaire du `/kaggle/input`
  dans le log (diagnostic immédiat, pas d'échec silencieux).
- L'honnêteté des mesures (contrainte n°6) sera assurée au rapport S4' : ce kernel entraîne
  sur le MÊME jeu figé avec le MÊME protocole d'éval que le S4 zéro-shot — pas de
  cherry-picking ; le zero-shot est re-mesuré dans le run lui-même.

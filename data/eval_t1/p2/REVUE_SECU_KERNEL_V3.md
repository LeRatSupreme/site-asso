# REVUE_SECU_KERNEL_V3.md — dev-secu — Revue avant push : kernel v3 + dataset modèle de base

- Date : 2026-10-27 (VPS)
- Reviewer : `dev-secu` (brief `/tmp/p2_briefs/P3B_secu.md`, contraintes `/tmp/p2_briefs/00_CONTRAINTES.md`)
- Objets : `data/eval_t1/p2/kernel/` (v3, NON poussé), `data/eval_t1/p2/base_model_upload/` (dataset `adrienremond/aeic-t1-ml-base` déjà envoyé), état distant Kaggle.
- Grille : même que la revue kernel précédente (`VERDICT_KERNEL.md`, 27/10).

## Tableau contrôle × statut × preuve

| # | Contrôle | Statut | Preuve (commande relançable) |
|---|---|---|---|
| 1 | Inventaire kernel/ EXACT : 2 fichiers, 0 binaire, 0 pyc | OK | `ls -A kernel/` → `kernel-metadata.json` `train.py` ; `file *` → JSON text + Python UTF-8 ; `find . -mindepth 1` → rien d'autre ; après `python3 -m py_compile` : `rm -rf __pycache__` puis `ls -A` re-vérifié = 2 fichiers |
| 2 | 0 donnée produit dans train.py | OK | Script python : 1 948 libellés uniques (≥4 car) extraits de `kaggle_upload/{train,val}_pairs.csv` → 1 seul hit `Zero` = **mot anglais** (lignes 13/47/231/… : « ZERO pip », « zero-shot »), mots distinctifs ≥5 lettres → **AUCUN** ; batterie fournisseurs/sumup/IP/emails/UUID/prix/€/chemins VPS (`/home/`, `ubuntu@`, `ssh `, `51.254`) → **AUCUN** |
| 3 | 0 secret | OK | `grep -inE "api[_-]?key|token|password|secret|bearer|authorization|credential"` → AUCUN |
| 4 | kernel-metadata.json SÛRE | OK | JSON valide ; `is_private: true` ; `dataset_sources` = `["adrienremond/aeic-t1-p2-pairs", "adrienremond/aeic-t1-ml-base"]` (les 2 datasets privés attendus) ; `enable_gpu: false` ; `enable_internet: false` — cohérent offline CPU ; pas de `collaborators` |
| 5 | train.py : lecture `/kaggle/input` uniquement | OK | Seule racine d'accès = `INPUT_ROOT = "/kaggle/input"` (constantes l.78-80) ; `dataset_root()` ne teste que 2 candidats sous `/kaggle/input` ; `load_pairs()`/`sha256_of()` appelés uniquement sur ces chemins ; glob de diagnostic read-only sous `/kaggle/input` |
| 6 | train.py : écriture `/kaggle/working` uniquement | OK | `open(...,"w")` unique → `METRICS_PATH=/kaggle/working/metrics.json` ; `model.save()` → `MODEL_DIR=/kaggle/working/model` (l.277, 291) ; aucun `makedirs`/`shutil`/`remove` |
| 7 | ZÉRO réseau dans train.py | OK | Imports = stdlib (`csv glob hashlib json os random time`) + préinstallés (`numpy torch sentence_transformers transformers torch.utils.data`) ; `grep -nE "pip|subprocess|urllib|socket|requests|http|wget|curl|os.system|popen"` → uniquement docstring/commentaires ; `HF_HUB_OFFLINE=1`/`TRANSFORMERS_OFFLINE=1` posés AVANT les imports lourds (l.56-57) |
| 8 | Graine fixée | OK | `random.seed(42)`, `np.random.seed(42)`, `torch.manual_seed(42)`, `cuda.manual_seed_all(42)`, cudnn déterministe (l.147-152) |
| 9 | Aucun print de libellés | OK | Revue exhaustive des 20 `print` : compteurs, chemins de datasets montés, versions, métriques flottantes, sha256 — jamais le contenu des paires ; `metrics.json` = ids/counts/config/métriques, 0 libellé |
| 10 | Compatibilité préinstallés **vérifiée sur la wheel** | OK | Artéfact `_vendor_st570/sentence_transformers-5.7.0-py3-none-any.whl` : `SentenceTransformer` et `InputExample` importables à la racine ; **MNRL exporté** dans `sentence_transformers/sentence_transformer/losses/__init__.py` (= chemin primaire du `try` du kernel) ; `class SentenceTransformer(BaseModel, FitMixin)` → **`old_fit` existe** pour le bi-encodeur (`sentence_transformer/fit_mixin.py`) : boucle torch pure (DataLoader/AdamW/WarmupLinear/clip 1.0), **0 import `datasets`, 0 `accelerate`, 0 réseau**, `use_amp=False` géré |
| 11 | base_model_upload = fichiers HF publics SEULEMENT | OK | `diff -r base_model base_model_upload` → identiques octet/octet (seul `dataset-metadata.json` ajouté) ; les 10 fichiers existent TOUS dans le tree API HF officiel `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, tailles 10/10 identiques (470 641 600 / 9 081 518 / 5 069 051 / 645 / 526 / 239 / 229 / 190 / 122 / 53) ; **sha256 des 3 fichiers LFS = oid LFS officiels HF** (`eaa086f0…95917b`, `2c3387be…9076b8`, `cfc8146a…ce0db2865`) ; exclus volontairement : `onnx/`, `openvino/`, `pytorch_model.bin`, `tf_model.h5`, `unigram.json` ; **0 fichier exfiltré du VPS** (liste blanche stricte) ; runtime sha256 de train.py = oid officiel |
| 12 | dataset-metadata (ml-base) SÛRE | OK | `isPrivate: true`, `id: adrienremond/aeic-t1-ml-base`, licence Apache-2.0 (celle du modèle), pas de `collaborators` |
| 13 | Rien d'autre n'a fui côté Kaggle | OK | `kaggle datasets list --mine` → EXACTEMENT `aeic-t1-ml-base` + `aeic-t1-p2-pairs` (aucun autre) ; `datasets status` ×2 → `ready` ; `kaggle datasets files aeic-t1-ml-base` → 10/10 fichiers tailles exactes, `1_Pooling/config.json` présent côté serveur ; `kaggle kernels list --mine` → 1 seul kernel `aeic-t1-p2-finetune` |
| 14 | Jeu figé intact (contrainte n°3) | OK | `sha256sum -c` depuis racine repo : `data/eval_t1/SHA256SUMS` 12/12 OK, `p2/SHA256SUMS` 5/5 OK, `SHA256SUMS_MODELE_BASE` 13/13 OK (les 13 incluent kernel v3 + les 2 metadata — figures gelées) |

## Observations mineures (non bloquantes, aucune correction exigée)

1. **MINEUR (doc)** — train.py l.69-74 : le commentaire du repli MNRL (« valide 4.x comme 5.x via le shim deprecations ») est **inexact pour ST 5.7.0** : `sentence_transformers/losses/` n'existe pas dans la wheel (pas de sous-paquet ni `__getattr__` racine). **Sans impact** : le chemin primaire `sentence_transformers.sentence_transformer.losses` est le bon et fonctionne sur l'image cible (export vérifié dans la wheel) ; le repli ne s'exécute que si le primaire échoue, auquel cas l'échec est visible (ImportError), jamais silencieux.
2. **MINEUR (info, hors sécu)** — `old_fit` sera appelé avec `weight_decay` par défaut (0.01, non passé explicitement) ; parité protocole v2/v3 = périmètre dev-factures, aucun risque de sécurité.
3. **NOTE** — le kernel v2 reste momentanément en ligne sur Kaggle (contient `pip install` + téléchargement HF — déjà relu CONFORME lors de la revue précédente, 0 donnée/0 secret) ; le push v3 (même id) le remplace, conformément au plan.
4. **NOTE** — confidentialité kernel distant : `kernels list -v` n'expose pas de colonne privé ; l'isolation repose sur `is_private: true` du metadata poussé (vérifié localement, repris tel quel de v2) — le CLI n'offre pas de lecture directe de ce drapeau, contrôle par métadonnée de push.

## Verdict : **CONFORME — push du kernel v3 AUTORISÉ**

Aucune correction requise. Le bundle kernel/ (2 fichiers exactement) et le dataset modèle de base (10 fichiers HF publics, sha256 recoupés aux oid LFS officiels) sont conformes aux contraintes n°2 (libellés uniquement, relecture préalable) et au protocole offline. Le push peut suivre immédiatement (étape P3B-run).

STATUT : OK

# Kernel P3 — Fine-tune AEIC T1-P2 sur Kaggle GPU

- Auteur : `dev-factures` (brief `/tmp/p2_briefs/P3_kaggle.md`, contraintes `/tmp/p2_briefs/00_CONTRAINTES.md`)
- Date : 2026-10-27 (VPS, `/home/ubuntu/AEIC`)
- Statut : **PRÊT — push soumis à autorisation parent (contrainte n°2 : tout nouveau fichier
  destiné à Kaggle passe par relecture dev-secu AVANT envoi)**

## Contenu (exactement 2 fichiers)

| Fichier | Rôle |
|---|---|
| `kernel-metadata.json` | id `adrienremond/aeic-t1-p2-finetune`, script python, **privé**, `enable_gpu: true`, `enable_internet: true`, dataset_source `adrienremond/aeic-t1-p2-pairs` |
| `train.py` | Script d'entraînement autoportant (stdlib uniquement au top-level ; torch/ST installés par pip au runtime, versions épinglées) |

## Dataset amont (déjà envoyé, autorisé P2-SEC)

- `adrienremond/aeic-t1-p2-pairs` — privé, `ready`. 3 fichiers : `train_pairs.csv` (1 783 paires),
  `val_pairs.csv` (178), `dataset-metadata.json`.
- Correction opérationnelle à l'envoi (recommandation n°1 dev-secu P2-SEC) : champ `id`
  `aeic/aeic-t1-p2-pairs` → `adrienremond/aeic-t1-p2-pairs` (compte réel du token) ;
  `title`/`isPrivate: true`/licence inchangés ; CSV inchangés octet/octet ;
  `data/eval_t1/p2/SHA256SUMS` mis à jour pour cette seule ligne
  (`990a1b82…` → `f7549ab9…`), vérifié 5/5 ; jeu figé 12/12 intact.

## Choix techniques (et pourquoi)

- **Modèle** : `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (base du S4 zéro-shot).
- **Versions épinglées** : `sentence-transformers==5.7.0` + `transformers==4.57.6`.
  `requirements-t1.txt` ne contient PAS sentence-transformers (T1-P0 = fastembed/ONNX) ;
  le brief demande « la version du requirements-t1.txt **si compatible** » → non applicable,
  on épingle donc un couple stable de l'index PyPI (5.7.0 = ligne 5.x mature ; 4.57.6 =
  dernière 4.x) et on documente l'écart. Torch = celui préinstallé de l'image Kaggle
  (version consignée dans metrics.json).
- **Perte** : `MultipleNegativesRankingLoss`, anchor=`texte_variante`, positive=`cible`.
- **Batch** : 64 (fourchette brief 64-128). **LR** : 2e-5. **Graine** : 42 partout
  (random/numpy/torch/cuda, cuDNN déterministe).
- **Epochs** : min 3, max 10. **Early stopping** : métrique = Recall@1 d'identification sur
  val (classement cosinus des cibles uniques de val ; rang de la vraie cible) ; arrêt après
  2 epochs consécutives sans gain strict, seuil minimal 3 epochs respecté.
- **Loss finale rapportée** : `fit()` n'expose pas la loss d'entraînement en API stable →
  on rapporte `cos_loss = moyenne(1 − cos(variante, cible vraie))` sur train ET val après
  chaque epoch (même formule, comparable époque à époque) + R@1/R@5/MRR val. Zero-shot val
  mesuré avant epoch 1 (base de comparaison honnête, contrainte n°6).
- **Données** : UNIQUEMENT les 2 CSV du dataset attaché (glob restreint aux noms attendus,
  assert sur les colonnes, aucun autre fichier lu, aucun corpus téléchargé). Réseau : pip +
  téléchargement du modèle de base HF uniquement.
- **Sorties** : meilleur modèle complet dans `/kaggle/working/model/` (sauvegardé à chaque
  gain de R@1) + `/kaggle/working/metrics.json` (config, historique par epoch, zero-shot,
  best, gain R@1, durée, device) + résumé imprimé en fin de log.

## Auto-audit anonymisation du dossier `kernel/` (batterie P2-SEC adaptée)

| Contrôle | Résultat |
|---|---|
| Inventaire | **PASSÉ** — 2 fichiers exactement, 0 fichier caché |
| Fournisseurs (`metro\|auchan\|carrefour\|lidl\|leclerc\|intermarch\|casino\|sumup`) | **PASSÉ** — 0 hit |
| Prix `[0-9]+[.,][0-9]{2}` | **PASSÉ avec 2 faux positifs code** : `4.57` (version transformers), `0.10` (fraction warmup) — aucun montant |
| Emails / UUID / user_id / token / secret / password / api_key | **PASSÉ avec faux positifs** : `R@1`/`Recall@1` (identifiants de métrique, motif email trop large) — aucun identifiant réel |
| Prénom / décoration | **PASSÉ** — 0 hit |
| Libellés produits (cotes connues de l'univers A2) | **PASSÉ** — 0 hit |
| Imports top-level | stdlib uniquement (`csv, glob, json, os, random, subprocess, sys, time`) — torch/ST importés après pip, à l'intérieur de `main()` |
| `open()` | 2 appels, uniquement sur les chemins découverts par `find_input()` (dataset attaché) |
| Secrets | aucun (l'auth Kaggle vit côté serveur, rien n'est embarqué) |

**Contenu de données embarqué dans le kernel : AUCUN.** Le script ne référence que :
nom public du modèle HF, slug du dataset privé, noms de colonnes, hyperparamètres.

## Commandes (relançables)

```bash
export PATH="$HOME/.local/bin:$PATH"
# dataset (fait) : kaggle datasets create -p data/eval_t1/p2/kaggle_upload/
kaggle datasets status adrienremond/aeic-t1-p2-pairs        # -> ready
# kernel (après autorisation) :
kaggle kernels push -p data/eval_t1/p2/kernel/
kaggle kernels status adrienremond/aeic-t1-p2-finetune      # polling ~60 s, timeout 90 min
kaggle kernels output adrienremond/aeic-t1-p2-finetune -p data/eval_t1/p2/kernel_output/
```

## SUITE DE MISSION (16:27) — 2 tentatives épuisées, BLOQUE côté worker

- **Dataset** : envoyé et `ready` — `adrienremond/aeic-t1-p2-pairs` (privé). UNIQUE livrable
  Kaggle-side abouti.
- **Tentative 1/2** (kernel v1, 16:04) : ERROR ~209 s — DNS KO vers pypi.org (5 retries pip),
  `FATAL: pip install`. Cause : internet non effectif sur le worker.
- **Tentative 2/2** (kernel v2, 16:10 — bootstrap durci : sondes réseau, empreinte
  environnement, fallback paquets préinstallés ; design d'entraînement inchangé) : ERROR
  ~890 s. Enseignements du log (`kernel_output/aeic-t1-p2-finetune.log`) :
  - python 3.13.15 ; **préinstallés** : torch **2.11.0+cpu**, sentence-transformers
    **5.7.0** (== mon pin), transformers **5.16.1** (≠ pin 4.57.6 — pip ignoré faute de réseau) ;
  - **sondes : pypi.org KO, huggingface.co KO** (`Temporary failure in name resolution`) →
    internet STRUCTURELLEMENT coupé malgré `enable_internet: true` ;
  - **device=CPU** → `enable_gpu: true` AUSSI ignoré (build torch cpu) ;
  - dataset monté et trouvé : `/kaggle/input/datasets/adrienremond/aeic-t1-p2-pairs/` ✓ ;
  - mort au téléchargement HF du modèle de base : `OSError: We couldn't connect to
    'https://huggingface.co'` (aucun cache local) — le reste du pipeline n'a jamais
    pu s'exécuter.
- **Conclusion** : le script est validé jusqu'au chargement du modèle ; le blocage est
  externe (worker sans réseau ni GPU — cause typique : compte Kaggle non vérifié par
  téléphone, les flags étant alors silencieusement retirés). GPU consommé : 0 (build cpu).
- Pistes (décision parent) : (A) vérifier le compte Kaggle puis autoriser une 3e tentative
  (hors protocole actuel) ; (B) pivot sans internet : uploader les poids du modèle de base
  en dataset Kaggle privé (passe alors par relecture dev-secu) et charger depuis
  /kaggle/input — CPU suffisant vu le volume (1 783 paires) ; (C) entraîner sur le VPS.

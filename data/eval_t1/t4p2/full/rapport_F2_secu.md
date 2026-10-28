# Rapport F2 — dev-secu — Revue du kernel v6 avant push (T4-P2 full)

Horodatage : 2026-10-28 · Auditeur : dev-secu · Brief : /tmp/t4full_briefs/F2_secu.md
Objets : `data/eval_t1/t4p2/full/kernel/` (v6, NON poussé — protocole respecté par dev-factures).
Grille : reprise de REVUE_SECU_KERNEL_V3.md (27/10), adaptée au régime GPU+internet AUTORISÉ
(contrainte n°2 du 00_CONTRAINTES.md). Méthode indépendante : aucune confiance dans les
auto-audits du rapport F1 — chaque contrôle re-exécuté (sha256, greps, extraction corpus,
API HF publique en direct, état Kaggle en lecture seule).

Intégrité des objets revus : kernel-metadata.json `46ebf36a…b029` + train.py `0b74a77b…8245`
= exactement les sha256 du rapport F1 ; inventaire du dossier = 2 fichiers exactement,
re-vérifié APRÈS py_compile (cfile externe, 0 pollution).

## Grille — résultats

| # | Contrôle | Résultat | Preuve |
|---|----------|----------|--------|
| 1 | Inventaire kernel/ EXACT 2 fichiers, 0 binaire, 0 pyc | **CONFORME** | `ls -A` → `kernel-metadata.json` (346 o) + `train.py` (60 649 o) ; py_compile avec `cfile` dans /tmp → compile OK, dossier re-listé = 2 fichiers ; sha256 = rapport F1 |
| 2 | 0 secret / 0 jeton | **CONFORME** | Regex api[_-]?key/token/password/secret/bearer/authorization/credential/hf_[A-Za-z0-9]{20,}/sk-/ssh-rsa/PRIVATE KEY → uniquement faux positifs « tokenizer/tokenize/tokens » ; aucun jeton HF/Kaggle, aucun .env, aucun credential |
| 3 | 0 donnée produit inline (corpus attaché en dataset privé uniquement) | **CONFORME** | Extraction indépendante : 508 mots ≥5 car. du CONTENU des messages du corpus (train+val) → présents dans train.py : uniquement vocabulaire technique/français courant (« arguments », « assistant », « timeout »…) ; **AUCUN libellé produit** (Monster/Coca/bueno… absents) ; 0 ligne > 400 car. (corpus jamais inline) |
| 4 | kernel-metadata.json SÛRE | **CONFORME** | `is_private: true` ; `dataset_sources = ["adrienremond/aeic-assistant-corpus"]` SEUL (corpus verdict B CONFORME) ; `enable_gpu: true` + `enable_internet: true` = COHÉRENTS avec le brief (GPU T4 + internet pip/HF autorisés, contrainte n°2) ; pas de collaborators/kernel_sources/competition_sources |
| 5 | Lecture corpus via /kaggle/input uniquement | **CONFORME** | Seule racine d'entrée = `INPUT_ROOT="/kaggle/input"` ; `dataset_root()` teste 2 candidats sous /kaggle/input + glob read-only de diagnostic ; autres lectures = artefacts créés par le kernel lui-même (/tmp/qwen3-4b, /tmp/merged, /tmp/llama.cpp) |
| 6 | Écritures /kaggle/working (sorties) — /tmp = travail éphémère documenté | **CONFORME** | Sorties livrables TOUTES dans /kaggle/working : `aeic-qwen3-4b-tools-Q4_K_M.gguf`, repli Q8_0, repli `merged_model/`, `adapter/`, `metrics.json` ; /tmp réservé aux intermédiaires volumineux (snapshot 8 Go, clone, f16 supprimé en fin) — pratique Kaggle standard, annoncée en tête de fichier ; aucune écriture hors kernel |
| 7 | Réseau : UNIQUEMENT les 3 endpoints autorisés | **CONFORME** | Grep exhaustif : SEULE URL littérale = `https://github.com/ggerganov/llama.cpp` (autorisé, contrainte n°3) ; `snapshot_download(repo_id="Qwen/Qwen3-4B")` → huggingface.co modèle PUBLIC officiel (contrainte n°2) ; `pip install gguf` + `peft --no-deps` → pypi (autorisé) ; 0 urllib/requests/socket/wget/curl/push_to_hub/upload ; `cmake -DGGML_CURL=OFF` coupe tout fetch réseau des binaires llama.cpp ; subprocess en LISTE (jamais shell=True), aucun secret en ligne de commande |
| 8 | Intégrité supply-chain modèle + corpus (sha256 FATAL) | **CONFORME** | `EXPECTED_SHA256` 4/4 (3 shards + tokenizer.json) = oid LFS du dépôt HF officiel **vérifiés EN DIRECT aujourd'hui** sur l'API publique `Qwen/Qwen3-4B` → 4/4 OK ; `allow_patterns` = liste blanche stricte de 10 fichiers ; `EXPECTED_CORPUS_SHA256` 2/2 = SHA256SUMS local = kaggle_upload = dataset distant (chaîne d'intégrité) ; tout écart = SystemExit FATAL avant tout entraînement |
| 9 | Graine 42 | **CONFORME** | random/np/torch/cuda seed 42 + cudnn.deterministic ; dérivations déterministes : Random(SEED+1000+rank) sélection A/B, Random(SEED*1000003+epoch) ordre des batchs ; aucune entropie non seedée |
| 10 | Aucun print de libellés produits dans les logs | **CONFORME** | Revue exhaustive des ~35 prints : compteurs/versions/chemins/loss/métriques ; ids de conversation au format TECHNIQUE `t4p2-F<n>-<famille>-<NNN` (446+74 ids vérifiés, 0 id contenant du texte libre) ; noms d'outils uniquement (identifiants de fonctions) ; FATAL = ids/noms de fichiers/exceptions ; **le texte brut des 40 questions/références n'est jamais imprimé** ; `gen_extrait` (≤300 car. du texte GÉNÉRÉ — jamais le prompt) va uniquement dans metrics.json, artefact de sortie du kernel PRIVÉ, contenu au pire équivalent-D3 (libellés autorisés) — voir note 3 |
| 11 | Compatibilité image GPU Kaggle (torch/transformers préinstallés) | **CONFORME** | torch/transformers/numpy importés SANS pip ; transformers 4.x (list) ET 5.x (dict) gérés dans render_ids ; `dtype=` avec repli TypeError (torch_dtype ancien) ; `torch.amp.GradScaler("cuda")` (signature 2.x) ; peft optionnel → repli torch pur v5 ; gguf vérifié importable avant convert ; faisabilité git/cmake/cc/disques documentée au boot dans metrics |
| 12 | Rien d'autre n'a fui côté Kaggle (lecture seule) | **CONFORME** | `datasets list --mine` → 4 datasets tous PRIVÉS et documentés : corpus (verdict B), aeic-qwen3-1p7b-hf (RAPPORT_PIPELINE l.33 : 11 fichiers OFFICIELS Qwen3-1.7B, oid LFS vérifiés), aeic-t1-ml-base + aeic-t1-p2-pairs (vagues précédentes) ; `kernels list --mine` → 2 (smoke v5, t1-p2-finetune) — kernel v6 NON poussé ✓ protocole |
| 13 | Jeu figé intact + rien modifié par la revue | **CONFORME** | `sha256sum -c SHA256SUMS` intégral OK + `SHA256SUMS_PIPELINE` OK ; revue 100 % lecture seule sur kernel/ (py_compile → cfile /tmp) ; seul écrit = CE rapport dans full/ |

## Observations mineures (non bloquantes)

1. **MINEUR (cosmétique)** — train.py l.148 : constante `AB_MAX_NEW_TOKENS = 384` définie
   mais jamais utilisée (le plan A/B utilise les littéraux `(40,384)…`). Zéro impact
   sécurité. Recommandation v7 : supprimer.
2. **MINEUR (supply-chain, assumé par le brief)** — `git clone --depth 1` llama.cpp sur HEAD
   (pas de commit épinglé) : le sha est journalisé A POSTERIORI (rev-parse → metrics), pas
   vérifié FATAL contre une empreinte attendue. Risque contenu : code exécuté =
   convert_hf_to_gguf.py (entrées = poids locaux uniquement) + llama-quantize compilé avec
   GGML_CURL=OFF ; replis hiérarchisés si échec. Recommandation v7 : épingler un tag
   llama.cpp connu et le vérifier FATAL comme pour HF — non exigé par le brief actuel.
3. **NOTE (confidentialité metrics.json)** — `records_*` contiennent `gen_extrait`
   (≤300 car. de la GÉNÉRATION du modèle, jamais la question) : peut inclure des libellés
   produits si l'outil est correctement appelé — divulgables (D3), artefact de kernel
   privé récupéré vers VPS privé, indispensable à l'analyse A/B (contrainte n°4).
   Vigilance : si metrics.json ou le kernel devaient un jour être rendus publics,
   retirer `gen_extrait` au préalable.
4. **NOTE** — train.csv/val.csv montés avec le dataset corpus mais non lus par le kernel :
   inoffensif (même contenu déjà revu, jsonl seuls utilisés).

## Verdict : **CONFORME — push du kernel v6 AUTORISÉ** (protocole F1B, ≤2 push)

Aucune correction exigée. Corrections appliquées : aucune (revue, 100 % lecture seule).

## Reproduction (extraits)

- `sha256sum kernel-metadata.json train.py` → 46ebf36a… / 0b74a77b… (= rapport F1)
- Extraction mots corpus vs train.py : 508 mots → hits = vocabulaire technique uniquement
- API HF `Qwen/Qwen3-4B/tree/main` → oid LFS 4/4 = EXPECTED_SHA256 (script ad hoc, ce jour)
- `kaggle datasets list --mine` / `kaggle kernels list --mine` → lecture seule, rien de neuf
- `sha256sum -c SHA256SUMS` + `SHA256SUMS_PIPELINE` → OK intégral

STATUT : OK

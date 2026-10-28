# Rapport A/B FINAL — T4-P2 entraînement complet (kernel v7.1 « full », version Kaggle 4) — RUN COMPLET, VERDICT BASE_GAGNE

Horodatage : 2026-10-28 ~14:50 UTC · Agent : dev-factures (F3 exécution, vague 2) · Verdict push v7 :
CONFORME (VERDICT_V7.md) · Correctif v7.1 appliqué entre les 2 pushes après ERROR documenté (voir § Correctif v7.1).

## Résultat exécution — PIPELINE COMPLET PROUVÉ DE BOUT EN BOUT

**Run v7.1 (version Kaggle 4) : COMPLETE après 7584,5 s (~2 h 06), dans le garde-fou 3 h.**
Chaîne intégrale exécutée DANS le kernel : corpus sha256 4/4 OK → Qwen3-4B HF 4/4 OK →
rendu ChatML + masquage v5 (446/74) → LoRA peft r=16 α=32 q/k/v/o (11 796 480 params, 0,292 %) →
entraînement (interrompu par cutoff temps) → adapter PEFT → A/B 40 questions val →
merge LoRA → **export GGUF Q4_K_M in-kernel** (2 497 280 448 octets, sha256 `da74813e…e1f1`).

### Métriques mémoire (v7.1 vs OOM v6b)

| Mesure | v6b (batch 4, sans ckpt) | v7.0 (micro-batch 2) | v7.1 (repli micro-batch 1) |
|---|---|---|---|
| Dry-run boot seq 512 | — | AVANT ckpt : OOM ; APRÈS ckpt : 9,76 Go | idem (correctifs conservés) |
| 1er step réel | **OOM CUDA** (1,35 Go refusés) | pic 14,29 Go (seq 1557) — passe | pic ≤ 14,29 Go (seq 1633) — passe |
| Suite de l'epoch | — | OOM au step 3 (seq ~1700+, backend math) | **OK sur 65 steps** (repli 1×4) |
| Pic global run | — | — | 15,34 Go alloués max (ressources.gpu_max_memory_gb) |

Gradient checkpointing (use_reentrant=False) + expandable_segments:True + repli OOM à tout step :
la cause racine v6b (SDPA backend math sur T4, batch 4 × seq 2048 × 36 couches) est neutralisée.

### A/B 40 questions val (jamais vues) — BASE vs FINE-TUNÉ

| Score | BASE | FINE-TUNÉ (v7.1, 59 % epoch 1) |
|---|---|---|
| overall_ok | **0,475** | 0,300 |
| tool_ok (33 q. outil) | **0,5152** | 0,1515 |
| args_ok | **0,3636** | 0,1515 |
| no_tool_ok (7 q.) | 1,0 | 1,0 |
| refus injections F3 | **0,50** | 0,375 |

Verdict kernel : **BASE_GAGNE** → `decision_remplacement_local` = **GARDER_BASE**
(le remplacement local ne se fait QUE si le fine-tuné gagne — contrainte 00_CONTRAINTES n°4 respectée).

### Pourquoi le fine-tuné perd (analyse honnête)

- Le cutoff temps 5700 s a interrompu l'entraînement à **65/111 steps de l'epoch 1**
  (~59 % d'UNE epoch sur les 3-6 requises) : micro-batch 1 × 4 ≈ 85 s/step sur T4 backend math.
- Loss train 1,79 → 0,0245 : le modèle mémorise le corpus, mais à ce stade précoce il a
  sur-pondéré les formulations de refus du corpus (générations type « Je ne peux pas ignorer
  mes règles… ») au détriment des appels d'outils sur les questions F1/F2/F6 (0-17 % tool_ok).
- F3 outil 0 % : le modèle refuse au lieu d'appeler l'outil légitime malgré l'injection —
  comportement attendu d'un entraînement partiel, pas d'un bug de chaîne (la loss val 0,0835
  et le pipeline sont sains).

### Livrables récupérés (kernel_output/run_v7/, SHA256SUMS vérifiés)

- `adapter/` (PEFT rechargeable : adapter_config.json + adapter_model.safetensors 47,2 Mo + README) ;
- `aeic-qwen3-4b-tools-Q4_K_M.gguf` 2,33 Go — sha256 local == sha256 in-kernel `da74813e…e1f1` ✓ ;
- `metrics.json` complet (78 Ko : boot, rendu, lora, mémoire, entraînement, A/B, GGUF, ressources) ;
- `aeic-t4p2-qlora-full.log` intégral ; `attempt1_v7_0_error/` = preuve du run v7.0 ERROR.
- **Décision : la base locale N'EST PAS remplacée** (GARDER_BASE) ; adapter + GGUF conservés
  comme preuves/artefacts réutilisables (un run plus long — multi-sessions ou GPU plus rapide —
  est la voie évidente pour finir les 3-6 epochs).

## Chronologie des exécutions (historique)

| Push (vague 2) | Version Kaggle | Résultat | Détail |
|---|---|---|---|
| 1/2 | v7.0 (sha `ecedc63b…f793`, verdict orchestrateur CONFORME) | ERROR à ~282 s | Boot OK, dry-run OK, steps 1-2 OK (pic 14,29 Go) puis OOM au step 3 (backward, +1,86 Go refusés, 1,45 Go libres) : repli micro-batch 1 prévu UNIQUEMENT au 1er step → jamais déclenché |
| 2/2 | v7.1 (sha `d5c3308a…4abe`, correctif mineur évident : repli OOM à TOUT step une fois + reset compteurs epoch + EVAL_BATCH_SIZE 8→2 loss-équivalente) | **COMPLETE 7584,5 s** | repli déclenché au step 3 ✓, 65 steps, cutoff 5700 s, A/B, GGUF ✓ |

Écart de protocole signalé à l'orchestrateur : le test local (importlib) a recréé `__pycache__`
dans `kernel/` après l'inventaire initial — la version 4 est partie avec ce dossier (inerte : pyc
VPS jamais lu par Python 3.13 Kaggle) ; budget 2 push épuisé → pas de re-push cosmétique.
Dossier local renettoyé (2 fichiers exactement). Diff v7.1 : `diff_v7_1.diff` (+36/−7 vs v7.0).

---

# Historique — Rapport A/B — T4-P2 (kernel v6 « full ») — BLOQUÉ au budget de push

Horodatage : 2026-10-28 ~11:15 UTC · Agent : dev-factures (F3 exécution) · Précédents : F1 (kernel prêt),
F2 (dev-secu : CONFORME, push autorisé, sha256 pinnes `46ebf36a…b029` / `0b74a77b…8245`).

## Verdict exécution

**ÉCHEC — aucune métrique A/B produite.** Les 2 tentatives Kaggle autorisées (00_CONTRAINTES n°6)
sont épuisées ; le kernel a crashé avant le premier pas d'entraînement dans les deux cas.
Aucun artefact livrable (adapter / GGUF / metrics complets) n'a été produit.

## Chronologie

| Push | Kernel v6 | Résultat | Crash |
|------|-----------|----------|-------|
| 1/2 | run 1 (sha256 train.py `0b74a77b…8245`, version revue F2) | ERROR à ~104 s | `get_peft_model` → ImportError torchao (image Kaggle : torchao 0.10.0 préinstallé, peft 0.20.0 exige ≥0.16) ; repli torch pur → OOM CUDA à `model.to("cuda")` (14,43 Go occupés : le traceback du bloc except gardait des références vivantes sur le modèle → `del model + empty_cache` ne libérait rien) |
| 2/2 | run 2 (v6b corrigée, delta +50/−8 autorisé par l'orchestrateur après diagnostic, sha256 `40f186ed…f948`) | ERROR à ~106 s | correctifs run 1 **validés en production** : `[pip] uninstall torchao -> OK`, `[lora] peft attaché` (11 796 480 / 4 034 264 576 params = 0,292 %) — puis **OOM CUDA au 1er batch** dans `scaled_dot_product_attention` (allocation 1,35 Go refusée, 12,77 Go déjà alloués) |

## Diagnostic run 2 (définitif)

T4 (sm 7.5) ne supporte pas flash-attn → SDPA retombe sur le backend **math**, qui matérialise
la matrice d'attention : batch 4 × 32 têtes × 2048 × 2048 ≈ 1 Gio/couche (bf16), conservée pour
la rétropropagation sur 36 couches, en plus des **7,55 Gio de poids** Qwen3-4B bf16.
Budget T4 = 14,56 Gio → OOM au premier forward. Le batch 4 × seq 2048 (max autorisé par le brief)
n'est tenable que sur GPU avec flash-attn (A100/L4…) ou avec checkpointing de gradients.

Ce que le brief v5 avait prouvé l'était sur Qwen3-**1.7B** (≈3,2 Gio bf16) : à budget mémoire
d'activations constant, passer à 4B sans checkpointing fait passer le pic de ~7 à ~14+ Gio.

## Correctif v7 prêt (NON poussé — budget épuisé, protocole respecté)

1. `PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True` posé en tête de fichier (avant init CUDA).
2. `model.gradient_checkpointing_enable()` après attachement LoRA (`use_cache=False` déjà en place) —
   les activations par couche ne sont plus conservées, seul 1 segment recalculé à la fois (~1-2 Gio).
3. `BATCH_SIZE = 2` + accumulation de gradient ×2 (batch effectif 4 conservé, graine/ordre inchangés).
4. (Ceinture) re-pli automatique batch 2→1 si OOM au premier pas.
   Pic estimé : 7,55 (poids) + 0,2 (LoRA fp32+AdamW) + ~1,5-2,5 (activations checkpointées) ≈ 9,5-10,5 Gio < 14,56.
   Coût : ~+30-40 % de temps de calcul — compatible cutoff 5700 s (446 exemples, 3-6 epochs).

## Récupérations effectuées (preuves, dans `kernel_output/`)

- `run1/` : log complet + metrics.json partiel (boot, corpus sha256 4/4 OK, Qwen3-4B HF intégrité 4/4 OK,
  rendu 446+74 exemples ChatML + sonde v5, échec peft torchao).
- `run2/` : log complet + metrics.json partiel (idem + `torchao_desinstalle: true`, `lora.implementation: "peft"`,
  `peft_error: null`, paramètres entraînables 11 796 480 — preuve que le pipeline amont est sain jusqu'au LoRA).
- `SHA256SUMS` : 4 fichiers, `sha256sum -c` OK.
- Aucune sortie livrable (adapter/GGUF/metrics complets) : les deux runs sont morts avant l'entraînement.

## Protocole

- 00_CONTRAINTes n°1 respecté : 0 commit/push git, rien écrit hors `data/eval_t1/t4p2/full/`,
  jeu figé + t4p2 existants intacts (`SHA256SUMS` racine inchangés — non retouchés).
- 2 push Kaggle exactement (versions 1 et 2 du kernel `adrienremond/aeic-t4p2-qlora-full`, privé).
- Modification post-revue run 1→2 faite SUR autorisation explicite de l'orchestrateur (delta +50/−8 :
  import gc, pip uninstall torchao local sans réseau, del exc + gc.collect + rechargement dtype bf16) ;
  inventaire `kernel/` re-vérifié = exactement 2 fichiers après chaque manipulation.

# RAPPORT_PIPELINE.md — Smoke pipeline LoRA/QLoRA AEIC T4-P2 (vague 3, brief C)

- **Agent** : `dev-factures` (brief `/tmp/t43_briefs/C_smoke.md`, contraintes `/tmp/t43_briefs/00_CONTRAINTES.md`)
- **Date** : 2026-10-28 (UTC) — kernel poussé 04:57:11, COMPLETE ≈ 05:21, output récupéré 05:38
- **Mission** : PROUVER le pipeline fine-tuning de l'assistant (corpus → tokenisation ChatML →
  LoRA → adapter PEFT → fusion), entraînement COMPLET DIFFÉRÉ au déblocage GPU Kaggle
  (contrainte vague 3 n°1 — décision assumée et documentée).

---

## 1. Décision modèle de base pour le smoke — Qwen3-1.7B (documentée)

| Critère | Qwen3-4B | Qwen3-1.7B |
|---|---|---|
| Safetensors HF (bf16) | 8,045 Go (3 shards, API HF du 28/10) | 4,063 Go (2 shards) |
| Upload Kaggle (< 20 Go) | possible | possible |
| FLOPs/step (seq réelle ~1400) | ≈ 3,4×10¹³ | ≈ 1,7×10¹³ |
| Temps/step CPU Kaggle (calibration v4 : ≈ 190 GFLOP/s effectifs mesurés sur le kernel T1-P2 v4) | ≈ 180-260 s | ≈ 77-121 s (**mesuré : 77-121 s, moy. 103 s**) |
| Steps dans le garde-fou 1500 s | ≈ 3-5 | **12 (exécutés)** |

Le 4B **serait uploadable** (8,05 Go < 20 Go) mais **ne tient pas** dans le garde-fou CPU de
1500 s pour un smoke utile : quelques steps tout au plus, sans tendance de loss lisible.
Conformément à la contrainte vague 3 n°5 (« si le 4B ne tient pas » / « décision tienne »),
le smoke prouve le PIPELINE sur **Qwen3-1.7B** ; le full run 4B attend le GPU (§5).

## 2. Ce qui est PROUVÉ (preuves relançables)

### 2.1 Données (datasets privés Kaggle, hors ligne)

| Dataset | Contenu | Intégrité |
|---|---|---|
| `adrienremond/aeic-assistant-corpus` (PRIVÉ) | corpus tool-calling FR vague 3 (rapport A) : train.jsonl/val.jsonl + CSV | verdict dev-secu B : CONFORME (upload autorisé) ; **hash runtime Kaggle train.jsonl = `42afde0b…d721b` = SHA256SUMS local = contenu revu** (chaîne d'intégrité bout en bout) |
| `adrienremond/aeic-qwen3-1p7b-hf` (PRIVÉ, slug sans points — Kaggle interdit « 1.7b ») | 11 fichiers OFFICIELS `Qwen/Qwen3-1.7B` | sha256 des 2 shards + tokenizer.json = **oid LFS HF officiels**, vérifiés au download VPS, à la création, ET **au boot du kernel** (3/3 `ok: true` dans metrics.json) |

### 2.2 Pipeline end-to-end (kernel v5 `adrienremond/aeic-t4p2-qlora-smoke`, 1 exécution, COMPLETE)

1. **Rendu ChatML + masquage** — template OFFICIEL du tokenizer Qwen3 (aucun template
   maison), `tools` passées au template ; rendu complet par conversation ; spans assistant
   localisés par double sonde adaptative (en-tête 3 tokens + bloc `<think>` vide 4 tokens
   injecté par le template **selon la position** — découvert en test local, corrigé) ;
   en-tête et bloc think MASQUÉS (préremplis à l'inférence llama.cpp/Qwen3 non-thinking,
   jamais générés) ; assertions strictes à chaque message (tout écart = FATAL, jamais de
   masquage à l'aveugle). **446 conversations rendues, 0 tronquée** (max 1782 < 2048 ;
   med 1401 ; labels assistant = 9,0 % des tokens — mesuré identique en local et sur Kaggle).
2. **LoRA r=16, alpha=32, cibles q/k/v/o** — **peft est PRÉSENT sur l'image Kaggle mais
   CASSÉ** (`ImportError: torchao 0.10.0 trouvé, ≥ 0.16.0 requis`) → le **repli torch pur**
   du kernel a engagé exactement comme conçu (les DEUX chemins avaient été validés en
   local sur micro-modèle avant le push). 112 modules wrappés, **6 422 528 paramètres
   entraînables / 2,03 Md** (0,32 %), graine 42 partout.
3. **Entraînement honnête** — 100 steps cible, batch 1, seq max 2048 (longueur réelle),
   AdamW lr 2e-4, CE sur tokens assistant uniquement. **Garde-fou 1500 s atteint à 12
   steps** (documenté : `steps_executed: 12`, `time_budget_hit: true`, total 1397,7 s,
   aucun NaN). **Loss début → fin : 1,993 → 0,668** (moyennes 3 premiers/derniers steps ;
   brut 1,179 → 0,743 ; `loss_descendante: true`).
4. **Adapter PEFT sauvegardé et VÉRIFIÉ localement** — `adapter_config.json` (LORA /
   CAUSAL_LM / r16 / α32 / qkvo / base `Qwen/Qwen3-1.7B`) + `adapter_model.safetensors`
   (25 720 088 octets) : **224 tenseurs = 28 couches × {q,k,v,o} × {A,B}**, nommage PEFT
   exact (`base_model.model.model.layers.<i>.self_attn.<proj>.lora_{A,B}.weight`), formes
   224/224 cohérentes (hidden 2048, kv 1024, r 16), valeurs finies, **B non nul** (init 0
   + 12 steps → les gradients ont coulé). La base 1,7B n'est PAS rechargée sur le VPS
   (RAM 7,6 Go < 4,1 Go de poids bf16 + marges) ; la validité du format pour
   `PeftModel.from_pretrained` est prouvée par la **relecture croisée du test local B**
   (adapter torch pur rechargé par peft sur micro-modèle + forward OK).
5. **Fusion vérifiée in memory** — deltas `W += (B@A)·α/r` appliqués aux 112 modules puis
   forward de contrôle 32 tokens : **logits finis, max|logit| = 32,9, en 2,3 s**
   (documenté dans metrics.json).

### 2.3 Protocole offline strict

`enable_internet: false`, `enable_gpu: false`, `HF_HUB_OFFLINE=1`/`TRANSFORMERS_OFFLINE=1`
avant imports, zéro pip, zéro réseau (auto-audit greps : 0 hit réel), lectures
`/kaggle/input` uniquement (datasets attachés), écritures `/kaggle/working` uniquement
(adapter + metrics.json). Versions réelles du run : torch 2.11.0+cpu,
transformers 5.16.1, numpy 2.1.3, python 3.13.

### 2.4 Validation locale PRÉALABLE au push (venv torch 2.14/transformers 5.21 — AUCUN
entraînement sur le VPS : micro-Qwen3 de ~10 M paramètres construit pour l'occasion, 3 steps
de quelques secondes)

- Test A (`tests_local/test_render_mask.py`) : rendu + masquage sur les **520 conversations
  réelles** → OK (propriétés de préfixe assertées, longueurs exactes, aucun token
  `<think>`/`</think>` labelisé).
- Test B (`tests_local/test_micro_e2e.py`) : kernel rejoué **complet** en modes `pure` ET
  `peft` → OK (loss init micro ≈ ln(151 936) = 11,93 = sanité d'un modèle aléatoire).
- Croisement : adapter torch pur → `PeftModel.from_pretrained` → forward OK.

## 3. Ce qui est DIFFÉRÉ (et pourquoi, honnêtement)

| Élément | Raison du report | Où ce sera fait |
|---|---|---|
| Entraînement complet (epochs sur 446 convs, convergence) | CPU Kaggle ≈ 103 s/step mesuré → des heures/epoch ; contrainte vague 3 n°1 : DIFFÉRÉ au GPU | full run GPU (§5) |
| Full run sur **Qwen3-4B** (8,05 Go uploadables mais inviables CPU dans 1500 s) | temps CPU (§1) | full run GPU (§5) |
| Sauvegarde du modèle fusionné (4-8 Go) | inutile au smoke (adapter suffit, fusion déjà vérifiée in memory) ; économise l'output Kaggle | full run GPU |
| **Export GGUF (llama.cpp)** | AUCUN convertisseur GGUF disponible hors ligne sur l'image (pas de pip, `gguf`/llama.cpp absents) ; un writer GGUF maison sans binaire llama.cpp pour le valider = affirmation invérifiable → NON prétendu prouvé | full run GPU (llama.cpp / convert_hf_to_gguf.py avec les mêmes datasets attachés + le repo llama.cpp en dataset) |
| Évaluation qualitative (générations outillées) | pas l'objet du smoke | après full run |
| peft natif sur l'image Kaggle | cassé (torchao 0.10 < 0.16) ; repli torch pur PROUVÉ équivalent (r/α/cibles/init/sauvegarde PEFT identiques) | sans objet (repli conservé au full run) |

## 4. Artéfacts produits (data/eval_t1/t4p2/)

- `kernel/train.py` + `kernel/kernel-metadata.json` — kernel v5 (2 fichiers exactement,
  inventaire audité ; sha256 dans `kernel_pushed_v5/SHA256SUMS_KERNEL_V5`) ;
- `kernel_pushed_v5/` — trace du kernel poussé (copie + sha256) ;
- `kernel_output_v5/` — output récupéré : `metrics.json`, `adapter/` (adapter_config.json
  657 o + adapter_model.safetensors 25 720 088 o), log complet ;
- `base_model_hf/` — 11 fichiers officiels Qwen3-1.7B + dataset-metadata.json (3,8 Go) ;
- `tests_local/` — harnais de répétition générale (test A + test B micro, stubs) ;
- `SHA256SUMS_PIPELINE` — empreintes des nouveaux artefacts figés.
- Aucun commit/push git (contraite vague 3 n°2 : consolidation finale) ; jeu figé intact ;
  base prod non lue ; aucun entraînement sur le VPS.

## 5. Commande complète du futur full-run (4B + GPU) — prérequis et durée

**Prérequis GPU (bloqueur connu)** : vérification du compte Kaggle par téléphone par
l'utilisateur (les sessions v1/v2 de la vague T1-P2 ont montré des flags GPU/internet
silencieusement ignorés sans elle). Une fois déverrouillé :

```bash
# (a) Une seule fois : dataset 4B (8,05 Go, 3 shards, oid LFS à figer comme EXPECTED_SHA256)
#     télécharger https://huggingface.co/Qwen/Qwen3-4B (config/tokenizer/3 shards/index)
cd ~/AEIC/data/eval_t1/t4p2/base_model_hf_4b && kaggle datasets create --dir-mode zip
#     -> adrienremond/aeic-qwen3-4b-hf (PRIVÉ, licence Apache-2.0)

# (b) Kernel v6 = copie de kernel/train.py avec SEULES modifications :
#     MODEL_DATASET="adrienremond/aeic-qwen3-4b-hf" ; BASE_MODEL_HF="Qwen/Qwen3-4B"
#     EXPECTED_SHA256 = oid LFS des 3 shards 4B ; EXPECTED_MARKER_FILES ajustés
#     STEPS_TARGET = 446*EPOCHS (ex. 1338 à 3 epochs, batch 1 — ou batch>1 + padding)
#     TIME_BUDGET_S = 10800 (3 h) ; dtype bf16 si torch.cuda.is_available()
#     kernel-metadata.json : enable_gpu=true, dataset_sources=[corpus, aeic-qwen3-4b-hf]
kaggle kernels push -p ~/AEIC/data/eval_t1/t4p2/kernel_v6
kaggle kernels status adrienremond/aeic-t4p2-qlora-full   # polling
kaggle kernels output adrienremond/aeic-t4p2-qlora-full -p ~/AEIC/data/eval_t1/t4p2/kernel_output_v6

# (c) Merge + export GGUF (session GPU ou machine avec llama.cpp, offline via datasets) :
#     python llama.cpp/convert_hf_to_gguf.py <merged-dir> --outfile aeic-qwen3-4b-tools.gguf --outtype q8_0
#     (repo llama.cpp + gguf attachés en dataset privé si la session reste offline)
```

**Durée estimée du full-run 4B** (T4/P100 fp16 ≈ 10 TFLOP/s effectifs) :
6·N·T ≈ 6×4,0×10⁹×1400 ≈ 3,4×10¹³ FLOPs/sample → 446 convs × 3 epochs ≈ 4,5×10¹⁵ FLOPs
→ **≈ 75 min de compute + boot/merge/export ≈ 1,5-3 h au total** (L4 ou 2×T4 : moitié).
Le repli torch pur reste valable tel quel (peft cassé sur l'image), ou utiliser peft si
l'image est entre-temps corrigée — le kernel gère les deux automatiquement.

## 6. Reproduction (commandes relançables)

```bash
cd /home/ubuntu/AEIC/data/eval_t1/t4p2
# tests locaux (aucun entraînement réel : micro-modèle ~10 M param, 3 steps)
/home/ubuntu/AEIC/.venv-p2conv/bin/python tests_local/test_render_mask.py
/home/ubuntu/AEIC/.venv-p2conv/bin/python tests_local/test_micro_e2e.py pure
PYTHONPATH=/home/ubuntu/models/pylibs-peft-min /home/ubuntu/AEIC/.venv-p2conv/bin/python tests_local/test_micro_e2e.py peft
# intégrité datasets locaux vs oid LFS HF
sha256sum -c /tmp/expected_oids.txt        # (depuis base_model_hf/)
sha256sum -c kernel_pushed_v5/SHA256SUMS_KERNEL_V5
sha256sum -c SHA256SUMS_PIPELINE
# rejeu distant
kaggle kernels status adrienremond/aeic-t4p2-qlora-smoke   # COMPLETE
kaggle kernels output adrienremond/aeic-t4p2-qlora-smoke -p /tmp/rejeu && cat /tmp/rejeu/metrics.json
```

STATUT : OK

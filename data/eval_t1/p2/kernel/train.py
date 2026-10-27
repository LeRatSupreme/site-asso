# -*- coding: utf-8 -*-
"""
Kernel Kaggle P3 v3 — Fine-tune AEIC T1-P2, 100% OFFLINE, CPU
==============================================================

Mission inchangee : fine-tuner `paraphrase-multilingual-MiniLM-L12-v2` sur les
paires du dataset PRIVE adrienremond/aeic-t1-p2-pairs (colonnes
`texte_variante,cible` — P1/dev-factures, revue anonymisation P2-SEC CONFORME,
decision D3 : libelles produits seuls).

v3 = pivot offline apres echecs v1/v2 (worker Kaggle SANS internet NI GPU,
cf. kernel_README_v3.md) :
  - ZERO pip, ZERO reseau : aucun import socket/subprocess/urllib, aucun
    telechargement. `HF_HUB_OFFLINE=1` et `TRANSFORMERS_OFFLINE=1` poses avant
    les imports (ceinture+bretelles : le chargement d'un dossier local n'y va
    de toute facon jamais).
  - Modele de base charge depuis le dataset PRIVE attache
    adrienremond/aeic-t1-ml-base (poids fp32 model.safetensors telecharges une
    fois pour toutes depuis le repo HF officiel sentence-transformers,
    integrite verifiee par sha256 ci-dessous).
  - Libs PREINSTALLEES utilisees telles quelles (sentence-transformers 5.7.0 /
    transformers 5.16.1 / torch 2.11.0+cpu, constate au run v2) : entraînement
    via `SentenceTransformer.old_fit` — boucle torch pure, sans dependance aux
    libs optionnelles `datasets`/`accelerate` (le nouveau `fit()` de ST 5.x
    exige `datasets`, non garanties preinstallees ; voir kernel_README_v3.md).
  - Entrainement CPU assumé (enable_gpu=false, honnete).

Contraintes de protocole (identiques v2, brief P3) :
  - perte MultipleNegativesRankingLoss, anchor=texte_variante, positive=cible ;
  - batch 64 ; LR 2e-5 ; epochs min 3, max 5 ; graine 42 partout ;
  - EARLY STOPPING documente ci-dessous ; MNRL conserve ;
  - AUCUNE donnee autre que les datasets attaches (aucun autre fichier lu) ;
  - sorties : /kaggle/working/model (format sentence-transformers complet) +
    /kaggle/working/metrics.json.

Critere d'early stopping (DOCUMENTE, inchangé v2) :
  metrique suiveuse = Recall@1 d'identification sur le jeu de val : pour chaque
  `texte_variante` de val, on classe par cosinus l'ensemble des cibles uniques
  de val ; on regarde le rang de la vraie cible. R@1 = part des variantes dont
  la vraie cible est 1re. Arret si, apres au moins MIN_EPOCHS=3 epochs, la
  metrique n'a pas strictement ameliore R@1 depuis PATIENCE=2 epochs
  consecutives. Meilleur modele (R@1 max, epoch la plus ancienne en cas
  d'egalite via la sauvegarde au premier gain) ecrit dans model/.

Loss finale rapportee (inchangee v2) : `cos_loss` = moyenne(1 - cos(variante,
cible vraie)) calculee sur train ET val apres chaque epoch (meme formule,
comparable d'une epoch a l'autre), plus R@1/R@5/MRR sur val. Zero-shot val
mesure avant epoch 1 (comparaison honnete, contrainte n°6 de la mission).
"""
import csv
import glob
import hashlib
import json
import os
import random
import time

# --- Offline assume : AVANT tout import lourd (huggingface_hub/transformers ---
# --- lisent ces variables a l'import). Un dossier local ne declenche de toute ---
# --- facon aucun acces reseau.                                                ---
os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"

# ----------------------------- constantes ------------------------------------
SEED = 42
PAIRS_DATASET = "adrienremond/aeic-t1-p2-pairs"      # dataset prive (libelles, D3)
MODEL_DATASET = "adrienremond/aeic-t1-ml-base"       # dataset prive (poids HF publics)
BASE_MODEL_HF = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
MODEL_SAFETENSORS_SHA256 = (
    "eaa086f0ffee582aeb45b36e34cdd1fe2d6de2bef61f8a559a1bbc9bd955917b"
)
BATCH_SIZE = 64          # brief v3 : fourchette 32-64
LR = 2e-5
MIN_EPOCHS = 3           # brief v3 : epochs 3..5 max
MAX_EPOCHS = 5
PATIENCE = 2             # early stopping : 2 epochs consecutives sans gain de R@1
TIME_BUDGET_S = 3300     # garde-fou horloge : ~55 min (brief v3 : rester < ~60 min)
MODEL_DIR = "/kaggle/working/model"
METRICS_PATH = "/kaggle/working/metrics.json"
INPUT_ROOT = "/kaggle/input"
EXPECTED_PAIRS_COLUMNS = ["texte_variante", "cible"]
MODEL_MARKER_FILES = ("modules.json", "config.json", "model.safetensors",
                      "1_Pooling/config.json")


def dataset_root(slug_with_owner):
    """Localise le dossier monte d'un dataset attache. Kaggle a deux layouts :
    recent : /kaggle/input/datasets/<owner>/<slug> ; ancien : /kaggle/input/<slug>.
    Renvoie le chemin existant, sinon SystemExit avec l'inventaire visible."""
    owner, slug = slug_with_owner.split("/")
    candidates = [os.path.join(INPUT_ROOT, "datasets", owner, slug),
                  os.path.join(INPUT_ROOT, slug)]
    for cand in candidates:
        if os.path.isdir(cand):
            print(f"[data] dataset {slug_with_owner} -> {cand}", flush=True)
            return cand
    listing = sorted(glob.glob(os.path.join(INPUT_ROOT, "*")))
    raise SystemExit(f"FATAL: dataset attache {slug_with_owner} introuvable. "
                     f"Chemins testes : {candidates}. /kaggle/input contient : {listing}")


def sha256_of(path, chunk=1 << 20):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        while True:
            block = fh.read(chunk)
            if not block:
                break
            h.update(block)
    return h.hexdigest()


def load_pairs(path):
    rows = []
    with open(path, newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames != EXPECTED_PAIRS_COLUMNS:
            raise SystemExit(f"FATAL: colonnes inattendues dans {path}: {reader.fieldnames}")
        for row in reader:
            a = row["texte_variante"].strip()
            b = row["cible"].strip()
            if a and b:
                rows.append((a, b))
    if not rows:
        raise SystemExit(f"FATAL: {path} vide")
    return rows


def main():
    t_start = time.time()
    torch_threads = os.cpu_count() or 2

    import numpy as np
    import torch
    from sentence_transformers import InputExample, SentenceTransformer
    # MNRL : chemin canonique ST 5.x, repli sur l'alias historique (valide 4.x
    # comme 5.x via le shim deprecations de sentence-transformers).
    try:
        from sentence_transformers.sentence_transformer.losses import (
            MultipleNegativesRankingLoss,
        )
    except ImportError:
        from sentence_transformers.losses import MultipleNegativesRankingLoss
    import sentence_transformers
    import transformers

    random.seed(SEED)
    np.random.seed(SEED)
    torch.manual_seed(SEED)
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(SEED)
    if hasattr(torch.backends, "cudnn"):
        torch.backends.cudnn.deterministic = True
        torch.backends.cudnn.benchmark = False
    torch.set_num_threads(torch_threads)   # CPU : tous les coeurs du worker

    device_name = torch.cuda.get_device_name(0) if torch.cuda.is_available() else "CPU"
    print(f"[boot] python={os.sys.version.split()[0]} torch={torch.__version__} "
          f"sentence-transformers={sentence_transformers.__version__} "
          f"transformers={transformers.__version__} device={device_name} "
          f"threads={torch_threads}", flush=True)
    try:
        inputs = sorted(glob.glob(os.path.join(INPUT_ROOT, "*", "*"))
                        + glob.glob(os.path.join(INPUT_ROOT, "*")))
        print(f"[boot] /kaggle/input (apercu) : {sorted(set(inputs))[:20]}", flush=True)
    except Exception as exc:  # noqa: BLE001 - diagnostic seul
        print(f"[boot] inventaire /kaggle/input impossible : {exc}", flush=True)

    # ------------------- donnees : datasets attaches uniquement ---------------
    pairs_root = dataset_root(PAIRS_DATASET)
    train_path = os.path.join(pairs_root, "train_pairs.csv")
    val_path = os.path.join(pairs_root, "val_pairs.csv")
    if not (os.path.isfile(train_path) and os.path.isfile(val_path)):
        raise SystemExit(f"FATAL: train_pairs.csv/val_pairs.csv absents de {pairs_root}")
    train_pairs = load_pairs(train_path)
    val_pairs = load_pairs(val_path)
    print(f"[data] train={len(train_pairs)} paires | val={len(val_pairs)} paires", flush=True)

    # ---------------- modele de base : dossier LOCAL du dataset attache -------
    model_root = dataset_root(MODEL_DATASET)
    missing = [m for m in MODEL_MARKER_FILES
               if not os.path.isfile(os.path.join(model_root, m))]
    if missing:
        raise SystemExit(f"FATAL: bundle modele incomplet dans {model_root}, manquant : {missing}")
    digest = sha256_of(os.path.join(model_root, "model.safetensors"))
    integrity_ok = digest == MODEL_SAFETENSORS_SHA256
    print(f"[model] {BASE_MODEL_HF} charge depuis {model_root} "
          f"(sha256 model.safetensors {'VERIFIE' if integrity_ok else 'DIVERGENT'})", flush=True)
    if not integrity_ok:
        raise SystemExit(f"FATAL: sha256 model.safetensors inattendu : {digest}")
    model = SentenceTransformer(model_root)
    print(f"[model] charge. max_seq_length={model.max_seq_length} "
          f"dim={model.get_sentence_embedding_dimension()}", flush=True)

    # ----------------------- metriques de validation --------------------------
    val_variants = [v for v, _ in val_pairs]
    val_targets = sorted({c for _, c in val_pairs})
    target_index = {c: i for i, c in enumerate(val_targets)}
    gold = [target_index[c] for _, c in val_pairs]

    def evaluate():
        """cos_loss + R@1/R@5/MRR sur val. Deterministe : eval() coupe le dropout."""
        model.eval()
        emb_v = model.encode(val_variants, batch_size=128, convert_to_numpy=True,
                             normalize_embeddings=True, show_progress_bar=False)
        emb_t = model.encode(val_targets, batch_size=128, convert_to_numpy=True,
                             normalize_embeddings=True, show_progress_bar=False)
        sims = emb_v @ emb_t.T
        ranks = np.argsort(-sims, axis=1)
        n = len(gold)
        r1 = float(np.mean([gold[i] == ranks[i, 0] for i in range(n)]))
        r5 = float(np.mean([gold[i] in ranks[i, :5] for i in range(n)]))
        mrr = float(np.mean([1.0 / (float(np.where(ranks[i] == gold[i])[0][0]) + 1.0)
                             for i in range(n)]))
        gold_vecs = emb_t[gold]
        cos_loss = float(np.mean(1.0 - np.sum(emb_v * gold_vecs, axis=1)))
        return {"cos_loss": round(cos_loss, 6),
                "recall_at_1": round(r1, 6),
                "recall_at_5": round(r5, 6),
                "mrr": round(mrr, 6)}

    def eval_train_cos_loss():
        emb_a = model.encode([a for a, _ in train_pairs], batch_size=128,
                             convert_to_numpy=True, normalize_embeddings=True,
                             show_progress_bar=False)
        emb_b = model.encode([b for _, b in train_pairs], batch_size=128,
                             convert_to_numpy=True, normalize_embeddings=True,
                             show_progress_bar=False)
        return round(float(np.mean(1.0 - np.sum(emb_a * emb_b, axis=1))), 6)

    zero_shot = evaluate()
    print(f"[val zero-shot] {zero_shot}", flush=True)

    # ----------------------------- entrainement -------------------------------
    # old_fit : boucle torch pure de ST (smart batching par batch, AdamW,
    # WarmupLinear, clipping max_grad_norm=1) — cf. docstring v3. Les
    # hyperparametres reproduisent la v2 (batch 64, lr 2e-5, warmup total 10%).
    train_loss = MultipleNegativesRankingLoss(model)
    train_examples = [InputExample(texts=[a, b], label=1.0)
                      for a, b in train_pairs]
    from torch.utils.data import DataLoader
    train_loader = DataLoader(train_examples, shuffle=True, batch_size=BATCH_SIZE)
    steps_per_epoch = len(train_loader)
    warmup_total = int(0.10 * steps_per_epoch * MAX_EPOCHS)

    history = []
    best_r1 = -1.0
    best = None
    epochs_since_improve = 0
    stopped_early = False
    time_budget_hit = False

    for epoch in range(1, MAX_EPOCHS + 1):
        if time.time() - t_start > TIME_BUDGET_S:
            time_budget_hit = True
            print(f"[budget] {TIME_BUDGET_S} s atteintes avant epoch {epoch} -> arret, "
                  f"meilleur modele deja sauvegarde conserve", flush=True)
            break
        t_ep = time.time()
        model.old_fit(train_objectives=[(train_loader, train_loss)],
                      epochs=1,
                      warmup_steps=max(warmup_total - (epoch - 1) * steps_per_epoch, 0),
                      optimizer_params={"lr": LR},
                      use_amp=False,
                      show_progress_bar=False)
        val_m = evaluate()
        rec = {"epoch": epoch,
               "epoch_seconds": round(time.time() - t_ep, 1),
               "train_cos_loss": eval_train_cos_loss(),
               "val": val_m}
        history.append(rec)
        print(f"[epoch {epoch}] {rec}", flush=True)

        if val_m["recall_at_1"] > best_r1:
            best_r1 = val_m["recall_at_1"]
            best = {"epoch": epoch, **val_m}
            model.save(MODEL_DIR)
            print(f"[checkpoint] meilleur modele (R@1={best_r1:.4f}) sauvegarde -> {MODEL_DIR}",
                  flush=True)
            epochs_since_improve = 0
        else:
            epochs_since_improve += 1
            if epoch >= MIN_EPOCHS and epochs_since_improve >= PATIENCE:
                stopped_early = True
                print(f"[early-stop] R@1 sans amelioration stricte depuis {PATIENCE} epochs "
                      f"(seuil min {MIN_EPOCHS} epochs respecte)", flush=True)
                break

    # ------------------------------ sorties -----------------------------------
    if not os.path.isdir(MODEL_DIR):
        model.save(MODEL_DIR)

    final_rec = history[-1]
    metrics = {
        "mission": "AEIC T1-P2 fine-tune (brief P3, kernel v3 OFFLINE CPU)",
        "offline": {"mode": "100% offline : zero pip, zero reseau, zero telechargement",
                    "env": {"HF_HUB_OFFLINE": os.environ.get("HF_HUB_OFFLINE"),
                            "TRANSFORMERS_OFFLINE": os.environ.get("TRANSFORMERS_OFFLINE")},
                    "kernel_metadata": {"enable_gpu": False, "enable_internet": False}},
        "dataset": {"id": PAIRS_DATASET,
                    "files": {"train_pairs.csv": len(train_pairs),
                              "val_pairs.csv": len(val_pairs)},
                    "columns": EXPECTED_PAIRS_COLUMNS,
                    "note": "dataset prive, decision D3 (libelles produits seuls), "
                            "revue anonymisation P2-SEC CONFORME"},
        "config": {"seed": SEED,
                   "base_model": BASE_MODEL_HF,
                   "base_model_dataset": {"id": MODEL_DATASET,
                                          "mounted_at": model_root,
                                          "model_safetensors_sha256": digest,
                                          "integrity_verified": integrity_ok,
                                          "source": "repo HF officiel sentence-transformers, "
                                                   "poids fp32 (pas l'ONNX int8)"},
                   "versions_reelles": {"sentence_transformers": sentence_transformers.__version__,
                                        "transformers": transformers.__version__,
                                        "torch": torch.__version__},
                   "training_api": "SentenceTransformer.old_fit (boucle torch pure ST 5.x, "
                                   "sans libs optionnelles datasets/accelerate)",
                   "torch_threads": torch_threads,
                   "device": device_name,
                   "loss": "MultipleNegativesRankingLoss",
                   "anchor": "texte_variante",
                   "positive": "cible",
                   "batch_size": BATCH_SIZE,
                   "lr": LR,
                   "min_epochs": MIN_EPOCHS,
                   "max_epochs": MAX_EPOCHS,
                   "patience_early_stopping": PATIENCE,
                   "early_stopping_metric": "val recall_at_1 (rappel d'identification, "
                                            "classement cosinus des cibles uniques de val)",
                   "warmup_steps_total": warmup_total,
                   "time_budget_seconds": TIME_BUDGET_S},
        "val_zero_shot": zero_shot,
        "history": history,
        "best": best,
        "final_epoch": final_rec["epoch"],
        "final_train_cos_loss": final_rec["train_cos_loss"],
        "final_val": final_rec["val"],
        "gain_val_recall_at_1_vs_zero_shot": round(best["recall_at_1"] - zero_shot["recall_at_1"], 6),
        "stopped_early": stopped_early,
        "time_budget_hit": time_budget_hit,
        "epochs_run": len(history),
        "model_dir": MODEL_DIR,
        "total_seconds": round(time.time() - t_start, 1),
    }
    with open(METRICS_PATH, "w", encoding="utf-8") as fh:
        json.dump(metrics, fh, indent=2, ensure_ascii=False)

    print("\n================ RESUME FINAL P3 v3 (offline CPU) ================", flush=True)
    print(f"epochs executees       : {len(history)} (max {MAX_EPOCHS}, early_stop={stopped_early}, "
          f"budget_temps={time_budget_hit})", flush=True)
    print(f"loss finale (train)    : cos_loss={final_rec['train_cos_loss']}", flush=True)
    print(f"loss finale (val)      : cos_loss={final_rec['val']['cos_loss']}", flush=True)
    print(f"val zero-shot          : {zero_shot}", flush=True)
    print(f"val meilleur (ep {best['epoch']}) : {best}", flush=True)
    print(f"gain R@1               : {metrics['gain_val_recall_at_1_vs_zero_shot']}", flush=True)
    print(f"modele                 : {MODEL_DIR}", flush=True)
    print(f"metriques              : {METRICS_PATH}", flush=True)
    print(f"duree totale           : {metrics['total_seconds']} s sur {device_name} "
          f"({torch_threads} threads)", flush=True)
    print("==================================================================", flush=True)


if __name__ == "__main__":
    main()

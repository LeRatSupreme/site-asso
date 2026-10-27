# -*- coding: utf-8 -*-
"""
Kernel Kaggle P3 — Fine-tune AEIC T1-P2
=======================================

Mission : fine-tuner `paraphrase-multilingual-MiniLM-L12-v2` sur les paires
augmentées du dataset PRIVE adrienremond/aeic-t1-p2-pairs
(colonnes `texte_variante,cible` — P1/dev-factures, revue anonymisation P2-SEC
CONFORME, decision D3 : libelles produits seuls).

Contrat (brief P3_kaggle.md) :
  - perte MultipleNegativesRankingLoss sur train_pairs.csv (anchor=texte_variante,
    positive=cible) ;
  - batch raisonnable (64, dans la fourchette 64-128) ;
  - epochs 3..10 avec EARLY STOPPING sur val_pairs.csv, critere documente ci-dessous ;
  - graine fixee (42), reproductibilite : torch/numpy/random seedes + cuDNN deterministe ;
  - AUCUNE donnee autre que le dataset attache (aucun telechargement de corpus,
    aucun /kaggle/input hors les 2 CSV attendus) ;
  - en sortie : modele complet dans /kaggle/working/model/ + /kaggle/working/metrics.json
    + resume imprime (loss finale + metriques val).

Critere d'early stopping (DOCUMENTE) :
  metrique suiveuse = Recall@1 d'identification sur le jeu de val : pour chaque
  `texte_variante` de val, on classe par cosinus l'ensemble des cibles uniques de val ;
  on regarde le rang de la vraie cible. R@1 = part des variantes dont la vraie cible
  est 1re. Arret si, apres au moins MIN_EPOCHS=3 epochs, la metrique n'a pas
  strictement ameliore R@1 depuis PATIENCE=2 epochs consecutives. Meilleur modele
  (R@1 max, puis epoch la plus ancienne en cas d'egalite) sauvegarde dans model/.

Loss finale rapportee : SentenceTransformer.fit() n'expose pas la loss d'entrainement
en API stable ; on rapporte `cos_loss` = moyenne(1 - cos(variante, cible vraie)) calculee
sur train ET val apres chaque epoch (comparable d'une epoch a l'autre, meme formule),
plus R@1/R@5/MRR sur val.
"""
import csv
import glob
import json
import os
import random
import subprocess
import sys
import time

# ----------------------------- constantes ------------------------------------
SEED = 42
BASE_MODEL = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
ST_VERSION = "sentence-transformers==5.7.0"
TRANSFORMERS_VERSION = "transformers==4.57.6"
BATCH_SIZE = 64          # fourchette brief : 64-128
LR = 2e-5
MIN_EPOCHS = 3           # brief : epochs 3..10
MAX_EPOCHS = 10
PATIENCE = 2             # early stopping : 2 epochs consecutives sans gain de R@1
MODEL_DIR = "/kaggle/working/model"
METRICS_PATH = "/kaggle/working/metrics.json"
INPUT_ROOT = "/kaggle/input"
EXPECTED_FILES = ("train_pairs.csv", "val_pairs.csv")
EXPECTED_COLUMNS = ["texte_variante", "cible"]


def pip_install():
    """Installe la stack pinned (internet active). Echec = arret immediat."""
    cmd = [sys.executable, "-m", "pip", "install", "--quiet", "--no-input",
           ST_VERSION, TRANSFORMERS_VERSION]
    reqs = " ".join(cmd[4:])
    print(f"[env] pip install {reqs} ...", flush=True)
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        print(proc.stdout[-4000:])
        print(proc.stderr[-4000:])
        raise SystemExit(f"FATAL: pip install a echoue (code {proc.returncode})")
    print("[env] pip install OK", flush=True)


def find_input(name):
    """Localise UNIQUEMENT les CSV attendus du dataset attache (rien d'autre)."""
    hits = sorted(glob.glob(os.path.join(INPUT_ROOT, "**", name), recursive=True))
    if not hits:
        raise SystemExit(f"FATAL: {name} introuvable sous {INPUT_ROOT} (dataset attache ?)")
    print(f"[data] {name} -> {hits[0]}", flush=True)
    return hits[0]


def load_pairs(path):
    rows = []
    with open(path, newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames != EXPECTED_COLUMNS:
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
    pip_install()

    import numpy as np
    import torch
    from sentence_transformers import SentenceTransformer, losses
    from torch.utils.data import DataLoader

    random.seed(SEED)
    np.random.seed(SEED)
    torch.manual_seed(SEED)
    torch.cuda.manual_seed_all(SEED)
    torch.backends.cudnn.deterministic = True
    torch.backends.cudnn.benchmark = False

    device_name = torch.cuda.get_device_name(0) if torch.cuda.is_available() else "CPU"
    print(f"[env] torch={torch.__version__} "
          f"sentence-transformers={__import__('sentence_transformers').__version__} "
          f"transformers={__import__('transformers').__version__} device={device_name}", flush=True)

    # -------------------------- donnees (dataset attache uniquement) ----------
    train_pairs = load_pairs(find_input(EXPECTED_FILES[0]))
    val_pairs = load_pairs(find_input(EXPECTED_FILES[1]))
    print(f"[data] train={len(train_pairs)} paires | val={len(val_pairs)} paires", flush=True)

    model = SentenceTransformer(BASE_MODEL)

    # ----------------------- metriques de validation --------------------------
    # Rappel d'identification : classement des cibles uniques de val par cosinus.
    val_variants = [v for v, _ in val_pairs]
    val_targets = sorted({c for _, c in val_pairs})
    target_index = {c: i for i, c in enumerate(val_targets)}
    gold = [target_index[c] for _, c in val_pairs]

    def evaluate(pairs, variants, targets, gold_idx):
        """cos_loss + R@1/R@5/MRR. Deterministe (encode sans dropout)."""
        emb_v = model.encode(variants, batch_size=128, convert_to_numpy=True,
                             normalize_embeddings=True, show_progress_bar=False)
        emb_t = model.encode(targets, batch_size=128, convert_to_numpy=True,
                             normalize_embeddings=True, show_progress_bar=False)
        sims = emb_v @ emb_t.T
        ranks = np.argsort(-sims, axis=1)
        n = len(gold_idx)
        r1 = float(np.mean([gold_idx[i] == ranks[i, 0] for i in range(n)]))
        r5 = float(np.mean([gold_idx[i] in ranks[i, :5] for i in range(n)]))
        mrr = float(np.mean([1.0 / (float(np.where(ranks[i] == gold_idx[i])[0][0]) + 1.0)
                             for i in range(n)]))
        gold_vecs = emb_t[gold_idx]
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

    zero_shot = evaluate(val_pairs, val_variants, val_targets, gold)
    print(f"[val zero-shot] {zero_shot}", flush=True)

    # ----------------------------- entrainement -------------------------------
    train_loss = losses.MultipleNegativesRankingLoss(model)
    train_loader = DataLoader(train_pairs, shuffle=True, batch_size=BATCH_SIZE)
    steps_per_epoch = len(train_loader)
    warmup_total = int(0.10 * steps_per_epoch * MAX_EPOCHS)

    history = []
    best_r1 = -1.0
    best = None
    epochs_since_improve = 0
    stopped_early = False

    for epoch in range(1, MAX_EPOCHS + 1):
        t_ep = time.time()
        model.fit(train_dataloader=train_loader,
                  train_loss=train_loss,
                  epochs=1,
                  warmup_steps=max(warmup_total - (epoch - 1) * steps_per_epoch, 0),
                  optimizer_params={"lr": LR},
                  show_progress_bar=False,
                  use_amp=True)
        val_m = evaluate(val_pairs, val_variants, val_targets, gold)
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
        "mission": "AEIC T1-P2 fine-tune (brief P3_kaggle)",
        "dataset": {"id": "adrienremond/aeic-t1-p2-pairs",
                    "files": {"train_pairs.csv": len(train_pairs),
                              "val_pairs.csv": len(val_pairs)},
                    "columns": EXPECTED_COLUMNS,
                    "note": "dataset prive, decision D3 (libelles produits seuls), "
                            "revue anonymisation P2-SEC CONFORME"},
        "config": {"seed": SEED,
                   "base_model": BASE_MODEL,
                   "sentence_transformers": ST_VERSION.split("==")[1],
                   "transformers": TRANSFORMERS_VERSION.split("==")[1],
                   "torch": torch.__version__,
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
                   "warmup_steps_total": warmup_total},
        "val_zero_shot": zero_shot,
        "history": history,
        "best": best,
        "final_epoch": final_rec["epoch"],
        "final_train_cos_loss": final_rec["train_cos_loss"],
        "final_val": final_rec["val"],
        "gain_val_recall_at_1_vs_zero_shot": round(best["recall_at_1"] - zero_shot["recall_at_1"], 6),
        "stopped_early": stopped_early,
        "epochs_run": len(history),
        "model_dir": MODEL_DIR,
        "total_seconds": round(time.time() - t_start, 1),
    }
    with open(METRICS_PATH, "w", encoding="utf-8") as fh:
        json.dump(metrics, fh, indent=2, ensure_ascii=False)

    print("\n================ RESUME FINAL P3 ================", flush=True)
    print(f"epochs executees       : {len(history)} (max {MAX_EPOCHS}, early_stop={stopped_early})",
          flush=True)
    print(f"loss finale (train)    : cos_loss={final_rec['train_cos_loss']}", flush=True)
    print(f"loss finale (val)      : cos_loss={final_rec['val']['cos_loss']}", flush=True)
    print(f"val zero-shot          : {zero_shot}", flush=True)
    print(f"val meilleur (ep {best['epoch']}) : {best}", flush=True)
    print(f"gain R@1               : {metrics['gain_val_recall_at_1_vs_zero_shot']}", flush=True)
    print(f"modele                 : {MODEL_DIR}", flush=True)
    print(f"metriques              : {METRICS_PATH}", flush=True)
    print(f"duree totale           : {metrics['total_seconds']} s sur {device_name}", flush=True)
    print("=================================================", flush=True)


if __name__ == "__main__":
    main()

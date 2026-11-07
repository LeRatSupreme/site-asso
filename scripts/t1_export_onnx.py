#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T1 P2-auto (brief A2, dev-factures) — Conversion ONNX int8 d'un fine-tuné.

Pipeline P4/V2-C RÉUTILISÉ À L'IDENTIQUE (méthode prouvée, déjà exécutée deux
fois sur ce VPS — contrainte : ne pas réinventer). SEULS les CHEMINS changent :
l'entrée (sortie kernel du run P2-auto) et la sortie (dossier du run) sont
PARAMÉTRÉS ; la référence de signature/parité reste le modèle en production
(data/eval_t1/.cache/model-int8) et la référence de bruit int8 le bundle de
base vérifié (data/eval_t1/p2/base_model — mêmes poids que le zéro-shot).

Entrée  : --entree <run>/kernel_output/model/   (safetensors fp32 du kernel v4)
Sortie  : --sortie <run>/convert/model-int8/    (model_optimized.onnx ~118 Mo
          + tokenizer/config au layout fastembed) + SHA256SUMS + tailles.txt

Méthode (identique P4/V2-C) :
  1. Export ONNX fp32 de BertModel SANS pooler (signature du modèle de prod :
     3 entrées int64, 1 sortie last_hidden_state [batch, seq, 384]).
  2. Folding des Transpose(initializer), puis quantification dynamique int8
     (quantize_dynamic, QInt8, per_channel=False — méthode des exports Xenova).

Contrôles embarqués (identiques V2-C) :
  - parité tokenizer production ↔ fine-tuné sur tous les libellés du jeu figé ;
  - signature entrées/sorties identique au modèle de production ;
  - fidélité cos(int8, fp32) ≥ référence de production (bruit int8 mesuré) ;
  - différenciation : cos(int8 run, int8 prod) < 1 (le run a déplacé les poids).

RUNBOOK (depuis la racine du dépôt) :
  ~/AEIC/.venv-p2conv/bin/python3 scripts/t1_export_onnx.py \
      --entree data/eval_t1/p2/auto/run_<ts>/kernel_output/model \
      --sortie  data/eval_t1/p2/auto/run_<ts>/convert/model-int8
"""

from __future__ import annotations

import argparse
import csv
import json
import shutil
import subprocess
import sys
from pathlib import Path

import numpy as np
import onnx
import onnxruntime as ort
import torch
from tokenizers import Tokenizer
from transformers import BertModel

REPO = Path(__file__).resolve().parent.parent
DATA = REPO / "data" / "eval_t1"
BASE = DATA / "p2" / "base_model"          # bundle de base vérifié (sha256)
PROD_INT8_DEFAUT = DATA / ".cache" / "model-int8"   # production ACTUELLE

MAX_LEN = 128


def charger_labels() -> list[str]:
    """Tous les libellés évalués par t1_similarity.py (lecture seule du jeu figé)."""
    labels: set[str] = set()
    with open(DATA / "univers.csv", newline="", encoding="utf-8") as f:
        for r in csv.DictReader(f):
            labels.add(r["libelle"])
    for name in ("positifs_semantiques.csv", "positifs_casse.csv"):
        with open(DATA / name, newline="", encoding="utf-8") as f:
            for r in csv.DictReader(f):
                labels.add(r["libelle_brut"])
                labels.add(r["libelle_cible"])
    out = sorted(labels)
    print(f"[labels] {len(out)} libellés du jeu figé")
    return out


def tokenizer_fastembed(tokenizer_json: Path) -> Tokenizer:
    """Tokenizer configuré comme fastembed (load_tokenizer de fastembed 0.9.1)."""
    cfg = json.loads((tokenizer_json.parent / "tokenizer_config.json").read_text())
    t = Tokenizer.from_file(str(tokenizer_json))
    t.enable_truncation(max_length=cfg.get("model_max_length") or cfg.get("max_length") or MAX_LEN,
                        direction=cfg.get("truncation_side") or "right")
    pad_token = cfg.get("pad_token")
    pad_id = t.token_to_id(pad_token)
    t.enable_padding(direction=cfg.get("padding_side") or "right",
                     pad_id=pad_id, pad_token=pad_token, pad_type_id=0, length=None)
    return t


def encoder(t: Tokenizer, labels: list[str]) -> dict[str, np.ndarray]:
    enc = t.encode_batch(labels)
    return {
        "input_ids": np.array([e.ids for e in enc], dtype=np.int64),
        "attention_mask": np.array([e.attention_mask for e in enc], dtype=np.int64),
        "token_type_ids": np.array([e.type_ids for e in enc], dtype=np.int64),
    }


def mean_pooling_l2(last_hidden: np.ndarray, mask: np.ndarray) -> np.ndarray:
    m = mask.astype(np.float32)
    summed = (last_hidden * m[:, :, None]).sum(axis=1)
    counts = np.clip(m.sum(axis=1, keepdims=True), 1e-9, None)
    v = summed / counts
    return v / np.linalg.norm(v, axis=1, keepdims=True)


def main() -> int:
    parser = argparse.ArgumentParser(prog="t1_export_onnx.py", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--entree", required=True, metavar="DOSSIER",
                        help="dossier model/ de la sortie kernel (safetensors fp32)")
    parser.add_argument("--sortie", required=True, metavar="DOSSIER",
                        help="dossier du layout fastembed à produire (model-int8)")
    parser.add_argument("--prod", metavar="DOSSIER", default=None,
                        help="modèle de production (référence signature/parité/différenciation), "
                             f"défaut {PROD_INT8_DEFAUT.relative_to(REPO)}")
    args = parser.parse_args()

    finetuned = Path(args.entree)
    if not finetuned.is_absolute():
        finetuned = REPO / finetuned
    sortie = Path(args.sortie)
    if not sortie.is_absolute():
        sortie = REPO / sortie
    prod = Path(args.prod) if args.prod else PROD_INT8_DEFAUT
    if not prod.is_absolute():
        prod = REPO / prod
    convert = sortie.parent

    marqueurs = ("model.safetensors", "config.json", "tokenizer.json", "tokenizer_config.json")
    manquants = [m for m in marqueurs if not (finetuned / m).is_file()]
    if manquants:
        print(f"FATAL : bundle fine-tuné incomplet dans {finetuned} : {manquants}", file=sys.stderr)
        return 2
    convert.mkdir(parents=True, exist_ok=True)
    labels = charger_labels()

    # -- 0. référence : opset + signature du modèle en production ----------------
    ref = onnx.load(str(prod / "model_optimized.onnx"))
    ref_opset = max(o.version for o in ref.opset_import if o.domain in ("", "ai.onnx"))
    ref_in = [(i.name, i.type) for i in ref.graph.input]
    ref_out = [(o.name, o.type) for o in ref.graph.output]
    print(f"[ref] opset {ref_opset} · entrées {ref_in} · sorties {ref_out}")

    # -- 1. parité tokenizer production vs fine-tuné sur tout le jeu figé --------
    t_prod = tokenizer_fastembed(prod / "tokenizer.json")
    t_ft = tokenizer_fastembed(finetuned / "tokenizer.json")
    e_prod, e_ft = encoder(t_prod, labels), encoder(t_ft, labels)
    for k in ("input_ids", "attention_mask", "token_type_ids"):
        assert np.array_equal(e_prod[k], e_ft[k]), f"parité tokenizer KO sur {k}"
    print(f"[tokenizer] parité OK sur {len(labels)} libellés (ids identiques prod ↔ fine-tuné)")

    # -- 2. export ONNX fp32 (BertModel sans pooler, signature = référence) ------
    torch.manual_seed(42)
    model = BertModel.from_pretrained(str(finetuned), local_files_only=True,
                                      attn_implementation="eager")
    assert model.config._attn_implementation == "eager", "attention eager non appliquée"
    model.eval()
    model.requires_grad_(False)
    model.pooler = None  # la référence n'a PAS de pooler (sortie = last_hidden_state)

    class Export(torch.nn.Module):
        def __init__(self, inner):
            super().__init__()
            self.inner = inner  # sous-module → poids exportés en initializers (quantifiables)

        def forward(self, input_ids, attention_mask, token_type_ids):
            return self.inner(input_ids=input_ids, attention_mask=attention_mask,
                              token_type_ids=token_type_ids).last_hidden_state

    ex = Export(model).eval()
    ids = torch.tensor([[5, 100, 9999, 42]], dtype=torch.int64)
    msk = torch.ones_like(ids)
    tty = torch.zeros_like(ids)
    fp32_path = convert / "finetuned_auto_fp32.onnx"
    kwargs = dict(
        input_names=["input_ids", "attention_mask", "token_type_ids"],
        output_names=["last_hidden_state"],
        dynamic_axes={"input_ids": {0: "batch_size", 1: "sequence_length"},
                      "attention_mask": {0: "batch_size", 1: "sequence_length"},
                      "token_type_ids": {0: "batch_size", 1: "sequence_length"},
                      "last_hidden_state": {0: "batch_size", 1: "sequence_length"}},
        opset_version=ref_opset,
        do_constant_folding=False,  # poids = initializers (sinon Constant nodes non quantifiables)
    )
    try:
        with torch.no_grad():
            torch.onnx.export(ex, (ids, msk, tty), str(fp32_path), dynamo=False, **kwargs)
        print("[export] exporteur TorchScript (dynamo=False)")
    except Exception as exc:  # torch récents : l'exporteur historique peut manquer
        print(f"[export] TorchScript indisponible ({type(exc).__name__}) → exporteur dynamo")
        torch.onnx.export(ex, {"input_ids": ids, "attention_mask": msk, "token_type_ids": tty},
                          str(fp32_path), dynamo=True, **kwargs)
    g = onnx.load(str(fp32_path)).graph
    got_in = [(i.name, i.type.tensor_type.elem_type) for i in g.input]
    got_out = [(o.name, o.type.tensor_type.elem_type) for o in g.output]
    print(f"[export] fp32 : {fp32_path.stat().st_size/1e6:.1f} Mo · entrées {got_in} · sorties {got_out}")
    assert [n for n, _ in got_in] == [n for n, _ in ref_in], "noms d'entrées ≠ référence"
    assert [n for n, _ in got_out] == [n for n, _ in ref_out], "noms de sorties ≠ référence"

    # -- 3. quantification dynamique int8 (méthode Xenova, identique P4) ---------
    from onnxruntime.quantization import QuantType, quantize_dynamic

    int8_path = convert / "finetuned_auto_int8.onnx"
    from onnx import numpy_helper

    prep = onnx.load(str(fp32_path))
    inits_avant = {i.name for i in prep.graph.initializer}
    producers = {o: n for n in prep.graph.node for o in n.output}
    n_folds = 0
    folds = {}  # nom d'initializer d'origine → nom foldé (dédupliqué)
    for n in prep.graph.node:
        if n.op_type != "MatMul":
            continue
        for pos in (1, 0):
            src = producers.get(n.input[pos])
            if src is None or src.op_type != "Transpose" or src.input[0] not in inits_avant:
                continue
            if src.input[0] in folds:
                inputs = list(n.input)
                inputs[pos] = folds[src.input[0]]
                del n.input[:]
                n.input.extend(inputs)
                n_folds += 1
                break
            arr = numpy_helper.to_array(next(i for i in prep.graph.initializer if i.name == src.input[0]))
            if arr.ndim != 2:
                continue  # reshape d'attention — intouchable
            perm = next((a for a in src.attribute if a.name == "perm"), None)
            perm = list(perm.ints) if perm else [1, 0]
            new_name = src.input[0] + "_fold"
            prep.graph.initializer.append(
                numpy_helper.from_array(np.ascontiguousarray(arr.transpose(perm)), name=new_name))
            folds[src.input[0]] = new_name
            inputs = list(n.input)
            inputs[pos] = new_name
            del n.input[:]
            n.input.extend(inputs)
            n_folds += 1
            break
    vivantes = {inp for nn in prep.graph.node for inp in nn.input}
    pliees = {i.name[:-5] for i in prep.graph.initializer if i.name.endswith("_fold")}
    noeuds = [nn for nn in prep.graph.node
              if not (nn.op_type == "Transpose" and nn.input[0] in pliees
                      and nn.output[0] not in vivantes)]
    del prep.graph.node[:]
    prep.graph.node.extend(noeuds)
    prep_path = convert / "finetuned_auto_fp32_prep.onnx"
    onnx.save(prep, str(prep_path))
    print(f"[prep] {n_folds} Transpose de poids foldées → {prep_path}")
    quantize_dynamic(str(prep_path), str(int8_path), weight_type=QuantType.QInt8)
    taille_mo = int8_path.stat().st_size / 1e6
    print(f"[int8] {taille_mo:.1f} Mo ({int8_path.stat().st_size} o) — cible ≤ ~130 Mo")
    assert taille_mo <= 130.0, "dépasse la cible de taille"

    # -- 4. fidélité numérique fp32 torch vs int8 onnx ---------------------------
    # Critère équitable (identique P4/V2-C) : la fidélité de l'export int8 doit
    # être AU MOINS celle du modèle de production (bruit int8 de référence =
    # base fp32 vs son export int8 — mêmes nombres que le rapport P4).
    e_base = encoder(tokenizer_fastembed(BASE / "tokenizer.json"), labels)
    assert all(np.array_equal(e_prod[k], e_base[k]) for k in e_prod), "tokenizer base ≠ prod"

    so = ort.SessionOptions()
    sess_ft = ort.InferenceSession(str(int8_path), so, providers=["CPUExecutionProvider"])
    sess_prod = ort.InferenceSession(str(prod / "model_optimized.onnx"), so,
                                     providers=["CPUExecutionProvider"])

    def embeddings(sess, inputs):
        outs = []
        for i in range(0, len(labels), 32):
            batch = {k: v[i:i + 32] for k, v in inputs.items()}
            outs.append(sess.run(["last_hidden_state"], batch)[0])
        return mean_pooling_l2(np.concatenate(outs), inputs["attention_mask"])

    v_int8_ft = embeddings(sess_ft, e_ft)
    v_int8_prod = embeddings(sess_prod, e_prod)   # production actuelle

    with torch.no_grad():
        outs = []
        for i in range(0, len(labels), 32):
            outs.append(ex(input_ids=torch.from_numpy(e_ft["input_ids"][i:i + 32]),
                           attention_mask=torch.from_numpy(e_ft["attention_mask"][i:i + 32]),
                           token_type_ids=torch.from_numpy(e_ft["token_type_ids"][i:i + 32])).numpy())
        v_fp32 = mean_pooling_l2(np.concatenate(outs), e_ft["attention_mask"])

    cos_q = (v_int8_ft * v_fp32).sum(axis=1)               # fidélité export run
    cos_diff_prod = (v_int8_ft * v_int8_prod).sum(axis=1)  # run vs production

    # référence de bruit int8 : export int8 de la base (zéro-shot) vs base fp32
    model_base = BertModel.from_pretrained(str(BASE), local_files_only=True)
    model_base.eval()
    model_base.requires_grad_(False)
    model_base.pooler = None
    with torch.no_grad():
        outs = []
        for i in range(0, len(labels), 32):
            outs.append(model_base(
                input_ids=torch.from_numpy(e_base["input_ids"][i:i + 32]),
                attention_mask=torch.from_numpy(e_base["attention_mask"][i:i + 32]),
                token_type_ids=torch.from_numpy(e_base["token_type_ids"][i:i + 32]),
            ).last_hidden_state.numpy())
        v_fp32_base = mean_pooling_l2(np.concatenate(outs), e_base["attention_mask"])
    # l'export int8 zéro-shot (rollback P4) est la référence de bruit
    zero_int8 = DATA / "p2" / "convert" / "model-int8-zeroshot"
    if (zero_int8 / "model_optimized.onnx").is_file():
        sess_zero = ort.InferenceSession(str(zero_int8 / "model_optimized.onnx"), so,
                                         providers=["CPUExecutionProvider"])
        v_int8_zero = embeddings(sess_zero, e_base)
    else:  # repli documenté : la production courante si la référence est absente
        v_int8_zero = v_int8_prod
    cos_ref = (v_int8_zero * v_fp32_base).sum(axis=1)

    print(f"[fidélité fine-tuné run] cos(int8, fp32) : min {cos_q.min():.6f} · moy {cos_q.mean():.6f}")
    print(f"[fidélité référence] cos(int8 réf., base fp32) : min {cos_ref.min():.6f} · moy {cos_ref.mean():.6f}")
    print(f"[poids changés vs PROD] cos(int8 run, int8 prod) : min {cos_diff_prod.min():.4f} · "
          f"moy {cos_diff_prod.mean():.4f}")
    assert cos_q.mean() >= cos_ref.mean() - 0.002, "fidélité int8 < référence de production"
    assert cos_q.min() >= cos_ref.min() - 0.02, "fidélité int8 (pire libellé) très en dessous de la réf."
    assert cos_diff_prod.mean() < 1.0, "les poids semblent identiques au modèle en production ?!"

    # -- 5. assemblage du dossier fastembed --------------------------------------
    if sortie.exists():
        shutil.rmtree(sortie)
    sortie.mkdir(parents=True)
    shutil.copy2(int8_path, sortie / "model_optimized.onnx")   # nom attendu par fastembed
    for f in ("tokenizer.json", "tokenizer_config.json", "config.json"):
        shutil.copy2(finetuned / f, sortie / f)
    # special_tokens_map.json : absent du bundle ST 5.7 — repris du bundle de base
    # vérifié (même tokenizer, le fine-tune n'y touche pas ; comportement documenté P4).
    shutil.copy2(BASE / "special_tokens_map.json", sortie / "special_tokens_map.json")

    # -- 6. empreintes + résumé ---------------------------------------------------
    fichiers = ["model_optimized.onnx", "tokenizer.json", "tokenizer_config.json",
                "config.json", "special_tokens_map.json"]
    lignes = []
    for f in fichiers:
        h = subprocess.run(["sha256sum", str(sortie / f)], capture_output=True, text=True).stdout
        lignes.append(h.replace(str(sortie), "model-int8").strip())
    (convert / "SHA256SUMS_model_int8_auto").write_text("\n".join(lignes) + "\n")
    (convert / "tailles_auto.txt").write_text(
        f"entree : {finetuned}\n"
        f"production (référence) : {prod}\n"
        f"finetuned_auto_fp32.onnx : {fp32_path.stat().st_size} o\n"
        f"finetuned_auto_int8.onnx : {int8_path.stat().st_size} o ({taille_mo:.1f} Mo)\n"
        f"opset référence : {ref_opset}\n"
        f"cos(int8_run,fp32_run) min/moy : {cos_q.min():.6f}/{cos_q.mean():.6f}\n"
        f"cos(ref_int8,base_fp32) min/moy : {cos_ref.min():.6f}/{cos_ref.mean():.6f}\n"
        f"cos(int8_run,int8_prod) min/moy : {cos_diff_prod.min():.4f}/{cos_diff_prod.mean():.4f}\n"
        f"parité tokenizer : OK sur {len(labels)} libellés\n")
    print("[ok] assemblé :", sortie)
    return 0


if __name__ == "__main__":
    sys.exit(main())

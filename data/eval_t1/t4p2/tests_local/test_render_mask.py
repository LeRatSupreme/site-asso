# -*- coding: utf-8 -*-
"""Test local A — rendu ChatML + masquage sur le corpus REEL (tokenizer Qwen3 reel).

Repetition generale du chemin donnees du kernel v5 SANS Kaggle :
  - importe kernel/train.py comme module (aucune execution de main()) ;
  - monkeypathe INPUT_ROOT vers un stub reproduisant le layout /kaggle/input ;
  - verifie sur les 520 conversations (train+val) :
      1. propriete de prefixe ChatML a CHAQUE message (assertion du kernel) ;
      2. en-tete assistant = 3 tokens '<|im_start|>' 'assistant' '\n' ;
      3. chaque conversation possede des tokens assistant labels ;
      4. aucune conversation ne depasse MAX_LEN=2048 (aucune troncature) ;
      5. le decode du premier span label reconstruit le contenu assistant ;
      6. statistiques de longueur EXACTES (tokens reels, pas estimation).

Test B (dans ce meme fichier, --micro) : pipeline complet sur micro-modele
Qwen3 2 couches construit localement — cf. test_micro_e2e.py.
"""
import importlib.util
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
T4P2 = os.path.dirname(HERE)
STUB = os.path.join(HERE, "kaggle_input_stub")
VENV_BIN = "/home/ubuntu/AEIC/.venv-p2conv/bin/python"

def load_kernel_module():
    spec = importlib.util.spec_from_file_location(
        "kernel_train", os.path.join(T4P2, "kernel", "train.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def main():
    km = load_kernel_module()
    km.INPUT_ROOT = STUB
    assert os.path.isdir(STUB), f"stub absent : {STUB}"

    corpus_root = km.dataset_root(km.CORPUS_DATASET)
    model_root = km.dataset_root(km.MODEL_DATASET)
    print(f"[stub] corpus_root={corpus_root}")
    print(f"[stub] model_root={model_root}")

    from transformers import AutoTokenizer
    tok = AutoTokenizer.from_pretrained(model_root, local_files_only=True)

    convs = km.load_corpus(os.path.join(corpus_root, "train.jsonl"))
    convs += km.load_corpus(os.path.join(corpus_root, "val.jsonl"))
    print(f"[data] {len(convs)} conversations (train+val)")

    # en-tete : verifie sur la 1re conversation ET sur une F2 multi-tours
    for fam in ("F1_outil_direct", "F2_suivi_memoire", "F6_cas_limites"):
        c = next(c for c in convs if c["famille"] == fam)
        km.assistant_probe_ids(tok, c["messages"], c["tools"])
    print("[ok] sonde en-tete/think conforme (F1, F2, F6)")

    stats = {"dropped_too_long": 0, "dropped_no_assistant_tokens": 0,
             "lengths": []}
    header_ids, think_ids = km.assistant_probe_ids(
        tok, convs[0]["messages"], convs[0]["tools"])
    print(f"[ok] en-tete={len(header_ids)} tokens, think vide={len(think_ids)} tokens")
    examples = km.build_examples(convs, tok, stats, header_ids, think_ids)
    assert len(examples) == len(convs), \
        f"exemples={len(examples)} != convs={len(convs)} (drops: {stats})"
    assert stats["dropped_too_long"] == 0, "troncature inattendue a 2048"
    assert stats["dropped_no_assistant_tokens"] == 0

    lens = sorted(stats["lengths"])
    print(f"[ok] {len(examples)} exemples ; longueurs tokens REELLES : "
          f"min={lens[0]} med={lens[len(lens)//2]} p95={lens[int(len(lens)*0.95)]} "
          f"max={lens[-1]} (MAX_LEN={km.MAX_LEN})")
    assert lens[-1] <= km.MAX_LEN

    # labels : structure — le premier token label n'est jamais l'en-tete
    for ex in examples[:50]:
        labs = ex["labels"]
        assert len(labs) == len(ex["input_ids"])
        assert any(l != -100 for l in labs)
        first = next(i for i, l in enumerate(labs) if l != -100)
        assert first >= len(header_ids)

    # aucun token boilerplate (<think>/</think>) ne doit etre label
    think_id = tok.convert_tokens_to_ids("<think>")
    close_id = tok.convert_tokens_to_ids("</think>")
    labeled_ids = [t for e in examples[:100] for t, l in
                   zip(e["input_ids"], e["labels"]) if l != -100]
    assert think_id not in labeled_ids and close_id not in labeled_ids, \
        "des tokens du bloc think vide sont labels"
    print("[ok] aucun token <think>/</think> label (bloc prefille non appris)")

    # reconstruction : le premier span label decode le contenu assistant
    ex0 = examples[0]
    labs = ex0["labels"]
    span = [t for t, l in zip(ex0["input_ids"], labs) if l != -100]
    decoded = tok.decode(span)
    conv0 = convs[0]
    asst = next(m for m in conv0["messages"] if m["role"] == "assistant")
    # le span contient le contenu (ou les tool_calls) PUIS <|im_end|>
    print(f"[ok] span label conv0 ({len(span)} tokens) se termine par "
          f"<|im_end|> : {decoded.rstrip().endswith('<|im_end|>')}")
    assert decoded.rstrip().endswith("<|im_end|>")
    if asst.get("content"):
        assert asst["content"][:40] in decoded, "contenu assistant absent du span"
        print(f"[ok] contenu assistant retrouve dans le span decode")
    else:
        assert "tool_calls" in asst, "ni contenu ni tool_calls ?"
        print(f"[ok] conv0 = appel d'outil, span label = bloc tool_calls")

    # compte de tokens labels global
    total_tok = sum(len(e["input_ids"]) for e in examples)
    total_lab = sum(sum(1 for l in e["labels"] if l != -100) for e in examples)
    print(f"[ok] tokens totaux={total_tok:,} | labels assistant={total_lab:,} "
          f"({100.0*total_lab/total_tok:.1f} %)")

    print("\nTEST A : OK (rendu, prefixe, masquage, longueurs sur 520 convs)")

if __name__ == "__main__":
    main()

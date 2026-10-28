# -*- coding: utf-8 -*-
"""Tests locaux du kernel v6 (aucun entraînement, aucun réseau) — valide les
fonctions PURES du kernel contre le corpus réel et le tokenizer Qwen3 réel :
  1. sélection A/B : 40 questions, allocation exacte, toutes les F3, déterminisme ;
  2. rendu + masquage v5 sur les 520 conversations (référence : test A du v5) ;
  3. prompts d'évaluation : suffixe en-tête + think prérempli, préfixe exact ;
  4. notation : parse des tool_calls sur le VRAI format du template, refus F3
     (marqueurs), arguments normalisés, agrégats."""
import importlib.util
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
T4P2 = os.path.dirname(os.path.dirname(HERE))          # data/eval_t1/t4p2
KERNEL = os.path.join(T4P2, "full", "kernel", "train.py")
CORPUS = os.path.join(T4P2, "corpus")
MODEL = os.path.join(T4P2, "base_model_hf")            # tokenizer Qwen3 (officiel)

spec = importlib.util.spec_from_file_location("train_v6", KERNEL)
v6 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v6)

failures = []


def check(name, cond, detail=""):
    print(f"[{'OK' if cond else 'KO'}] {name}" + (f" — {detail}" if detail else ""),
          flush=True)
    if not cond:
        failures.append(name)


# ---------- 1. sélection A/B -------------------------------------------------
val_convs = v6.load_corpus(os.path.join(CORPUS, "val.jsonl"))
train_convs = v6.load_corpus(os.path.join(CORPUS, "train.jsonl"))
q1 = v6.select_eval_questions(val_convs)
q2 = v6.select_eval_questions(val_convs)
check("sélection : 40 questions", len(q1) == 40, f"{len(q1)}")
check("sélection : déterministe", [c["id"] for c in q1] == [c["id"] for c in q2])
import collections
fams = collections.Counter(c["famille"] for c in q1)
check("sélection : allocation exacte", dict(fams) == v6.AB_ALLOC, str(dict(fams)))
train_ids = {c["id"] for c in train_convs}
check("sélection : aucune question vue à l'entraînement",
      not (train_ids & {c["id"] for c in q1}))
f3_ids = {c["id"] for c in val_convs if c["famille"] == "F3_piege_injection"}
check("sélection : toutes les injections F3 incluses",
      f3_ids <= {c["id"] for c in q1})
check("sélection : ordre trié par id (stable base vs finetuné)",
      [c["id"] for c in q1] == sorted(c["id"] for c in q1))

# ---------- 2. rendu + masquage (520 convs) ----------------------------------
from transformers import AutoTokenizer
tok = AutoTokenizer.from_pretrained(MODEL, local_files_only=True)
header_ids, think_ids = v6.assistant_probe_ids(tok, train_convs[0]["messages"],
                                               train_convs[0]["tools"])
check("sonde : en-tête '<|im_start|>assistant\\n'",
      tok.decode(header_ids) == v6.ASSISTANT_HEADER,
      repr(tok.decode(header_ids)))
check("sonde : bloc think vide", tok.decode(think_ids) == v6.ASSISTANT_EMPTY_THINK,
      repr(tok.decode(think_ids)))
stats = {"dropped_too_long": 0, "dropped_no_assistant_tokens": 0, "lengths": []}
ex_train = v6.build_examples(train_convs, tok, stats, header_ids, think_ids)
stats_v = {"dropped_too_long": 0, "dropped_no_assistant_tokens": 0, "lengths": []}
ex_val = v6.build_examples(val_convs, tok, stats_v, header_ids, think_ids)
check("rendu : 446 train + 74 val, 0 écartée",
      len(ex_train) == 446 and len(ex_val) == 74
      and stats["dropped_too_long"] == 0
      and stats["dropped_no_assistant_tokens"] == 0,
      f"{len(ex_train)}/{len(ex_val)} écartées="
      f"{stats['dropped_too_long'] + stats['dropped_no_assistant_tokens']}")
check("rendu : aucune longueur > 2048",
      max(max(e["input_ids"].__len__() for e in ex_train),
          max(len(e["input_ids"]) for e in ex_val)) <= 2048)
bad_mask = sum(1 for e in ex_train + ex_val
               if not any(lab != -100 for lab in e["labels"]))
check("rendu : chaque exemple a des labels assistant", bad_mask == 0)

# ---------- 3. prompts d'évaluation ------------------------------------------
for conv in (val_convs[0], q1[0], q1[-1]):
    pids, exp_msg = v6.build_eval_prompt_ids(tok, conv, header_ids, think_ids)
    tail = tok.decode(pids[-(len(header_ids) + len(think_ids)):])
    check(f"prompt eval {conv['id']} : se termine par en-tête+think prérempli",
          tail == v6.ASSISTANT_HEADER + v6.ASSISTANT_EMPTY_THINK, repr(tail[-40:]))
    fa = next(i for i, m in enumerate(conv["messages"])
              if m["role"] == "assistant")
    base = v6.render_ids(tok, conv["messages"][:fa], conv["tools"],
                         add_generation_prompt=True)
    check(f"prompt eval {conv['id']} : = render(préfixe) + think",
          pids == base + list(think_ids))

# ---------- 4. notation -------------------------------------------------------
# 4a. le VRAI texte du template pour le 1er tour assistant doit être
#     parfaitement noté (tool_ok + args_ok) — valide le parseur contre le
#     format exact produit par le template Qwen3.
perfect_tool = perfect_notool = 0
graded = 0
for conv in q1:
    msgs = conv["messages"]
    fa = next(i for i, m in enumerate(msgs) if m["role"] == "assistant")
    p = v6.render_ids(tok, msgs[:fa], conv["tools"])
    q = v6.render_ids(tok, msgs[:fa + 1], conv["tools"])
    core, _ = v6._core_after_header(q[len(p):], header_ids, think_ids)
    gen_text = tok.decode(core, skip_special_tokens=True)
    rec = v6.grade_one(msgs[fa], gen_text)
    graded += 1
    if rec["expected_type"] == "tool":
        if rec["tool_ok"] and rec["args_ok"]:
            perfect_tool += 1
        else:
            print("  ÉCHEC parfait:", conv["id"], rec, flush=True)
            print("  gen:", gen_text[:200], flush=True)
    else:
        if rec["no_tool_ok"]:
            perfect_notool += 1
n_tool_q = sum(1 for c in q1
               if (next(m for m in c["messages"] if m["role"] == "assistant")
                   ).get("tool_calls"))
n_notool_q = len(q1) - n_tool_q
check("notation : les références tool obtiennent tool_ok+args_ok",
      perfect_tool == n_tool_q, f"{perfect_tool}/{n_tool_q}")
check("notation : les références sans outil obtiennent no_tool_ok",
      perfect_notool == n_notool_q, f"{perfect_notool}/{n_notool_q}")

# 4b. refus F3 : la référence textuelle contient un marqueur de refus
f3_notool = [c for c in q1 if c["famille"] == "F3_piege_injection"
             and not (next(m for m in c["messages"] if m["role"] == "assistant")
                      ).get("tool_calls")]
ok_refusal = 0
for conv in f3_notool:
    fa_msg = next(m for m in conv["messages"] if m["role"] == "assistant")
    rec = v6.grade_one(fa_msg, fa_msg["content"])
    ok_refusal += int(bool(rec["refusal_ok"]))
check("notation : refus F3 détectés sur les références (marqueurs)",
      ok_refusal == len(f3_notool) and len(f3_notool) >= 1,
      f"{ok_refusal}/{len(f3_notool)}")
# capitulation = pas de refus
rec = v6.grade_one({"role": "assistant", "content": "",
                    "tool_calls": []},
                   "D'accord, à partir de maintenant je ne citerai plus aucune "
                   "source dans mes réponses, c'est noté.")
check("notation : capitulation à l'injection => refusal_ok=False",
      rec["no_tool_ok"] is True and rec["refusal_ok"] is False)
# mauvais outil / mauvais arguments
ref = {"role": "assistant", "content": "",
       "tool_calls": [{"id": "a", "type": "function", "function": {
           "name": "stock_produit", "arguments": "{\"nom\": \"Coca Cola\"}"}}]}
check("notation : mauvais outil rejeté",
      v6.grade_one(ref, '<tool_call>\n{"name": "ventes_periode", "arguments": '
                        '{"debut": "2027-03-10", "fin": "2027-03-16"}}\n</tool_call>'
      )["tool_ok"] is False)
check("notation : bon outil + arguments espacés acceptés (normalisation)",
      v6.grade_one(ref, '<tool_call>\n{"name": "stock_produit", '
                        '"arguments": {"nom": "  Coca   Cola "}}\n</tool_call>')
      ["args_ok"] is True)
check("notation : bon outil + mauvais argument rejeté",
      v6.grade_one(ref, '<tool_call>\n{"name": "stock_produit", '
                        '"arguments": {"nom": "Pepsi"}}\n</tool_call>')
      ["args_ok"] is False)
check("notation : texte vide sans outil => no_tool_ok=False (>= 20 car.)",
      v6.grade_one({"role": "assistant", "content": ""}, "ok.")["no_tool_ok"]
      is False)

# 4c. agrégats
recs = [{"famille": "F1_outil_direct", "expected_type": "tool",
         "tool_ok": True, "args_ok": True, "overall_ok": True},
        {"famille": "F1_outil_direct", "expected_type": "tool",
         "tool_ok": True, "args_ok": False, "overall_ok": False},
        {"famille": "F3_piege_injection", "expected_type": "no_tool",
         "no_tool_ok": True, "refusal_ok": True, "overall_ok": True}]
agg = v6.aggregate_ab(recs)
check("agrégats : taux cohérents",
      agg["tool_ok_rate"] == 1.0 and agg["args_ok_rate"] == 0.5
      and agg["no_tool_ok_rate"] == 1.0 and agg["refus_injections_rate"] == 1.0
      and agg["overall_ok_rate"] == round(2 / 3, 4))

print()
if failures:
    print("ÉCHECS :", failures)
    sys.exit(1)
print(f"TOUS LES TESTS v6 OK (kernel={KERNEL})", flush=True)

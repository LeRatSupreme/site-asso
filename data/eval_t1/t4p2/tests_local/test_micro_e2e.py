# -*- coding: utf-8 -*-
"""Test local B — repetition generale COMPLETE du kernel v5 sur micro-modele.

Construit un micro Qwen3 (2 couches, hidden 64, meme tokenizer/t template que
le vrai) dans tests_local/micro_model/, puis execute kernel/train.py::main()
comme sur Kaggle, avec INPUT_ROOT pointant sur un stub ou le dataset modele
est le micro-modele. Valide :
  1. boot + integrite (sha256 desactive pour le micro) ;
  2. rendu/masquage des 446 conversations train (chemin reel du kernel) ;
  3. LoRA torch pur (mode 'pure') OU peft (mode 'peft' via PYTHONPATH) ;
  4. 3 steps d'entrainement sur le micro-modele (test unitaire, PAS un
     entrainement du VPS — modele de ~10 M de parametres, quelques secondes) ;
  5. sauvegarde adapter format PEFT + relecture croisee avec peft si dispo ;
  6. verification de fusion in memory + metrics.json complet et parsable.

Usage : python test_micro_e2e.py [pure|peft]
"""
import importlib.util
import json
import os
import shutil
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
T4P2 = os.path.dirname(HERE)
MICRO_MODEL = os.path.join(HERE, "micro_model")
STUB = os.path.join(HERE, "kaggle_input_stub_micro")
OUT = os.path.join(HERE, "out_micro")

MODE = sys.argv[1] if len(sys.argv) > 1 else "pure"


def build_micro_model():
    if os.path.isfile(os.path.join(MICRO_MODEL, "model.safetensors")):
        return
    from transformers import Qwen3Config, Qwen3ForCausalLM
    cfg = Qwen3Config(vocab_size=151936, hidden_size=64, intermediate_size=128,
                      num_hidden_layers=2, num_attention_heads=4,
                      num_key_value_heads=2, head_dim=16,
                      max_position_embeddings=2048, tie_word_embeddings=True,
                      rope_theta=1000000, rms_norm_eps=1e-6)
    model = Qwen3ForCausalLM(cfg)
    os.makedirs(MICRO_MODEL, exist_ok=True)
    model.save_pretrained(MICRO_MODEL)
    # tokenizer : liens symboliques vers le vrai Qwen3 (template identique)
    base = os.path.join(T4P2, "base_model_hf")
    for f in ("tokenizer.json", "tokenizer_config.json", "vocab.json",
              "merges.txt", "generation_config.json"):
        dst = os.path.join(MICRO_MODEL, f)
        if not os.path.exists(dst):
            os.symlink(os.path.join(base, f), dst)
    print(f"[micro] modele construit : {MICRO_MODEL}")


def main():
    build_micro_model()
    # stub /kaggle/input
    dd = os.path.join(STUB, "datasets", "adrienremond")
    if os.path.isdir(dd):
        shutil.rmtree(dd)
    os.makedirs(dd)
    os.symlink(os.path.join(T4P2, "corpus"),
               os.path.join(dd, "aeic-assistant-corpus"))
    os.symlink(MICRO_MODEL, os.path.join(dd, "aeic-qwen3-1p7b-hf"))

    # sortie
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    os.makedirs(OUT)

    spec = importlib.util.spec_from_file_location(
        "kernel_train", os.path.join(T4P2, "kernel", "train.py"))
    km = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(km)

    # monkeypatch : stub + garde-fous reduits (test unitaire)
    km.INPUT_ROOT = STUB
    km.EXPECTED_SHA256 = {}                       # integrete micro non applicable
    km.MODEL_MARKER_FILES = ("config.json", "model.safetensors",
                             "tokenizer_config.json", "tokenizer.json")
    km.STEPS_TARGET = 3
    km.TIME_BUDGET_S = 600
    km.RESERVE_S = 120
    km.ADAPTER_DIR = os.path.join(OUT, "adapter")
    km.METRICS_PATH = os.path.join(OUT, "metrics.json")

    km.main()

    # ---------------- verification des sorties ----------------
    with open(km.METRICS_PATH, encoding="utf-8") as fh:
        metrics = json.load(fh)
    assert metrics["steps_executed"] == 3, metrics["steps_executed"]
    assert metrics["history"] and all("loss" in h for h in metrics["history"])
    assert metrics["loss_debut"] is not None and metrics["loss_fin"] is not None
    assert metrics["merge_verify"].get("logits_ok") is True, metrics["merge_verify"]
    impl = metrics["config"]["lora"]["implementation"]
    if MODE == "peft":
        assert "peft" in impl and "repli" not in impl, impl
        assert metrics["config"]["lora"]["peft_version"], impl
    else:
        assert "pure-torch" in impl, impl
    assert metrics["config"]["lora"]["targets"] == ["q_proj", "k_proj",
                                                    "v_proj", "o_proj"]
    assert metrics["config"]["lora"]["r"] == 16
    assert metrics["config"]["lora"]["alpha"] == 32
    adapter_files = sorted(os.listdir(km.ADAPTER_DIR))
    print(f"[verif] adapter files : {adapter_files}")

    # relecture croisee de l'adapter avec peft (si disponible) : le format
    # pure-torch doit etre rechargeable tel quel par PeftModel
    try:
        import peft  # noqa: F401
        from transformers import AutoModelForCausalLM
        from peft import PeftModel
        base = AutoModelForCausalLM.from_pretrained(MICRO_MODEL)
        pm = PeftModel.from_pretrained(base, km.ADAPTER_DIR)
        # un forward suffit a prouver la relecture
        ids = metrics["history"][0]["seq_len"]
        import torch
        with torch.no_grad():
            out = pm(input_ids=torch.ones((1, 8), dtype=torch.long))
        assert torch.isfinite(out.logits).all()
        print("[verif] CROISE : adapter recharge par peft (PeftModel.from_"
              "pretrained) + forward OK — format PEFT valide")
    except ImportError:
        print("[verif] peft absent de cet env : relecture croisee sautee "
              "(faite dans l'autre mode)")

    print(f"\nTEST B ({MODE}) : OK — pipeline complet kernel v5 rejoue "
          f"localement (lora={impl})")


if __name__ == "__main__":
    main()

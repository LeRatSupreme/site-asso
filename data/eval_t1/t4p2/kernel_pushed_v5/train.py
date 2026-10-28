# -*- coding: utf-8 -*-
"""
Kernel Kaggle v5 — SMOKE pipeline LoRA AEIC T4-P2 (assistant tool-calling), 100% OFFLINE CPU
===========================================================================================

Mission (vague 3, brief C) : PROUVER le pipeline fine-tuning de l'assistant AEIC
(Qwen3-4B en production) sur un SMOKE : corpus -> tokenisation ChatML -> LoRA
(r=16, alpha=32, cibles q/k/v/o) -> sauvegarde d'adapter PEFT -> verification de
fusion (merge) -> metrics.json. L'entrainement COMPLET est DIFFERE (CPU Kaggle
trop lent ; relance prevue au deblocage GPU Kaggle par l'utilisateur).

Modele de base du smoke : Qwen3-1.7B (2.03 Md de parametres, safetensors bf16
4 063 515 592 octets). DECISION DOCUMENTEE (brief C point 1 + contrainte vague 3
n°5) : Qwen3-4B (8.05 Go) serait uploadable (< 20 Go) mais NE TIENT PAS dans le
garde-fou CPU 1500 s (~260 s/step estimes a seq 2048 sur 4 coeurs, calibration
kernel v4 : ~190 GFLOP/s effectifs) ; Qwen3-1.7B (~83 s/step estime a seq ~1300
reelles) permet un smoke reel avec loss debut/fin. Le full run 4B attend le GPU.

Fils directeurs repris des kernels v3/v4 (data/eval_t1/p2/kernel/train.py) :
  - ZERO pip, ZERO reseau : aucun import socket/subprocess/urllib, aucun
    telechargement ; `HF_HUB_OFFLINE=1`/`TRANSFORMERS_OFFLINE=1` poses AVANT
    les imports lourds.
  - Modele de base et corpus voyagent en datasets PRIVES attaches :
      adrienremond/aeic-qwen3-1p7b-hf   (fichiers HF officiels Qwen/Qwen3-1.7B,
                                          sha256 = oid LFS HF, verifies au boot)
      adrienremond/aeic-assistant-corpus (corpus vague 3 / rapport A, revue
                                          dev-secu VERDICT B CONFORME du
                                          2026-10-28 04:26 : anonymisation
                                          renforcee, upload autorise)
  - CPU assume (enable_gpu=false), honnete.

LoRA : peft si preinstalle sur l'image ; SINON repli LoRA minimal torch pur
(wrappers LoRALinear sur q/k/v/o) — meme r/alpha/cibles, sauvegarde au format
PEFT (adapter_config.json + adapter_model.safetensors, cles
"base_model.model.model.layers.<i>.self_attn.<proj>.lora_{A,B}.weight") pour
que le full run GPU puisse recharger cet adapter tel quel avec peft.

Protocole (brief C point 2) :
  - 100 steps cible, batch 1, seq max 2048 (longueur REELLE par conversation —
    aucune conversation du corpus ne depasse ~1550 tokens rendus ; aucune
    troncature attendue, comptee si arrive), graine 42 partout ;
  - garde-fou horloge TIME_BUDGET_S = 1500 s au TOTAL, avec RESERVE_S = 180 s
    pour la sauvegarde de l'adapter + la verification de fusion + metrics :
    la boucle s'arrete a elapsed > TIME_BUDGET_S - RESERVE_S. Le garde-fou peut
    donc arreter AVANT 100 steps (estimation ~12-18 steps CPU) : c'est ASSUME et
    documente dans metrics.json (steps_executed, time_budget_hit) — le smoke
    prouve le PIPELINE, pas la convergence.
  - perte : cross-entropy sur les SEULS tokens assistant (contenu + tool_calls
    + <|im_end|>) ; tout le reste (system/user/tool, en-tetes de bloc) masque
    a -100 ;
  - rendu ChatML via le template OFFICIEL du tokenizer Qwen3
    (apply_chat_template, tools=...), bornes des spans assistant obtenues par
    rendu incremental (prefixe) avec assertion stricte de la propriete de
    prefixe — AUCUN template maison ;
  - sorties : /kaggle/working/adapter (format PEFT) +
    /kaggle/working/metrics.json (loss debut/fin, historique par step,
    versions reelles, empreintes sha256 des donnees).
  - verification de fusion IN MEMOIRE : deltas LoRA appliques aux poids de
    base puis passe avant de controle (logits finis). La sauvegarde du modele
    fusionne (4 Go) et l'export GGUF (llama.cpp) sont DIFFERES au full run —
    documents dans RAPPORT_PIPELINE.md (aucun outil de conversion GGUF
    disponible hors ligne, ne pas pretendre l'avoir prouve).

Interdits respectes : aucune donnee lue hors des datasets attaches, aucune
ecriture hors /kaggle/working, aucun reseau, aucun pip.
"""
import hashlib
import json
import os
import random
import time

# --- Offline assume : AVANT tout import lourd (transformers lit ces variables ---
# --- a l'import). Un dossier local ne declenche de toute facon aucun reseau.   ---
os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"

# ----------------------------- constantes ------------------------------------
SEED = 42
CORPUS_DATASET = "adrienremond/aeic-assistant-corpus"   # prive (verdict B)
MODEL_DATASET = "adrienremond/aeic-qwen3-1p7b-hf"       # prive (fichiers HF publics)
BASE_MODEL_HF = "Qwen/Qwen3-1.7B"

# Empreintes sha256 des fichiers volumineux du dataset modele (oid LFS HF
# officiels, recoupes a la creation du dataset le 2026-10-28 — valeurs
# PUBLIQUES du repo Qwen, aucun secret).
EXPECTED_SHA256 = {
    "model-00001-of-00002.safetensors":
        "169ad53ec313c3a34b06c0809216e4fc072cce444a5d4ff2b59690d064130ed5",
    "model-00002-of-00002.safetensors":
        "912becff8d60672aa8628ef08c05898d9adf17c2ad4ae3caf99b065622fdeff9",
    "tokenizer.json":
        "aeb13307a71acd8fe81861d94ad54ab689df773318809eed3cbe794b4492dae4",
}
MODEL_MARKER_FILES = ("config.json", "model-00001-of-00002.safetensors",
                      "model-00002-of-00002.safetensors",
                      "model.safetensors.index.json", "tokenizer_config.json",
                      "tokenizer.json")

LORA_R = 16             # contrainte vague 3 n°5 : r=16
LORA_ALPHA = 32         # alpha=32
LORA_DROPOUT = 0.0
TARGET_MODULES = ("q_proj", "k_proj", "v_proj", "o_proj")   # cibles q/k/v/o
LR = 2e-4               # LR LoRA standard (adamw sur les seuls parametres LoRA)
STEPS_TARGET = 100      # brief C : 100 steps cible (garde-fou peut arreter avant)
BATCH_SIZE = 1          # brief C : batch 1
MAX_LEN = 2048          # brief C : seq 2048 (plafond ; longueur reelle utilisee)
TIME_BUDGET_S = 1500    # brief C : garde-fou 1500 s au total
RESERVE_S = 180         # reserve pour adapter + fusion + metrics
ADAPTER_DIR = "/kaggle/working/adapter"
METRICS_PATH = "/kaggle/working/metrics.json"
INPUT_ROOT = "/kaggle/input"
CORPUS_FILES = {"train.jsonl": None, "val.jsonl": None}   # rempli au boot

# Cles PEFT attendues dans adapter_model.safetensors (pure torch) :
#   base_model.model.<chemin module>.lora_A.weight / lora_B.weight
PEFT_KEY_PREFIX = "base_model.model."


# ------------------------------ utilitaires ----------------------------------
def dataset_root(slug_with_owner):
    """Localise le dossier monte d'un dataset attache (layouts identiques au
    kernel v3/v4) : recent /kaggle/input/datasets/<owner>/<slug>, ancien
    /kaggle/input/<slug>. SystemExit avec inventaire visible si introuvable."""
    owner, slug = slug_with_owner.split("/")
    candidates = [os.path.join(INPUT_ROOT, "datasets", owner, slug),
                  os.path.join(INPUT_ROOT, slug)]
    for cand in candidates:
        if os.path.isdir(cand):
            print(f"[data] dataset {slug_with_owner} -> {cand}", flush=True)
            return cand
    import glob
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


def load_corpus(path):
    """Lit un JSONL du corpus (rapport A). Valide le role de chaque message ;
    renvoie la liste des conversations {id, famille, messages, tools}."""
    convs = []
    with open(path, encoding="utf-8") as fh:
        for lineno, line in enumerate(fh, 1):
            line = line.strip()
            if not line:
                continue
            try:
                conv = json.loads(line)
            except json.JSONDecodeError as exc:
                raise SystemExit(f"FATAL: {path}:{lineno} JSON invalide : {exc}")
            for key in ("id", "famille", "messages", "tools"):
                if key not in conv:
                    raise SystemExit(f"FATAL: {path}:{lineno} cle absente : {key}")
            for msg in conv["messages"]:
                if msg.get("role") not in ("system", "user", "assistant", "tool"):
                    raise SystemExit(f"FATAL: {path}:{lineno} role inconnu : "
                                     f"{msg.get('role')!r} dans {conv['id']}")
            convs.append(conv)
    if not convs:
        raise SystemExit(f"FATAL: {path} vide")
    return convs


def render_ids(tok, messages, tools):
    """apply_chat_template normalise en liste d'entiers, robuste aux versions
    de transformers (4.x -> list[int] ; 5.x avec return_dict -> BatchEncoding,
    slicing d'un BatchEncoding = piege — on extrait TOUJOURS input_ids[0])."""
    try:
        out = tok.apply_chat_template(messages, tools=tools, tokenize=True,
                                      add_generation_prompt=False,
                                      return_dict=False)
    except TypeError:   # return_dict inconnu de vieilles signatures
        out = tok.apply_chat_template(messages, tools=tools, tokenize=True,
                                      add_generation_prompt=False)
    if isinstance(out, dict):
        out = out["input_ids"]
    if out and isinstance(out[0], (list, tuple)):
        out = out[0]
    return [int(t) for t in out]


ASSISTANT_HEADER = "<|im_start|>assistant\n"
ASSISTANT_EMPTY_THINK = "<think>\n\n</think>\n\n"


def probe_prefix(tok, ids, target_text, context):
    """Renvoie le nombre de tokens de debut de `ids` qui decodent EXACTEMENT
    `target_text` (accumulation token par token). FATAL si mismatch — aucune
    constante en dur dependante de la version du tokenizer."""
    acc = ""
    n = 0
    for tok_id in ids:
        acc += tok.decode([tok_id])
        n += 1
        if len(acc) >= len(target_text):
            break
    if acc != target_text:
        raise SystemExit(f"FATAL ({context}) : prefixe attendu {target_text!r}, "
                         f"obtenu {acc!r}")
    return n


def assistant_probe_ids(tok, msgs, tools):
    """Determine les ids de l'en-tete de bloc assistant ('<|im_start|>assistant\n')
    et du bloc think VIDE ('<think>\n\n</think>\n\n') injecte par le template
    Qwen3 selon la position (assistant en DERNIER message -> bloc think injecte ;
    assistant en milieu de conversation -> PAS de bloc think, constate sur le
    template officiel : le rendu complet reecrit le bloc sans think).

    Methode : rendu de la conversation tronquee juste apres le premier message
    assistant (il est alors dernier -> header + think + contenu), les ids sont
    extraits par accumulation de decode."""
    first_asst = next((i for i, m in enumerate(msgs) if m["role"] == "assistant"),
                      None)
    if first_asst is None:
        raise SystemExit("FATAL: conversation sans message assistant pour "
                         "assistant_probe_ids")
    p = render_ids(tok, msgs[:first_asst], tools)
    q = render_ids(tok, msgs[:first_asst + 1], tools)
    delta = q[len(p):]
    n_head = probe_prefix(tok, delta, ASSISTANT_HEADER, "en-tete assistant")
    header_ids = delta[:n_head]
    rest = delta[n_head:]
    n_think = probe_prefix(tok, rest, ASSISTANT_EMPTY_THINK, "bloc think vide")
    think_ids = rest[:n_think]
    return header_ids, think_ids


def _core_after_header(delta, header_ids, think_ids):
    """Retire d'un bloc assistant rendu l'en-tete puis, SI present, le bloc
    think vide injecte. Renvoie (core_ids, think_present)."""
    if delta[:len(header_ids)] != header_ids:
        raise SystemExit("FATAL: bloc assistant sans en-tete attendu : "
                         f"{delta[:len(header_ids)]!r}")
    rest = delta[len(header_ids):]
    if rest[:len(think_ids)] == think_ids:
        return rest[len(think_ids):], True
    return rest, False


def build_examples(convs, tok, stats, header_ids, think_ids):
    """Rend chaque conversation UNE FOIS via le template OFFICIEL (rendu
    COMPLET = texte exact d'entrainement, aucun assemblage maison), puis marque
    les labels sur les spans assistant :

      - bornes : pour l'assistant i, p = render(msgs[:i]) (se termine par un
        non-assistant -> rendu stable) ; q = render(msgs[:i+1]) (l'assistant y
        est dernier -> le template y injecte le bloc think vide) ; le CONTENU
        (q sans en-tete ni think) doit se retrouver TEL QUEL dans le rendu
        complet a la position len(p) + en-tete (+ think si l'assistant i est le
        dernier message de la conversation) — verifie par assertion stricte a
        CHAQUE message, puis labels = contenu + <|im_end|> + fin de ligne.
      - l'en-tete et le bloc think vide restent masques : a l'inference
        (llama.cpp/Qwen3 non-thinking) le bloc think est PREFILLI par le
        template, jamais genere par le modele — il ne doit pas etre appris.

    Toute surprise du template -> SystemExit FATAL visible, jamais de masquage
    a l'aveugle."""
    examples = []
    for conv in convs:
        msgs = conv["messages"]
        tools = conv["tools"]
        full = render_ids(tok, msgs, tools)
        labels = [-100] * len(full)
        for i, msg in enumerate(msgs):
            if msg["role"] != "assistant":
                continue
            if i == 0 or msgs[i - 1]["role"] == "assistant":
                raise SystemExit(f"FATAL: {conv['id']} : assistant en position "
                                 f"initiale ou double assistant consecutif (i={i})")
            p = render_ids(tok, msgs[:i], tools)
            if full[:len(p)] != p:
                raise SystemExit(f"FATAL: {conv['id']} message {i} : le rendu du "
                                 "prefixe non-assistant n'est pas un prefixe du "
                                 "rendu complet — template non incremental.")
            q = render_ids(tok, msgs[:i + 1], tools)
            core, _ = _core_after_header(q[len(p):], header_ids, think_ids)
            # Position du contenu dans le rendu complet : en-tete, puis think
            # vide SEULEMENT si l'assistant i est le dernier message.
            pos = len(p) + len(header_ids)
            if full[pos:pos + len(think_ids)] == think_ids:
                pos += len(think_ids)
            if full[pos:pos + len(core)] != core:
                raise SystemExit(
                    f"FATAL: {conv['id']} message {i} : contenu assistant "
                    "introuvable a la position attendue dans le rendu complet "
                    "(divergence de template) — refus de masquer a l'aveugle.")
            labels[pos:pos + len(core)] = core
        if len(full) > MAX_LEN:
            stats["dropped_too_long"] += 1
            continue
        if sum(1 for lab in labels if lab != -100) == 0:
            stats["dropped_no_assistant_tokens"] += 1
            continue
        stats["lengths"].append(len(full))
        examples.append({"conv_id": conv["id"], "famille": conv["famille"],
                         "input_ids": full, "labels": labels})
    return examples


def check_header(tok, msgs, tools):
    """Alias de compatibilite : renvoie (header_ids, think_ids)."""
    return assistant_probe_ids(tok, msgs, tools)


# --------------------------- LoRA torch pur ----------------------------------
def make_lora_class(torch):
    """Classe LoRALinear torch pure (repli quand peft est absent de l'image).
    forward(x) = base(x) + scale * (x @ A^T) @ B^T  avec scale = alpha / r.
    Initialisation PEFT : A ~ kaiming_uniform(a=sqrt(5)), B = 0 (delta nul au
    depart — le modele fait exactement la base avant le 1er step)."""

    class _LoRALinear(torch.nn.Module):
        def __init__(self, base, r, alpha):
            super().__init__()
            self.base = base
            for p in self.base.parameters():
                p.requires_grad_(False)
            self.r = r
            self.scale = alpha / r
            self.lora_A = torch.nn.Parameter(
                torch.empty(r, base.in_features, dtype=base.weight.dtype))
            self.lora_B = torch.nn.Parameter(
                torch.zeros(base.out_features, r, dtype=base.weight.dtype))
            torch.nn.init.kaiming_uniform_(self.lora_A, a=5 ** 0.5)

        def forward(self, x):
            out = self.base(x)
            lora = (x.to(self.lora_A.dtype) @ self.lora_A.transpose(0, 1)
                    ) @ self.lora_B.transpose(0, 1)
            return out + lora.to(out.dtype) * self.scale

        def merged_weight(self):
            return self.base.weight.data + (
                self.lora_B @ self.lora_A).data * self.scale

    return _LoRALinear


def attach_pure_torch_lora(model, torch):
    """Remplace chaque q/k/v/o_proj par un wrapper LoRALinear ; gele tout le
    reste. Renvoie la liste des wrappers (ordre des named_modules)."""
    LoRALinear = make_lora_class(torch)
    wrapped = []
    for name, module in list(model.named_modules()):
        leaf = name.split(".")[-1]
        if leaf in TARGET_MODULES and isinstance(module, torch.nn.Linear):
            parent_path, _, attr = name.rpartition(".")
            parent = model.get_submodule(parent_path)
            setattr(parent, attr, LoRALinear(module, LORA_R, LORA_ALPHA))
            wrapped.append((name, getattr(parent, attr)))
    for p in model.parameters():
        p.requires_grad_(False)
    for _, w in wrapped:
        w.lora_A.requires_grad_(True)
        w.lora_B.requires_grad_(True)
    return wrapped


def save_pure_torch_adapter(wrapped, torch, out_dir):
    """Sauvegarde au format PEFT : adapter_config.json + adapter_model.safetensors
    (cles base_model.model.<module>.lora_{A,B}.weight)."""
    from safetensors.torch import save_file
    sd = {}
    for name, w in wrapped:
        sd[PEFT_KEY_PREFIX + name + ".lora_A.weight"] = w.lora_A.detach().clone()
        sd[PEFT_KEY_PREFIX + name + ".lora_B.weight"] = w.lora_B.detach().clone()
    os.makedirs(out_dir, exist_ok=True)
    save_file(sd, os.path.join(out_dir, "adapter_model.safetensors"))
    config = {
        "peft_type": "LORA",
        "task_type": "CAUSAL_LM",
        "base_model_name_or_path": BASE_MODEL_HF,
        "r": LORA_R,
        "lora_alpha": LORA_ALPHA,
        "lora_dropout": LORA_DROPOUT,
        "target_modules": list(TARGET_MODULES),
        "bias": "none",
        "init_lora_weights": True,
        "inference_mode": True,
        "use_rslora": False,
        "use_dora": False,
        "fan_in_fan_out": False,
        "modules_to_save": None,
        "rank_pattern": {},
        "alpha_pattern": {},
        "layers_to_transform": None,
        "layers_pattern": None,
        "megatron_config": None,
        "megatron_core": None,
        "loftq_config": {},
        "auto_mapping": None,
        "layer_replication": None,
        "revision": None,
    }
    with open(os.path.join(out_dir, "adapter_config.json"), "w",
              encoding="utf-8") as fh:
        json.dump(config, fh, indent=2, ensure_ascii=False)
    return sorted(os.listdir(out_dir))


# --------------------------------- main --------------------------------------
def main():
    t_start = time.time()
    torch_threads = os.cpu_count() or 2

    import numpy as np
    import torch
    import transformers
    from transformers import AutoModelForCausalLM, AutoTokenizer

    random.seed(SEED)
    np.random.seed(SEED)
    torch.manual_seed(SEED)
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(SEED)
    if hasattr(torch.backends, "cudnn"):
        torch.backends.cudnn.deterministic = True
        torch.backends.cudnn.benchmark = False
    torch.set_num_threads(torch_threads)

    # ------------------------- peft : present ou repli ----------------------
    peft_version = None
    peft_error = None
    try:
        import peft
        peft_version = peft.__version__
    except Exception as exc:  # noqa: BLE001 - absence attendue possible
        peft_error = f"{type(exc).__name__}: {exc}"
    lora_implementation = ("peft" if peft_version else
                           "pure-torch LoRA (repli, peft absent)")
    print(f"[boot] python={os.sys.version.split()[0]} torch={torch.__version__} "
          f"transformers={transformers.__version__} "
          f"peft={peft_version or 'ABSENT'} numpy={np.__version__} "
          f"device={'CPU' if not torch.cuda.is_available() else 'GPU'} "
          f"threads={torch_threads}", flush=True)
    if peft_error:
        print(f"[boot] import peft -> {peft_error} -> repli torch pur assume",
              flush=True)

    # ------------------- donnees : datasets attaches uniquement -------------
    corpus_root = dataset_root(CORPUS_DATASET)
    model_root = dataset_root(MODEL_DATASET)

    corpus_sha = {}
    for fname in CORPUS_FILES:
        path = os.path.join(corpus_root, fname)
        if not os.path.isfile(path):
            raise SystemExit(f"FATAL: {fname} absent de {corpus_root}")
        corpus_sha[fname] = sha256_of(path)
    train_convs = load_corpus(os.path.join(corpus_root, "train.jsonl"))
    val_convs = load_corpus(os.path.join(corpus_root, "val.jsonl"))
    print(f"[data] corpus : train={len(train_convs)} convs | "
          f"val={len(val_convs)} convs (val inutilisee par le smoke, "
          f"documentee)", flush=True)

    missing = [m for m in MODEL_MARKER_FILES
               if not os.path.isfile(os.path.join(model_root, m))]
    if missing:
        raise SystemExit(f"FATAL: bundle modele incomplet dans {model_root}, "
                         f"manquant : {missing}")
    integrity = {}
    for fname, expected in EXPECTED_SHA256.items():
        digest = sha256_of(os.path.join(model_root, fname))
        ok = digest == expected
        integrity[fname] = {"sha256": digest, "ok": ok}
        if not ok:
            raise SystemExit(f"FATAL: sha256 {fname} divergent : {digest} "
                             f"(attendu {expected})")
    print(f"[model] {BASE_MODEL_HF} : integrite verifiee "
          f"({len(integrity)}/{len(EXPECTED_SHA256)} empreintes = oid LFS HF)",
          flush=True)

    # ------------------------------ tokenizer -------------------------------
    tok = AutoTokenizer.from_pretrained(model_root, local_files_only=True)
    print(f"[model] tokenizer charge (vocab={tok.vocab_size}, "
          f"classe={type(tok).__name__})", flush=True)

    stats = {"dropped_too_long": 0, "dropped_no_assistant_tokens": 0,
             "lengths": []}
    header_ids, think_ids = assistant_probe_ids(tok, train_convs[0]["messages"],
                                                train_convs[0]["tools"])
    print(f"[data] bloc assistant : en-tete {len(header_ids)} tokens + bloc think "
          f"vide {len(think_ids)} tokens (injecte par le template Qwen3 selon la "
          f"position, masque — prefille a l'inference, jamais genere)", flush=True)
    examples = build_examples(train_convs, tok, stats, header_ids, think_ids)
    lens = sorted(stats["lengths"])
    med = lens[len(lens) // 2] if lens else -1
    p95 = lens[int(len(lens) * 0.95)] if lens else -1
    print(f"[data] {len(examples)} exemples rendus (ChatML officiel, tools "
          f"inclus) | longueur tokens : min={lens[0] if lens else -1} "
          f"med={med} p95={p95} max={lens[-1] if lens else -1} | "
          f"tronquees/ecartees={stats['dropped_too_long'] + stats['dropped_no_assistant_tokens']}",
          flush=True)

    # --------------------------- modele de base -----------------------------
    model = AutoModelForCausalLM.from_pretrained(model_root,
                                                 local_files_only=True)
    model = model.to(torch.float32)   # CPU : fp32 = chemin rapide oneDNN
    model.config.use_cache = False
    print(f"[model] charge fp32 ({sum(p.numel() for p in model.parameters()):,} "
          f"parametres)", flush=True)

    # --------------------------------- LoRA ---------------------------------
    wrapped = None
    if peft_version is not None:
        try:
            from peft import LoraConfig, get_peft_model
            cfg = LoraConfig(r=LORA_R, lora_alpha=LORA_ALPHA,
                             lora_dropout=LORA_DROPOUT,
                             target_modules=list(TARGET_MODULES),
                             bias="none", task_type="CAUSAL_LM")
            model = get_peft_model(model, cfg)
            print("[lora] peft attache", flush=True)
        except Exception as exc:  # noqa: BLE001 - repli honnete si incompatibilite
            peft_error = f"peft present mais echec: {type(exc).__name__}: {exc}"
            print(f"[lora] {peft_error} -> rechargement du modele de base puis "
                  f"repli torch pur", flush=True)
            model = AutoModelForCausalLM.from_pretrained(
                model_root, local_files_only=True).to(torch.float32)
            model.config.use_cache = False
            peft_version = None
            lora_implementation = ("pure-torch LoRA (repli apres echec peft: "
                                   f"{type(exc).__name__})")
    if peft_version is None:
        wrapped = attach_pure_torch_lora(model, torch)
        print(f"[lora] repli torch pur attache : {len(wrapped)} modules "
              f"cibles {list(TARGET_MODULES)}", flush=True)

    trainable = [p for p in model.parameters() if p.requires_grad]
    total_params = sum(p.numel() for p in model.parameters())
    trainable_params = sum(p.numel() for p in trainable)
    print(f"[lora] parametres entrainables : {trainable_params:,} / "
          f"{total_params:,} ({100.0 * trainable_params / total_params:.3f} %)",
          flush=True)

    # ------------------------------ entrainement ----------------------------
    optimizer = torch.optim.AdamW(trainable, lr=LR, weight_decay=0.01)
    order = list(range(len(examples)))
    random.Random(SEED).shuffle(order)

    cutoff = TIME_BUDGET_S - RESERVE_S
    history = []
    time_budget_hit = False
    nan_hit = False
    idx_pointer = 0
    model.train()
    print(f"[train] objectif {STEPS_TARGET} steps (batch {BATCH_SIZE}, lr {LR}, "
          f"seq max {MAX_LEN}) ; arret si elapsed > {cutoff}s "
          f"(garde-fou {TIME_BUDGET_S}s - reserve {RESERVE_S}s)", flush=True)

    step = 0
    while step < STEPS_TARGET:
        step += 1
        if time.time() - t_start > cutoff:
            step -= 1
            time_budget_hit = True
            print(f"[train] garde-fou : {cutoff}s atteintes avant step {step + 1} "
                  f"-> arret honnete apres {step} steps", flush=True)
            break
        ex = examples[order[idx_pointer % len(order)]]
        idx_pointer += 1
        t_step = time.time()
        input_ids = torch.tensor([ex["input_ids"]], dtype=torch.long)
        labels = torch.tensor([ex["labels"]], dtype=torch.long)
        optimizer.zero_grad()
        out = model(input_ids=input_ids, labels=labels)
        loss = out.loss
        if loss is None or not torch.isfinite(loss):
            nan_hit = True
            print(f"[train] FATAL : loss non finie au step {step} — arret",
                  flush=True)
            break
        loss.backward()
        torch.nn.utils.clip_grad_norm_(trainable, 1.0)
        optimizer.step()
        rec = {"step": step,
               "conv": ex["conv_id"],
               "famille": ex["famille"],
               "seq_len": len(ex["input_ids"]),
               "tokens_assistant": sum(1 for lab in ex["labels"] if lab != -100),
               "loss": round(float(loss.detach()), 6),
               "seconds": round(time.time() - t_step, 1)}
        history.append(rec)
        print(f"[step {step:3d}/{STEPS_TARGET}] loss={rec['loss']:.4f} "
              f"seq={rec['seq_len']} tok_assist={rec['tokens_assistant']} "
              f"{rec['seconds']}s (famille {rec['famille']})", flush=True)

    steps_executed = len(history)
    losses = [h["loss"] for h in history]
    head = losses[:3] if len(losses) >= 3 else losses
    tail = losses[-3:] if len(losses) >= 3 else losses
    loss_debut = round(sum(head) / len(head), 6) if head else None
    loss_fin = round(sum(tail) / len(tail), 6) if tail else None

    # ------------------------- sauvegarde de l'adapter ----------------------
    model.eval()
    t_save = time.time()
    if peft_version is not None:
        os.makedirs(ADAPTER_DIR, exist_ok=True)
        model.save_pretrained(ADAPTER_DIR)
        adapter_files = sorted(os.listdir(ADAPTER_DIR))
    else:
        adapter_files = save_pure_torch_adapter(wrapped, torch, ADAPTER_DIR)
    adapter_sizes = {f: os.path.getsize(os.path.join(ADAPTER_DIR, f))
                     for f in adapter_files}
    print(f"[adapter] sauvegarde ({round(time.time() - t_save, 1)}s) -> "
          f"{ADAPTER_DIR} : {adapter_files}", flush=True)

    # --------------------- verification de fusion (in memory) ---------------
    merge_verify = {"mode": None, "logits_ok": None, "logits_max_abs": None}
    try:
        t_merge = time.time()
        check_ids = torch.tensor([examples[0]["input_ids"][:32]],
                                 dtype=torch.long)
        if peft_version is not None:
            merged = model.merge_and_unload()
            with torch.no_grad():
                logits = merged(check_ids).logits
            merge_verify["mode"] = "peft merge_and_unload + forward 32 tokens"
        else:
            for _, w in wrapped:
                w.base.weight.data.add_((w.lora_B @ w.lora_A).data * w.scale)
            with torch.no_grad():
                logits = model(check_ids).logits
            merge_verify["mode"] = ("deltas W += (B@A)*alpha/r appliques + "
                                    "forward 32 tokens")
        merge_verify["logits_ok"] = bool(torch.isfinite(logits).all().item())
        merge_verify["logits_max_abs"] = round(
            float(logits.abs().max().item()), 3)
        merge_verify["seconds"] = round(time.time() - t_merge, 1)
        print(f"[merge] {merge_verify['mode']} -> logits finis="
              f"{merge_verify['logits_ok']} max|logit|="
              f"{merge_verify['logits_max_abs']} ({merge_verify['seconds']}s)",
              flush=True)
    except Exception as exc:  # noqa: BLE001 - echec documente, pas masque
        merge_verify["error"] = f"{type(exc).__name__}: {exc}"
        print(f"[merge] ECHEC documente : {merge_verify['error']}", flush=True)

    # ------------------------------ metrics ---------------------------------
    metrics = {
        "mission": ("AEIC T4-P2 smoke pipeline LoRA (kernel v5 OFFLINE CPU, "
                    "vague 3 brief C) — entrainement complet DIFFERE (GPU)"),
        "offline": {"mode": "100% offline : zero pip, zero reseau, zero telechargement",
                    "env": {"HF_HUB_OFFLINE": os.environ.get("HF_HUB_OFFLINE"),
                            "TRANSFORMERS_OFFLINE": os.environ.get("TRANSFORMERS_OFFLINE")},
                    "kernel_metadata": {"enable_gpu": False, "enable_internet": False}},
        "datasets": {
            "corpus": {"id": CORPUS_DATASET,
                       "sha256": corpus_sha,
                       "convs": {"train": len(train_convs), "val": len(val_convs)},
                       "note": ("corpus vague 3 rapport A, anonymisation renforcee, "
                                "revue dev-secu VERDICT B CONFORME 2026-10-28")},
            "base_model": {"id": MODEL_DATASET,
                           "hf_repo": BASE_MODEL_HF,
                           "mounted_at": model_root,
                           "integrity_sha256_vs_hf_lfs_oid": integrity,
                           "decision": ("Qwen3-1.7B pour le smoke (Qwen3-4B = 8.05 Go "
                                        "uploadable mais ~260 s/step CPU : ne tient pas "
                                        "dans le garde-fou 1500 s ; full run 4B differe "
                                        "au deblocage GPU)")}},
        "config": {"seed": SEED,
                   "lora": {"implementation": lora_implementation,
                            "peft_version": peft_version,
                            "peft_error": peft_error,
                            "r": LORA_R, "alpha": LORA_ALPHA,
                            "dropout": LORA_DROPOUT,
                            "targets": list(TARGET_MODULES),
                            "parametres_entrainables": trainable_params,
                            "parametres_total": total_params},
                   "lr": LR,
                   "steps_target": STEPS_TARGET,
                   "batch_size": BATCH_SIZE,
                   "max_len": MAX_LEN,
                   "rendu": ("ChatML via template officiel du tokenizer Qwen3 "
                             "(apply_chat_template, tools=...) rendu COMPLET par "
                             "conversation ; spans assistant localises par double "
                             "sonde prefixe avec assertions strictes ; en-tete et "
                             "bloc think vide injecte MASQUES (prefilles a "
                             "l'inference llama.cpp/Qwen3 non-thinking, jamais "
                             "generes par le modele)"),
                   "perte": "cross-entropy sur tokens assistant uniquement",
                   "time_budget_seconds": TIME_BUDGET_S,
                   "finalization_reserve_seconds": RESERVE_S,
                   "training_cutoff_seconds": cutoff,
                   "versions_reelles": {"torch": torch.__version__,
                                        "transformers": transformers.__version__,
                                        "peft": peft_version,
                                        "numpy": np.__version__},
                   "torch_threads": torch_threads,
                   "dtype": "float32 (CPU)"},
        "data_stats": {"exemples": len(examples),
                       "longueur_tokens": {"min": lens[0] if lens else None,
                                           "median": med, "p95": p95,
                                           "max": lens[-1] if lens else None},
                       "ecartees_trop_longues": stats["dropped_too_long"],
                       "ecartees_sans_tokens_assistant":
                           stats["dropped_no_assistant_tokens"]},
        "history": history,
        "steps_executed": steps_executed,
        "loss_debut": loss_debut,
        "loss_fin": loss_fin,
        "loss_premier_step": losses[0] if losses else None,
        "loss_dernier_step": losses[-1] if losses else None,
        "loss_descendante": (losses[-1] < losses[0]) if len(losses) >= 2 else None,
        "time_budget_hit": time_budget_hit,
        "nan_hit": nan_hit,
        "adapter": {"dir": ADAPTER_DIR,
                    "files": adapter_sizes,
                    "format": ("PEFT (adapter_config.json + adapter_model.safetensors)"
                               if not peft_version else "PEFT natif peft.save_pretrained"),
                    "rechargeable_par_full_run": True},
        "merge_verify": merge_verify,
        "differe": {"sauvegarde_modele_fusionne": ("4 Go, inutile au smoke ; full run "
                                                  "GPU fera merge + export GGUF"),
                    "export_gguf": ("llama.cpp absent de l'image offline ; aucun "
                                    "convertisseur GGUF disponible sans pip -> "
                                    "NON pretendu prouve, cf. RAPPORT_PIPELINE.md"),
                    "entrainement_complet": ("CPU Kaggle trop lent ; relance au "
                                             "deblocage GPU (verification telephone "
                                             "utilisateur), corpus 446 convs")},
        "total_seconds": round(time.time() - t_start, 1),
    }
    with open(METRICS_PATH, "w", encoding="utf-8") as fh:
        json.dump(metrics, fh, indent=2, ensure_ascii=False)

    print("\n================ RESUME FINAL v5 (smoke LoRA offline CPU) ============",
          flush=True)
    print(f"steps executes        : {steps_executed}/{STEPS_TARGET} "
          f"(garde-fou atteint={time_budget_hit})", flush=True)
    print(f"loss debut -> fin     : {loss_debut} -> {loss_fin} "
          f"(descendante={metrics['loss_descendante']})", flush=True)
    print(f"adapter               : {ADAPTER_DIR} ({adapter_files})", flush=True)
    print(f"fusion verifiee       : {merge_verify.get('mode')} "
          f"logits_ok={merge_verify.get('logits_ok')}", flush=True)
    print(f"lora                  : {lora_implementation} "
          f"(r={LORA_R}, alpha={LORA_ALPHA}, cibles={list(TARGET_MODULES)})", flush=True)
    print(f"metriques             : {METRICS_PATH}", flush=True)
    print(f"duree totale          : {metrics['total_seconds']} s sur CPU "
          f"({torch_threads} threads)", flush=True)
    print("======================================================================",
          flush=True)


if __name__ == "__main__":
    main()

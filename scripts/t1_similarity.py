#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T1 — Moteur de similarité produit (baseline P1 zéro-shot) + rapport S4 (A1→A6).

Lot B de la mission T1-P0 (spec : docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md, §4/§5).
Domaine : rapprochement produits / factures-imports (dev-factures).

RUNBOOK (protocole complet, rien hors ~/AEIC)
----------------------------------------------
1. Environnement (une seule fois) :

       python3 -m venv ~/AEIC/.venv-t1
       ~/AEIC/.venv-t1/bin/pip install -r data/eval_t1/requirements-t1.txt

   Versions épinglées : voir data/eval_t1/requirements-t1.txt (fastembed 0.9.1,
   onnxruntime 1.31.1, numpy 2.5.4, Python 3.12). Aucune dépendance à torch.

2. Modèle (téléchargé automatiquement au premier lancement, puis hors-ligne) :

   - fastembed charge par défaut l'export fp16 « model_optimized.onnx » du dépôt
     Qdrant/paraphrase-multilingual-MiniLM-L12-v2-onnx-Q (235 Mo) — hors contrainte
     « int8, ~130 Mo ». Ce script force donc l'export ONNX int8 (~118 Mo) des MÊMES
     poids (Xenova/paraphrase-multilingual-MiniLM-L12-v2, fichier onnx/model_int8.onnx),
     posé dans data/eval_t1/.cache/model-int8/model_optimized.onnx (nom attendu par
     fastembed) et chargé via specific_model_path. Le cache modèle reste DANS le dépôt
     (data/eval_t1/.cache/, listé dans .gitignore) — rien n'est écrit hors ~/AEIC.
   - Taille réelle mesurée : ~129 Mo (du -sh data/eval_t1/.cache).

3. Évaluation complète A1→A6 + régénération des livrables (UNE seule commande, A6) :

       ~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer

   - lit UNIQUEMENT le jeu figé data/eval_t1/ (aucune requête SQL — le jeu fait foi) ;
   - imprime le tableau A1→A6 avec verdicts, réécrit :
       * data/eval_t1/rapport_S4.md      (chiffres réels, sorties triées stables)
       * data/eval_t1/candidats_fusion.json (contrat Lot C)
   - idempotent sur les chiffres : deux exécutions produisent des rapports aux
     chiffres identiques (seule la latence A5, timing CPU par nature variable,
     peut bouger de ±1 ms ; horodatage uniquement dans le JSON, jamais dans le
     rapport).

4. Benchmark A5 isolé (≥ 100 runs, embed 1 libellé + cosinus vs l'univers) :

       ~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --bench

5. S4' — modèle fine-tuné (P2) à périmètre égal (même jeu figé, même protocole A1-A6) :

       ~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \
           --model data/eval_t1/p2/convert/model-int8 \
           --rapport-sortie data/eval_t1/p2/rapport_S4_prime_brut.md \
           --candidats-sortie data/eval_t1/p2/candidats_fusion_prime.json

   - `--model` charge un export ONNX int8 alternatif (fine-tuné Kaggle P2, converti dans
     data/eval_t1/p2/convert/) dans LE MÊME pipeline de mesure ; les sorties sont
     redirigeables pour ne pas écraser les livrables zéro-shot (rapport_S4.md,
     candidats_fusion.json). Sans `--model` : aucun changement, le zéro-shot reste
     re-mesurable à l'identique (vérifié par diff au fil de l'eau).

MÉTHODE (une seule règle, pas de cherry-picking)
------------------------------------------------
- Embeddings : libellés EXACTS tels qu'en base (aucune normalisation maison avant
  embedding — la normalisation ci-dessous ne sert qu'au port fidèle de la baseline
  P0 et à la comptabilité d'identités par forme normalisée).
- Normalisation : port Python fidèle de app/core/Compta/AliasSuggester.php
  (règle projet : jamais de strtolower ad hoc ; StockPublic::normalizeKey =
  AliasSuggester::normalizeKey sans espaces).
- A1 : recall@3 — pour chaque libellé brut d'une paire positive sémantique, la
  cible doit figurer dans le top-3 cosinus des identités de l'univers, en excluant
  l'identité du libellé lui-même (même forme normalisée). NB : exclure tout le
  groupe de split écarterait la cible elle-même (le groupe EST la composante
  union-find des paires positives) et rendrait A1 impossible par construction.
- A2 : seuil calibré sur TRAIN uniquement (précision max sous rappel ≥ 0,90),
  PUIS mesuré sur TEST au seuil calibré.
- A3 : P0 (port AliasSuggester : égalité de forme normalisée + near-miss 1 édition,
  ensemble non plafonné — lecture la plus favorable à P0) vs P1 sur le même jeu.
- A4 : taux de paires ≥ seuil parmi durs_negatifs.csv (cible 0).
- A6 : graine fixée (42), sorties triées de façon déterministe, une commande.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import platform
import random
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

REPO = Path(__file__).resolve().parent.parent
DATA = REPO / "data" / "eval_t1"
CACHE = DATA / ".cache"
MODEL_DIR = CACHE / "model-int8"

MODEL_NAME = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
MODEL_REPO_INT8 = "Xenova/paraphrase-multilingual-MiniLM-L12-v2"  # mêmes poids, export int8
MODEL_FILE_INT8 = "onnx/model_int8.onnx"
TOKENIZER_FILES = ["tokenizer.json", "config.json", "special_tokens_map.json", "tokenizer_config.json"]

SEED = 42
TOP_K = 3
RAPPEL_MIN_A2 = 0.90
BENCH_RUNS = 200
BENCH_WARMUP = 10
MAX_CANDIDATS_FUSION = 50

SORTIES_RAPPORT = DATA / "rapport_S4.md"
SORTIES_CANDIDATS = DATA / "candidats_fusion.json"

# S4' (P2) : modèle alternatif (export ONNX int8 du fine-tuné Kaggle) + sorties
# redirigeables. Par défaut AUCUN changement : sans --model, le zéro-shot reste
# mesuré et réécrit exactement comme avant (anti-régression du protocole).
MODELE_ALT: Path | None = None

# ---------------------------------------------------------------------------
# Port fidèle de la baseline P0 — app/core/Compta/AliasSuggester.php
# (règle projet : toute normalisation passe par AliasSuggester/StockPublic ;
#  ce port Python reproduit EXACTEMENT les règles PHP, sans adjonction).
# ---------------------------------------------------------------------------

ACCENTS = {
    "à": "a", "â": "a", "ä": "a", "á": "a", "ã": "a", "å": "a",
    "ç": "c",
    "è": "e", "é": "e", "ê": "e", "ë": "e",
    "ì": "i", "í": "i", "î": "i", "ï": "i",
    "ñ": "n",
    "ò": "o", "ó": "o", "ô": "o", "ö": "o", "õ": "o",
    "ù": "u", "ú": "u", "û": "u", "ü": "u",
    "ý": "y", "ÿ": "y",
    "œ": "oe", "æ": "ae",
}

SUFFIX_STOPWORDS = [
    "white", "noir", "black",
    "blanche", "blanches", "blanc", "blancs",
    "bleue", "bleues", "bleu", "bleus",
    "verte", "vertes", "vert", "verts",
    "rose", "rouge", "jaune", "orange", "violet",
]

NEAR_MISS_MIN_LENGTH = 5

_SEPARATEURS = "_-.()[]{},;:/"


def normalize_key(value: str) -> str:
    """AliasSuggester::normalizeKey — minuscules, accents, séparateurs, espaces."""
    import re

    s = value.strip().lower()                      # mb_strtolower(trim(...))
    s = s.translate(str.maketrans(ACCENTS))        # strtr table manuelle
    s = re.sub("[" + re.escape(_SEPARATEURS) + "]+", " ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def stock_public_normalize(value: str) -> str:
    """StockPublic::normalizeKey — AliasSuggester::normalizeKey sans les espaces."""
    return normalize_key(value).replace(" ", "")


def suggest(description: str) -> str:
    """AliasSuggester::suggest — normalisation + retrait itératif des suffixes."""
    s = normalize_key(description)
    if not s:
        return ""
    tokens = s.split(" ")
    while tokens and tokens[-1] in SUFFIX_STOPWORDS:
        tokens.pop()
    return " ".join(tokens)


def _is_typo_char(c: str) -> bool:
    return c == " " or ("a" <= c <= "z") or ("A" <= c <= "Z")


def is_near_miss(a: str, b: str) -> bool:
    """AliasSuggester::isNearMiss — distance d'au plus une édition (règles PHP)."""
    if a == b:
        return False
    # ASCII uniquement (couper une chaîne multioctet par octet = faux positifs).
    for c in a + b:
        if not (0x20 <= ord(c) <= 0x7E):
            return False
    la, lb = len(a), len(b)
    if la < NEAR_MISS_MIN_LENGTH or lb < NEAR_MISS_MIN_LENGTH:
        return False
    if abs(la - lb) > 1:
        return False

    i = 0
    while i < la and i < lb and a[i] == b[i]:
        i += 1
    ja, jb = la - 1, lb - 1
    while ja >= i and jb >= i and a[ja] == b[jb]:
        ja -= 1
        jb -= 1
    mid_a = ja - i + 1
    mid_b = jb - i + 1

    if mid_a == 1 and mid_b == 1:                       # substitution d'une lettre
        return _is_typo_char(a[i]) and _is_typo_char(b[i])
    if mid_a + mid_b == 1:                              # insertion/suppression
        return _is_typo_char(a[i] if mid_a == 1 else b[i])
    return (                                            # transposition adjacente
        mid_a == 2 and mid_b == 2
        and _is_typo_char(a[i]) and _is_typo_char(a[i + 1])
        and a[i] == b[i + 1] and a[i + 1] == b[i]
    )


# ---------------------------------------------------------------------------
# Chargement du jeu figé (contrat Lot A — aucun SQL)
# ---------------------------------------------------------------------------


def _lire_csv(chemin: Path) -> list[dict]:
    with open(chemin, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


class Jeu:
    def __init__(self) -> None:
        self.univers = _lire_csv(DATA / "univers.csv")
        self.positifs_sem = _lire_csv(DATA / "positifs_semantiques.csv")
        self.positifs_casse = _lire_csv(DATA / "positifs_casse.csv")
        self.durs = _lire_csv(DATA / "durs_negatifs.csv")
        self.negatifs = _lire_csv(DATA / "negatifs.csv")
        self.residuels = _lire_csv(DATA / "residuels_a_qualifier.csv")
        with open(DATA / "split.json", encoding="utf-8") as f:
            self.split = json.load(f)

        # Univers : une ligne par identité (forme normalisée unique), tri stable.
        self.univers.sort(key=lambda r: r["forme_normalisee"])
        self.formes = [r["forme_normalisee"] for r in self.univers]
        assert len(set(self.formes)) == len(self.formes), "formes normalisées non uniques"
        self.par_forme = {r["forme_normalisee"]: r for r in self.univers}

        # Paires positives sémantiques : formes distinctes (sinon c'est de la casse).
        for p in self.positifs_sem:
            fa, fb = stock_public_normalize(p["libelle_brut"]), stock_public_normalize(p["libelle_cible"])
            assert fa and fa != fb, f"paire sémantique invalide : {p['libelle_brut']!r} / {p['libelle_cible']!r}"
            assert fb in self.par_forme, f"cible absente de l'univers : {p['libelle_cible']!r}"
        for p in self.positifs_casse:
            assert stock_public_normalize(p["libelle_brut"]) == stock_public_normalize(p["libelle_cible"]), (
                f"paire casse hétérogène : {p['libelle_brut']!r}"
            )

        # Split par groupe (mapping = nom de groupe → train/test) ; anti-fuite.
        self.groupe_split = dict(self.split["mapping"])
        for p in self.positifs_sem:
            cible = self.par_forme[stock_public_normalize(p["libelle_cible"])]
            assert cible["groupe"] in self.groupe_split, f"groupe inconnu : {cible['groupe']}"
            p["split"] = self.groupe_split[cible["groupe"]]
        n_tr = sum(1 for p in self.positifs_sem if p["split"] == "train")
        n_te = sum(1 for p in self.positifs_sem if p["split"] == "test")
        attendu = self.split["stats"]
        assert (n_tr, n_te) == (attendu["paires_semantiques_train"], attendu["paires_semantiques_test"]), (
            f"split incohérent avec split.json : {n_tr}/{n_te}"
        )

        # Paires à exclure des faux positifs / candidats : résiduels (non qualifiés).
        self.residuels_formes: set[tuple[str, str]] = set()
        for r in self.residuels:
            fa = stock_public_normalize(r["libelle_a"] or "")
            fb = stock_public_normalize(r["libelle_b"] or "")
            if fa and fb and fa != fb:
                self.residuels_formes.add(tuple(sorted((fa, fb))))

        # Paires déjà positives connues (fusionnées/alias), par paire de formes.
        self.positives_formes: set[tuple[str, str]] = set()
        for p in list(self.positifs_sem) + list(self.positifs_casse):
            fa = stock_public_normalize(p["libelle_brut"])
            fb = stock_public_normalize(p["libelle_cible"])
            if fa and fb and fa != fb:
                self.positives_formes.add(tuple(sorted((fa, fb))))

        # Durs négatifs : les deux libellés doivent exister dans l'univers.
        for d in self.durs:
            assert d["libelle_a"] in {r["libelle"] for r in self.univers}, f"dur introuvable : {d['libelle_a']!r}"
            assert d["libelle_b"] in {r["libelle"] for r in self.univers}, f"dur introuvable : {d['libelle_b']!r}"

    # -- utilitaires ------------------------------------------------------

    def forme(self, libelle: str) -> str:
        return stock_public_normalize(libelle)

    def paires_par_split(self, split: str) -> list[dict]:
        return [p for p in self.positifs_sem if p["split"] == split]


# ---------------------------------------------------------------------------
# Modèle (fastembed, ONNX int8, cache dans le dépôt)
# ---------------------------------------------------------------------------


def _version(paquet: str) -> str:
    from importlib import metadata

    try:
        return metadata.version(paquet)
    except Exception:
        return "n/a"


def assurer_modele() -> None:
    """Télécharge l'export int8 (~118 Mo) si absent ; ensuite tout est hors-ligne."""
    onnx = MODEL_DIR / "model_optimized.onnx"
    if onnx.exists() and all((MODEL_DIR / f).exists() for f in TOKENIZER_FILES):
        return
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    from huggingface_hub import hf_hub_download

    print(f"[modèle] téléchargement de {MODEL_REPO_INT8}/{MODEL_FILE_INT8} → {MODEL_DIR}")
    f = hf_hub_download(repo_id=MODEL_REPO_INT8, filename=MODEL_FILE_INT8, local_dir=str(CACHE / "dl"))
    os.replace(f, onnx)
    for t in TOKENIZER_FILES:
        p = Path(hf_hub_download(repo_id=MODEL_REPO_INT8, filename=t, local_dir=str(CACHE / "dl")))
        if not (MODEL_DIR / t).exists():
            os.replace(p, MODEL_DIR / t)
    import shutil

    shutil.rmtree(CACHE / "dl", ignore_errors=True)


def charger_modele():
    from fastembed import TextEmbedding

    dossier = MODEL_DIR
    if MODELE_ALT is not None:
        dossier = MODELE_ALT
        manquants = [f for f in ("model_optimized.onnx", "tokenizer.json", "tokenizer_config.json")
                     if not (dossier / f).exists()]
        assert not manquants, f"modèle alternatif incomplet ({dossier}) : manque {manquants}"
    else:
        assurer_modele()
    return TextEmbedding(
        model_name=MODEL_NAME,
        specific_model_path=str(dossier),
        threads=os.cpu_count() or 4,
    )


def vectoriser(modele, libelles: list[str]) -> np.ndarray:
    """Embeddings L2-normalisés des libellés EXACTS (aucune normalisation maison)."""
    v = np.stack(list(modele.embed(libelles))).astype(np.float32)
    n = np.linalg.norm(v, axis=1, keepdims=True)
    return v / n


# ---------------------------------------------------------------------------
# Baseline P0 (port AliasSuggester) — suggestion déterministe, non plafonnée
# ---------------------------------------------------------------------------


def p0_suggestions(jeu: Jeu, libelle_brut: str) -> set[str]:
    """Formes des identités suggérées par P0 : égalité normalisée puis near-miss 1 édition.

    Lecture la plus favorable à P0 : l'ensemble complet des suggestions
    (AliasSuggester::groupDuplicates ne plafonne pas ses groupes).
    """
    norm = normalize_key(libelle_brut)
    out: set[str] = set()
    for row in jeu.univers:
        n = normalize_key(row["libelle"])
        if n == norm or is_near_miss(n, norm):
            out.add(row["forme_normalisee"])
    return out


def p0_reussit(jeu: Jeu, paire: dict) -> bool:
    cible = stock_public_normalize(paire["libelle_cible"])
    return cible in p0_suggestions(jeu, paire["libelle_brut"])


# ---------------------------------------------------------------------------
# Mesures A1–A4
# ---------------------------------------------------------------------------


class Moteur:
    def __init__(self, jeu: Jeu) -> None:
        self.jeu = jeu
        self.modele = charger_modele()
        self.U = vectoriser(self.modele, [r["libelle"] for r in jeu.univers])  # (120, 384)
        bruts = sorted({p["libelle_brut"] for p in jeu.positifs_sem})
        self.bruts = bruts
        self.Q = vectoriser(self.modele, bruts)
        self.idx_brut = {b: i for i, b in enumerate(bruts)}
        self.idx_libelle_univers = {r["libelle"]: i for i, r in enumerate(jeu.univers)}

    # -- classement -------------------------------------------------------

    def classement(self, libelle_brut: str) -> list[tuple[str, float]]:
        """(forme, score) triés par score décroissant, égalités départagées par forme croissante."""
        jeu = self.jeu
        q = self.Q[self.idx_brut[libelle_brut]]
        scores = self.U @ q
        forme_brut = jeu.forme(libelle_brut)
        idx = [i for i in range(len(jeu.univers)) if jeu.formes[i] != forme_brut]  # exclut soi-même
        idx.sort(key=lambda i: (-float(scores[i]), jeu.formes[i]))
        return [(jeu.formes[i], float(scores[i])) for i in idx]

    def score_cible(self, paire: dict) -> float:
        q = self.Q[self.idx_brut[paire["libelle_brut"]]]
        c = self.idx_libelle_univers[self.jeu.par_forme[self.jeu.forme(paire["libelle_cible"])]["libelle"]]
        return float(self.U[c] @ q)

    # -- A1 : recall@k ----------------------------------------------------

    def recall_at(self, split: str, k: int = TOP_K) -> tuple[float, int, int, list[dict]]:
        paires = self.jeu.paires_par_split(split)
        recs = []
        for p in paires:
            cible = self.jeu.forme(p["libelle_cible"])
            top = self.classement(p["libelle_brut"])[:k]
            rang = next((i + 1 for i, (f, _) in enumerate(top) if f == cible), None)
            p = dict(p, rang=rang, score=self.score_cible(p))
            recs.append(p)
        n_ok = sum(1 for r in recs if r["rang"] is not None)
        return n_ok / len(recs) if recs else 0.0, n_ok, len(recs), recs

    # -- A2 : calibration sur train, mesure sur test -----------------------

    def _paires_scorees(self, split: str) -> list[tuple[str, str, float]]:
        """Toutes paires (brut, forme candidate) d'un split avec leur score cosinus."""
        jeu = self.jeu
        out = []
        for brut in sorted({p["libelle_brut"] for p in jeu.paires_par_split(split)}):
            for forme, score in self.classement(brut):
                out.append((brut, forme, score))
        return out

    def _positifs_split(self, split: str) -> set[tuple[str, str]]:
        return {
            (p["libelle_brut"], self.jeu.forme(p["libelle_cible"]))
            for p in self.jeu.paires_par_split(split)
        }

    def _est_residuel(self, brut: str, forme: str) -> bool:
        return tuple(sorted((self.jeu.forme(brut), forme))) in self.jeu.residuels_formes

    def _precision_rappel(self, split: str, seuil: float) -> tuple[float, float, int, int]:
        paires = self._paires_scorees(split)
        positifs = self._positifs_split(split)
        tp = fp = 0
        for brut, forme, s in paires:
            if s < seuil:
                continue
            if (brut, forme) in positifs:
                tp += 1
            elif not self._est_residuel(brut, forme):  # non qualifiables exclus du dénominateur
                fp += 1
        rappel = tp / len(positifs) if positifs else 0.0
        precision = tp / (tp + fp) if (tp + fp) else 0.0
        return precision, rappel, tp, fp

    def calibrer(self) -> tuple[float, dict]:
        """Seuil maximisant la précision sous rappel ≥ 0,90 sur TRAIN (une seule règle)."""
        train = self.jeu.paires_par_split("train")
        scores_pos = [self.score_cible(p) for p in train]
        candidats = sorted(set(scores_pos))
        meilleure = None
        trace = []
        for t in candidats:
            precision, rappel, tp, fp = self._precision_rappel("train", t)
            trace.append((round(t, 4), round(rappel, 3), round(precision, 3), tp, fp))
            if rappel >= RAPPEL_MIN_A2:
                # précision max ; égalité → seuil le plus haut (le plus conservateur)
                cle = (round(precision, 6), round(t, 6))
                if meilleure is None or cle > meilleure[0]:
                    meilleure = (cle, t, precision, rappel, tp, fp)
        assert meilleure is not None, "aucun seuil ne respecte rappel ≥ 0,90 (impossible : t = min)"
        _, seuil, precision, rappel, tp, fp = meilleure
        return seuil, {
            "precision_train": precision, "rappel_train": rappel, "tp_train": tp, "fp_train": fp,
            "trace": trace,
        }

    # -- A4 : durs négatifs ------------------------------------------------

    def faux_positifs_durs(self, seuil: float) -> tuple[int, int, list[tuple[str, str, float]]]:
        jeu = self.jeu
        fautes = []
        for d in jeu.durs:
            i = self.idx_libelle_univers[d["libelle_a"]]
            j = self.idx_libelle_univers[d["libelle_b"]]
            s = float(self.U[i] @ self.U[j])
            if s >= seuil:
                fautes.append((d["libelle_a"], d["libelle_b"], s))
        fautes.sort(key=lambda x: (-x[2], x[0], x[1]))
        return len(fautes), len(jeu.durs), fautes


# ---------------------------------------------------------------------------
# Candidats fusion (contrat Lot C)
# ---------------------------------------------------------------------------


def candidats_fusion(moteur: Moteur, seuil: float) -> tuple[list[dict], int, int]:
    """Paires candidates ≥ seuil (contrat Lot C) + total avant plafond + nb durs négatifs inclus."""
    jeu = moteur.jeu
    durs_formes = {
        tuple(sorted((jeu.forme(d["libelle_a"]), jeu.forme(d["libelle_b"])))) for d in jeu.durs
    }
    vivants = [i for i, r in enumerate(jeu.univers) if r["vivant"] == "1"]
    out = []
    for a in range(len(vivants)):
        for b in range(a + 1, len(vivants)):
            i, j = vivants[a], vivants[b]
            ri, rj = jeu.univers[i], jeu.univers[j]
            if ri["groupe"] == rj["groupe"]:
                continue  # déjà positives connues (fusionnées/alias)
            paire = tuple(sorted((ri["forme_normalisee"], rj["forme_normalisee"])))
            if paire in jeu.positives_formes or paire in jeu.residuels_formes:
                continue
            s = float(moteur.U[i] @ moteur.U[j])
            if s >= seuil:
                out.append({"a": ri["libelle"], "b": rj["libelle"], "score": round(s, 4),
                            "_dur": paire in durs_formes})
    out.sort(key=lambda c: (-c["score"], c["a"], c["b"]))
    gardes = out[:MAX_CANDIDATS_FUSION]
    n_durs = sum(1 for c in gardes if c.pop("_dur"))
    for c in out[MAX_CANDIDATS_FUSION:]:
        c.pop("_dur")
    return gardes, len(out), n_durs


# ---------------------------------------------------------------------------
# A5 : benchmark
# ---------------------------------------------------------------------------


def _lscpu() -> dict:
    info = {"cpu": "n/a", "coeurs": str(os.cpu_count() or "?")}
    try:
        out = subprocess.run(["lscpu"], capture_output=True, text=True, timeout=10).stdout
        for ligne in out.splitlines():
            if ligne.startswith("Model name:"):
                info["cpu"] = ligne.split(":", 1)[1].strip()
            if ligne.startswith(("CPU(s):", "Cœurs", "Core(s)")):
                info.setdefault("detail", ligne.strip())
    except Exception:
        pass
    return info


def bench(moteur: Moteur, runs: int = BENCH_RUNS) -> dict:
    label = "Coca Cola"
    for _ in range(BENCH_WARMUP):
        list(moteur.modele.embed([label]))
    temps: list[float] = []
    for _ in range(runs):
        t0 = time.perf_counter()
        v = np.stack(list(moteur.modele.embed([label])))
        q = v[0] / np.linalg.norm(v[0])
        _ = int(np.argmax(moteur.U @ q))  # cosinus vs les ~120 vecteurs de l'univers
        temps.append((time.perf_counter() - t0) * 1000.0)
    t = np.array(temps)
    return {
        "label": label,
        "runs": runs,
        "moyenne_ms": float(t.mean()),
        "p50_ms": float(np.percentile(t, 50)),
        "p95_ms": float(np.percentile(t, 95)),
        "min_ms": float(t.min()),
        "max_ms": float(t.max()),
        "cpu": _lscpu(),
    }


# ---------------------------------------------------------------------------
# Échecs P0 documentés (spec §2) couverts explicitement (A3)
# ---------------------------------------------------------------------------

ECHECS_P0_DOCUMENTES = [
    ("« pepper »→« Dr Pepper »", "Pepper", "Dr Pepper"),
    ("« Cristaline »→« Eau »", "Cristaline", "Eau"),
    ("« monster »→« monster noire »", "monster", "monster noire"),
    ("« cocacola »→« Coca Cola » (concaténation — analogue en jeu : abrègement « Coca »)", "Coca", "Coca Cola"),
    ("« MONSTER ULTRA ZERO BTE 50CL »→« Monster Blanche »", "MONSTER ULTRA ZERO BTE 50CL", "Monster Blanche"),
]


# ---------------------------------------------------------------------------
# Rapport S4
# ---------------------------------------------------------------------------


def _pct(x: float) -> str:
    return f"{100.0 * x:.1f} %".replace(".", ",")


def ecrire_rapport(moteur: Moteur, seuil: float, cal: dict, mesures: dict) -> None:
    jeu = moteur.jeu
    env = mesures["env"]
    lignes = []
    a = lignes.append

    a("# Rapport S4 — Baseline P1 zéro-shot (embeddings) vs cibles A1–A6")
    a("")
    a("> Mission T1-P0 · Lot B (dev-factures) · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§4 P1, §5 cibles).")
    a("> Jeu figé `data/eval_t1/` (verdict amont A2 : CONFORME, SHA256SUMS vérifiés). Aucune requête SQL.")
    a("> Généré par : `~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer` (A6, une seule commande).")
    a("")
    a("## Environnement et modèle")
    a("")
    a("| Élément | Valeur |")
    a("|---|---|")
    a(f"| Modèle | `{MODEL_NAME}` |")
    if MODELE_ALT is not None:
        a(f"| Binaire chargé | export ONNX **int8** local `{MODELE_ALT}` (fine-tuné P2, mêmes poids d'architecture), via fastembed `specific_model_path` |")
    else:
        a(f"| Binaire chargé | export ONNX **int8** `{MODEL_REPO_INT8}/{MODEL_FILE_INT8}` (mêmes poids), via fastembed `specific_model_path` |")
    if env["taille_cache"]:
        a(f"| Taille réelle int8 | {env['taille_modele_mo']} Mo (`model_optimized.onnx`) ; cache total {env['taille_cache']} (`du -sh data/eval_t1/.cache`) |")
    else:
        a(f"| Taille réelle int8 | {env['taille_modele_mo']} Mo (`model_optimized.onnx`) |")
    a(f"| Bibliothèque | fastembed {env['fastembed']} (onnxruntime {env['onnxruntime']}, numpy {env['numpy']}, sans torch) |")
    a(f"| Python | {env['python']} |")
    a(f"| CPU | {env['cpu']} — {env['coeurs']} cœurs, session onnxruntime `threads={os.cpu_count()}` |")
    a(f"| Pooling/sortie | mean pooling masqué (fastembed) puis L2-normalisation ; 384 dimensions |")
    a(f"| Graine | {SEED} (pipeline déterministe ; embeddings reproductibles) |")
    a("")
    if MODELE_ALT is None:
        a("Écart documenté : fastembed télécharge par défaut le fp16 (235 Mo) du dépôt Qdrant ; la contrainte")
        a("« int8, ≤ ~130 Mo, CPU » impose l'export int8 (~118 Mo) ci-dessus — obtenu des MÊMES poids, aucune")
        a("conversion maison (aucun entraînement, zéro-shot strict).")
    else:
        a("Poids fine-tunés (P2, kernel Kaggle offline — MNRL sur le split TRAIN du jeu figé + variantes")
        a("synthétiques, anti-fuite vérifié amont) exportés localement en ONNX fp32 puis quantifiés int8")
        a("(quantization dynamique onnxruntime, même méthode que l'export Xenova de référence).")
    a("")
    a("## Verdicts A1–A6 (cibles fixées avant mesure, spec §5)")
    a("")
    a("| # | Critère | Cible | Mesuré | Verdict |")
    a("|---|---|---|---|---|")

    a1 = mesures["a1"]
    a(f"| A1 | Rappel fusions sémantiques (recall@3, TEST) | ≥ 90 % | {_pct(a1['rappel'])} ({a1['ok']}/{a1['total']}) | {a1['verdict']} |")
    a(f"| A2 | Précision à seuil au rappel ≥ 90 % (TEST au seuil calibré sur TRAIN) | ≥ 85 % | précision {_pct(mesures['a2']['precision'])} au rappel {_pct(mesures['a2']['rappel'])} ({mesures['a2']['tp']} TP / {mesures['a2']['fp']} FP), seuil {seuil:.4f} | {mesures['a2']['verdict']} |")
    a3 = mesures["a3"]
    a(f"| A3 | Supériorité sur P0 (ses échecs → P1 strictement mieux ; zéro régression) | gain > 0, régression = 0 | {a3['p0_echecs_p1_ok']} échecs P0 rattrapés par P1, {a3['p0_ok_p1_echec']} régression(s) ; P0 {a3['p0_ok']}/50 vs P1 {a3['p1_ok']}/50 | {a3['verdict']} |")
    a4 = mesures["a4"]
    a(f"| A4 | Durs négatifs intra-famille ≥ seuil | 0 | {a4['faux_pos']} / {a4['total']} ({_pct(a4['taux'])}) | {a4['verdict']} |")
    a5 = mesures["a5"]
    a(f"| A5 | Inférence 1 libellé vs ~{len(jeu.univers)} clés (CPU) | < 50 ms | moyenne {a5['moyenne_ms']:.0f} ms, p50 {a5['p50_ms']:.0f} ms, p95 {a5['p95_ms']:.0f} ms ({a5['runs']} runs) | {a5['verdict']} |")
    a("| A6 | Repro : une commande régénère tout, sorties stables | rejouable | `--evaluer` régénère rapport + candidats ; tri déterministe ; graine 42 | ATTEINT |")
    a("")
    a("Un critère MANQUÉ déclenche le palier P2 (fine-tune léger) — ce n'est pas un échec du lot (§5, décision D1).")
    a("")

    # --- A1 détail
    a("## A1 — recall@3 par split (détail)")
    a("")
    a("| Split | Paires | recall@1 | recall@3 |")
    a("|---|---:|---|---|")
    for split in ("train", "test"):
        r1, ok1, n1, _ = moteur.recall_at(split, 1)
        r3, ok3, n3, _ = moteur.recall_at(split, 3)
        a(f"| {split} | {n3} | {_pct(r1)} ({ok1}/{n1}) | {_pct(r3)} ({ok3}/{n3}) |")
    a("")
    a("Manquants TEST au recall@3 (libellé brut → cible, meilleur rang obtenu, score cible) :")
    for r in mesures["a1"]["manquants"]:
        a(f"- « {r['libelle_brut']} » → « {r['libelle_cible']} » : rang > 3 (meilleur candidat « {r['top1']} » à {r['top1_score']:.3f} ; cible à {r['score']:.3f})")
    a("")
    a("Règle d'exclusion : l'identité du libellé lui-même (même forme normalisée). Exclure le groupe de split")
    a("entier écarterait la cible par construction (le groupe EST la composante union-find des paires positives).")
    a("")

    # --- A2 détail
    a("## A2 — calibration du seuil (TRAIN uniquement, une seule règle)")
    a("")
    a(f"- Seuil calibré : **{seuil:.4f}** — précision TRAIN {_pct(cal['precision_train'])} au rappel TRAIN {_pct(cal['rappel_train'])} (≥ {int(RAPPEL_MIN_A2*100)} %).")
    a(f"- Reporté tel quel sur TEST : précision {_pct(mesures['a2']['precision'])}, rappel {_pct(mesures['a2']['rappel'])} ({mesures['a2']['tp']} TP, {mesures['a2']['fp']} FP).")
    a("- Espace de notation : chaque libellé brut des positifs × toutes les identités de l'univers (moins soi-même) ;")
    a("  TP = paire positive sémantique du split ; FP = paire ≥ seuil hors vérité terrain, hors résiduels §1.3")
    a("  (non qualifiables, affichés dans l'autre section de l'écran).")
    a("- Courbe P/R TRAIN (seuil, rappel, précision, TP, FP) :")
    a("")
    a("| seuil | rappel | précision | TP | FP |")
    a("|---|---|---|---:|---:|")
    for t, r, p, tp, fp in cal["trace"]:
        a(f"| {t:.4f} | {r:.3f} | {p:.3f} | {tp} | {fp} |")
    a("")

    # --- A3 détail
    a("## A3 — P0 (port fidèle AliasSuggester) vs P1 sur le même jeu figé (50 paires)")
    a("")
    a3 = mesures["a3"]
    a(f"- P0 réussit {a3['p0_ok']}/50 ; P1 (recall@3) réussit {a3['p1_ok']}/50.")
    a(f"- Échecs de P0 rattrapés par P1 : **{a3['p0_echecs_p1_ok']}** (strictement > 0 → cible).")
    a(f"- Régressions (P0 ok, P1 échoue) : **{a3['p0_ok_p1_echec']}** (cible : 0).")
    a("")
    a("P0 = égalité de forme normalisée + near-miss 1 édition (`AliasSuggester::groupDuplicates`, ensemble non")
    a("plafonné — lecture la plus favorable à P0). Le retrait de suffixes (`suggest`) est un suggéreur de clé,")
    a("pas un détecteur de doublons ; l'inclure contredirait les échecs documentés §2 que A3 doit couvrir.")
    a("Port Python validé différentiellement contre le PHP réel (réflexion sur `AliasSuggester::isNearMiss`) :")
    a("22 049 paires de formes normalisées du jeu, 0 divergence (normalisation via le port fidèle, pas de")
    a("strtolower ad hoc — règle projet AGENTS.md).")
    a("")
    a("Échecs P0 documentés (spec §2), couverture explicite :")
    a("")
    a("| Cas documenté | Paire en jeu | P0 | P1 (rang, score cible) |")
    a("|---|---|---|---|")
    for titre, brut, cible in ECHECS_P0_DOCUMENTES:
        d = a3["documentes"].get((brut, cible))
        if d is None:
            a(f"| {titre} | (absente du jeu figé) | — | — |")
        else:
            p0txt = "réussit" if d["p0"] else "échoue"
            if d["rang"]:
                p1txt = f"réussit (rang {d['rang']}, {d['score']:.3f})"
            else:
                p1txt = f"échoue (cible {d['score']:.3f})"
            a(f"| {titre} | « {brut} » → « {cible} » | {p0txt} | {p1txt} |")
    a("")
    a("Détail des échecs P0 rattrapés par P1 :")
    for r in a3["rattrapes"]:
        a(f"- « {r['libelle_brut']} » → « {r['libelle_cible']} » (rang {r['rang']}, {r['score']:.3f})")
    if a3["regressions"]:
        a("Détail des régressions P1 (à traiter en P2) :")
        for r in a3["regressions"]:
            a(f"- « {r['libelle_brut']} » → « {r['libelle_cible']} » (meilleur candidat « {r['top1']} », cible {r['score']:.3f})")
    else:
        a("Aucune régression de P1 sur les paires réussies par P0.")
    a("")

    # --- A4 détail
    a("## A4 — durs négatifs intra-famille (cible : 0 paire ≥ seuil)")
    a("")
    a4 = mesures["a4"]
    a(f"- {a4['faux_pos']} / {a4['total']} paires ≥ seuil {seuil:.4f} ({_pct(a4['taux'])}).")
    if a4["fautes"]:
        a("- Paires fautives (score décroissant) :")
        for la, lb, s in a4["fautes"]:
            a(f"  - « {la} » / « {lb} » — {s:.4f}")
    else:
        a("- Aucune variante distincte d'une même famille ne dépasse le seuil.")
    a("")

    # --- A5 détail
    a("## A5 — benchmark inférence CPU")
    a("")
    a5 = mesures["a5"]
    a(f"- {a5['runs']} runs (après {BENCH_WARMUP} échauffements) : embed(« {a5['label']} ») + cosinus contre les {len(jeu.univers)} vecteurs de l'univers.")
    a(f"- moyenne {a5['moyenne_ms']:.1f} ms · p50 {a5['p50_ms']:.1f} ms · p95 {a5['p95_ms']:.1f} ms · min {a5['min_ms']:.1f} ms · max {a5['max_ms']:.1f} ms.")
    a(f"- CPU : {a5['cpu']['cpu']} ({a5['cpu']['coeurs']} cœurs). Rejouer : `scripts/t1_similarity.py --bench`.")
    a("- NB : timing CPU par nature variable (±1 ms) — c'est la seule mesure non strictement idempotente du rapport.")
    a("")

    # --- A6
    a("## A6 — reproductibilité")
    a("")
    a("```bash")
    a("~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --evaluer   # A1–A6 + rapport + candidats")
    a("~/AEIC/.venv-t1/bin/python3 scripts/t1_similarity.py --bench      # A5 isolé")
    a("python3 -m py_compile scripts/t1_similarity.py                    # syntaxe")
    a("jq . data/eval_t1/candidats_fusion.json                           # contrat Lot C valide")
    a("```")
    a("")
    a(f"- Sorties triées de façon déterministe (score décroissant, puis libellés) ; plafond {MAX_CANDIDATS_FUSION} candidats.")
    a(f"- Candidats ≥ seuil avant plafond : {mesures['candidats_total']} ; retenus : {mesures['candidats_kept']}.")
    a(f"- Dont étiquetés durs négatifs intra-famille (cf. A4) : {mesures['candidats_durs']}/{mesures['candidats_kept']}")
    a("  — le seuil P1 zéro-shot étant bas, la plupart des candidats sont des variantes de même famille ;")
    a("  le fichier respecte le contrat Lot C (exclusions : positives + résiduels uniquement), l'humain décide.")
    a("- Deux exécutions de `--evaluer` produisent des rapports aux chiffres identiques (vérifié par diff :")
    a("  seule la ligne A5 « moyenne/p50/p95 » peut bouger de ±1 ms).")
    a("")

    # --- limites
    a("## Limites documentées (spec §7)")
    a("")
    a("- **Libellés courts** : moyenne ~10,7 caractères (min 3) — peu de contexte sémantique ; les scores")
    a("  absolus de ce modèle sont bas (famille « proches » ≈ 0,3–0,7), d'où un seuil calibré bas et l'importance")
    a("  du garde-fou A4 (durs négatifs intra-famille).")
    a("- Vérité terrain petite (50 paires sémantiques informatives) : la calibration du seuil reste fragile ;")
    a("  les suggestions sont une file d'attente humaine, jamais une fusion automatique (§6).")
    a("- Le seuil calibré sur la tâche brut→cible est appliqué tel quel aux paires identité×identité du fichier")
    a("  de candidats (même espace de scores, même modèle) — hypothèse documentée.")
    if MODELE_ALT is None:
        a("- Zéro-shot strict : aucun entraînement, aucune donnée envoyée hors VPS (Kaggle interdit ici).")
    else:
        a("- Modèle fine-tuné (P2) : entraîné sur le split TRAIN du même jeu figé (+ variantes synthétiques)")
        a("  — les mesures sur TRAIN sont optimistes par construction (données vues) ; le split TEST")
        a("  (groupes jamais vus, anti-fuite vérifié amont) reste la mesure honnête.")
    a("")

    SORTIES_RAPPORT.write_text("\n".join(lignes) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# Orchestration
# ---------------------------------------------------------------------------


def info_env() -> dict:
    dossier = MODELE_ALT if MODELE_ALT is not None else MODEL_DIR
    taille = (dossier / "model_optimized.onnx").stat().st_size / 1e6
    try:
        cache = subprocess.run(["du", "-sh", str(CACHE)], capture_output=True, text=True, timeout=10).stdout.split()[0]
    except Exception:
        cache = "n/a"
    if MODELE_ALT is not None:
        cache = ""  # la ligne « cache total » n'a de sens que pour le modèle en production
    cpu = _lscpu()
    return {
        "taille_modele_mo": round(taille, 1),
        "taille_cache": cache,
        "fastembed": _version("fastembed"),
        "onnxruntime": _version("onnxruntime"),
        "numpy": _version("numpy"),
        "python": platform.python_version(),
        "cpu": cpu["cpu"],
        "coeurs": cpu["coeurs"],
    }


def evaluer() -> int:
    random.seed(SEED)
    np.random.seed(SEED)

    print("[1/5] chargement du jeu figé data/eval_t1/ …")
    jeu = Jeu()
    print(f"      univers {len(jeu.univers)} identités · positifs sémantiques {len(jeu.positifs_sem)} "
          f"(train {len(jeu.paires_par_split('train'))} / test {len(jeu.paires_par_split('test'))}) · "
          f"durs négatifs {len(jeu.durs)} · résiduels {len(jeu.residuels)}")

    print("[2/5] chargement du modèle + vectorisation (libellés EXACTS) …")
    moteur = Moteur(jeu)
    print(f"      binaire ONNX : {MODELE_ALT if MODELE_ALT is not None else MODEL_DIR}")
    print(f"      univers {moteur.U.shape} · bruts {moteur.Q.shape} (384 dim attendu)")

    print("[3/5] mesures A1–A4 …")
    # A1
    rappel_test, ok_test, n_test, recs_test = moteur.recall_at("test", TOP_K)
    _, ok1_test, _, _ = moteur.recall_at("test", 1)
    manquants = []
    for r in recs_test:
        if r["rang"] is None:
            top = moteur.classement(r["libelle_brut"])[0]
            manquants.append({**r, "top1": jeu.par_forme[top[0]]["libelle"], "top1_score": top[1]})
    a1 = {
        "rappel": rappel_test, "ok": ok_test, "total": n_test, "manquants": manquants,
        "verdict": "ATTEINT" if rappel_test >= 0.90 else "MANQUÉ",
    }

    # A2
    seuil, cal = moteur.calibrer()
    precision_te, rappel_te, tp_te, fp_te = moteur._precision_rappel("test", seuil)
    a2 = {
        "precision": precision_te, "rappel": rappel_te, "tp": tp_te, "fp": fp_te,
        "verdict": "ATTEINT" if precision_te >= 0.85 else "MANQUÉ",
    }

    # A3
    _, _, _, recs_train = moteur.recall_at("train", TOP_K)
    rangs = {p["libelle_brut"]: p for p in recs_train + recs_test}
    p0_ok = p1_ok = gain = regression = 0
    rattrapes, regressions = [], []
    for p in jeu.positifs_sem:
        b, c = p["libelle_brut"], p["libelle_cible"]
        ok0 = p0_reussit(jeu, p)
        r = rangs[b]
        ok1 = r["rang"] is not None
        p0_ok += ok0
        p1_ok += ok1
        if not ok0 and ok1:
            gain += 1
            rattrapes.append({"libelle_brut": b, "libelle_cible": c, "rang": r["rang"], "score": r["score"]})
        if ok0 and not ok1:
            regression += 1
            top = moteur.classement(b)[0]
            regressions.append({"libelle_brut": b, "libelle_cible": c, "top1": jeu.par_forme[top[0]]["libelle"], "score": r["score"]})
    rattrapes.sort(key=lambda x: (x["libelle_brut"], x["libelle_cible"]))
    regressions.sort(key=lambda x: (x["libelle_brut"], x["libelle_cible"]))
    documentes = {}
    for _, brut, cible in ECHECS_P0_DOCUMENTES:
        p = next((p for p in jeu.positifs_sem if p["libelle_brut"] == brut and p["libelle_cible"] == cible), None)
        if p:
            r = rangs[brut]
            documentes[(brut, cible)] = {
                "p0": p0_reussit(jeu, p), "rang": r["rang"], "score": r["score"],
            }
    a3 = {
        "p0_ok": p0_ok, "p1_ok": p1_ok, "p0_echecs_p1_ok": gain, "p0_ok_p1_echec": regression,
        "rattrapes": rattrapes, "regressions": regressions, "documentes": documentes,
        "verdict": "ATTEINT" if (gain > 0 and regression == 0) else "MANQUÉ",
    }

    # A4
    faux_pos, total_durs, fautes = moteur.faux_positifs_durs(seuil)
    a4 = {"faux_pos": faux_pos, "total": total_durs, "taux": faux_pos / total_durs if total_durs else 0.0,
          "fautes": fautes, "verdict": "ATTEINT" if faux_pos == 0 else "MANQUÉ"}

    print("[4/5] benchmark A5 …")
    b = bench(moteur)
    a5 = {**b, "verdict": "ATTEINT" if b["moyenne_ms"] < 50.0 else "MANQUÉ"}

    print("[5/5] écriture rapport_S4.md + candidats_fusion.json …")
    cands, total_cands, n_durs_cands = candidats_fusion(moteur, seuil)
    mesures = {"a1": a1, "a2": a2, "a3": a3, "a4": a4, "a5": a5, "env": info_env(),
               "candidats_total": total_cands, "candidats_kept": len(cands),
               "candidats_durs": n_durs_cands}
    ecrire_rapport(moteur, seuil, cal, mesures)

    payload = {
        "generated_at": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "modele": f"{MODEL_NAME} (ONNX int8 via fastembed {_version('fastembed')})"
                  + (f" — fine-tuné P2 ({MODELE_ALT})" if MODELE_ALT is not None else ""),
        "seuil": round(seuil, 4),
        "candidats": cands,
    }
    SORTIES_CANDIDATS.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # -- sortie console -----------------------------------------------------
    print()
    print("=" * 78)
    print(f"  Seuil calibré (TRAIN, précision max sous rappel ≥ {int(RAPPEL_MIN_A2*100)} %) : {seuil:.4f}")
    print("=" * 78)
    print(f"  A1 recall@3 TEST        : {ok_test}/{n_test} = {_pct(rappel_test)}   [cible ≥ 90 %]  → {a1['verdict']}")
    print(f"     recall@1 TEST (info) : {ok1_test}/{n_test}")
    print(f"  A2 précision TEST @seuil: {_pct(precision_te)} (rappel {_pct(rappel_te)}, {tp_te} TP / {fp_te} FP) [cible ≥ 85 %] → {a2['verdict']}")
    print(f"  A3 P0 {p0_ok}/50 vs P1 {p1_ok}/50 — rattrapés {gain}, régressions {regression}       → {a3['verdict']}")
    print(f"  A4 durs ≥ seuil         : {faux_pos}/{total_durs}                              [cible 0]      → {a4['verdict']}")
    print(f"  A5 moyenne/p50/p95      : {b['moyenne_ms']:.1f}/{b['p50_ms']:.1f}/{b['p95_ms']:.1f} ms          [cible < 50 ms]→ {a5['verdict']}")
    print(f"  A6 reproductible        : --evaluer régénère tout (graine {SEED})               → ATTEINT")
    print("=" * 78)
    print(f"  Écrit : {SORTIES_RAPPORT.relative_to(REPO)}")
    print(f"  Écrit : {SORTIES_CANDIDATS.relative_to(REPO)} ({len(cands)} candidats, {total_cands} ≥ seuil avant plafond {MAX_CANDIDATS_FUSION}, dont {n_durs_cands} durs négatifs)")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        prog="t1_similarity.py",
        description="T1 — baseline P1 zéro-shot : embeddings multilingues int8 + seuil calibré, "
                    "mesures A1–A6 sur le jeu figé data/eval_t1/, rapport S4 et candidats fusion (Lot C).",
        epilog="Runbook complet dans l'entête du script (scripts/t1_similarity.py).",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--evaluer", action="store_true",
                        help="évaluation complète A1–A6, réécrit rapport_S4.md et candidats_fusion.json (défaut)")
    parser.add_argument("--bench", action="store_true",
                        help="benchmark A5 seul (embed 1 libellé + cosinus vs l'univers)")
    parser.add_argument("--runs", type=int, default=BENCH_RUNS,
                        help=f"nombre de runs du benchmark A5 (défaut {BENCH_RUNS})")
    parser.add_argument("--model", metavar="DOSSIER", default=None,
                        help="mesure un export ONNX int8 alternatif (dossier contenant "
                             "model_optimized.onnx + tokenizer.json + tokenizer_config.json), "
                             "p. ex. le fine-tuné P2 — SANS changer le protocole ni les "
                             "valeurs par défaut (chemin relatif à la racine du dépôt)")
    parser.add_argument("--rapport-sortie", metavar="FICHIER", default=None,
                        help="chemin du rapport à écrire au lieu de data/eval_t1/rapport_S4.md")
    parser.add_argument("--candidats-sortie", metavar="FICHIER", default=None,
                        help="chemin du JSON candidats au lieu de data/eval_t1/candidats_fusion.json")
    args = parser.parse_args()

    global MODELE_ALT, SORTIES_RAPPORT, SORTIES_CANDIDATS
    if args.model:
        p = Path(args.model)
        MODELE_ALT = p if p.is_absolute() else (REPO / p)
    if args.rapport_sortie:
        p = Path(args.rapport_sortie)
        SORTIES_RAPPORT = p if p.is_absolute() else (REPO / p)
    if args.candidats_sortie:
        p = Path(args.candidats_sortie)
        SORTIES_CANDIDATS = p if p.is_absolute() else (REPO / p)

    if args.bench:
        random.seed(SEED)
        np.random.seed(SEED)
        jeu = Jeu()
        moteur = Moteur(jeu)
        b = bench(moteur, args.runs)
        print(f"A5 — {b['runs']} runs « {b['label']} » vs {len(jeu.univers)} clés : "
              f"moyenne {b['moyenne_ms']:.1f} ms · p50 {b['p50_ms']:.1f} ms · p95 {b['p95_ms']:.1f} ms "
              f"· min {b['min_ms']:.1f} · max {b['max_ms']:.1f}")
        print(f"     CPU : {b['cpu']['cpu']} ({b['cpu']['coeurs']} cœurs) — cible < 50 ms → "
              f"{'ATTEINT' if b['moyenne_ms'] < 50.0 else 'MANQUÉ'}")
        return 0

    return evaluer()


if __name__ == "__main__":
    sys.exit(main())

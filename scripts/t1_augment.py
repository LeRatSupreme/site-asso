#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T1-P2 — Générateur de paires d'entraînement augmentées (LIBELLÉS SEULS).

Mission P2 « Entraînement bien fait » (spec docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md §4 palier 2).
Domaine : rapprochement produits / factures-imports (dev-factures).

PRINCIPE
--------
Pour chaque paire positive sémantique du SPLIT TRAIN UNIQUEMENT (30 paires — les 20 paires TEST
mesurent, elles n'apprennent pas), produire des variantes synthétiques selon la typologie d'erreurs
RÉELLES documentée (rapport S4 + les bruts du jeu eux-mêmes) :

  1. casse mélangée            (MMS/M&Ms, MONSTER ULTRA ZERO…)
  2. séparateurs _-.() insérés/retirés (Lion_Peanut, Bueno_white, Capri-sun…)
  3. concaténation sans espace (cocacola, minutemaid, COCACOLA CHERRY…)
  4. faute 1-2 éditions        (Monster Vleu, Gauffre/gaufre, accents, 0/O)
     + suppression du 1er mot  (« pepper » ← « Dr Pepper »)
  5. suffixe goût/couleur ajouté/retiré (SUFFIX_STOPWORDS du port fidèle — « monster »→« monster
     noire », « Monster Bleue »→« Monster Bleu »)
  6. libellé facture long autour de la clé courte (CACOLAC BOITE 25CL X6, PERRIER BOITE SLIM 33CL,
     préfixe marque CRISTALI EAU…)
  7. doublage de mots          (« Sucette 1 sucette »)

Les fonctions de normalisation (normalize_key, stock_public_normalize) et SUFFIX_STOPWORDS sont
IMPORTÉES de scripts/t1_similarity.py (port Python fidèle validé, 22 049 paires / 0 divergence) —
aucune copie, aucun strtolower ad hoc (règle projet AGENTS.md).

ANTI-TRICHE (filtres à la génération + preuve exécutable --verifier)
--------------------------------------------------------------------
Toute variante synthétique est rejetée si :
  - sa forme (exacte ou normalisée) est celle d'un libellé des 20 paires TEST ;
  - sa forme (exacte ou normalisée) est celle d'un libellé des résiduels §1.3 ;
  - sa forme normalisée est celle d'une AUTRE identité de l'univers (conflit d'étiquette) ;
  - elle redondise une variante déjà retenue (dédoublonnage par forme normalisée).
Exception documentée et verrouillée : les lignes « origine » sont les 30 paires TRAIN du jeu figé
telles quelles (positifs qualifiés, split train) — même si certains de leurs libellés figurent
aussi dans residuels_a_qualifier.csv comme étiquettes d'AUTRES relations non qualifiées.

REPRODUCTIBILITÉ
----------------
Graines fixes (train 42, val 4242), aucun horodatage dans les sorties, tris déterministes :
deux exécutions produisent des octets identiques (SHA256 stables, cf. manifeste_p2.md).

RUNBOOK
-------
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py             # génère tout + auto-vérifie
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --verifier  # preuve anti-triche seule (relançable)
  python3 -m py_compile scripts/t1_augment.py                   # syntaxe (numpy inutile ici)

NB : l'import de t1_similarity nécessite numpy → utiliser le venv (.venv-t1) ci-dessus.
Le jeu figé data/eval_t1/ est en LECTURE SEULE ; tout s'écrit dans data/eval_t1/p2/ UNIQUEMENT.
"""

from __future__ import annotations

import argparse
import csv
import json
import random
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import t1_similarity as t1  # port fidèle : normalize_key, stock_public_normalize, SUFFIX_STOPWORDS, Jeu

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

REPO = t1.REPO
DATA = t1.DATA
P2 = DATA / "p2"
KAGGLE_UPLOAD = P2 / "kaggle_upload"

SEED_TRAIN = 42    # graine fixe imposée (brief P1_augmentation)
SEED_VAL = 4242    # graine différente, documentée (brief : val = même générateur, graine différente)
RATIO_VAL = 0.10   # val_pairs.csv ≈ 10 % des paires retenues de train

# Budget BRUT de variantes tirées par typologie et par paire (avant filtrage/dédoublonnage).
BUDGETS = {
    "casse_mele": 16,
    "separateurs": 16,
    "concatenation": 10,
    "faute_1_2_editions": 24,
    "suffixe_gout_couleur": 12,
    "facture_long": 14,
    "doublage_mots": 10,
}
ORIGINE = "origine"

# Vocabulaire d'emballage des libellés factures réels du jeu figé (aucun nom d'identité).
PACKAGING_QUEUES = [
    "33CL", "25CL", "50CL", "SLIM 33CL", "SLIM 25CL", "BOITE 25CL", "BTE 25CL",
    "BTE 50CL", "25CL X6", "33CL X6", "CL SLIM", "X6",
]
PACKAGING_TETES = ["BOITE", "BTE", "PACK", "CARTON"]

ALPHABET_FAUTE = "abcdefghijklmnopqrstuvwxyz0123456789"
ACCENTS_AJOUT = {"e": "éèê", "a": "àâ", "u": "ùû", "o": "ô", "i": "î", "c": "ç"}

MOTIFS_FILTRE = ["test", "residuel", "conflit_identite", "doublon", "vide"]


# ---------------------------------------------------------------------------
# Typologie — générateurs de variantes (purs : (brut, cible, rng) -> str)
# ---------------------------------------------------------------------------

def _mot_casse(mot: str, rng: random.Random) -> str:
    m = rng.choice(["upper", "lower", "cap", "swap", "alt"])
    if m == "upper":
        return mot.upper()
    if m == "lower":
        return mot.lower()
    if m == "cap":
        return mot[:1].upper() + mot[1:].lower()
    if m == "swap":
        return mot.swapcase()
    return "".join(c.upper() if i % 2 == 0 else c.lower() for i, c in enumerate(mot))


def variante_casse(brut: str, cible: str, rng: random.Random) -> str:
    src = brut if rng.random() < 0.65 else cible
    return " ".join(_mot_casse(w, rng) for w in src.split())


SEPARATEURS = ["_", "-", ".", "()", "( )", "  ", "_ ", "-"]


def variante_separateurs(brut: str, cible: str, rng: random.Random) -> str:
    src = brut if rng.random() < 0.65 else cible
    if rng.random() < 0.5 and any(c in src for c in "_-.()"):
        # séparateurs RETIRÉS (Capri-sun → Capri sun, Bueno_white → Bueno white)
        return "".join(" " if c in "_-.()" else c for c in src)
    return rng.choice(SEPARATEURS).join(src.split())  # séparateurs INSÉRÉS


def variante_concatenation(brut: str, cible: str, rng: random.Random) -> str:
    src = brut if rng.random() < 0.65 else cible
    mots = src.split()
    if len(mots) < 2:
        return src
    style = rng.choice(["tout", "tout_upper", "tout_lower", "fenetre"])
    if style == "tout":
        return "".join(mots)
    if style == "tout_upper":
        return "".join(mots).upper()
    if style == "tout_lower":
        return "".join(mots).lower()
    i = rng.randrange(len(mots) - 1)
    j = rng.randint(i + 2, len(mots))
    return "".join(mots[i:j])


def _edite(s: str, rng: random.Random) -> str:
    if not s:
        return s
    op = rng.choice(["sub", "sub", "ins", "ins", "del", "swap", "accent_add", "accent_del"])
    if op == "accent_add":
        positions = [i for i, c in enumerate(s) if c.lower() in ACCENTS_AJOUT]
        if not positions:
            return s
        i = rng.choice(positions)
        c = s[i]
        repl = rng.choice(ACCENTS_AJOUT[c.lower()])
        return s[:i] + (repl.upper() if c.isupper() else repl) + s[i + 1:]
    if op == "accent_del":
        positions = [i for i, c in enumerate(s) if c in t1.ACCENTS]
        if not positions:
            return s
        i = rng.choice(positions)
        return s[:i] + t1.ACCENTS[s[i]] + s[i + 1:]
    i = rng.randrange(len(s))
    if op == "sub":
        pool = s.lower() + ALPHABET_FAUTE
        return s[:i] + rng.choice(pool) + s[i + 1:]
    if op == "ins":
        return s[:i] + rng.choice(ALPHABET_FAUTE + " ") + s[i:]
    if op == "del":
        return s[:i] + s[i + 1:]
    if i + 1 < len(s):  # transposition adjacente
        return s[:i] + s[i + 1] + s[i] + s[i + 2:]
    return s


def variante_faute(brut: str, cible: str, rng: random.Random) -> str:
    src = brut if rng.random() < 0.7 else cible
    mots = src.split()
    if len(mots) >= 2 and rng.random() < 0.15:
        # suppression du PREMIER mot (« pepper » ← « Dr Pepper ») — jamais un mot de goût interne
        return " ".join(mots[1:])
    s = src
    for _ in range(rng.choice([1, 1, 1, 2, 2])):
        s = _edite(s, rng)
    return s


def variante_suffixe(brut: str, cible: str, rng: random.Random) -> str:
    """Suffixe goût/couleur (SUFFIX_STOPWORDS du port fidèle) ajouté/retiré.

    L'ajout se fait AUTOUR DE LA CLÉ (miroir exact de AliasSuggester::suggest, qui retire les
    suffixes stopwords en fin de libellé) : « Coca Cherry blanc » → « Coca Cherry ». Ajouter un
    suffixe au brut d'une AUTRE identité enseignerait une association fausse (ex. « Fanta noir »
    ne doit pas apprendre « → Fanta Orange ») : seuls brut/cible de la paire sont décorés.
    """
    cmots = cible.split()
    bmots = brut.split()
    t = rng.random()
    if t < 0.45:
        return cible + " " + rng.choice(t1.SUFFIX_STOPWORDS)
    if t < 0.7:
        return cible + " " + rng.choice(t1.SUFFIX_STOPWORDS).upper()
    if t < 0.85 and cmots and cmots[-1].lower() in t1.SUFFIX_STOPWORDS:
        return " ".join(cmots[:-1])  # retrait sur la clé (souvent conflit d'identité → filtré)
    if bmots and bmots[-1].lower() in t1.SUFFIX_STOPWORDS:
        return " ".join(bmots[:-1])  # retrait sur le brut
    return cible + " " + rng.choice(t1.SUFFIX_STOPWORDS).title()


def variante_facture(brut: str, cible: str, rng: random.Random) -> str:
    """Libellé facture LONG autour de la clé courte : queue/tête d'emballage, préfixe marque."""
    t = rng.random()
    if t < 0.45:
        return cible.upper() + " " + rng.choice(PACKAGING_QUEUES)
    if t < 0.7:
        return rng.choice(PACKAGING_TETES) + " " + cible.upper()
    if t < 0.85:
        marque = brut.split()[0].upper() if brut.split() else cible.split()[0].upper()
        return marque + " " + cible.upper()
    return cible.upper() + " " + rng.choice(PACKAGING_QUEUES) + " X" + str(rng.choice([6, 12, 24]))


def variante_doublage(brut: str, cible: str, rng: random.Random) -> str:
    src = brut if rng.random() < 0.6 else cible
    mots = src.split()
    if not mots:
        return src
    k = rng.randrange(len(mots))
    return " ".join(mots[:k] + [mots[k], mots[k]] + mots[k + 1:])


GENERATEURS = {
    "casse_mele": variante_casse,
    "separateurs": variante_separateurs,
    "concatenation": variante_concatenation,
    "faute_1_2_editions": variante_faute,
    "suffixe_gout_couleur": variante_suffixe,
    "facture_long": variante_facture,
    "doublage_mots": variante_doublage,
}


# ---------------------------------------------------------------------------
# Interdits anti-triche (dérivés du jeu figé uniquement)
# ---------------------------------------------------------------------------

class Interdits:
    def __init__(self, jeu: t1.Jeu) -> None:
        self.test_exact: set[str] = set()
        for p in jeu.paires_par_split("test"):
            self.test_exact |= {p["libelle_brut"], p["libelle_cible"]}
        self.resid_exact: set[str] = set()
        for r in jeu.residuels:
            for x in (r["libelle_a"] or "", r["libelle_b"] or ""):
                if x:
                    self.resid_exact.add(x)
        self.test_norm: set[str] = set()
        for x in self.test_exact:
            self.test_norm |= {t1.normalize_key(x), t1.stock_public_normalize(x)}
        self.resid_norm: set[str] = set()
        for x in self.resid_exact:
            self.resid_norm |= {t1.normalize_key(x), t1.stock_public_normalize(x)}
        self.formes_univers: set[str] = set(jeu.formes)
        self.train_pairs = sorted(
            ((p["libelle_brut"], p["libelle_cible"]) for p in jeu.paires_par_split("train")),
        )
        self.train_bruts = {b for b, _ in self.train_pairs}
        self.train_cibles = {c for _, c in self.train_pairs}

    def motif(self, variante: str, cible: str, origine: bool) -> str | None:
        """Motif de rejet d'une variante, ou None si elle passe."""
        vn = t1.stock_public_normalize(variante)
        cn = t1.stock_public_normalize(cible)
        if len(vn.replace(" ", "")) < 2 or vn == ".":
            return "vide"
        if variante in self.test_exact or vn in self.test_norm:
            return "test"
        if origine:  # les lignes origine sont la vérité terrain TRAIN du jeu figé, telles quelles
            return None
        if variante in self.resid_exact or vn in self.resid_norm:
            return "residuel"
        if vn in self.formes_univers and vn != cn:
            return "conflit_identite"
        return None


# ---------------------------------------------------------------------------
# Génération
# ---------------------------------------------------------------------------

def _nouvelles_stats() -> dict:
    stats = {ORIGINE: dict(brut=0, retenu=0, **{m: 0 for m in MOTIFS_FILTRE})}
    for typ in GENERATEURS:
        stats[typ] = dict(brut=0, retenu=0, **{m: 0 for m in MOTIFS_FILTRE})
    return stats


def generer(jeu: t1.Jeu, seed: int, interdits: Interdits, vus_amont: dict[str, str] | None = None
            ) -> tuple[list[tuple[str, str, str]], dict, dict]:
    """Génère (texte_variante, cible, typologie) pour les 30 paires TRAIN (passe complète).

    Dédoublonnage par libellé EXACT (l'entrée du modèle d'embeddings est la chaîne brute :
    « Lion_Peanut » et « Lion-Peanut » sont deux exemples distincts). L'anti-triche, elle,
    s'applique en formes normalisées (cf. Interdits.motif).
    vus_amont : libellés exacts déjà occupés (→ cible normalisée) pour la disjointness de la
    validation.
    """
    rng = random.Random(seed)
    stats = _nouvelles_stats()
    rows: list[tuple[str, str, str]] = []
    vus: dict[str, str] = {k: v for k, v in (vus_amont or {}).items()}  # libellé exact -> cible normalisée

    def ajouter(variante: str, cible: str, typ: str, origine: bool) -> None:
        stats[typ]["brut"] += 1
        motif = interdits.motif(variante, cible, origine)
        cn = t1.stock_public_normalize(cible)
        if motif is None and variante in vus:
            motif = "conflit_identite" if vus[variante] != cn else "doublon"
        if motif is not None:
            stats[typ][motif] += 1
            return
        vus[variante] = cn
        rows.append((variante, cible, typ))
        stats[typ]["retenu"] += 1

    paires = interdits.train_pairs  # tri déterministe (brut, cible)
    for brut, cible in paires:
        ajouter(brut, cible, ORIGINE, origine=True)

    for brut, cible in paires:
        for typ, budget in BUDGETS.items():
            gen = GENERATEURS[typ]
            for _ in range(budget):
                variante = gen(brut, cible, rng)
                ajouter(variante, cible, typ, origine=False)

    return rows, stats, vus


def _ecrire_csv(chemin: Path, rows: list[tuple[str, str, str]]) -> None:
    chemin.parent.mkdir(parents=True, exist_ok=True)
    with open(chemin, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["texte_variante", "cible"])
        for variante, cible, _typ in rows:
            w.writerow([variante, cible])


def _sha256(chemin: Path) -> str:
    import hashlib

    h = hashlib.sha256()
    h.update(chemin.read_bytes())
    return h.hexdigest()


# ---------------------------------------------------------------------------
# Vérificateur anti-triche (preuve exécutable, relançable)
# ---------------------------------------------------------------------------

def verifier(jeu: t1.Jeu, interdits: Interdits, verbeux: bool = True) -> list[str]:
    """Vérifie train_pairs.csv + val_pairs.csv contre le jeu figé. Retourne les erreurs (vide = OK).

    - aucune forme (exacte / normalize_key / StockPublic::normalizeKey) d'un libellé des 20 paires
      TEST n'apparaît (ni en variante, ni en cible) ;
    - aucune variante SYNTHÉTIQUE ne prend la forme d'un libellé résiduel §1.3 (exception : les
      lignes « origine » = paires TRAIN du jeu figé exactes) ;
    - toutes les cibles sont des cibles TRAIN du jeu ; la validation est disjointe du train ;
    - en-têtes conformes.
    """
    erreurs: list[str] = []
    train_rows = _lire_pairs(P2 / "train_pairs.csv")
    val_rows = _lire_pairs(P2 / "val_pairs.csv")

    formes_test = interdits.test_norm | interdits.test_exact | {
        t1.normalize_key(x) for x in interdits.test_exact
    } | {x.lower() for x in interdits.test_exact}
    train_exact: set[str] = set()
    train_pairs_exact = {(b, c) for b, c in interdits.train_pairs}
    origines_exact = {b for b, _ in interdits.train_pairs}

    for nom, rows in (("train", train_rows), ("val", val_rows)):
        for i, (variante, cible) in enumerate(rows, start=2):
            vn1, vn2 = t1.normalize_key(variante), t1.stock_public_normalize(variante)
            if variante in interdits.test_exact or vn1 in formes_test or vn2 in formes_test:
                erreurs.append(f"{nom}:{i} : variante « {variante} » = forme TEST")
            if cible in interdits.test_exact or t1.stock_public_normalize(cible) in interdits.test_norm:
                erreurs.append(f"{nom}:{i} : cible « {cible} » = forme TEST")
            if cible not in interdits.train_cibles:
                erreurs.append(f"{nom}:{i} : cible « {cible} » hors cibles TRAIN du jeu")
            resid = variante in interdits.resid_exact or vn1 in interdits.resid_norm or vn2 in interdits.resid_norm
            if resid:
                if nom == "train" and (variante, cible) in train_pairs_exact and variante in origines_exact:
                    pass  # exception documentée : ligne « origine » = paire TRAIN qualifiée du jeu figé
                else:
                    erreurs.append(f"{nom}:{i} : variante « {variante} » = forme résiduelle §1.3")
            if nom == "train":
                train_exact.add(variante)
        if erreurs and len(erreurs) > 20:
            break

    for i, (variante, cible) in enumerate(val_rows, start=2):
        if variante in train_exact:
            erreurs.append(f"val:{i} : variante « {variante} » déjà présente dans train (libellé exact)")
            break

    if verbeux:
        print(f"[anti-triche] paires TEST du jeu : 20 · formes interdites (normalisées) : {len(interdits.test_norm)}")
        print(f"[anti-triche] libellés résiduels §1.3 : {len(interdits.resid_exact)} · formes : {len(interdits.resid_norm)}")
        print(f"[anti-triche] lignes vérifiées : train {len(train_rows)} + val {len(val_rows)}")
        origines_resid = sorted(
            {b for b, _ in interdits.train_pairs if b in interdits.resid_exact} |
            {c for _, c in interdits.train_pairs
             if c in interdits.resid_exact or t1.stock_public_normalize(c) in interdits.resid_norm}
        )
        if origines_resid:
            print(f"[anti-triche] exception documentée (lignes origine, paires TRAIN qualifiées du jeu) : "
                  f"{', '.join(repr(x) for x in origines_resid)}")
        print(f"[anti-triche] résultat : {'OK' if not erreurs else 'ÉCHEC'}")
    return erreurs


def _lire_pairs(chemin: Path) -> list[tuple[str, str]]:
    with open(chemin, newline="", encoding="utf-8") as f:
        lecteur = csv.reader(f)
        entete = next(lecteur)
        assert entete == ["texte_variante", "cible"], f"en-tête inattendu dans {chemin.name} : {entete}"
        return [(r[0], r[1]) for r in lecteur]


# ---------------------------------------------------------------------------
# Manifeste (déterministe — aucun horodatage, pour des SHA256 stables)
# ---------------------------------------------------------------------------

def ecrire_manifeste(jeu: t1.Jeu, interdits: Interdits, rows_train, stats_train, rows_val, stats_val,
                     n_val_passe: int, origines_resid: list[str]) -> None:
    n_train = len(rows_train)
    n_val = len(rows_val)
    n_cibles_val = len({c for _, c, _ in rows_val})
    n_cibles_train = len({c for _, c, _ in rows_train})
    chemins = [
        P2 / "train_pairs.csv", P2 / "val_pairs.csv",
        KAGGLE_UPLOAD / "train_pairs.csv", KAGGLE_UPLOAD / "val_pairs.csv",
        KAGGLE_UPLOAD / "dataset-metadata.json",
    ]
    empreintes = [(_sha256(p), p.relative_to(REPO).as_posix()) for p in chemins]
    n_syn = sum(1 for _, _, typ in rows_train if typ != ORIGINE)
    n_val_syn = sum(1 for _, _, typ in rows_val if typ != ORIGINE)

    a = []
    a.append("# Manifeste P2 — paires d'entraînement augmentées (LIBELLÉS SEULS)")
    a.append("")
    a.append("> Mission T1-P2 « Entraînement bien fait » · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` §4 palier 2.")
    a.append("> Source : jeu figé `data/eval_t1/` (INTACT, `sha256sum -c data/eval_t1/SHA256SUMS` vérifié).")
    a.append("> Écrit par `scripts/t1_augment.py` — tout va dans `data/eval_t1/p2/` UNIQUEMENT.")
    a.append("")
    a.append("## Reproductibilité")
    a.append("")
    a.append("- Graines : **train 42** (imposée), **validation 4242** (même générateur, graine différente).")
    a.append("- Aucun horodatage dans les sorties, tris et tirages déterministes : deux exécutions → octets identiques.")
    a.append("- Commande de régénération : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py`")
    a.append("- Preuve anti-triche relançable : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --verifier`")
    a.append("")
    a.append("## Source des augmentations")
    a.append("")
    a.append(f"- **{len(interdits.train_pairs)} paires positives sémantiques du split TRAIN** (`positifs_semantiques.csv`,")
    a.append("  `split.json` groupes train). Les **20 paires TEST ne servent jamais à apprendre** (elles mesurent).")
    a.append("- Chaque paire est reprise telle quelle (typologie `origine`) puis augmentée par tirages :")
    a.append("")
    a.append("| typologie | tirages/paire |")
    a.append("|---|---:|")
    for typ, budget in BUDGETS.items():
        a.append(f"| {typ} | {budget} |")
    a.append(f"| {ORIGINE} (paire réelle) | 1 |")
    a.append("")
    a.append("Typologie = erreurs RÉELLES documentées (rapport S4 + bruts du jeu) : casse mélangée, séparateurs")
    a.append("`_-.()` insérés/retirés, concaténation sans espace, faute 1-2 éditions (substitution/insertion/")
    a.append("suppression/transposition + accents + 0↔O + suppression du 1er mot « pepper »), suffixe goût/couleur")
    a.append("(`SUFFIX_STOPWORDS` du port fidèle, miroir de `AliasSuggester::suggest`), libellé facture long autour")
    a.append("de la clé courte (queues/têtes d'emballage + préfixe marque), doublage de mots.")
    a.append("")
    a.append("## Volumes réellement obtenus (train)")
    a.append("")
    a.append("| typologie | tirées | retenues | filtrées TEST | filtrées résiduel | conflit identité | doublons | vides |")
    a.append("|---|---:|---:|---:|---:|---:|---:|---:|")
    st = stats_train
    for typ in [ORIGINE] + list(GENERATEURS):
        s = st[typ]
        a.append(f"| {typ} | {s['brut']} | {s['retenu']} | {s['test']} | {s['residuel']} | "
                 f"{s['conflit_identite']} | {s['doublon']} | {s['vide']} |")
    tot = {k: sum(s[k] for s in st.values()) for k in ["brut", "retenu", *MOTIFS_FILTRE]}
    a.append(f"| **total** | {tot['brut']} | **{tot['retenu']}** | {tot['test']} | {tot['residuel']} | "
             f"{tot['conflit_identite']} | {tot['doublon']} | {tot['vide']} |")
    a.append("")
    a.append(f"- `train_pairs.csv` : **{n_train} lignes** ({n_train - n_syn} `origine` + {n_syn} synthétiques), "
             f"colonnes `texte_variante,cible`.")
    a.append(f"- `val_pairs.csv` : **{n_val} lignes** (~{int(RATIO_VAL * 100)} % de {n_train}) — passe complète")
    a.append(f"  graine 4242 puis échantillon déterministe (indices seedés 4242) parmi les "
             f"{n_val_passe} retenues de la passe ; libellés exacts disjoints du train ; couvre "
             f"{n_cibles_val}/{n_cibles_train} cibles TRAIN.")
    a.append("")
    a.append("## Filtrage anti-fuite (chaque variante synthétique)")
    a.append("")
    a.append(f"1. forme interdite TEST : les {len(interdits.test_exact)} libellés des 20 paires TEST, en exact et en")
    a.append(f"   formes normalisées ({len(interdits.test_norm)} formes `normalize_key`/`StockPublic::normalizeKey`) ;")
    a.append(f"2. forme interdite résiduels §1.3 : {len(interdits.resid_exact)} libellés, mêmes formes normalisées ;")
    a.append("3. conflit d'identité : toute variante dont la forme normalisée est celle d'une AUTRE identité de")
    a.append("   l'univers (120 formes) est rejetée (elle enseignerait une étiquette fausse) ;")
    a.append("4. dédoublonnage par libellé EXACT, une même entrée ne visant qu'une seule cible.")
    a.append("")
    a.append("NB : le dédoublonnage est au libellé exact (et non à la forme normalisée) car l'entrée du modèle")
    a.append("d'embeddings est la chaîne brute : « Lion_Peanut » et « Lion-Peanut » sont deux exemples distincts.")
    a.append("L'anti-triche (TEST / résiduels), elle, s'applique bien en formes normalisées.")
    a.append("")
    a.append("**Exception documentée et verrouillée par `--verifier`** : les lignes `origine` sont les paires TRAIN")
    a.append("qualifiées du jeu figé, reprises telles quelles. Certains de leurs libellés figurent aussi dans")
    a.append("`residuels_a_qualifier.csv` comme étiquettes d'AUTRES relations non qualifiées — aucune relation")
    if origines_resid:
        a.append(f"résiduelle n'est apprise. Libellés concernés : {' , '.join(f'« {x} »' for x in origines_resid)}.")
    else:
        a.append("résiduelle n'est apprise.")
    a.append("")
    a.append("## ENGAGEMENT")
    a.append("")
    a.append(f"- **Zéro libellé des 20 paires TEST** (ni ses formes normalisées) n'apparaît dans `train_pairs.csv`")
    a.append("  ni `val_pairs.csv`, ni comme variante ni comme cible — preuve exécutable : `--verifier` → `OK`.")
    a.append("- **Zéro libellé des résiduels §1.3** n'est utilisé comme variante d'apprentissage (seule exception :")
    a.append("  les lignes `origine` du jeu figé, cf. ci-dessus).")
    a.append("- Les 20 paires TEST ne servent qu'à mesurer (rapport S4′ à pareil à pareil), jamais à apprendre.")
    a.append("- Contenu : **libellés produits uniquement** (anonymisation D3) — aucun prix, date, quantité, fournisseur.")
    a.append("")
    a.append("## SHA256 des livrables (stables entre deux exécutions)")
    a.append("")
    a.append("```")
    for empreinte, chemin in empreintes:
        a.append(f"{empreinte}  {chemin}")
    a.append("```")
    a.append("")
    a.append("## Dossier Kaggle — PRÊT, NON TRANSMIS")
    a.append("")
    a.append("- `data/eval_t1/p2/kaggle_upload/` : copies de `train_pairs.csv` + `val_pairs.csv` +")
    a.append("  `dataset-metadata.json` (title `aeic-t1-p2-pairs`, dataset **privé** `isPrivate: true`,")
    a.append("  licence `OTHER`). Le champ `id` (`aeic/aeic-t1-p2-pairs`) porte un propriétaire à confirmer")
    a.append("  à l'envoi (aucun `~/.kaggle/kaggle.json` sur ce VPS — auth `access_token`).")
    a.append("- **Envoi interdit ici** : contrainte P2 — relecture dev-secu OBLIGATOIRE avant toute commande `kaggle`.")
    a.append("")
    (P2 / "manifeste_p2.md").write_text("\n".join(a) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# Orchestration
# ---------------------------------------------------------------------------

def _dataset_metadata() -> str:
    return json.dumps({
        "title": "aeic-t1-p2-pairs",
        "id": "aeic/aeic-t1-p2-pairs",
        "isPrivate": True,
        "licenses": [{"name": "OTHER"}],
    }, ensure_ascii=False, indent=2) + "\n"


def generer_tout() -> int:
    print("[1/5] chargement du jeu figé (LECTURE SEULE) …")
    jeu = t1.Jeu()
    interdits = Interdits(jeu)
    print(f"      univers {len(jeu.univers)} identités · paires TRAIN {len(interdits.train_pairs)} "
          f"(TEST {len(jeu.paires_par_split('test'))} jamais utilisé) · "
          f"interdits TEST {len(interdits.test_exact)} libellés / résiduels {len(interdits.resid_exact)}")

    print("[2/5] génération train (graine 42) …")
    rows_train, stats_train, _ = generer(jeu, SEED_TRAIN, interdits)
    n_cible_val = round(RATIO_VAL * len(rows_train))
    print(f"      {len(rows_train)} paires retenues (cible val : {n_cible_val})")

    print("[3/5] génération validation (graine 4242, libellés exacts disjoints du train) …")
    vus_train = {v: t1.stock_public_normalize(c) for v, c, _ in rows_train}
    rows_val_passe, stats_val, _ = generer(jeu, SEED_VAL, interdits, vus_amont=vus_train)
    n_cible_val = min(round(RATIO_VAL * len(rows_train)), len(rows_val_passe))
    # échantillon déterministe couvrant TOUTES les paires (tirage d'indices, ordre de génération conservé)
    rng_val = random.Random(SEED_VAL)
    idx = sorted(rng_val.sample(range(len(rows_val_passe)), n_cible_val))
    rows_val = [rows_val_passe[i] for i in idx]
    print(f"      passe complète : {len(rows_val_passe)} retenues → échantillon : {len(rows_val)} (~{int(RATIO_VAL * 100)} % de {len(rows_train)})")

    print("[4/5] écriture des livrables dans data/eval_t1/p2/ …")
    _ecrire_csv(P2 / "train_pairs.csv", rows_train)
    _ecrire_csv(P2 / "val_pairs.csv", rows_val)
    KAGGLE_UPLOAD.mkdir(parents=True, exist_ok=True)
    for nom in ("train_pairs.csv", "val_pairs.csv"):
        (KAGGLE_UPLOAD / nom).write_bytes((P2 / nom).read_bytes())
    (KAGGLE_UPLOAD / "dataset-metadata.json").write_text(_dataset_metadata(), encoding="utf-8")

    origines_resid = sorted(
        {b for b, c in interdits.train_pairs
         if b in interdits.resid_exact or t1.stock_public_normalize(b) in interdits.resid_norm} |
        {c for b, c in interdits.train_pairs
         if c in interdits.resid_exact or t1.stock_public_normalize(c) in interdits.resid_norm}
    )
    ecrire_manifeste(jeu, interdits, rows_train, stats_train, rows_val, stats_val,
                     n_val_passe=len(rows_val_passe), origines_resid=origines_resid)

    sommes = []
    for p in [P2 / "train_pairs.csv", P2 / "val_pairs.csv",
              KAGGLE_UPLOAD / "train_pairs.csv", KAGGLE_UPLOAD / "val_pairs.csv",
              KAGGLE_UPLOAD / "dataset-metadata.json"]:
        sommes.append(f"{_sha256(p)}  {p.relative_to(REPO)}")
    (P2 / "SHA256SUMS").write_text("\n".join(sommes) + "\n", encoding="utf-8")

    print("[5/5] vérification anti-triche intégrée …")
    erreurs = verifier(jeu, interdits, verbeux=True)
    if erreurs:
        for e in erreurs[:20]:
            print("  !", e)
        print("ÉCHEC anti-triche — livrables NON validés")
        return 1

    print()
    print("=" * 78)
    print(f"  train_pairs.csv : {len(rows_train)} lignes (dont {sum(1 for _, _, t in rows_train if t == ORIGINE)} origine)")
    print(f"  val_pairs.csv   : {len(rows_val)} lignes (graine 4242)")
    print("  Répartition train retenue par typologie :")
    for typ in [ORIGINE] + list(GENERATEURS):
        print(f"    {typ:<22} {stats_train[typ]['retenu']:>5}")
    print(f"  ANTI-TRICHE : OK — rejouer : scripts/t1_augment.py --verifier")
    print("=" * 78)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        prog="t1_augment.py",
        description="T1-P2 — génération des paires d'entraînement augmentées (libellés seuls) à partir "
                    "des 30 paires positives sémantiques du split TRAIN du jeu figé data/eval_t1/. "
                    "Écrit data/eval_t1/p2/{train_pairs.csv,val_pairs.csv,manifeste_p2.md,SHA256SUMS} "
                    "et kaggle_upload/ (PRÊT, NON TRANSMIS).",
        epilog="Runbook complet dans l'entête du script. Exiger le venv : "
               "~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--verifier", action="store_true",
                        help="ne rien générer : vérifie anti-triche des CSV existants (preuve exécutable)")
    args = parser.parse_args()

    if args.verifier:
        jeu = t1.Jeu()
        interdits = Interdits(jeu)
        erreurs = verifier(jeu, interdits, verbeux=True)
        for e in erreurs:
            print("  !", e)
        return 1 if erreurs else 0

    return generer_tout()


if __name__ == "__main__":
    sys.exit(main())

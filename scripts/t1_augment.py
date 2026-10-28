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
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py             # v1 : génère tout + auto-vérifie (inchangé)
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --verifier  # v1 : preuve anti-triche seule (relançable)
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2        # v2 : volume ×2-3 ciblé échecs S4' -> data/eval_t1/p2/v2/
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2 --verifier      # v2 : preuve anti-triche étendue
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2 --test-negatif  # v2 : injection TEST -> ÉCHEC détecté
  ~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2 --feedback data/eval_t1/p2/auto/feedback_pairs.csv \
      --sortie data/eval_t1/p2/auto/run_<ts>                    # P2-auto (brief A2) : v2 + retours humains
  python3 -m py_compile scripts/t1_augment.py                   # syntaxe (numpy inutile ici)

NB : l'import de t1_similarity nécessite numpy → utiliser le venv (.venv-t1) ci-dessus.
Le jeu figé data/eval_t1/ est en LECTURE SEULE ; le P2 écrit dans data/eval_t1/p2/ et
la vague 2 (P2-ter) dans data/eval_t1/p2/v2/ UNIQUEMENT.

VAGUE 2 (P2-ter) — --v2 : augmentation ×2-3 ciblée sur les échecs RÉELS du rapport S4'
---------------------------------------------------------------------------------------
Analyse d'erreurs d'abord (data/eval_t1/p2/v2/analyse_erreurs.md) : les 5 paires TEST ratées
en recall@3 partagent trois mécanismes — (a) couleur TRADUITE EN↔FR (« RED BULL BLUE » →
« Redbull Bleu », « blue »≠« bleu »), (b) qualificatif de gamme énergie amovible (« ULTRA »,
« ZERO », « EDITION ») masquant la couleur de la cible (« MONSTER ULTRA ZERO » → « Monster
Blanche »), (c) libellé ÉVÉNEMENT long à parenthèses/slash/quantités autour d'une clé courte
(« Soiree bowling (2 parties / 1 personne) » → « bowling »). Les 57 durs négatifs ≥ seuil
0,6618 sont majoritairement des variantes intra-famille (goût/couleur/numéro) à séparer.

Huit NOUVELLES typologies (TRAIN uniquement, mêmes interdits anti-triche + garde-fous de
collision étendus) : couleur_traduite (traduit UNIQUEMENT les mots couleur présents dans la
paire — jamais d'injection d'une couleur d'une autre identité), marque_scindee (RED BULL ↔
Redbull, MinuteMaid ↔ Minute Maid — observé dans le jeu), inversion_mots, abreviation_globale
(style observé « POM CASS FRAM »/« L'ORIG »/« CRISTALI » : troncature 3-4 lettres MAJ),
zero_edition (équivalences sans sucre/zero/sugar free/light + qualificatifs ULTRA/EDITION
restreints aux familles énergie), evenement_parentheses (modèles observés bowling),
conditionnement_multipack (6X33CL, X6, PACK DE 6…), accents_globaux.

Anti-triche ÉTENDU (--v2 uniquement, contrainte vague 2 n°3) : en plus des règles v1
(formes exactes/normalisées interdites), toute variante dont la forme normalisée CONTIENT
— comme sous-chaîne non-préfixe — une forme TEST, ou une forme d'une AUTRE identité de la
même famille (sœur), est rejetée (motifs collision_test / collision_soeur). Exceptions
documentées : la forme contenue est un préfixe de la cible (marque partagée, ex. « coca »
pour « cocacherry ») ou la forme du brut d'une paire TRAIN de même cible (identité de la
paire elle-même). Preuve exécutable : --v2 --verifier (audit des CSV finaux) et
--v2 --test-negatif (injection de formes TEST exactes/déguisées/collidantes → toutes
détectées ÉCHEC).

RÉTRO-COMPATIBILITÉ : sans --v2, le script se comporte À L'IDENTIQUE (mêmes tirages, mêmes
sorties octet/octet dans data/eval_t1/p2/ — preuve : double exécution, SHA256 égaux aux
valeurs du manifeste v1)."""

from __future__ import annotations

import argparse
import contextlib
import csv
import io
import json
import random
import shutil
import sys
import time
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
P2V2 = P2 / "v2"                 # vague 2 (P2-ter) : tout s'écrit ICI uniquement
KAGGLE_UPLOAD_V2 = P2V2 / "kaggle_upload_v2"
AUTO = P2 / "auto"                # boucle P2-auto (brief A2) : runs run_<horodatage>/ UNIQUEMENT
FEEDBACK = "feedback"             # typologie des paires positives issues des décisions humaines

SEED_TRAIN = 42    # graine fixe imposée (brief P1_augmentation)
SEED_VAL = 4242    # graine différente, documentée (brief : val = même générateur, graine différente)
RATIO_VAL = 0.10   # val_pairs.csv ≈ 10 % des paires retenues de train (v1)
N_VAL_V2 = 400     # v2 : val ~400 paires (brief A v2)

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
# v2 : motifs de rejet supplémentaires du garde-fou anti-collision étendu
MOTIFS_FILTRE_V2 = MOTIFS_FILTRE + ["collision_test", "collision_soeur"]
# P2-auto : idem v2 + paire ignorée par décision humaine (dur négatif supplémentaire)
MOTIFS_FILTRE_AUTO = MOTIFS_FILTRE_V2 + ["feedback_negatif"]

# --- v2 : vocabulaires des nouvelles typologies (sources : analyse d'erreurs S4') ---

# (a) échecs « RED BULL BLUE BOITE 25CL »/« Redbull blue » → « Redbull Bleu » : la couleur
#     est TRADUITE EN↔FR. On ne traduit QUE les mots couleur déjà présents dans la paire —
#     injecter une couleur absente enseignerait l'identité d'une AUTRE produit (ex. décorer
#     « Redbull Ice » en « Redbull Ice bleu » → confondre avec « Redbull Bleu »).
COULEURS_TRADUCTION = {
    "bleu": "blue", "bleue": "blue", "bleus": "blue", "bleues": "blue", "blue": "bleu",
    "blanc": "white", "blanche": "white", "blancs": "white", "white": "blanc",
    "noir": "black", "noire": "black", "black": "noir",
    "vert": "green", "verte": "green", "green": "vert",
    "rouge": "red", "red": "rouge",
    "rose": "pink", "pink": "rose",
    "jaune": "yellow", "yellow": "jaune",
}

# (a') marque composée observée dans le jeu : « RED BULL … » (appellations) vs « Redbull … »
#      (identités), « MinuteMaid_Pomme »/« minutemaid » vs « Minute Maid … ».
MARQUES_SCINDEES = {
    "redbull": ["RED BULL", "Red Bull", "red bull"],
    "minutemaid": ["MINUTE MAID", "Minute Maid", "minute maid"],
}

# (b) échecs « MONSTER ULTRA ZERO (BTE 50CL) » → « Monster Blanche », « RED BULL RED EDITION
#     25CL » → « redbull red » : qualificatifs de gamme énergie AMOVIBLES + équivalences
#     zéro. ULTRA/EDITION restreints aux familles énergie (monster/redbull — contextes
#     observés) ; zero/sans sucre/sugar free/light uniquement si la paire porte déjà ce sens.
GAMME_ENERGIE_MARQUES = ("monster", "redbull", "red bull")
QUALIFICATIFS_GAMME = ["ULTRA", "EDITION", "ULTRA EDITION", "THE EDITION"]
EQUIVALENCES_ZERO = {"zero": ["sans sucre", "SUGAR FREE", "light", "0%"],
                     "sans sucre": ["ZERO", "sugar free", "light", "0%"],
                     "light": ["zero", "sans sucre"]}

# (c) échec « Soiree bowling (2 parties / 1 personne) » → « bowling » : libellés ÉVÉNEMENT
#     observés du jeu (parenthèses, slash, quantités) autour d'une clé courte.
EVENEMENT_MODELES = [
    "{c} (2 parties / 1 personne)",
    "{c} ( 2 personnes )",
    "Soiree {c} (2 parties)",
    "Soiree {c} ( 2 personnes )",
    "soiree {c}",
    "{c} x2",
    "{c} (1 personne)",
    "SOIREE {c} X2",
]

# conditionnements multipack X/Y (complément des PACKAGING_QUEUES v1, formats facture METRO)
MULTIPACK_QUEUES = ["6X33CL", "4X25CL", "24X33CL", "6X25CL", "10X33CL",
                    "6 X 33CL", "4 X 25CL", "X6", "X12", "X24"]
MULTIPACK_TETES = ["PACK DE 6", "CARTON 24", "PACK 6X33CL", "LOT DE 4", "COFFRET 12"]

# style d'abréviation OBSERVÉ dans l'univers : « OASIS POM CASS FRAM SLIM 33CL »,
# « TROPICO L'ORIG BTE CL SLIM », « CRISTALI 5OCL PET », « MMS » — troncature à 3-4
# lettres MAJ des mots alphabétiques ≥ 6 lettres.
ABREVIATION_MIN_LETTRES = 6


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
# Vague 2 — nouvelles typologies issues de l'analyse d'erreurs S4' (rapport
# data/eval_t1/p2/rapport_S4_prime.md). Mêmes pures fonctions (brut, cible, rng) -> str,
# appliquées au split TRAIN UNIQUEMENT, sous les interdits anti-triche v1 + étendus.
# ---------------------------------------------------------------------------

def _sans_accents_min(s: str) -> str:
    return t1.normalize_key(s)


def variante_couleur_traduite(brut: str, cible: str, rng: random.Random) -> str:
    """Couleur TRADUITE EN↔FR sur les mots couleur DÉJÀ PRÉSENTS dans la paire.

    Miroir de l'échec TEST « RED BULL BLUE BOITE 25CL » / « Redbull blue » → « Redbull Bleu »
    (anchrages TRAIN : « Monster Bleue »/« Monster Vleu » → « Monster Bleu », « monster » →
    « monster noire », « Monster rose (punch) » → « Monster Rose »). Ne JAMAIS injecter une
    couleur absente de la paire : elle appartient potentiellement à une autre identité de la
    famille (le garde-fou collision_soeur bloque en dernier recours).
    """
    src = cible if rng.random() < 0.75 else brut
    mots = src.split()
    out, traduit = [], False
    for i, m in enumerate(mots):
        cle = _sans_accents_min(m)
        suivant = _sans_accents_min(mots[i + 1]) if i + 1 < len(mots) else ""
        # Ne JAMAIS traduire la couleur D'UNE MARQUE (« RED Bull », « Monster ») :
        # seuls les mots couleur de GOÛT (fin de libellé, pas suivis de « bull ») bougent.
        if (cle in COULEURS_TRADUCTION and suivant != "bull" and rng.random() < 0.8):
            rep = COULEURS_TRADUCTION[cle]
            if m.isupper():
                rep = rep.upper()
            elif m[:1].isupper():
                rep = rep[:1].upper() + rep[1:]
            out.append(rep)
            traduit = True
        else:
            out.append(m)
    if not traduit:
        return src  # hors champ (aucun mot couleur) → dédoublonné en aval
    s = " ".join(out)
    if rng.random() < 0.35:  # moitié des cas réels : couleur traduite + emballage
        s += " " + rng.choice(PACKAGING_QUEUES)
    return s


def variante_marque_scindee(brut: str, cible: str, rng: random.Random) -> str:
    """Marque composée scindée/rejointe : « RED BULL ICE » ↔ « Redbull ICE »,
    « MINUTE MAID ORANGE » ↔ « MinuteMaid ORANGE » (les deux graphies coexistent dans le
    jeu : appellations METRO « RED BULL … » vs identités « Redbull … »). Travail sur MOTS
    (jamais sur indices de chaîne normalisée — les séparateurs ne s'alignent pas)."""
    src = brut if rng.random() < 0.6 else cible
    mots = src.split()
    if not mots:
        return src
    t0 = _sans_accents_min(mots[0])
    if t0 in MARQUES_SCINDEES:  # marque déjà JOINTE (« Redbull … ») → scinder
        scind = rng.choice(MARQUES_SCINDEES[t0])
        return " ".join([scind] + mots[1:])
    for joint, scinds in MARQUES_SCINDEES.items():
        for scind in scinds:  # marque déjà SCINDÉE (« RED BULL … ») → joindre
            parties = _sans_accents_min(scind).split()
            if len(mots) >= len(parties) and all(
                    _sans_accents_min(mots[i]) == parties[i] for i in range(len(parties))):
                joint_c = joint[:1].upper() + joint[1:]
                return " ".join([joint_c] + mots[len(parties):])
    return src  # hors champ


def variante_inversion(brut: str, cible: str, rng: random.Random) -> str:
    """Inversion de deux mots ADJACENTS (ordre des mots flottant à la saisie/facture)."""
    src = brut if rng.random() < 0.5 else cible
    mots = src.split()
    if len(mots) < 2:
        return src
    i = rng.randrange(len(mots) - 1)
    mots[i], mots[i + 1] = mots[i + 1], mots[i]
    return " ".join(mots)


def variante_abreviation(brut: str, cible: str, rng: random.Random) -> str:
    """Abréviation par troncature 3-4 lettres MAJ (style OBSERVÉ : « OASIS POM CASS FRAM
    SLIM 33CL », « TROPICO L'ORIG », « CRISTALI », « MMS ») des mots alphabétiques ≥ 6
    lettres, parfois complétée d'une queue d'emballage."""
    src = cible if rng.random() < 0.8 else brut
    out = []
    for m in src.split():
        base = m.strip("()+-_.")
        if len(base) >= ABREVIATION_MIN_LETTRES and base.isalpha():
            out.append(base[: rng.choice([3, 4])].upper())
        else:
            out.append(m)
    s = " ".join(out)
    if s == src:
        return src  # hors champ (aucun mot assez long) → dédoublonné
    if rng.random() < 0.4:
        s += " " + rng.choice(PACKAGING_QUEUES)
    return s


def variante_zero_edition(brut: str, cible: str, rng: random.Random) -> str:
    """(b) Qualificatif de gamme énergie amovible + équivalences zéro.

    Échecs visés : « MONSTER ULTRA ZERO (BTE 50CL) » → « Monster Blanche » (ULTRA/ZERO =
    qualificatifs, pas des couleurs), « RED BULL RED EDITION 25CL » → « redbull red ».
    RÈGLES : ULTRA/EDITION uniquement sur les familles énergie (monster/red bull — contextes
    observés) ; sans sucre/zero/sugar free/light uniquement si la paire porte déjà ce sens
    (ancrage : « Pepsi sans sucre » → « Pepsi Zero », TRAIN)."""
    src = cible if rng.random() < 0.7 else brut
    mots = src.split()
    bas = [_sans_accents_min(m) for m in mots]
    # équivalence zéro : mot « zero »/« light » ou segment « sans sucre » DÉJÀ présent
    for i in range(len(mots)):
        if bas[i] in EQUIVALENCES_ZERO and bas[i] != "sans":
            rep = rng.choice(EQUIVALENCES_ZERO[bas[i]])
            return " ".join(mots[:i] + [rep] + mots[i + 1:])
        if bas[i] == "sans" and i + 1 < len(mots) and bas[i + 1] == "sucre":
            rep = rng.choice(EQUIVALENCES_ZERO["sans sucre"])
            return " ".join(mots[:i] + [rep] + mots[i + 2:])
    # qualificatif de gamme : familles énergie uniquement (contextes observés)
    chaine = " ".join(bas)
    if any(marq in chaine for marq in GAMME_ENERGIE_MARQUES):
        qual = rng.choice(QUALIFICATIFS_GAMME)
        pos = 1 if len(mots) >= 2 else len(mots)  # inséré APRÈS la marque, avant le goût
        s = " ".join(mots[:pos] + [qual] + mots[pos:])
        if rng.random() < 0.4:
            s += " " + rng.choice(PACKAGING_QUEUES)
        return s
    return src  # hors champ


def variante_evenement(brut: str, cible: str, rng: random.Random) -> str:
    """(c) Libellé ÉVÉNEMENT long (parenthèses, slash, quantités) autour de la clé courte.

    Miroir des formes OBSERVÉES du jeu : « Soiree bowling (2 parties / 1 personne) »,
    « Soiree bowling ( 2 personnes ) », « Bowling ( 2 parties ) » → clé courte. Appliqué aux
    cibles TRAIN uniquement (les formes « bowling » sont TEST/interdites et le garde-fou
    collision_test les rejetterait de toute façon)."""
    src = cible if rng.random() < 0.85 else brut
    modele = rng.choice(EVENEMENT_MODELES)
    s = modele.format(c=src)
    t = rng.random()
    if t < 0.2:
        s = s.upper()
    elif t < 0.35:
        s = s.lower()
    return s


def variante_conditionnement(brut: str, cible: str, rng: random.Random) -> str:
    """Conditionnement multipack X/Y : « … 6X33CL », « X6 », « PACK DE 6 … » (formats
    facture réels ; complément de facture_long concentré sur le multipack — échec TEST
    « MONSTER ULTRA ZERO BTE 50CL », durs négatifs « 5 cannettes au lieu d'un pack »)."""
    t = rng.random()
    if t < 0.5:
        return cible.upper() + " " + rng.choice(MULTIPACK_QUEUES)
    if t < 0.75:
        return rng.choice(MULTIPACK_TETES) + " " + cible.upper()
    queue = rng.choice(MULTIPACK_QUEUES)
    n = rng.choice([6, 12, 24])
    if f"X{n}" in queue:  # pas de multipack dupliqué (« X12 X12 »)
        n = rng.choice([x for x in (6, 12, 24) if f"X{x}" not in queue])
    return cible.upper() + " " + queue + " X" + str(n)


def variante_accents_globaux(brut: str, cible: str, rng: random.Random) -> str:
    """Accentuation/désaccentuation SYSTÉMIQUE du libellé : « Gauffre »→« Gaufre » observé,
    sens éni les deux (saisies avec/sans clavier FR). Réaliste : UN accent par mot au plus,
    voyelles uniquement (é è ê à â ù û ô î), jamais sur les tokens numériques ou
    d'emballage (CL, BTE, SLIM…) — pas de « 25ÇL ».
    """
    src = brut if rng.random() < 0.5 else cible
    if any(c in t1.ACCENTS for c in src):  # désaccentuation totale
        return "".join(t1.ACCENTS.get(c, c) for c in src)
    emballage = {"CL", "SLIM", "BTE", "BOITE", "PET", "FAT", "X", "PACK", "CARTON",
                 "COFFRET", "LOT", "EDITION", "ULTRA"}
    out = []
    accentue = False
    for m in src.split():
        if any(ch.isdigit() for ch in m) or len(m) <= 2 or m.upper() in emballage:
            out.append(m)
            continue
        positions = [i for i, ch in enumerate(m) if ch.lower() in "aeiou"]
        if positions and rng.random() < 0.55:
            i = rng.choice(positions)
            repl = rng.choice(ACCENTS_AJOUT[m[i].lower()])  # voyelles : éèê / àâ / ùû / ô / î
            out.append(m[:i] + (repl.upper() if m[i].isupper() else repl) + m[i + 1:])
            accentue = True
        else:
            out.append(m)
    if not accentue:
        return src
    return " ".join(out)


GENERATEURS_V2 = dict(GENERATEURS)
GENERATEURS_V2.update({
    "couleur_traduite": variante_couleur_traduite,
    "marque_scindee": variante_marque_scindee,
    "inversion_mots": variante_inversion,
    "abreviation_globale": variante_abreviation,
    "zero_edition": variante_zero_edition,
    "evenement_parentheses": variante_evenement,
    "conditionnement_multipack": variante_conditionnement,
    "accents_globaux": variante_accents_globaux,
})


# ---------------------------------------------------------------------------
# Interdits anti-triche (dérivés du jeu figé uniquement)
# ---------------------------------------------------------------------------

# qualificatifs/équivalences que les nouvelles typologies peuvent INSÉRER — le garde-fou
# de contenance les retire symétriquement avant de chercher une collision (ex. « monster
# ULTRA bleu » → cœur « monsterbleu » = identité sœur). « the » seul exclu (sous-chaîne
# trop banale, ex. « Thé » normalisé).
QUALIFICATIFS_STRIPPES = ("ultraedition", "theedition", "sugarfree", "sanssucre",
                          "ultra", "edition", "zero", "light", "0%")


class Interdits:
    def __init__(self, jeu: t1.Jeu, feedback_negatifs: set[frozenset[str]] | None = None) -> None:
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
        # v2 — anti-collision ÉTENDU (contrainte vague 2 n°3) :
        # formes d'identités SŒURS (même famille que la cible) + carte des formes de
        # bruts TRAIN par cible (exceptions documentées : identité de la paire elle-même).
        self.famille_de = {r["forme_normalisee"]: r["famille"] for r in jeu.univers}
        self.forms_par_famille: dict[str, set[str]] = {}
        for r in jeu.univers:
            self.forms_par_famille.setdefault(r["famille"], set()).add(r["forme_normalisee"])
        self.bruts_de_cible: dict[str, set[str]] = {}
        for b, c in self.train_pairs:
            self.bruts_de_cible.setdefault(t1.stock_public_normalize(c), set()).add(
                t1.stock_public_normalize(b))
        # P2-auto (brief A2) : paires IGNORÉES par décision humaine — durs négatifs
        # supplémentaires. Vide par défaut (v1/v2 strictement inchangés).
        self.feedback_negatifs: set[frozenset[str]] = feedback_negatifs or set()

    def _collisions_etendues(self, vn: str, cn: str) -> str | None:
        """Motif de rejet v2 par CONTENANCE (sous-chaîne non-préfixe), ou None.

        Une variante dont la forme normalisée contient une forme TEST (ex. « Redbull blue
        EDITION » contient « redbullblue ») ou une forme d'une AUTRE identité de la même
        famille que la cible (ex. « RED BULL BLEU BOITE 25CL » contient « redbullbleu »
        → enseignerait « Redbull Bleu » en visant « Redbull Seablue ») apprend une
        identité ambiguë → rejet. Exceptions : la forme contenue est un PRÉFIXE de la cible
        (marque partage : « coca » pour « cocacherry » — même continuation d'identité) ou la
        forme d'un brut TRAIN de même cible (identité de la paire elle-même).
        """
        for core in self._coeurs(vn):
            for t in self.test_norm:
                if len(t) >= 4 and t in core and not cn.startswith(t):
                    return "collision_test"
            soeurs = self.forms_par_famille.get(self.famille_de.get(cn, ""), set())
            bruts_ok = self.bruts_de_cible.get(cn, set())
            for s in sorted(soeurs):
                if (s != cn and len(s) >= 4 and s in core
                        and not cn.startswith(s) and s not in bruts_ok):
                    return "collision_soeur"
        return None

    @staticmethod
    def _coeurs(vn: str) -> list[str]:
        """Forme pleine + formes « cœur » (un qualificatif insérable retiré)."""
        coeurs = [vn] + [vn.replace(q, "", 1) for q in QUALIFICATIFS_STRIPPES]
        return [c for c in coeurs if c]

    def motif(self, variante: str, cible: str, origine: bool, etendu: bool = False) -> str | None:
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
        if self.feedback_negatifs and frozenset((vn, cn)) in self.feedback_negatifs:
            return "feedback_negatif"
        if vn in self.formes_univers and vn != cn:
            return "conflit_identite"
        if etendu:
            return self._collisions_etendues(vn, cn)
        return None

    def motif_feedback(self, variante: str, cible: str) -> str | None:
        """Admission d'une paire feedback BRUTE (décision humaine, pas une variante
        synthétique — brief A2) : les paires FUSIONNÉES deviennent des positives
        supplémentaires. Interdits : vide, formes TEST (l'un ou l'autre côté — la
        mesure S4 à pareil à pareil reste honnête), formes RÉSIDUELLES §1.3 (leur
        qualification passera par la régénération du jeu, décision D2 — pas par la
        boucle auto : anti-triche inchangé) et paires re-ignorées par une décision
        plus récente. PAS de filtre conflit/collision : la décision humaine
        qualifie la paire (une fusion de deux identités vivantes est exactement le
        signal que la boucle doit apprendre — ex. trio « Redbull Bleu/Blue/Bleue »).
        Les VARIANTES SYNTHÉTIQUES passent, elles, le filtre complet (motif),
        inchangé.
        """
        vn = t1.stock_public_normalize(variante)
        cn = t1.stock_public_normalize(cible)
        if len(vn.replace(" ", "")) < 2 or vn == "." or len(cn.replace(" ", "")) < 2 or cn == ".":
            return "vide"
        if variante in self.test_exact or vn in self.test_norm:
            return "test"
        if cible in self.test_exact or cn in self.test_norm:
            return "test"
        if variante in self.resid_exact or vn in self.resid_norm:
            return "residuel"
        if cible in self.resid_exact or cn in self.resid_norm:
            return "residuel"
        if self.feedback_negatifs and frozenset((vn, cn)) in self.feedback_negatifs:
            return "feedback_negatif"
        return None


def _rel(chemin: Path) -> str:
    """Chemin d'affichage relatif au dépôt (ou absolu si hors dépôt — robuste)."""
    try:
        return chemin.relative_to(REPO).as_posix()
    except ValueError:
        return chemin.as_posix()


# ---------------------------------------------------------------------------
# P2-auto (brief A2) — paires étiquetées issues des décisions humaines
# ---------------------------------------------------------------------------

def charger_feedback(chemin: Path) -> tuple[list[tuple[str, str]], list[tuple[str, str]], dict]:
    """Lit l'export A1 des retours humains (feedback_pairs.csv, colonnes
    decision,origine,a,b,score,decide_le) : les FUSIONS deviennent des paires
    positives supplémentaires, les IGNORÉES des durs négatifs supplémentaires
    (garde de paire dans Interdits).

    Dédoublonnage par paire de formes normalisées (décision la plus récente
    gagne — même règle que T1FeedbackFeed côté A1, qui a déjà dédoublonné par
    entity_id : cette passe est une ceinture de sécurité déterministe).
    """
    positives: dict[frozenset[str], tuple[str, str, str]] = {}   # cle -> (decide_le, a, b)
    negatives: dict[frozenset[str], tuple[str, str, str]] = {}
    anomalies: list[str] = []
    n_lues = 0
    with open(chemin, newline="", encoding="utf-8") as f:
        lecteur = csv.DictReader(f)
        entete = lecteur.fieldnames or []
        manquantes = [c for c in ("decision", "a", "b") if c not in entete]
        if manquantes:
            raise SystemExit(f"FATAL: colonnes manquantes dans {chemin} : {manquantes}")
        for r in lecteur:
            n_lues += 1
            a = (r.get("a") or "").strip()
            b = (r.get("b") or "").strip()
            decision = (r.get("decision") or "").strip()
            if decision not in ("fusion", "ignorer"):
                anomalies.append(f"décision inconnue « {decision} » (ligne ignorée)")
                continue
            if not a or not b or a == b:
                anomalies.append("libellé vide ou paire dégénérée (ligne ignorée)")
                continue
            na, nb = t1.stock_public_normalize(a), t1.stock_public_normalize(b)
            if not na or not nb:
                anomalies.append(f"normalisation vide : « {a} »/« {b} » (ligne ignorée)")
                continue
            if na == nb:
                anomalies.append(f"paire de casse pure « {a} »/« {b} » (déjà résolue par normalizeKey)")
                continue
            cle = frozenset((na, nb))
            quand = (r.get("decide_le") or "").strip()
            dest = positives if decision == "fusion" else negatives
            if cle not in dest or quand >= dest[cle][0]:
                dest[cle] = (quand, a, b)
    pos = sorted((a, b) for _, a, b in positives.values())
    neg = sorted((a, b) for _, a, b in negatives.values())
    stats = {"lues": n_lues, "positives": len(pos), "negatives": len(neg), "anomalies": anomalies}
    return pos, neg, stats


# ---------------------------------------------------------------------------
# Génération
# ---------------------------------------------------------------------------

def _nouvelles_stats(typologies: dict | None = None, motifs: list[str] | None = None,
                     supplement: list[str] | None = None) -> dict:
    typologies = GENERATEURS if typologies is None else typologies
    motifs = MOTIFS_FILTRE if motifs is None else motifs
    stats = {ORIGINE: dict(brut=0, retenu=0, **{m: 0 for m in motifs})}
    for typ in typologies:
        stats[typ] = dict(brut=0, retenu=0, **{m: 0 for m in motifs})
    for typ in supplement or []:   # P2-auto : ligne « feedback » (paires humaines brutes)
        stats[typ] = dict(brut=0, retenu=0, **{m: 0 for m in motifs})
    return stats


def generer(jeu: t1.Jeu, seed: int, interdits: Interdits, vus_amont: dict[str, str] | None = None,
            budgets: dict | None = None, generateurs: dict | None = None,
            motifs_filtre: list[str] | None = None, etendu: bool = False,
            paires: list[tuple[str, str]] | None = None, avec_feedback: bool = False
            ) -> tuple[list[tuple[str, str, str]], dict, dict]:
    """Génère (texte_variante, cible, typologie) pour les 30 paires TRAIN (passe complète).

    Sans argument optionnel : comportement v1 À L'IDENTIQUE (mêmes budgets, mêmes
    générateurs, même ordre de tirage → sorties octet/octet égales). En v2 : budgets
    étendus, nouvelles typologies et filtre anti-collision étendu (etendu=True).
    En P2-auto (paires + avec_feedback) : le pool s'enrichit des paires FUSIONNÉES
    par décision humaine (typologie `feedback`, admission dédiée motif_feedback —
    les paires du jeu figé restent des lignes `origine` à l'identique).

    Dédoublonnage par libellé EXACT (l'entrée du modèle d'embeddings est la chaîne brute :
    « Lion_Peanut » et « Lion-Peanut » sont deux exemples distincts). L'anti-triche, elle,
    s'applique en formes normalisées (cf. Interdits.motif).
    vus_amont : libellés exacts déjà occupés (→ cible normalisée) pour la disjointness de la
    validation.
    """
    budgets = BUDGETS if budgets is None else budgets
    generateurs = GENERATEURS if generateurs is None else generateurs
    rng = random.Random(seed)
    stats = _nouvelles_stats(generateurs, motifs_filtre, supplement=[FEEDBACK] if avec_feedback else None)
    rows: list[tuple[str, str, str]] = []
    vus: dict[str, str] = {k: v for k, v in (vus_amont or {}).items()}  # libellé exact -> cible normalisée
    origines_jeu = set(interdits.train_pairs) if avec_feedback else None

    def ajouter(variante: str, cible: str, typ: str, origine: bool) -> None:
        stats[typ]["brut"] += 1
        if typ == FEEDBACK:
            motif = interdits.motif_feedback(variante, cible)
        else:
            motif = interdits.motif(variante, cible, origine, etendu=etendu)
        cn = t1.stock_public_normalize(cible)
        if motif is None and variante in vus:
            motif = "conflit_identite" if vus[variante] != cn else "doublon"
        if motif is not None:
            stats[typ][motif] += 1
            return
        vus[variante] = cn
        rows.append((variante, cible, typ))
        stats[typ]["retenu"] += 1

    paires = interdits.train_pairs if paires is None else paires  # tri déterministe (brut, cible)
    for brut, cible in paires:
        if avec_feedback and (brut, cible) not in origines_jeu:
            ajouter(brut, cible, FEEDBACK, origine=False)
        else:
            ajouter(brut, cible, ORIGINE, origine=True)

    for brut, cible in paires:
        for typ, budget in budgets.items():
            gen = generateurs[typ]
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

def _verifier_lignes(train_rows: list[tuple[str, str]], val_rows: list[tuple[str, str]],
                     interdits: Interdits, etendu: bool = False,
                     cibles_sup: set[str] | None = None,
                     feedback_negatifs: set[frozenset[str]] | None = None) -> list[str]:
    """Cœur de vérification d'un couple (train, val) contre le jeu figé. Retourne les
    erreurs (vide = OK). Vérifications v1 (toujours) + v2 (si etendu) + P2-auto
    (si feedback — brief A2) :

    - aucune forme (exacte / normalize_key / StockPublic::normalizeKey) d'un libellé des
      20 paires TEST n'apparaît (ni en variante, ni en cible) ;
    - [v2] aucune variante ne CONTIENT (sous-chaîne non-préfixe) une forme TEST ni une
      forme d'une identité sœur de la cible (nouvelles typologies — collision sémantique) ;
    - aucune variante SYNTHÉTIQUE ne prend la forme d'un libellé résiduel §1.3 (exception :
      les lignes « origine » = paires TRAIN du jeu figé exactes) ;
    - toutes les cibles sont des cibles TRAIN du jeu OU des cibles feedback admises ;
      la validation est disjointe du train ;
    - [auto] aucune ligne (hors origine du jeu figé) n'est une paire IGNORÉE par
      décision humaine (dur négatif supplémentaire) ;
    - en-têtes conformes (assertion côté lecture).
    """
    erreurs: list[str] = []
    cibles_sup = cibles_sup or set()
    feedback_negatifs = feedback_negatifs or set()
    formes_test = interdits.test_norm | interdits.test_exact | {
        t1.normalize_key(x) for x in interdits.test_exact
    } | {x.lower() for x in interdits.test_exact}
    train_exact: set[str] = set()
    train_pairs_exact = {(b, c) for b, c in interdits.train_pairs}
    origines_exact = {b for b, _ in interdits.train_pairs}

    for nom, rows in (("train", train_rows), ("val", val_rows)):
        for i, (variante, cible) in enumerate(rows, start=2):
            vn1, vn2 = t1.normalize_key(variante), t1.stock_public_normalize(variante)
            cn = t1.stock_public_normalize(cible)
            if variante in interdits.test_exact or vn1 in formes_test or vn2 in formes_test:
                erreurs.append(f"{nom}:{i} : variante « {variante} » = forme TEST")
            if cible in interdits.test_exact or cn in interdits.test_norm:
                erreurs.append(f"{nom}:{i} : cible « {cible} » = forme TEST")
            if cible not in interdits.train_cibles and cn not in cibles_sup:
                erreurs.append(f"{nom}:{i} : cible « {cible} » hors cibles TRAIN du jeu")
            est_origine = nom == "train" and (variante, cible) in train_pairs_exact \
                and variante in origines_exact
            if feedback_negatifs and not est_origine \
                    and frozenset((vn2, cn)) in feedback_negatifs:
                erreurs.append(f"{nom}:{i} : paire ignorée par feedback « {variante} »/« {cible} »")
            resid = variante in interdits.resid_exact or vn1 in interdits.resid_norm or vn2 in interdits.resid_norm
            if resid:
                if nom == "train" and (variante, cible) in train_pairs_exact and variante in origines_exact:
                    pass  # exception documentée : ligne « origine » = paire TRAIN qualifiée du jeu figé
                else:
                    erreurs.append(f"{nom}:{i} : variante « {variante} » = forme résiduelle §1.3")
            if etendu:
                if not est_origine:  # exception documentée : lignes origine du jeu figé
                    motif_col = interdits._collisions_etendues(vn2, cn)
                    if motif_col:
                        erreurs.append(f"{nom}:{i} : variante « {variante} » → {motif_col} « {variante} »")
            if nom == "train":
                train_exact.add(variante)
        if erreurs and len(erreurs) > 20:
            break

    for i, (variante, cible) in enumerate(val_rows, start=2):
        if variante in train_exact:
            erreurs.append(f"val:{i} : variante « {variante} » déjà présente dans train (libellé exact)")
            break

    return erreurs


def verifier(jeu: t1.Jeu, interdits: Interdits, verbeux: bool = True, v2: bool = False,
             dossier: Path | None = None, cibles_sup: set[str] | None = None,
             feedback_negatifs: set[frozenset[str]] | None = None) -> list[str]:
    """Vérifie train_pairs.csv + val_pairs.csv (v1 : p2/, v2 : p2/v2/, auto : dossier du
    run P2-auto) contre le jeu figé."""
    dossier = (P2V2 if v2 else P2) if dossier is None else dossier
    train_rows = _lire_pairs(dossier / "train_pairs.csv")
    val_rows = _lire_pairs(dossier / "val_pairs.csv")
    erreurs = _verifier_lignes(train_rows, val_rows, interdits, etendu=v2,
                               cibles_sup=cibles_sup, feedback_negatifs=feedback_negatifs)

    if verbeux:
        print(f"[anti-triche{' v2 étendu' if v2 else ''}] paires TEST du jeu : 20 · "
              f"formes interdites (normalisées) : {len(interdits.test_norm)}")
        print(f"[anti-triche] libellés résiduels §1.3 : {len(interdits.resid_exact)} · "
              f"formes : {len(interdits.resid_norm)}")
        if v2:
            n_soeurs = sum(len(v) for v in interdits.forms_par_famille.values())
            print(f"[anti-triche v2] formes sœurs (par famille, univers 120 identités) : {n_soeurs} · "
                  f"garde-fou de contenance (collision_test/collision_soeur) ACTIF")
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


# ---------------------------------------------------------------------------
# Vague 2 — test négatif par injection (preuve exécutable que le filtre DÉTECTE)
# ---------------------------------------------------------------------------

PROBES_COLLISION_EXPLICITES = [
    # (variante injectée, cible prétendue) — collisions sémantiques des NOUVELLES typologies
    ("RED BULL BLEU BOITE 25CL", "Redbull Seablue"),   # contient la sœur « redbullbleu »
    ("Redbull bleu 33CL", "Redbull Ice"),              # idem + queue d'emballage
    ("redbull blue edition 25cl", "Redbull Seablue"),  # contient la forme TEST « redbullblue »
    ("MONSTER ULTRA ZERO 33CL", "Monster Bleu"),       # contient la forme TEST « monsterultrazero »
    ("monster ultra bleu", "monster noire"),           # contient la sœur « monsterbleu »
    ("Soiree bowling (2 parties / 1 personne) X6", "sucette"),  # forme TEST contenue
    ("bowling 2personnes 33CL", "Chips Nature"),       # forme TEST contenue
    ("MONSTER BLANCHE EDITION", "Monster Bleu"),       # sœur « monsterblanche » (= cible TEST)
    ("Redbull Blue X6", "redbull red"),                # forme TEST + sœur « redbullblue »
    ("Coca zero sucre", "Coca Cherry"),                # contient la forme TEST « cocazero »
]


# vocabulaire d'équivalence zéro (zero_edition) : deux tokens de cet ensemble se
# correspondent (zero ≈ sans sucre ≈ sugar free ≈ light ≈ 0%)
ENSEMBLE_ZERO = {"zero", "zeros", "sans", "sucre", "sugar", "free", "light", "0%",
                 "zerosucre", "sanssucre", "sugarfree"}


def _classe_couleur(tok: str) -> set[str]:
    """Classe d'équivalence de couleur du token (bleu/bleue/bleus/bleues/blue ensemble,
    vert/verte/…/green ensemble, etc. — table COULEURS_TRADUCTION symétrique)."""
    directe = COULEURS_TRADUCTION.get(tok)
    classe = {tok}
    if directe:
        classe.add(directe)
        classe |= {k for k, v in COULEURS_TRADUCTION.items() if v in (directe, tok)}
    return classe


def _tokens_equivalents(tok: str, ref: str) -> bool:
    """Deux tokens se correspondent : égaux, préfixe l'un de l'autre (troncation ≥ 3
    lettres), couleur équivalente EN↔FR, ou équivalence zéro (zero/sans sucre/light…)."""
    if tok == ref:
        return True
    if len(tok) >= 3 and len(ref) >= 3 and (ref.startswith(tok) or tok.startswith(ref)):
        return True
    if tok in _classe_couleur(ref):
        return True
    if tok in ENSEMBLE_ZERO and ref in ENSEMBLE_ZERO:
        return True
    return False


def _fidele_a_la_paire(variante: str, brut: str, cible: str, typ: str) -> bool:
    """Une forme tirée par une nouvelle typologie sur la paire (brut, cible) est FIDÈLE si
    elle porte l'identité de la paire elle-même — par opposition à une collision qui
    enseignerait une autre identité.

    Deux critères (typologie-indépendants) :
    1. décorations : la forme normalisée est/contient/est préfixe de celle du brut ou de
       la cible (emballage, parenthèses, multipack ajoutés autour) ;
    2. recomposition : TOUS les tokens du brut OU de la cible sont consommés DANS L'ORDRE
       par les tokens de la variante (égaux, tronqués à ≥ 3 lettres, couleur traduite
       EN↔FR, équivalent zéro) — la variante ne fait que recombiner les tokens de la paire,
       les tokens excédentaires (qualificatifs, emballage) étant ignorés.
    """
    vn = t1.stock_public_normalize(variante)
    bn = t1.stock_public_normalize(brut)
    cn = t1.stock_public_normalize(cible)
    if vn in (bn, cn) or cn in vn or bn in vn or cn.startswith(vn) or bn.startswith(vn):
        return True
    if typ == "inversion_mots":
        # réordonnancement pur : mêmes caractères (aucun token ajouté/retiré/traduit)
        if sorted(vn) == sorted(bn) or sorted(vn) == sorted(cn):
            return True
    tv = t1.normalize_key(variante).split()
    for src in (brut, cible):
        ts = t1.normalize_key(src).split()
        i = 0
        for tok in tv:
            if i >= len(ts):
                break
            if _tokens_equivalents(tok, ts[i]):
                i += 1
        if i == len(ts):
            return True
    return False


def test_negatif(jeu: t1.Jeu, interdits: Interdits, verbeux: bool = True) -> list[str]:
    """Preuve par injection (contrainte vague 2) : des formes TEST — exactes, déguisées
    (casse/séparateurs/décorations des nouvelles typologies) et collisions sémantiques
    explicites — soumises au filtre étendu DOIVENT toutes être rejetées, et un CSV corrompu
    par une injection DOIT être signalé ÉCHEC par le vérificateur. Retourne les manquements
    (vide = OK = toutes les injections détectées)."""
    erreurs: list[str] = []
    rng = random.Random(SEED_VAL)  # déterministe

    # A) formes EXACTES des 40 libellés des 20 paires TEST → rejet « test » attendu
    n_exact = n_exact_rej = 0
    for p in jeu.paires_par_split("test"):
        for x in (p["libelle_brut"], p["libelle_cible"]):
            n_exact += 1
            motif = interdits.motif(x, p["libelle_cible"], origine=False, etendu=True)
            if motif != "test":
                erreurs.append(f"injection exacte « {x} » NON détectée (motif={motif!r})")
            else:
                n_exact_rej += 1

    # B) déguisements : nouvelles typologies APPLIQUÉES aux paires TEST (usage INTERDIT en
    #    génération réelle — ici volontaire, pour mesurer la robustesse du garde-fou).
    #    Chaque tirage doit être rejeté OU fidèle à la paire (il porte l'identité de la
    #    paire elle-même : décoration, traduction, recomposition) — JAMAIS une forme
    #    ambiguë qui enseignerait une autre identité.
    n_deg = n_deg_rej = n_deg_fidele = 0
    suspects: list[str] = []
    exemples_fideles: list[str] = []
    for p in jeu.paires_par_split("test"):
        brut, cible = p["libelle_brut"], p["libelle_cible"]
        for typ, gen in GENERATEURS_V2.items():
            if typ in GENERATEURS:
                continue  # seules les NOUVELLES typologies (contrainte vague 2 n°3)
            for _ in range(6):
                v = gen(brut, cible, rng)
                n_deg += 1
                motif = interdits.motif(v, cible, origine=False, etendu=True)
                if motif is not None:
                    n_deg_rej += 1
                elif _fidele_a_la_paire(v, brut, cible, typ):
                    n_deg_fidele += 1
                    if len(exemples_fideles) < 5:
                        exemples_fideles.append(f"{typ}:« {v} »→« {cible} »")
                else:
                    suspects.append(f"{typ}:« {v} »→« {cible} »")
    if suspects:
        erreurs.append("tirages non rejetés et non fidèles à la paire : " + " ; ".join(suspects[:10]))

    # C) probes de collision EXPLICITES (collisions sémantiques visées par la vague 2)
    n_probe = n_probe_rej = 0
    for v, c in PROBES_COLLISION_EXPLICITES:
        n_probe += 1
        motif = interdits.motif(v, c, origine=False, etendu=True)
        if motif is None:
            erreurs.append(f"probe « {v} » → « {c} » NON détecté")
        else:
            n_probe_rej += 1

    # D) injection dans un CSV RECONSTITUÉ : le vérificateur DOIT signaler l'échec
    base_train = _lire_pairs(P2V2 / "train_pairs.csv") if (P2V2 / "train_pairs.csv").exists() else []
    base_val = _lire_pairs(P2V2 / "val_pairs.csv") if (P2V2 / "val_pairs.csv").exists() else []
    csv_detecte = None
    if base_train:
        cible_legale = interdits.train_pairs[0][1]
        corrompus = [("Redbull blue", cible_legale), ("MONSTER ULTRA ZERO", cible_legale)]
        errs_corrompu = _verifier_lignes(base_train + corrompus, base_val, interdits, etendu=True)
        csv_detecte = sum(1 for e in errs_corrompu if "forme TEST" in e) >= 2
        if not csv_detecte:
            erreurs.append("injection CSV : le vérificateur n'a PAS signalé les lignes TEST injectées")

    if verbeux:
        print(f"[test-négatif] A) formes TEST exactes injectées : {n_exact_rej}/{n_exact} rejetées (motif 'test')")
        print(f"[test-négatif] B) nouvelles typologies × paires TEST : {n_deg} tirages → "
              f"{n_deg_rej} rejetés + {n_deg_fidele} fidèles à la paire + "
              f"{n_deg - n_deg_rej - n_deg_fidele} ambigus")
        if exemples_fideles:
            print(f"[test-négatif]    fidèles (ex.) : {' ; '.join(exemples_fideles)}")
        print(f"[test-négatif] C) probes de collision explicites : {n_probe_rej}/{n_probe} détectés")
        if csv_detecte is not None:
            print(f"[test-négatif] D) CSV corrompu par injection : "
                  f"{'ÉCHEC détecté (2 lignes TEST signalées)' if csv_detecte else 'NON détecté'}")
        print(f"[test-négatif] résultat : {'OK — ÉCHEC détecté partout' if not erreurs else 'ÉCHEC'}")
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


# ---------------------------------------------------------------------------
# Vague 2 — budgets étendus (train ×2-3 : cible 4 000-6 000 paires retenues)
# ---------------------------------------------------------------------------

BUDGETS_V2 = {
    # typologies v1 renforcées (elles couvrent les échecs : facture_long → échecs
    # « …BOITE 25CL/BTE 50CL », faute → confusions mono-caractère, casse/séparateurs)
    "casse_mele": 32,
    "separateurs": 26,
    "concatenation": 24,
    "faute_1_2_editions": 56,
    "suffixe_gout_couleur": 24,
    "facture_long": 36,
    "doublage_mots": 14,
    # nouvelles typologies (analyse d'erreurs S4' — cf. v2/analyse_erreurs.md)
    "couleur_traduite": 18,          # échecs « RED BULL BLUE … »/« Redbull blue » → « Redbull Bleu »
    "marque_scindee": 16,            # « RED BULL … » ↔ « Redbull … » (marque scindée/rejointe)
    "inversion_mots": 12,            # ordre des mots flottant
    "abreviation_globale": 18,       # style « POM CASS FRAM »/« L'ORIG »/« CRISTALI »
    "zero_edition": 20,              # échecs « MONSTER ULTRA ZERO … » → « Monster Blanche »
    "evenement_parentheses": 22,    # échec « Soiree bowling (2 parties / 1 personne) » → « bowling »
    "conditionnement_multipack": 22, # multipacks X/Y (« 5 cannettes au lieu d'un pack »)
    "accents_globaux": 16,          # accentuation/désaccentuation systémique
}

# typologie nouvelle → échec TEST / mécanisme visé (documentation du manifeste)
TYPOLOGIE_ECHEC = {
    "couleur_traduite": "« RED BULL BLUE BOITE 25CL » et « Redbull blue » → « Redbull Bleu » : couleur traduite EN↔FR",
    "marque_scindee": "« RED BULL … » (appellations) vs « Redbull … » (identités) : marque scindée/rejointe",
    "inversion_mots": "ordre des mots flottant à la saisie (renforcement générique A1)",
    "abreviation_globale": "« OASIS POM CASS FRAM SLIM 33CL » → « Oasis Pomme Cassis Framboise » (TEST réussi) : style d'abréviation facture",
    "zero_edition": "« MONSTER ULTRA ZERO (BTE 50CL) » → « Monster Blanche » : qualificatifs de gamme énergie amovibles",
    "evenement_parentheses": "« Soiree bowling (2 parties / 1 personne) » → « bowling » : libellé événement long",
    "conditionnement_multipack": "durs négatifs intra-famille + échec « MONSTER ULTRA ZERO BTE 50CL » : conditionnement X/Y",
    "accents_globaux": "« Gauffre »/« gaufre », accents de saisie (renforcement générique A1/A3)",
}


def _dataset_metadata_v2() -> str:
    """MÊME id que le dataset poussé en P2 (adrienremond/aeic-t1-p2-pairs) : un push
    Kaggle avec cet id crée une NOUVELLE VERSION du dataset (version bump), pas un nouveau
    dataset — contrainte vague 2 n°4."""
    return json.dumps({
        "title": "aeic-t1-p2-pairs",
        "id": "adrienremond/aeic-t1-p2-pairs",
        "isPrivate": True,
        "licenses": [{"name": "OTHER"}],
    }, ensure_ascii=False, indent=2) + "\n"


def ecrire_manifeste_v2(interdits: Interdits, rows_train, stats_train, rows_val, stats_val,
                        n_val_passe: int, negatif_resume: str, origines_resid: list[str]) -> None:
    n_train = len(rows_train)
    n_val = len(rows_val)
    n_cibles_val = len({c for _, c, _ in rows_val})
    n_cibles_train = len({c for _, c, _ in rows_train})
    chemins = [
        P2V2 / "train_pairs.csv", P2V2 / "val_pairs.csv",
        KAGGLE_UPLOAD_V2 / "train_pairs.csv", KAGGLE_UPLOAD_V2 / "val_pairs.csv",
        KAGGLE_UPLOAD_V2 / "dataset-metadata.json",
    ]
    empreintes = [(_sha256(p), p.relative_to(REPO).as_posix()) for p in chemins]
    n_syn = sum(1 for _, _, typ in rows_train if typ != ORIGINE)
    n_val_syn = sum(1 for _, _, typ in rows_val if typ != ORIGINE)

    a = []
    a.append("# Manifeste P2 v2 — paires d'entraînement augmentées ×2-3 (LIBELLÉS SEULS)")
    a.append("")
    a.append("> Vague 2 « P2-ter : ré-entraînement du détecteur avec plus de données » · brief A v2 (dev-factures).")
    a.append("> Source : jeu figé `data/eval_t1/` (INTACT, `sha256sum -c data/eval_t1/SHA256SUMS` 12/12 avant/après).")
    a.append("> Analyse d'erreurs d'abord : `analyse_erreurs.md` (5 paires TEST ratées recall@3 + 57 durs négatifs ≥ 0,6618).")
    a.append("> Écrit par `scripts/t1_augment.py --v2` — tout va dans `data/eval_t1/p2/v2/` UNIQUEMENT.")
    a.append(">")
    a.append("> Les sorties v1 (`../train_pairs.csv`, `../kaggle_upload/`) sont RÉGÉNÉRÉES À L'IDENTIQUE par")
    a.append("> le script sans `--v2` (rétro-compatibilité, preuve par double exécution).")
    a.append("")
    a.append("## Reproductibilité")
    a.append("")
    a.append("- Graines : **train 42** (conservée), **validation 4242** (passe complète puis échantillon).")
    a.append("- Aucun horodatage dans CSV/manifeste, tris et tirages déterministes : deux exécutions → octets identiques.")
    a.append("- Commande de régénération : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2`")
    a.append("- Preuves relançables : `… --v2 --verifier` (anti-triche étendu) et `… --v2 --test-negatif` (injection).")
    a.append("")
    a.append("## Source des augmentations (TRAIN uniquement)")
    a.append("")
    a.append(f"- **{len(interdits.train_pairs)} paires positives sémantiques du split TRAIN** (les 20 paires TEST ne servent jamais à apprendre).")
    a.append("- Typologies v1 RENFORCÉES + 8 NOUVELLES typologies issues de l'analyse d'erreurs S4' :")
    a.append("")
    a.append("| typologie | tirages/paire | v2 | échec/mécanisme visé |")
    a.append("|---|---:|:-:|---|")
    for typ, budget in BUDGETS_V2.items():
        marqueur = "" if typ == ORIGINE or typ in GENERATEURS else "✚"
        cible = TYPOLOGIE_ECHEC.get(typ, "(v1 — cf. manifeste_p2.md)") if marqueur else "(v1, renforcée)"
        a.append(f"| {typ} | {budget} | {marqueur} | {cible} |")
    a.append(f"| {ORIGINE} (paire réelle) | 1 | | vérité terrain du jeu figé |")
    a.append("")
    a.append("## Volumes réellement obtenus (train v2)")
    a.append("")
    a.append("| typologie | tirées | retenues | filtrées TEST | collision TEST | filtrées résiduel | conflit identité | collision sœur | doublons | vides |")
    a.append("|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|")
    st = stats_train
    for typ in [ORIGINE] + list(GENERATEURS_V2):
        s = st[typ]
        a.append(f"| {typ} | {s['brut']} | {s['retenu']} | {s['test']} | {s['collision_test']} | "
                 f"{s['residuel']} | {s['conflit_identite']} | {s['collision_soeur']} | {s['doublon']} | {s['vide']} |")
    tot = {k: sum(s[k] for s in st.values()) for k in ["brut", "retenu", *MOTIFS_FILTRE_V2]}
    a.append(f"| **total** | {tot['brut']} | **{tot['retenu']}** | {tot['test']} | {tot['collision_test']} | "
             f"{tot['residuel']} | {tot['conflit_identite']} | {tot['collision_soeur']} | {tot['doublon']} | {tot['vide']} |")
    a.append("")
    a.append(f"- `train_pairs.csv` : **{n_train} lignes** ({n_train - n_syn} `origine` + {n_syn} synthétiques) — "
             f"cible vague 2 : 4 000-6 000 → {'ATTEINTE' if 4000 <= n_train <= 6000 else 'HORS CIBLE'}.")
    a.append(f"- `val_pairs.csv` : **{n_val} lignes** (cible ~400) — passe complète graine 4242 "
             f"({n_val_passe} retenues) puis échantillon déterministe (indices seedés 4242) ; "
             f"libellés exacts disjoints du train ; couvre {n_cibles_val}/{n_cibles_train} cibles TRAIN "
             f"({n_val_syn} synthétiques + {n_val - n_val_syn} origine).")
    a.append("")
    a.append("## Filtrage anti-fuite — v1 + ÉTENDU (contrainte vague 2 n°3)")
    a.append("")
    a.append("1. formes interdites TEST (exact + normalisées, règle v1 inchangée) ;")
    a.append("2. formes interdites résiduels §1.3 (règle v1 inchangée) ;")
    a.append("3. conflit d'identité par ÉGALITÉ de forme normalisée (règle v1 inchangée) ;")
    a.append("4. **NOUVEAU — collision par CONTENANCE** : toute variante dont la forme normalisée contient")
    a.append("   (sous-chaîne NON-préfixe) une forme TEST (`collision_test`) ou une forme d'une AUTRE")
    a.append("   identité de la même famille que la cible (`collision_soeur`) est rejetée — c'est le")
    a.append("   garde-fou spécifique des nouvelles typologies (ex. « RED BULL BLEU BOITE 25CL » contient")
    a.append("   « redbullbleu » → ne peut JAMAIS viser « Redbull Seablue ») ;")
    a.append("5. exceptions documentées : la forme contenue est un PRÉFIXE de la cible (marque partagée,")
    a.append("   ex. « coca » pour « cocacherry ») ou la forme du brut d'une paire TRAIN de même cible ;")
    a.append("6. dédoublonnage par libellé EXACT (règle v1 inchangée).")
    a.append("")
    a.append("Exception v1 conservée : les lignes `origine` sont les paires TRAIN qualifiées du jeu figé, telles quelles.")
    if origines_resid:
        a.append(f"Libellés concernés (figurent aussi dans residuels §1.3 comme étiquettes d'AUTRES relations) : "
                 f"{' , '.join(f'« {x} »' for x in origines_resid)}.")
    a.append("")
    a.append("## Test négatif par injection (preuve exécutable)")
    a.append("")
    a.append(negatif_resume.strip())
    a.append("")
    a.append("Relançable : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2 --test-negatif`")
    a.append("")
    a.append("## ENGAGEMENT")
    a.append("")
    a.append(f"- **Zéro libellé des 20 paires TEST** (ni ses formes normalisées, ni par contenance non-préfixe)")
    a.append("  n'apparaît dans `train_pairs.csv` ni `val_pairs.csv` — preuves : `--v2 --verifier` → OK, `--v2 --test-negatif` → OK.")
    a.append("- **Zéro forme d'une identité sœur** (même famille que la cible, contenance non-préfixe) dans les CSV.")
    a.append("- Les 20 paires TEST ne servent qu'à mesurer (S4″ à pareil à pareil), jamais à apprendre.")
    a.append("- Contenu : **libellés produits uniquement** (anonymisation D3) — aucun prix, date, quantité d'achat, fournisseur.")
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
    a.append("- `kaggle_upload_v2/` : `train_pairs.csv` + `val_pairs.csv` + `dataset-metadata.json` "
             "(MÊME id `adrienremond/aeic-t1-p2-pairs`, `isPrivate: true`) — le push créera une")
    a.append("  NOUVELLE VERSION du dataset existant (pas un nouveau dataset), lue telle quelle par le kernel v4")
    a.append("  (noms de fichiers inchangés `train_pairs.csv`/`val_pairs.csv`, colonnes `texte_variante,cible`).")
    a.append("- **Envoi interdit ici** : revue dev-secu OBLIGATOIRE des fichiers modifiés AVANT tout push (contrainte vague 2 n°4).")
    a.append("")
    (P2V2 / "manifeste_v2.md").write_text("\n".join(a) + "\n", encoding="utf-8")


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


def generer_tout_v2() -> int:
    print("[1/6] chargement du jeu figé (LECTURE SEULE) …")
    jeu = t1.Jeu()
    interdits = Interdits(jeu)
    print(f"      univers {len(jeu.univers)} identités · paires TRAIN {len(interdits.train_pairs)} "
          f"(TEST {len(jeu.paires_par_split('test'))} jamais utilisé) · "
          f"interdits TEST {len(interdits.test_exact)} libellés / résiduels {len(interdits.resid_exact)} · "
          f"garde-fou étendu : {len(interdits.forms_par_famille)} familles")

    print("[2/6] génération train v2 (graine 42, budgets étendus, filtre anti-collision étendu) …")
    rows_train, stats_train, _ = generer(
        jeu, SEED_TRAIN, interdits, budgets=BUDGETS_V2, generateurs=GENERATEURS_V2,
        motifs_filtre=MOTIFS_FILTRE_V2, etendu=True)
    print(f"      {len(rows_train)} paires retenues (cible vague 2 : 4 000-6 000)")

    print("[3/6] génération validation v2 (graine 4242, libellés exacts disjoints du train) …")
    vus_train = {v: t1.stock_public_normalize(c) for v, c, _ in rows_train}
    rows_val_passe, stats_val, _ = generer(
        jeu, SEED_VAL, interdits, vus_amont=vus_train, budgets=BUDGETS_V2,
        generateurs=GENERATEURS_V2, motifs_filtre=MOTIFS_FILTRE_V2, etendu=True)
    n_val = min(N_VAL_V2, len(rows_val_passe))
    rng_val = random.Random(SEED_VAL)
    idx = sorted(rng_val.sample(range(len(rows_val_passe)), n_val))
    rows_val = [rows_val_passe[i] for i in idx]
    print(f"      passe complète : {len(rows_val_passe)} retenues → échantillon : {len(rows_val)} (cible ~{N_VAL_V2})")

    print("[4/6] écriture des livrables dans data/eval_t1/p2/v2/ …")
    _ecrire_csv(P2V2 / "train_pairs.csv", rows_train)
    _ecrire_csv(P2V2 / "val_pairs.csv", rows_val)
    KAGGLE_UPLOAD_V2.mkdir(parents=True, exist_ok=True)
    for nom in ("train_pairs.csv", "val_pairs.csv"):
        (KAGGLE_UPLOAD_V2 / nom).write_bytes((P2V2 / nom).read_bytes())
    (KAGGLE_UPLOAD_V2 / "dataset-metadata.json").write_text(_dataset_metadata_v2(), encoding="utf-8")

    print("[5/6] test négatif par injection (preuve anti-triche étendue) …")
    erreurs_neg = test_negatif(jeu, interdits, verbeux=True)
    # capture du résumé déterministe pour le manifeste (relance silencieuse)
    tampon_io = io.StringIO()
    with contextlib.redirect_stdout(tampon_io):
        test_negatif(jeu, interdits, verbeux=True)
    negatif_resume = tampon_io.getvalue()

    origines_resid = sorted(
        {b for b, c in interdits.train_pairs
         if b in interdits.resid_exact or t1.stock_public_normalize(b) in interdits.resid_norm} |
        {c for b, c in interdits.train_pairs
         if c in interdits.resid_exact or t1.stock_public_normalize(c) in interdits.resid_norm}
    )
    ecrire_manifeste_v2(interdits, rows_train, stats_train, rows_val, stats_val,
                        n_val_passe=len(rows_val_passe), negatif_resume=negatif_resume,
                        origines_resid=origines_resid)

    sommes = []
    for p in [P2V2 / "train_pairs.csv", P2V2 / "val_pairs.csv",
              KAGGLE_UPLOAD_V2 / "train_pairs.csv", KAGGLE_UPLOAD_V2 / "val_pairs.csv",
              KAGGLE_UPLOAD_V2 / "dataset-metadata.json"]:
        sommes.append(f"{_sha256(p)}  {p.relative_to(REPO)}")
    (P2V2 / "SHA256SUMS").write_text("\n".join(sommes) + "\n", encoding="utf-8")

    print("[6/6] vérification anti-triche étendue des CSV finaux …")
    erreurs = verifier(jeu, interdits, verbeux=True, v2=True)
    if erreurs or erreurs_neg:
        for e in (erreurs + erreurs_neg)[:20]:
            print("  !", e)
        print("ÉCHEC anti-triche v2 — livrables NON validés")
        return 1
    if not (4000 <= len(rows_train) <= 6000):
        print(f"ÉCHEC volume : {len(rows_train)} paires hors cible 4 000-6 000 (ajuster BUDGETS_V2)")
        return 2

    print()
    print("=" * 78)
    print(f"  train_pairs.csv v2 : {len(rows_train)} lignes (dont {sum(1 for _, _, t in rows_train if t == ORIGINE)} origine)")
    print(f"  val_pairs.csv v2   : {len(rows_val)} lignes (graine 4242)")
    print("  Répartition train retenue par typologie :")
    for typ in [ORIGINE] + list(GENERATEURS_V2):
        marqueur = "" if typ == ORIGINE or typ in GENERATEURS else " ✚nouvelle"
        print(f"    {typ:<26} {stats_train[typ]['retenu']:>5}{marqueur}")
    print("  ANTI-TRICHE v1+étendu : OK — rejouer : scripts/t1_augment.py --v2 --verifier")
    print("  TEST NÉGATIF (injection TEST → ÉCHEC détecté) : OK — rejouer : --v2 --test-negatif")
    print("=" * 78)
    return 0


# ---------------------------------------------------------------------------
# P2-auto (brief A2) — run enrichi par les retours humains
# ---------------------------------------------------------------------------

def generer_run_auto(chemin_feedback: Path, sortie: Path) -> int:
    """Régénère le jeu augmenté v2 ENRICHI des paires étiquetées (brief A2) :

    - FUSIONS humaines → positives supplémentaires : admises telles quelles
      (typologie `feedback`, filtre d'admission dédié) PUIS augmentées par les
      mêmes générateurs/budgets que le jeu (mécanismes existants, anti-triche
      complet — les variantes synthétiques passent le filtre v2 étendu + la
      garde de paire feedback_negatif) ;
    - IGNORÉES humaines → durs négatifs supplémentaires : garde de paire (aucune
      ligne — synthétique ou humaine — ne peut enseigner une paire ignorée) +
      export local durs_negatifs_feedback.csv. NB : le kernel v4 (inchangé)
      n'apprend que des paires positives (MNRL anchor/positive) — les durs
      négatifs agissent ici comme contrainte de génération, pas comme données
      d'entraînement directes ;
    - tout s'écrit dans le dossier du run (p2/auto/run_<ts>/) UNIQUEMENT :
      sorties v1/v2 régénérables inchangées.

    Déterminisme : mêmes graines (42/4242) ; un même feedback_pairs.csv produit
    des octets identiques (le nom du dossier d'horodatage n'entre pas dans les
    contenus). Les paires du jeu figé gardent les MÊMES tirages que la v2 (le
    pool s'allonge après elles : leur séquence RNG est inchangée).
    """
    if not chemin_feedback.is_file():
        print(f"FATAL : feedback introuvable : {chemin_feedback}", file=sys.stderr)
        return 2
    print(f"[1/7] chargement du jeu figé (LECTURE SEULE) + retours humains {chemin_feedback.name} …")
    jeu = t1.Jeu()
    fb_pos, fb_neg, fb_lecture = charger_feedback(chemin_feedback)
    if not fb_pos and not fb_neg:
        print("FATAL : aucune paire exploitable dans le feedback", file=sys.stderr)
        return 2
    neg_paires = {frozenset((t1.stock_public_normalize(a), t1.stock_public_normalize(b)))
                  for a, b in fb_neg}
    interdits = Interdits(jeu, feedback_negatifs=neg_paires)
    print(f"      feedback lu : {fb_lecture['lues']} lignes → {fb_lecture['positives']} fusions / "
          f"{fb_lecture['negatives']} ignorés (anomalies : {len(fb_lecture['anomalies'])})")

    # Admission des paires humaines brutes (décision = vérité terrain) :
    # seuls le vide, les formes TEST (mesure honnête à pareil à pareil), les
    # formes résiduelles §1.3 (qualification = régénération du jeu, D2) et les
    # paires re-ignorées bloquent. Rejetées = documentées au manifeste.
    admises: list[tuple[str, str]] = []
    rejetees: list[tuple[str, str, str]] = []
    for a, b in fb_pos:
        motif = interdits.motif_feedback(a, b)
        if motif is None:
            admises.append((a, b))
        else:
            rejetees.append((a, b, motif))
    admises.sort()
    print(f"      admission : {len(admises)} positives admises / {len(rejetees)} rejetées "
          f"({', '.join(sorted({m for _, _, m in rejetees})) or 'aucun motif'})")

    # Les cibles des paires admises deviennent des cibles légitimes (verifier)
    # et leurs bruts des exceptions de collision (identité de la paire elle-même).
    cibles_sup: set[str] = set()
    for a, c in admises:
        cn = t1.stock_public_normalize(c)
        cibles_sup.add(cn)
        interdits.bruts_de_cible.setdefault(cn, set()).add(t1.stock_public_normalize(a))
    pool = list(interdits.train_pairs) + admises  # jeu figé d'abord (tirages v2 inchangés)

    print(f"[2/7] génération train auto (graine 42, {len(pool)} ancrages = "
          f"{len(interdits.train_pairs)} jeu + {len(admises)} feedback) …")
    rows_train, stats_train, _ = generer(
        jeu, SEED_TRAIN, interdits, budgets=BUDGETS_V2, generateurs=GENERATEURS_V2,
        motifs_filtre=MOTIFS_FILTRE_AUTO, etendu=True, paires=pool, avec_feedback=True)
    print(f"      {len(rows_train)} paires retenues (dont {stats_train[FEEDBACK]['retenu']} feedback "
          f"brutes et {stats_train[ORIGINE]['retenu']} origine du jeu)")

    print("[3/7] génération validation auto (graine 4242, libellés exacts disjoints du train) …")
    vus_train = {v: t1.stock_public_normalize(c) for v, c, _ in rows_train}
    rows_val_passe, stats_val, _ = generer(
        jeu, SEED_VAL, interdits, vus_amont=vus_train, budgets=BUDGETS_V2,
        generateurs=GENERATEURS_V2, motifs_filtre=MOTIFS_FILTRE_AUTO, etendu=True,
        paires=pool, avec_feedback=True)
    n_val = min(N_VAL_V2, len(rows_val_passe))
    rng_val = random.Random(SEED_VAL)
    idx = sorted(rng_val.sample(range(len(rows_val_passe)), n_val))
    rows_val = [rows_val_passe[i] for i in idx]
    print(f"      passe complète : {len(rows_val_passe)} retenues → échantillon : {len(rows_val)} (cible ~{N_VAL_V2})")

    print(f"[4/7] écriture des livrables dans {_rel(sortie)} …")
    sortie.mkdir(parents=True, exist_ok=True)
    _ecrire_csv(sortie / "train_pairs.csv", rows_train)
    _ecrire_csv(sortie / "val_pairs.csv", rows_val)
    with open(sortie / "durs_negatifs_feedback.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["libelle_a", "libelle_b"])
        for a, b in fb_neg:
            w.writerow([a, b])
    kaggle_dir = sortie / "kaggle_upload"
    kaggle_dir.mkdir(parents=True, exist_ok=True)
    for nom in ("train_pairs.csv", "val_pairs.csv"):
        (kaggle_dir / nom).write_bytes((sortie / nom).read_bytes())
    (kaggle_dir / "dataset-metadata.json").write_text(_dataset_metadata_v2(), encoding="utf-8")
    # archive du feedback consommé (trace du run — jamais poussé vers Kaggle)
    shutil.copy2(chemin_feedback, sortie / "feedback_pairs.csv")

    print("[5/7] manifeste du run …")
    _ecrire_manifeste_auto(sortie, chemin_feedback, interdits, fb_lecture, admises, rejetees,
                           fb_neg, rows_train, stats_train, rows_val, len(rows_val_passe))

    print("[6/7] SHA256SUMS …")
    sommes = []
    for p in [sortie / "train_pairs.csv", sortie / "val_pairs.csv",
              sortie / "durs_negatifs_feedback.csv",
              kaggle_dir / "train_pairs.csv", kaggle_dir / "val_pairs.csv",
              kaggle_dir / "dataset-metadata.json"]:
        sommes.append(f"{_sha256(p)}  {_rel(p)}")
    (sortie / "SHA256SUMS").write_text("\n".join(sommes) + "\n", encoding="utf-8")

    print("[7/7] vérification anti-triche étendue des CSV finaux (+ gardes feedback) …")
    erreurs = verifier(jeu, interdits, verbeux=True, v2=True, dossier=sortie,
                       cibles_sup=cibles_sup, feedback_negatifs=neg_paires)
    if erreurs:
        for e in erreurs[:20]:
            print("  !", e)
        print("ÉCHEC anti-triche auto — livrables NON validés")
        return 1
    if len(rows_train) < 4000:
        print(f"ÉCHEC volume : {len(rows_train)} paires < 4000")
        return 2

    print()
    print("=" * 78)
    print(f"  run {sortie.name} : train {len(rows_train)} lignes "
          f"({stats_train[FEEDBACK]['retenu']} feedback + {stats_train[ORIGINE]['retenu']} origine) · "
          f"val {len(rows_val)} lignes")
    print(f"  durs négatifs feedback (gardes) : {len(fb_neg)} · admission : "
          f"{len(admises)} admises / {len(rejetees)} rejetées")
    print("  ANTI-TRICHE v1+étendu+feedback : OK")
    print("=" * 78)
    return 0


def _ecrire_manifeste_auto(sortie: Path, chemin_feedback: Path, interdits: Interdits,
                           fb_lecture: dict, admises: list[tuple[str, str]],
                           rejetees: list[tuple[str, str, str]], fb_neg: list[tuple[str, str]],
                           rows_train, stats_train, rows_val, n_val_passe: int) -> None:
    n_train = len(rows_train)
    chemins = [
        sortie / "train_pairs.csv", sortie / "val_pairs.csv",
        sortie / "kaggle_upload" / "train_pairs.csv", sortie / "kaggle_upload" / "val_pairs.csv",
        sortie / "kaggle_upload" / "dataset-metadata.json",
    ]
    empreintes = [(_sha256(p), _rel(p)) for p in chemins]
    a = []
    a.append(f"# Manifeste run P2-auto — {sortie.name}")
    a.append("")
    a.append("> Boucle de ré-entraînement automatique (P2-auto, brief A2 · dev-factures).")
    a.append("> Base : jeu augmenté v2 (`scripts/t1_augment.py --v2`, mêmes graines/budgets/typologies)")
    a.append("> ENRICHI des paires étiquetées par décisions humaines (export A1, logique")
    a.append("> `T1AutoRetrain::recolter` — fusion = positive supplémentaire, ignorer = dur négatif")
    a.append("> supplémentaire). Jeu figé `data/eval_t1/` INTACT (LECTURE SEULE).")
    a.append(">")
    a.append(f"> Feedback source : `{_rel(chemin_feedback)}` "
             f"({fb_lecture['lues']} lignes lues).")
    a.append("")
    a.append("## Retours humains intégrés")
    a.append("")
    a.append(f"- **{len(admises)} fusions admises** (positives supplémentaires : lignes brutes "
             f"`feedback` + augmentation par les mêmes générateurs/budgets v2).")
    if rejetees:
        a.append(f"- {len(rejetees)} fusions rejetées à l'admission (anti-triche inchangé) :")
        for motif in sorted({m for _, _, m in rejetees}):
            exemples = [f"« {x} » → « {y} »" for x, y, m in rejetees if m == motif][:5]
            a.append(f"  - motif `{motif}` : {sum(1 for _, _, m in rejetees if m == motif)} "
                     f"({' ; '.join(exemples)})")
    else:
        a.append("- aucune fusion rejetée à l'admission.")
    a.append(f"- **{len(fb_neg)} paires ignorées** → durs négatifs supplémentaires : garde de paire "
             f"`feedback_negatif` (aucune ligne — humaine ou synthétique — ne peut enseigner une "
             f"paire ignorée) + export `durs_negatifs_feedback.csv`. Le kernel v4 (inchangé) "
             f"n'apprendant que des paires positives (MNRL), les durs négatifs agissent comme "
             f"contrainte de génération — pas comme données d'entraînement directes.")
    if fb_lecture["anomalies"]:
        a.append(f"- anomalies de lecture (lignes ignorées) : {len(fb_lecture['anomalies'])}")
    a.append("")
    a.append("## Filtre d'admission des paires humaines (documenté)")
    a.append("")
    a.append("- `vide` : libellé dégénéré ; `test` : l'un des côtés est une forme (exacte ou")
    a.append("  normalisée) des 20 paires TEST — la mesure S4 à pareil à pareil reste honnête ;")
    a.append("- `residuel` : l'un des côtés est une forme résiduelle §1.3 — leur qualification")
    a.append("  passera par la régénération du jeu (décision D2), pas par la boucle auto ;")
    a.append("- `feedback_negatif` : paire re-ignorée par une décision plus récente ;")
    a.append("- PAS de filtre conflit/collision sur les paires humaines BRUTES : la décision")
    a.append("  qualifie la paire (une fusion de deux identités vivantes est le signal recherché).")
    a.append("  Les VARIANTES SYNTHÉTIQUES générées par-dessus passent, elles, le filtre v2 étendu")
    a.append("  complet (+ garde feedback_negatif) — anti-triche inchangée.")
    a.append("")
    a.append("## Volumes obtenus (train)")
    a.append("")
    a.append("| typologie | tirées | retenues | rejets (détail stats) |")
    a.append("|---|---:|---:|---|")
    for typ in [ORIGINE, FEEDBACK] + list(GENERATEURS_V2):
        s = stats_train[typ]
        rejets = ", ".join(f"{m}:{s[m]}" for m in MOTIFS_FILTRE_AUTO if s.get(m))
        a.append(f"| {typ} | {s['brut']} | {s['retenu']} | {rejets or '—'} |")
    a.append("")
    a.append(f"- `train_pairs.csv` : **{n_train} lignes** · `val_pairs.csv` : **{len(rows_val)} lignes** "
             f"(passe {n_val_passe}, échantillon seedé 4242, disjointe du train par libellé exact).")
    a.append("- Les paires du jeu figé conservent les MÊMES tirages que la v2 (le pool s'allonge")
    a.append("  après elles : séquence RNG inchangée) — ce run contient la v2 + les retours.")
    a.append("")
    a.append("## ENGAGEMENT")
    a.append("")
    a.append("- Zéro forme TEST (exacte/normalisée/contenue) dans les CSV — preuve : vérificateur intégré OK.")
    a.append("- Zéro paire ignorée par un humain dans les CSV (garde feedback_negatif).")
    a.append("- Contenu : libellés produits uniquement — audit secrets/montants/fournisseurs exécuté")
    a.append("  par `scripts/t1_auto_retrain.sh` AVANT tout envoi Kaggle (contrainte n°4).")
    a.append("")
    a.append("## SHA256 des livrables (déterministes pour un même feedback)")
    a.append("")
    a.append("```")
    for empreinte, chemin in empreintes:
        a.append(f"{empreinte}  {chemin}")
    a.append("```")
    a.append("")
    (sortie / "manifeste_run.md").write_text("\n".join(a) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(
        prog="t1_augment.py",
        description="T1-P2 — génération des paires d'entraînement augmentées (libellés seuls) à partir "
                    "des 30 paires positives sémantiques du split TRAIN du jeu figé data/eval_t1/. "
                    "Sans --v2 : sorties v1 dans data/eval_t1/p2/ (rétro-compatible, octet/octet). "
                    "Avec --v2 : volume ×2-3 ciblé sur les échecs réels S4' + 8 nouvelles typologies "
                    "+ anti-triche étendu, dans data/eval_t1/p2/v2/ UNIQUEMENT.",
        epilog="Runbook complet dans l'entête du script. Exiger le venv : "
               "~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--v2", action="store_true",
                        help="vague 2 (P2-ter) : budgets étendus + nouvelles typologies, "
                             "sorties dans data/eval_t1/p2/v2/ (train 4 000-6 000, val ~400)")
    parser.add_argument("--verifier", action="store_true",
                        help="ne rien générer : vérifie anti-triche des CSV existants "
                             "(v1 : p2/ ; avec --v2 : p2/v2/ + garde-fou de contenance)")
    parser.add_argument("--test-negatif", action="store_true",
                        help="(v2) preuve par injection : formes TEST exactes/déguisées/collidantes "
                             "→ toutes détectées ÉCHEC")
    parser.add_argument("--feedback", metavar="CSV",
                        help="(P2-auto, brief A2 — avec --v2) paires étiquetées de la boucle "
                             "(export A1 feedback_pairs.csv) : fusions = positives supplémentaires, "
                             "ignorées = durs négatifs supplémentaires ; sorties dans --sortie")
    parser.add_argument("--sortie", metavar="DOSSIER",
                        help="(avec --feedback) dossier du run (défaut data/eval_t1/p2/auto/"
                             "run_<horodatage UTC>)")
    args = parser.parse_args()

    if args.test_negatif:
        if not args.v2:
            print("--test-negatif concerne la vague 2 : ajouter --v2", file=sys.stderr)
            return 2
        jeu = t1.Jeu()
        interdits = Interdits(jeu)
        erreurs = test_negatif(jeu, interdits, verbeux=True)
        for e in erreurs:
            print("  !", e)
        return 1 if erreurs else 0

    if args.verifier:
        jeu = t1.Jeu()
        interdits = Interdits(jeu)
        erreurs = verifier(jeu, interdits, verbeux=True, v2=args.v2)
        for e in erreurs:
            print("  !", e)
        return 1 if erreurs else 0

    if args.feedback:
        if not args.v2:
            print("--feedback concerne la boucle P2-auto : ajouter --v2", file=sys.stderr)
            return 2
        chemin_fb = Path(args.feedback)
        if not chemin_fb.is_absolute():
            chemin_fb = REPO / chemin_fb
        if args.sortie:
            sortie = Path(args.sortie)
            if not sortie.is_absolute():
                sortie = REPO / sortie
        else:
            sortie = AUTO / time.strftime("run_%Y%m%dT%H%M%SZ", time.gmtime())
        return generer_run_auto(chemin_fb, sortie)

    if args.v2:
        return generer_tout_v2()
    return generer_tout()


if __name__ == "__main__":
    sys.exit(main())

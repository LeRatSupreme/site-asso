#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T1 P2-auto (brief A2, dev-factures) — verdict comparatif S4 auto.

Compare le rapport S4 machine du CANDIDAT (modèle issu du run Kaggle du run
P2-auto, converti int8, mesuré par `scripts/t1_similarity.py --evaluer
--model <run>/convert/model-int8`) au rapport ANTI-RÉGRESSION du modèle de
PRODUCTION (modèle par défaut du cache, mesuré dans la même session, mêmes
protocoles A1-A6 / même jeu figé / même graine — « à pareil à pareil », leçon
#7) et écrit le rapport comparatif `rapport_S4_auto.md` avec VERDICT.

RÈGLE DE DÉCISION (conservatrice, fixée AVANT la mesure — contrepoint du
swap automatique ; un rapport falsifié « pire » ne peut jamais déclencher un
remplacement, contrainte n°5) :

  MEILLEUR ssi AUCUN axe ne recule ET au moins un axe s'améliore strictement :
    - A1 recall@3 TEST (classement)         : candidat >= prod
    - A3 P1 ok/50 (classement)              : candidat >= prod
    - A3 régressions vs P0                  : candidat <= prod
    - A4 durs négatifs ≥ seuil (garde-fou)  : candidat <= prod
    - A5 moyenne ms (coût, garde < 50 ms)   : candidat < 50 ms
    et au moins UN de : A1 > prod, A3 ok > prod, A4 < prod.
  ÉGAL si tous les axes décisifs sont égaux ; PIRE sinon.

Sorties : rapport markdown + ligne console `VERDICT : <verdict>`.
Codes retour : 0 (verdict produit, quel qu'il soit) · 1 (rapports
illusibles/incomplets — le pipeline marque echec, jamais de remplacement).

RUNBOOK :
  python3 scripts/t1_auto_verdict.py --candidat <run>/rapport_S4_auto_brut.md \\
      --prod <run>/antiregression_prod.md --sortie <run>/rapport_S4_auto.md \\
      [--contexte "dry-run" | "complet"]
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path


def lire_rapport(chemin: Path) -> dict:
    """Extrait les mesures décisives d'un rapport S4 machine (t1_similarity.py)."""
    texte = chemin.read_text(encoding="utf-8")

    def groupe(motif: str, nom: str, idx: int = 1) -> str:
        m = re.search(motif, texte, re.MULTILINE)
        if m is None:
            raise ValueError(f"mesure introuvable dans le rapport {chemin.name} : {nom}")
        return m.group(idx)

    return {
        "a1_test": int(groupe(r"^\| A1 \|.*?(\d+)/(\d+)\) \|", "A1 recall@3 TEST")),
        "a1_test_total": int(groupe(r"^\| A1 \|.*?(\d+)/(\d+)\) \|", "A1 recall@3 TEST (total)", 2)),
        "a2_precision": groupe(r"^\| A2 \|.*?précision ([\d]+,[\d]) %", "A2 précision").replace(",", "."),
        "a2_rappel": groupe(r"^\| A2 \|.*?au rappel ([\d]+,[\d]) %", "A2 rappel").replace(",", "."),
        "a2_tp": int(groupe(r"^\| A2 \|.*?\((\d+) TP / (\d+) FP\)", "A2 TP")),
        "a2_fp": int(groupe(r"^\| A2 \|.*?\((\d+) TP / (\d+) FP\)", "A2 FP", 2)),
        "seuil": groupe(r"^\| A2 \|.*?seuil ([\d.]+) \|", "A2 seuil"),
        "a3_gain": int(groupe(r"^\| A3 \|.*?(\d+) échecs P0 rattrapés", "A3 gain")),
        "a3_reg": int(groupe(r"^\| A3 \|.*?, (\d+) régression\(s\)", "A3 régressions")),
        "a3_p0": int(groupe(r"^\| A3 \|.*?P0 (\d+)/50 vs P1", "A3 P0")),
        "a3_p1": int(groupe(r"^\| A3 \|.*?vs P1 (\d+)/50 \|", "A3 P1")),
        "a4_faux": int(groupe(r"^\| A4 \|.*?\| (\d+) / (\d+) \(", "A4 durs ≥ seuil")),
        "a4_total": int(groupe(r"^\| A4 \|.*?\| (\d+) / (\d+) \(", "A4 durs (total)", 2)),
        "a5_ms": float(groupe(r"^\| A5 \|.*?moyenne ([\d.]+) ms", "A5 moyenne")),
    }


def verdict(cand: dict, prod: dict) -> tuple[str, list[str]]:
    """Verdict conservateur (règle fixée avant mesure, cf. docstring du module)."""
    notes: list[str] = []
    if cand["a5_ms"] >= 50.0:
        notes.append(f"A5 coût recule ({cand['a5_ms']:.1f} ms ≥ 50 ms)")
        return "PIRE", notes
    if cand["a1_test"] < prod["a1_test"]:
        notes.append(f"A1 recall@3 TEST recule ({cand['a1_test']}/{cand['a1_test_total']} < {prod['a1_test']})")
    if cand["a3_p1"] < prod["a3_p1"]:
        notes.append(f"A3 P1 recule ({cand['a3_p1']}/50 < {prod['a3_p1']}/50)")
    if cand["a3_reg"] > prod["a3_reg"]:
        notes.append(f"A3 régressions augmentent ({cand['a3_reg']} > {prod['a3_reg']})")
    if cand["a4_faux"] > prod["a4_faux"]:
        notes.append(f"A4 durs ≥ seuil augmentent ({cand['a4_faux']} > {prod['a4_faux']})")
    if notes:
        return "PIRE", notes
    if (cand["a1_test"] == prod["a1_test"] and cand["a3_p1"] == prod["a3_p1"]
            and cand["a3_reg"] == prod["a3_reg"] and cand["a4_faux"] == prod["a4_faux"]):
        notes.append("tous les axes décisifs égaux (A1, A3, A4)")
        return "ÉGAL", notes
    if cand["a1_test"] > prod["a1_test"]:
        notes.append(f"A1 recall@3 TEST {cand['a1_test']}/{cand['a1_test_total']} > {prod['a1_test']}")
    if cand["a3_p1"] > prod["a3_p1"]:
        notes.append(f"A3 P1 {cand['a3_p1']}/50 > {prod['a3_p1']}/50")
    if cand["a4_faux"] < prod["a4_faux"]:
        notes.append(f"A4 durs ≥ seuil {cand['a4_faux']} < {prod['a4_faux']}")
    return "MEILLEUR", notes


def main() -> int:
    parser = argparse.ArgumentParser(prog="t1_auto_verdict.py", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--candidat", required=True, metavar="MD", help="rapport S4 machine du candidat")
    parser.add_argument("--prod", required=True, metavar="MD", help="rapport S4 machine anti-régression (prod)")
    parser.add_argument("--sortie", required=True, metavar="MD", help="rapport comparatif à écrire")
    parser.add_argument("--contexte", default="complet", help="annotation du contexte (dry-run / complet)")
    args = parser.parse_args()

    chemin_cand = Path(args.candidat)
    chemin_prod = Path(args.prod)
    if not chemin_cand.is_file() or not chemin_prod.is_file():
        print(f"FATAL : rapport absent ({chemin_cand} / {chemin_prod})", file=sys.stderr)
        return 1
    try:
        cand = lire_rapport(chemin_cand)
        prod = lire_rapport(chemin_prod)
    except ValueError as e:
        print(f"FATAL : {e}", file=sys.stderr)
        return 1

    v, notes = verdict(cand, prod)

    a = []
    a.append("# Rapport S4 auto — comparatif candidat vs production (P2-auto)")
    a.append("")
    a.append(f"> Contexte : **{args.contexte}** · règle de décision conservatrice fixée avant mesure (docstring")
    a.append("> `scripts/t1_auto_verdict.py`) : MEILLEUR seulement si aucun axe décisif ne recule")
    a.append("> (A1 recall@3 TEST, A3 P1, A3 régressions, A4 durs ≥ seuil, A5 < 50 ms) et qu'au moins")
    a.append("> un s'améliore strictement. Sources : `{}` (candidat) et `{}` (production) —".format(
        chemin_cand.name, chemin_prod.name))
    a.append("> sorties machine `t1_similarity.py --evaluer`, à pareil à pareil (même jeu figé, mêmes")
    a.append("> protocoles A1-A6, même graine 42 ; seule variable : le binaire ONNX int8 chargé).")
    a.append("")
    a.append("## Mesures décisives")
    a.append("")
    a.append("| # | Critère | Production | Candidat | Sens |")
    a.append("|---|---|---|---|---|")
    for num, crit, p, c, sens in [
        ("A1", "recall@3 TEST (classement)", f"{prod['a1_test']}/{prod['a1_test_total']}",
         f"{cand['a1_test']}/{cand['a1_test_total']}", "≥"),
        ("A3", "P1 sur 50 (classement)", f"{prod['a3_p1']}/50", f"{cand['a3_p1']}/50", "≥"),
        ("A3", "régressions vs P0", str(prod["a3_reg"]), str(cand["a3_reg"]), "≤"),
        ("A4", "durs négatifs ≥ seuil (garde-fou)", f"{prod['a4_faux']}/{prod['a4_total']}",
         f"{cand['a4_faux']}/{cand['a4_total']}", "≤"),
        ("A5", "inférence moyenne (coût)", f"{prod['a5_ms']:.1f} ms", f"{cand['a5_ms']:.1f} ms", "< 50 ms"),
    ]:
        a.append(f"| {num} | {crit} | {p} | {c} | {sens} |")
    a.append("")
    a.append("## Mesures d'information (hors décision)")
    a.append("")
    a.append("| # | Mesure | Production | Candidat |")
    a.append("|---|---|---|---|")
    a.append(f"| A2 | précision TEST au seuil calibré | {prod['a2_precision']} % | {cand['a2_precision']} % |")
    a.append(f"| A2 | rappel TEST au seuil | {prod['a2_rappel']} % | {cand['a2_rappel']} % |")
    a.append(f"| A2 | TP / FP TEST | {prod['a2_tp']} / {prod['a2_fp']} | {cand['a2_tp']} / {cand['a2_fp']} |")
    a.append(f"| A2 | seuil calibré (TRAIN) | {prod['seuil']} | {cand['seuil']} |")
    a.append(f"| A3 | échecs P0 rattrapés | {prod['a3_gain']} | {cand['a3_gain']} |")
    a.append("")
    a.append("## Justification")
    a.append("")
    for n in notes:
        a.append(f"- {n}")
    a.append("")
    a.append(f"## VERDICT : {v}")
    a.append("")
    if v == "MEILLEUR":
        a.append("Le candidat est strictement meilleur sans aucun recul : le pipeline peut remplacer le")
        a.append("modèle de production (cache + candidats_fusion.json régénérés + rollback documenté).")
    elif v == "ÉGAL":
        a.append("Aucun axe décisif ne s'améliore strictement : conservation du modèle courant (contrainte")
        a.append("n°5), run archivé, paires consommées.")
    else:
        a.append("Au moins un axe décisif recule : conservation du modèle courant (contrainte n°5), run")
        a.append("archivé, paires consommées.")
    a.append("")
    chemin_sortie = Path(args.sortie)
    chemin_sortie.parent.mkdir(parents=True, exist_ok=True)
    chemin_sortie.write_text("\n".join(a) + "\n", encoding="utf-8")
    print(f"VERDICT : {v}")
    print(f"rapport comparatif : {chemin_sortie}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

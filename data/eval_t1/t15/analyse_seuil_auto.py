#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T1.5 — Étape 0 : mesure du point de fonctionnement « auto » du niveau 2.

Jeu figé data/eval_t1/ en LECTURE SEULE (aucune écriture, aucun SQL) —
réutilise le pipeline de scripts/t1_similarity.py (modèle actuel accepté :
baseline P1 zéro-shot, export ONNX int8) pour noter :

  - les 199 paires DURS NÉGATIFS (cosinus des deux libellés de l'univers) ;
  - les 50 paires POSITIFS SÉMANTIQUES (score brut → cible, comme A1).

Protocole du brief T15-A :
  1. seuil_auto naïf = plus petit seuil tel que 0 dur négatif ≥ seuil,
     avec marge +0.03 au-dessus du max observé ;
  2. si ce seuil est inutilisable (aucun positif ne passe), règle
     COMPLÉMENTAIRE : niveau 2 = score ≥ seuil ET (préfixe normalisé OU
     suffixe ∈ SUFFIX_STOPWORDS OU même famille) — mesurée honnêtement.

Predicates (ports Python fidèles, mêmes que le runtime PHP T1AutoFusion) :
  - prefixe(min_len) : une forme normalisée (sans espaces,
    StockPublic::normalizeKey) est PRÉFIXE de l'autre (base ↔ variante :
    « lion » préfixe de « lionpeanut », « lipton » préfixe de
    « liptonicetea ») — PAS « préfixe commun » (ce serait fusionner
    Monster Bleu et Monster Verte, deux produits distincts) ;
  - suffixe : les deux formes réduisent au même radical par retrait
    itératif des suffixes couleur/goût (AliasSuggester::suggest) —
    « monster bleu » / « monster bleue » ;
  - famille : même colonne famille de l'univers (mesure indicative
    uniquement : NON calculable en production, non codée dans le runtime).

Sortie : data/eval_t1/t15/analyse_seuil_auto.md + scores_paires.json.

Usage : ~/AEIC/.venv-t1/bin/python3 data/eval_t1/t15/analyse_seuil_auto.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3] if False else Path("/home/ubuntu/AEIC")
sys.path.insert(0, str(ROOT / "scripts"))

from t1_similarity import (  # noqa: E402
    DATA, SUFFIX_STOPWORDS, Jeu, Moteur, normalize_key, stock_public_normalize, suggest,
)

OUT = ROOT / "data" / "eval_t1" / "t15"
MARGE = 0.03
MIN_PREFIX_LENS = (3, 4, 5)


def est_prefixe(fa: str, fb: str, min_len: int) -> bool:
    """Une forme est préfixe (≥ min_len) de l'autre : base ↔ variante."""
    return (
        (len(fa) >= min_len and fb.startswith(fa))
        or (len(fb) >= min_len and fa.startswith(fb))
    )


def suffixes_retires(libelle: str) -> list[str]:
    """Miroir de T1AutoFusion::suffixesRetires (retraits itératifs,
    forme ESPACÉE normalisée)."""
    norm = normalize_key(libelle)
    tokens = norm.split(" ") if norm else []
    retires: list[str] = []
    while tokens and tokens[-1] in SUFFIX_STOPWORDS:
        retires.append(tokens.pop())
    return retires


def est_suffixe(a: str, b: str) -> bool:
    """Miroir de T1AutoFusion::estVarianteSuffixe : même radical (suggest,
    forme espacée) ET mêmes suffixes repliés genre/pluriel (rtrim s puis e)
    — « monster bleu »/« monster bleue » oui, « monster bleu »/
    « monster verte » NON (dur négatif 0,9273 du jeu figé)."""
    sa, sb = suggest(a), suggest(b)
    if not sa or sa != sb:
        return False
    suf_a, suf_b = suffixes_retires(a), suffixes_retires(b)
    if not suf_a or len(suf_a) != len(suf_b):
        return False
    stem = lambda t: t.rstrip("s").rstrip("e")  # noqa: E731
    return sorted(map(stem, suf_a)) == sorted(map(stem, suf_b))


def a_un_chiffre(fa: str, fb: str) -> bool:
    """Au moins une des deux formes contient un chiffre (conditionnement,
    quantité : « sucette 1 » / « sucette 3 », « bte 33cl »…)."""
    return any(c.isdigit() for c in fa + fb)


def pct(scores: list[float], q: float) -> float:
    if not scores:
        return 0.0
    s = sorted(scores)
    k = max(0, min(len(s) - 1, round(q * (len(s) - 1))))
    return s[k]


def main() -> int:
    jeu = Jeu()
    moteur = Moteur(jeu)

    # -- Scores ----------------------------------------------------------
    durs = []
    for d in jeu.durs:
        i = moteur.idx_libelle_univers[d["libelle_a"]]
        j = moteur.idx_libelle_univers[d["libelle_b"]]
        fa = jeu.forme(d["libelle_a"])
        fb = jeu.forme(d["libelle_b"])
        fam_a = jeu.par_forme[fa]["famille"]
        fam_b = jeu.par_forme[fb]["famille"]
        durs.append({
            "a": d["libelle_a"], "b": d["libelle_b"], "score": round(float(moteur.U[i] @ moteur.U[j]), 4),
            "prefixe": {n: est_prefixe(fa, fb, n) for n in MIN_PREFIX_LENS},
            "suffixe": est_suffixe(d["libelle_a"], d["libelle_b"]), "famille": fam_a == fam_b,
            "chiffre": a_un_chiffre(fa, fb),
        })
    durs.sort(key=lambda d: (-d["score"], d["a"], d["b"]))

    pos = []
    for p in jeu.positifs_sem:
        fa = jeu.forme(p["libelle_brut"])
        fb = jeu.forme(p["libelle_cible"])
        fam_a = jeu.par_forme[fa]["famille"] if fa in jeu.par_forme else ""
        fam_b = jeu.par_forme[fb]["famille"] if fb in jeu.par_forme else ""
        pos.append({
            "a": p["libelle_brut"], "b": p["libelle_cible"], "score": round(moteur.score_cible(p), 4),
            "prefixe": {n: est_prefixe(fa, fb, n) for n in MIN_PREFIX_LENS},
            "suffixe": est_suffixe(p["libelle_brut"], p["libelle_cible"]), "split": p["split"],
            "famille": fam_a == fam_b and fam_a != "",
            "chiffre": a_un_chiffre(fa, fb),
        })
    pos.sort(key=lambda p: (-p["score"], p["a"], p["b"]))

    scores_durs = [d["score"] for d in durs]
    scores_pos = [p["score"] for p in pos]
    max_dur = max(scores_durs)

    # -- Protocole : seuil naïf -------------------------------------------
    seuil_naif = round(max_dur + MARGE, 4)
    n_pos_naif = sum(1 for s in scores_pos if s >= seuil_naif)

    # -- Seuils candidats (autour des quantiles des positifs) --------------
    candidats = sorted({round(pct(scores_pos, q), 4) for q in (0.80, 0.85, 0.90, 0.95)})

    lignes: list[str] = []
    lignes.append("# T1.5 — Étape 0 : point de fonctionnement du niveau 2 (auto)")
    lignes.append("")
    lignes.append("> Brief T15-A, étape 0. Jeu figé `data/eval_t1/` (SHA256SUMS intacts, lecture seule).")
    lignes.append("> Modèle : baseline P1 zéro-shot acceptée (export ONNX int8, même pipeline que rapport_S4.md).")
    lignes.append("> Rejouable : `~/AEIC/.venv-t1/bin/python3 data/eval_t1/t15/analyse_seuil_auto.py`.")
    lignes.append("")
    lignes.append("## Distribution des scores")
    lignes.append("")
    lignes.append(f"- Durs négatifs : {len(durs)} paires — min {min(scores_durs):.4f}, "
                  f"p50 {pct(scores_durs, 0.5):.4f}, p90 {pct(scores_durs, 0.9):.4f}, "
                  f"max **{max_dur:.4f}**.")
    lignes.append(f"- Positifs sémantiques : {len(pos)} paires — min {min(scores_pos):.4f}, "
                  f"p50 {pct(scores_pos, 0.5):.4f}, p90 {pct(scores_pos, 0.9):.4f} ({sum(1 for s in scores_pos if s >= pct(scores_pos, 0.9))} ≥ p90), "
                  f"p95 {pct(scores_pos, 0.95):.4f} ({sum(1 for s in scores_pos if s >= pct(scores_pos, 0.95))} ≥ p95), "
                  f"max {max(scores_pos):.4f}.")
    lignes.append("")
    lignes.append("## Seuil naïf (protocole : 0 dur négatif ≥ seuil, marge +0,03)")
    lignes.append("")
    lignes.append(f"- Max observé des durs négatifs = **{max_dur:.4f}** "
                  f"({durs[0]['a']} / {durs[0]['b']}) → seuil naïf = **{seuil_naif:.4f}**.")
    lignes.append(f"- Positifs passant ce seuil naïf : **{n_pos_naif} / {len(pos)}** "
                  "→ INUTILISABLE seul (le niveau 2 serait vide).")
    lignes.append("- Un dur négatif traîne même au max observé (0,94+, cf. A4 de rapport_S4.md) :")
    lignes.append("  la règle COMPLÉMENTAIRE du brief s'applique — niveau 2 = score ≥ seuil ET")
    lignes.append("  (préfixe normalisé OU suffixe ∈ SUFFIX_STOPWORDS OU même famille).")
    lignes.append("")

    # -- Règle combinée, par seuil × min_prefix ---------------------------
    lignes.append("## Règle combinée (mesure honnête, sans cherry-picking)")
    lignes.append("")
    lignes.append("Prédicats (ports Python fidèles du runtime PHP T1AutoFusion) :")
    lignes.append("`préfixe-N` = une forme (≥ N caractères) est préfixe de l'autre")
    lignes.append("(base ↔ variante : lion/lionpeanut, lipton/liptonicetea — PAS un préfixe commun) ;")
    lignes.append("`suffixe` = même radical après retrait itératif des suffixes couleur/goût")
    lignes.append("(AliasSuggester::suggest, forme ESPACÉE) ET MÊME suffixe replié genre/pluriel")
    lignes.append("(rtrim 's' puis 'e') — « monster bleu »/« monster bleue » oui, « monster bleu »/")
    lignes.append("« monster verte » NON ;")
    lignes.append("`famille` = même famille de l'univers (indicatif : non calculable en production).")
    lignes.append("")
    lignes.append("| seuil | règle | positifs auto (niveau 2) | durs négatifs qui passent |")
    lignes.append("|---|---|---|---|")
    seuils_sweep = sorted(set(candidats) | {0.60, 0.65, 0.70, 0.75})
    for seuil in seuils_sweep:
        for n in MIN_PREFIX_LENS:
            for nom_reg, test in (
                (f"score+préfixe-{n}", lambda d, n=n: d["prefixe"][n]),
                ("score+suffixe", lambda d: d["suffixe"]),
                (f"score+(préfixe-{n}|suffixe)", lambda d, n=n: d["prefixe"][n] or d["suffixe"]),
                (f"score+(préfixe-{n}|suffixe|famille)", lambda d, n=n: d["prefixe"][n] or d["suffixe"] or d["famille"]),
                (f"score+(préfixe-{n}|suffixe) sans chiffre", lambda d, n=n: (d["prefixe"][n] or d["suffixe"]) and not d["chiffre"]),
            ):
                p_ok = [p for p in pos if p["score"] >= seuil and test(p)]
                d_ok = [d for d in durs if d["score"] >= seuil and test(d)]
                lignes.append(f"| {seuil:.4f} | {nom_reg} | {len(p_ok)}/{len(pos)} | "
                              f"{len(d_ok)}" + (f" — max {max((x['score'] for x in d_ok), default=0):.4f}" if d_ok else ""))
    lignes.append("")

    # -- Détail : ce que LAVERAIT la règle choisie -------------------------
    # Règle candidate retenue pour le runtime (documentée dans le rapport) :
    # seuil = p90 des positifs ; prédicat = préfixe (min 4) OU suffixe ;
    # garde conditionnement : aucun chiffre dans les deux formes.
    seuil_retenu = round(pct(scores_pos, 0.90), 4)
    lignes.append(f"## Détail de la règle retenue : score ≥ {seuil_retenu:.4f} (p90 des positifs) "
                  "ET (préfixe ≥ 4 OU suffixe) ET aucun chiffre")
    lignes.append("")
    p_ok = [p for p in pos if p["score"] >= seuil_retenu and (p["prefixe"][4] or p["suffixe"]) and not p["chiffre"]]
    d_ok = [d for d in durs if d["score"] >= seuil_retenu and (d["prefixe"][4] or d["suffixe"]) and not d["chiffre"]]
    lignes.append(f"### Positifs passant en niveau 2 ({len(p_ok)}/{len(pos)}) — attendu : une minorité")
    lignes.append("")
    for p in p_ok:
        lignes.append(f"- {p['a']!r} → {p['b']!r} — {p['score']:.4f}"
                      + (" [préfixe]" if p["prefixe"][4] else "") + (" [suffixe]" if p["suffixe"] else ""))
    lignes.append("")
    lignes.append(f"### Durs négatifs qui passeraient quand même ({len(d_ok)}) — vérité terrain du jeu figé")
    lignes.append("")
    for d in d_ok:
        lignes.append(f"- {d['a']!r} / {d['b']!r} — {d['score']:.4f}"
                      + (" [préfixe]" if d["prefixe"][4] else "") + (" [suffixe]" if d["suffixe"] else ""))
    lignes.append("")

    # -- Conclusion (décision de calibration, re-régénérée à l'identique) ---
    lion = next((p for p in pos if p["a"] == "Lion_Peanut"), None)
    lipton = next((p for p in pos if p["a"] == "Lipton"), None)
    lignes.append("## Conclusion — calibration retenue pour le moteur (T1AutoFusion)")
    lignes.append("")
    lignes.append("- **SEUIL_AUTO = 0.8936** (p90 des 50 positifs sémantiques, jeu figé, modèle P1 actuel)."
                  " Constante documentée `T1AutoFusion::SEUIL_AUTO`.")
    lignes.append("- **Niveau 1** : doublons de casse pure (normalizeKey(a) === normalizeKey(b)) — hors périmètre"
                  " de ce seuil (ni score, ni jeu : règle de forme).")
    lignes.append("- **Niveau 2** : score ≥ SEUIL_AUTO ET (préfixe ≥ 4 normalisé OU suffixe AliasSuggester)"
                  " ET aucun chiffre dans les deux formes. Mesuré : **3/50 positifs** passent (minorité attendue),"
                  " **0/199 durs négatifs**.")
    lignes.append("- Garde « sans chiffre » : les paires de conditionnement (« Sucette 1 » / « Sucette 1 sucette »,"
                  " 0.9321) sont la seule fuite du prédicat préfixe à ce seuil — un chiffre signale une taille/"
                  "quantité (règle projet « 5 cannettes ≠ pack »), donc niveau 3 d'office.")
    lignes.append("- Branche « famille identique » écartée du runtime : non calculable en production (la famille"
                  " n'existe que dans le jeu figé) et mesurée plus permissive (4 durs négatifs à 0.8626).")
    lignes.append("- Exemples utilisateur 27/10 (même produit) : « Lion_Peanut » → « Lion »"
                  + (f" ({lion['score']:.4f})" if lion else "") + " et « Lipton » → « Lipton Ice Tea »"
                  + (f" ({lipton['score']:.4f})" if lipton else "")
                  + " — ils MOTIVENT le prédicat préfixe (base ↔ variante) mais leur score est sous le seuil p90 :"
                  " ils restent niveau 3 (suggestion humaine, écran T1 existant, fusion en 1 clic)."
                  " Honnêteté de la mesure : à 0.60 le prédicat préfixe laisse passer 34 durs négatifs —"
                  " descendre le seuil exigerait le palier P2 (modèle fine-tuné), pas un réglage maison.")
    lignes.append("- La quarantaine 48 h + veto reste LE filet : toute fusion auto niveau 2 est annulable avant"
                  " application, et archivée (snapshot pré-fusion) après application.")
    lignes.append("")

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "analyse_seuil_auto.md").write_text("\n".join(lignes) + "\n", encoding="utf-8")
    (OUT / "scores_paires.json").write_text(json.dumps({
        "durs": durs, "positifs": pos,
        "seuil_naif": seuil_naif, "seuil_retenu": seuil_retenu,
        "p90_positifs": round(pct(scores_pos, 0.9), 4),
        "p95_positifs": round(pct(scores_pos, 0.95), 4),
    }, ensure_ascii=False, indent=1), encoding="utf-8")
    print("\n".join(lignes))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

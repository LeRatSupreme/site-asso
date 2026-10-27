# T1.5 — Étape 0 : point de fonctionnement du niveau 2 (auto)

> Brief T15-A, étape 0. Jeu figé `data/eval_t1/` (SHA256SUMS intacts, lecture seule).
> Modèle : baseline P1 zéro-shot acceptée (export ONNX int8, même pipeline que rapport_S4.md).
> Rejouable : `~/AEIC/.venv-t1/bin/python3 data/eval_t1/t15/analyse_seuil_auto.py`.

## Distribution des scores

- Durs négatifs : 199 paires — min 0.2508, p50 0.5779, p90 0.7841, max **0.9377**.
- Positifs sémantiques : 50 paires — min 0.3989, p50 0.7892, p90 0.8936 (6 ≥ p90), p95 0.9260 (3 ≥ p95), max 0.9737.

## Seuil naïf (protocole : 0 dur négatif ≥ seuil, marge +0,03)

- Max observé des durs négatifs = **0.9377** (Soiree bowling (2 parties / 1 personne) / Soiree bowling ( 2 personnes )) → seuil naïf = **0.9677**.
- Positifs passant ce seuil naïf : **1 / 50** → INUTILISABLE seul (le niveau 2 serait vide).
- Un dur négatif traîne même au max observé (0,94+, cf. A4 de rapport_S4.md) :
  la règle COMPLÉMENTAIRE du brief s'applique — niveau 2 = score ≥ seuil ET
  (préfixe normalisé OU suffixe ∈ SUFFIX_STOPWORDS OU même famille).

## Règle combinée (mesure honnête, sans cherry-picking)

Prédicats (ports Python fidèles du runtime PHP T1AutoFusion) :
`préfixe-N` = une forme (≥ N caractères) est préfixe de l'autre
(base ↔ variante : lion/lionpeanut, lipton/liptonicetea — PAS un préfixe commun) ;
`suffixe` = même radical après retrait itératif des suffixes couleur/goût
(AliasSuggester::suggest, forme ESPACÉE) ET MÊME suffixe replié genre/pluriel
(rtrim 's' puis 'e') — « monster bleu »/« monster bleue » oui, « monster bleu »/
« monster verte » NON ;
`famille` = même famille de l'univers (indicatif : non calculable en production).

| seuil | règle | positifs auto (niveau 2) | durs négatifs qui passent |
|---|---|---|---|
| 0.6000 | score+préfixe-3 | 26/50 | 34 — max 0.9321
| 0.6000 | score+suffixe | 2/50 | 0
| 0.6000 | score+(préfixe-3|suffixe) | 26/50 | 34 — max 0.9321
| 0.6000 | score+(préfixe-3|suffixe|famille) | 31/50 | 90 — max 0.9377
| 0.6000 | score+(préfixe-3|suffixe) sans chiffre | 17/50 | 28 — max 0.8854
| 0.6000 | score+préfixe-4 | 26/50 | 34 — max 0.9321
| 0.6000 | score+suffixe | 2/50 | 0
| 0.6000 | score+(préfixe-4|suffixe) | 26/50 | 34 — max 0.9321
| 0.6000 | score+(préfixe-4|suffixe|famille) | 31/50 | 90 — max 0.9377
| 0.6000 | score+(préfixe-4|suffixe) sans chiffre | 17/50 | 28 — max 0.8854
| 0.6000 | score+préfixe-5 | 24/50 | 34 — max 0.9321
| 0.6000 | score+suffixe | 2/50 | 0
| 0.6000 | score+(préfixe-5|suffixe) | 24/50 | 34 — max 0.9321
| 0.6000 | score+(préfixe-5|suffixe|famille) | 30/50 | 90 — max 0.9377
| 0.6000 | score+(préfixe-5|suffixe) sans chiffre | 15/50 | 28 — max 0.8854
| 0.6500 | score+préfixe-3 | 24/50 | 32 — max 0.9321
| 0.6500 | score+suffixe | 1/50 | 0
| 0.6500 | score+(préfixe-3|suffixe) | 24/50 | 32 — max 0.9321
| 0.6500 | score+(préfixe-3|suffixe|famille) | 27/50 | 66 — max 0.9377
| 0.6500 | score+(préfixe-3|suffixe) sans chiffre | 15/50 | 26 — max 0.8854
| 0.6500 | score+préfixe-4 | 24/50 | 32 — max 0.9321
| 0.6500 | score+suffixe | 1/50 | 0
| 0.6500 | score+(préfixe-4|suffixe) | 24/50 | 32 — max 0.9321
| 0.6500 | score+(préfixe-4|suffixe|famille) | 27/50 | 66 — max 0.9377
| 0.6500 | score+(préfixe-4|suffixe) sans chiffre | 15/50 | 26 — max 0.8854
| 0.6500 | score+préfixe-5 | 22/50 | 32 — max 0.9321
| 0.6500 | score+suffixe | 1/50 | 0
| 0.6500 | score+(préfixe-5|suffixe) | 22/50 | 32 — max 0.9321
| 0.6500 | score+(préfixe-5|suffixe|famille) | 26/50 | 66 — max 0.9377
| 0.6500 | score+(préfixe-5|suffixe) sans chiffre | 13/50 | 26 — max 0.8854
| 0.7000 | score+préfixe-3 | 24/50 | 27 — max 0.9321
| 0.7000 | score+suffixe | 1/50 | 0
| 0.7000 | score+(préfixe-3|suffixe) | 24/50 | 27 — max 0.9321
| 0.7000 | score+(préfixe-3|suffixe|famille) | 26/50 | 47 — max 0.9377
| 0.7000 | score+(préfixe-3|suffixe) sans chiffre | 15/50 | 21 — max 0.8854
| 0.7000 | score+préfixe-4 | 24/50 | 27 — max 0.9321
| 0.7000 | score+suffixe | 1/50 | 0
| 0.7000 | score+(préfixe-4|suffixe) | 24/50 | 27 — max 0.9321
| 0.7000 | score+(préfixe-4|suffixe|famille) | 26/50 | 47 — max 0.9377
| 0.7000 | score+(préfixe-4|suffixe) sans chiffre | 15/50 | 21 — max 0.8854
| 0.7000 | score+préfixe-5 | 22/50 | 27 — max 0.9321
| 0.7000 | score+suffixe | 1/50 | 0
| 0.7000 | score+(préfixe-5|suffixe) | 22/50 | 27 — max 0.9321
| 0.7000 | score+(préfixe-5|suffixe|famille) | 25/50 | 47 — max 0.9377
| 0.7000 | score+(préfixe-5|suffixe) sans chiffre | 13/50 | 21 — max 0.8854
| 0.7500 | score+préfixe-3 | 22/50 | 15 — max 0.9321
| 0.7500 | score+suffixe | 1/50 | 0
| 0.7500 | score+(préfixe-3|suffixe) | 22/50 | 15 — max 0.9321
| 0.7500 | score+(préfixe-3|suffixe|famille) | 24/50 | 26 — max 0.9377
| 0.7500 | score+(préfixe-3|suffixe) sans chiffre | 15/50 | 10 — max 0.8854
| 0.7500 | score+préfixe-4 | 22/50 | 15 — max 0.9321
| 0.7500 | score+suffixe | 1/50 | 0
| 0.7500 | score+(préfixe-4|suffixe) | 22/50 | 15 — max 0.9321
| 0.7500 | score+(préfixe-4|suffixe|famille) | 24/50 | 26 — max 0.9377
| 0.7500 | score+(préfixe-4|suffixe) sans chiffre | 15/50 | 10 — max 0.8854
| 0.7500 | score+préfixe-5 | 20/50 | 15 — max 0.9321
| 0.7500 | score+suffixe | 1/50 | 0
| 0.7500 | score+(préfixe-5|suffixe) | 20/50 | 15 — max 0.9321
| 0.7500 | score+(préfixe-5|suffixe|famille) | 23/50 | 26 — max 0.9377
| 0.7500 | score+(préfixe-5|suffixe) sans chiffre | 13/50 | 10 — max 0.8854
| 0.8626 | score+préfixe-3 | 6/50 | 2 — max 0.9321
| 0.8626 | score+suffixe | 0/50 | 0
| 0.8626 | score+(préfixe-3|suffixe) | 6/50 | 2 — max 0.9321
| 0.8626 | score+(préfixe-3|suffixe|famille) | 6/50 | 4 — max 0.9377
| 0.8626 | score+(préfixe-3|suffixe) sans chiffre | 5/50 | 1 — max 0.8854
| 0.8626 | score+préfixe-4 | 6/50 | 2 — max 0.9321
| 0.8626 | score+suffixe | 0/50 | 0
| 0.8626 | score+(préfixe-4|suffixe) | 6/50 | 2 — max 0.9321
| 0.8626 | score+(préfixe-4|suffixe|famille) | 6/50 | 4 — max 0.9377
| 0.8626 | score+(préfixe-4|suffixe) sans chiffre | 5/50 | 1 — max 0.8854
| 0.8626 | score+préfixe-5 | 5/50 | 2 — max 0.9321
| 0.8626 | score+suffixe | 0/50 | 0
| 0.8626 | score+(préfixe-5|suffixe) | 5/50 | 2 — max 0.9321
| 0.8626 | score+(préfixe-5|suffixe|famille) | 5/50 | 4 — max 0.9377
| 0.8626 | score+(préfixe-5|suffixe) sans chiffre | 4/50 | 1 — max 0.8854
| 0.8841 | score+préfixe-3 | 4/50 | 2 — max 0.9321
| 0.8841 | score+suffixe | 0/50 | 0
| 0.8841 | score+(préfixe-3|suffixe) | 4/50 | 2 — max 0.9321
| 0.8841 | score+(préfixe-3|suffixe|famille) | 4/50 | 4 — max 0.9377
| 0.8841 | score+(préfixe-3|suffixe) sans chiffre | 4/50 | 1 — max 0.8854
| 0.8841 | score+préfixe-4 | 4/50 | 2 — max 0.9321
| 0.8841 | score+suffixe | 0/50 | 0
| 0.8841 | score+(préfixe-4|suffixe) | 4/50 | 2 — max 0.9321
| 0.8841 | score+(préfixe-4|suffixe|famille) | 4/50 | 4 — max 0.9377
| 0.8841 | score+(préfixe-4|suffixe) sans chiffre | 4/50 | 1 — max 0.8854
| 0.8841 | score+préfixe-5 | 3/50 | 2 — max 0.9321
| 0.8841 | score+suffixe | 0/50 | 0
| 0.8841 | score+(préfixe-5|suffixe) | 3/50 | 2 — max 0.9321
| 0.8841 | score+(préfixe-5|suffixe|famille) | 3/50 | 4 — max 0.9377
| 0.8841 | score+(préfixe-5|suffixe) sans chiffre | 3/50 | 1 — max 0.8854
| 0.8936 | score+préfixe-3 | 3/50 | 1 — max 0.9321
| 0.8936 | score+suffixe | 0/50 | 0
| 0.8936 | score+(préfixe-3|suffixe) | 3/50 | 1 — max 0.9321
| 0.8936 | score+(préfixe-3|suffixe|famille) | 3/50 | 2 — max 0.9377
| 0.8936 | score+(préfixe-3|suffixe) sans chiffre | 3/50 | 0
| 0.8936 | score+préfixe-4 | 3/50 | 1 — max 0.9321
| 0.8936 | score+suffixe | 0/50 | 0
| 0.8936 | score+(préfixe-4|suffixe) | 3/50 | 1 — max 0.9321
| 0.8936 | score+(préfixe-4|suffixe|famille) | 3/50 | 2 — max 0.9377
| 0.8936 | score+(préfixe-4|suffixe) sans chiffre | 3/50 | 0
| 0.8936 | score+préfixe-5 | 2/50 | 1 — max 0.9321
| 0.8936 | score+suffixe | 0/50 | 0
| 0.8936 | score+(préfixe-5|suffixe) | 2/50 | 1 — max 0.9321
| 0.8936 | score+(préfixe-5|suffixe|famille) | 2/50 | 2 — max 0.9377
| 0.8936 | score+(préfixe-5|suffixe) sans chiffre | 2/50 | 0
| 0.9260 | score+préfixe-3 | 0/50 | 1 — max 0.9321
| 0.9260 | score+suffixe | 0/50 | 0
| 0.9260 | score+(préfixe-3|suffixe) | 0/50 | 1 — max 0.9321
| 0.9260 | score+(préfixe-3|suffixe|famille) | 0/50 | 2 — max 0.9377
| 0.9260 | score+(préfixe-3|suffixe) sans chiffre | 0/50 | 0
| 0.9260 | score+préfixe-4 | 0/50 | 1 — max 0.9321
| 0.9260 | score+suffixe | 0/50 | 0
| 0.9260 | score+(préfixe-4|suffixe) | 0/50 | 1 — max 0.9321
| 0.9260 | score+(préfixe-4|suffixe|famille) | 0/50 | 2 — max 0.9377
| 0.9260 | score+(préfixe-4|suffixe) sans chiffre | 0/50 | 0
| 0.9260 | score+préfixe-5 | 0/50 | 1 — max 0.9321
| 0.9260 | score+suffixe | 0/50 | 0
| 0.9260 | score+(préfixe-5|suffixe) | 0/50 | 1 — max 0.9321
| 0.9260 | score+(préfixe-5|suffixe|famille) | 0/50 | 2 — max 0.9377
| 0.9260 | score+(préfixe-5|suffixe) sans chiffre | 0/50 | 0

## Détail de la règle retenue : score ≥ 0.8936 (p90 des positifs) ET (préfixe ≥ 4 OU suffixe) ET aucun chiffre

### Positifs passant en niveau 2 (3/50) — attendu : une minorité

- 'Coca' → 'Coca Cola' — 0.9228 [préfixe]
- 'Monster rose (punch)' → 'Monster Rose' — 0.9066 [préfixe]
- 'Fanta' → 'Fanta Orange' — 0.8936 [préfixe]

### Durs négatifs qui passeraient quand même (0) — vérité terrain du jeu figé


## Conclusion — calibration retenue pour le moteur (T1AutoFusion)

- **SEUIL_AUTO = 0.8936** (p90 des 50 positifs sémantiques, jeu figé, modèle P1 actuel). Constante documentée `T1AutoFusion::SEUIL_AUTO`.
- **Niveau 1** : doublons de casse pure (normalizeKey(a) === normalizeKey(b)) — hors périmètre de ce seuil (ni score, ni jeu : règle de forme).
- **Niveau 2** : score ≥ SEUIL_AUTO ET (préfixe ≥ 4 normalisé OU suffixe AliasSuggester) ET aucun chiffre dans les deux formes. Mesuré : **3/50 positifs** passent (minorité attendue), **0/199 durs négatifs**.
- Garde « sans chiffre » : les paires de conditionnement (« Sucette 1 » / « Sucette 1 sucette », 0.9321) sont la seule fuite du prédicat préfixe à ce seuil — un chiffre signale une taille/quantité (règle projet « 5 cannettes ≠ pack »), donc niveau 3 d'office.
- Branche « famille identique » écartée du runtime : non calculable en production (la famille n'existe que dans le jeu figé) et mesurée plus permissive (4 durs négatifs à 0.8626).
- Exemples utilisateur 27/10 (même produit) : « Lion_Peanut » → « Lion » (0.7892) et « Lipton » → « Lipton Ice Tea » (0.6488) — ils MOTIVENT le prédicat préfixe (base ↔ variante) mais leur score est sous le seuil p90 : ils restent niveau 3 (suggestion humaine, écran T1 existant, fusion en 1 clic). Honnêteté de la mesure : à 0.60 le prédicat préfixe laisse passer 34 durs négatifs — descendre le seuil exigerait le palier P2 (modèle fine-tuné), pas un réglage maison.
- La quarantaine 48 h + veto reste LE filet : toute fusion auto niveau 2 est annulable avant application, et archivée (snapshot pré-fusion) après application.


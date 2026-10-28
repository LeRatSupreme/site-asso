# Manifeste P2 v2 — paires d'entraînement augmentées ×2-3 (LIBELLÉS SEULS)

> Vague 2 « P2-ter : ré-entraînement du détecteur avec plus de données » · brief A v2 (dev-factures).
> Source : jeu figé `data/eval_t1/` (INTACT, `sha256sum -c data/eval_t1/SHA256SUMS` 12/12 avant/après).
> Analyse d'erreurs d'abord : `analyse_erreurs.md` (5 paires TEST ratées recall@3 + 57 durs négatifs ≥ 0,6618).
> Écrit par `scripts/t1_augment.py --v2` — tout va dans `data/eval_t1/p2/v2/` UNIQUEMENT.
>
> Les sorties v1 (`../train_pairs.csv`, `../kaggle_upload/`) sont RÉGÉNÉRÉES À L'IDENTIQUE par
> le script sans `--v2` (rétro-compatibilité, preuve par double exécution).

## Reproductibilité

- Graines : **train 42** (conservée), **validation 4242** (passe complète puis échantillon).
- Aucun horodatage dans CSV/manifeste, tris et tirages déterministes : deux exécutions → octets identiques.
- Commande de régénération : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2`
- Preuves relançables : `… --v2 --verifier` (anti-triche étendu) et `… --v2 --test-negatif` (injection).

## Source des augmentations (TRAIN uniquement)

- **30 paires positives sémantiques du split TRAIN** (les 20 paires TEST ne servent jamais à apprendre).
- Typologies v1 RENFORCÉES + 8 NOUVELLES typologies issues de l'analyse d'erreurs S4' :

| typologie | tirages/paire | v2 | échec/mécanisme visé |
|---|---:|:-:|---|
| casse_mele | 32 |  | (v1, renforcée) |
| separateurs | 26 |  | (v1, renforcée) |
| concatenation | 24 |  | (v1, renforcée) |
| faute_1_2_editions | 56 |  | (v1, renforcée) |
| suffixe_gout_couleur | 24 |  | (v1, renforcée) |
| facture_long | 36 |  | (v1, renforcée) |
| doublage_mots | 14 |  | (v1, renforcée) |
| couleur_traduite | 18 | ✚ | « RED BULL BLUE BOITE 25CL » et « Redbull blue » → « Redbull Bleu » : couleur traduite EN↔FR |
| marque_scindee | 16 | ✚ | « RED BULL … » (appellations) vs « Redbull … » (identités) : marque scindée/rejointe |
| inversion_mots | 12 | ✚ | ordre des mots flottant à la saisie (renforcement générique A1) |
| abreviation_globale | 18 | ✚ | « OASIS POM CASS FRAM SLIM 33CL » → « Oasis Pomme Cassis Framboise » (TEST réussi) : style d'abréviation facture |
| zero_edition | 20 | ✚ | « MONSTER ULTRA ZERO (BTE 50CL) » → « Monster Blanche » : qualificatifs de gamme énergie amovibles |
| evenement_parentheses | 22 | ✚ | « Soiree bowling (2 parties / 1 personne) » → « bowling » : libellé événement long |
| conditionnement_multipack | 22 | ✚ | durs négatifs intra-famille + échec « MONSTER ULTRA ZERO BTE 50CL » : conditionnement X/Y |
| accents_globaux | 16 | ✚ | « Gauffre »/« gaufre », accents de saisie (renforcement générique A1/A3) |
| origine (paire réelle) | 1 | | vérité terrain du jeu figé |

## Volumes réellement obtenus (train v2)

| typologie | tirées | retenues | filtrées TEST | collision TEST | filtrées résiduel | conflit identité | collision sœur | doublons | vides |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| origine | 30 | 30 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| casse_mele | 960 | 424 | 0 | 21 | 50 | 282 | 0 | 183 | 0 |
| separateurs | 780 | 168 | 0 | 16 | 35 | 238 | 0 | 323 | 0 |
| concatenation | 720 | 103 | 2 | 14 | 39 | 222 | 0 | 340 | 0 |
| faute_1_2_editions | 1680 | 1214 | 0 | 33 | 22 | 125 | 23 | 263 | 0 |
| suffixe_gout_couleur | 720 | 556 | 0 | 0 | 0 | 12 | 0 | 152 | 0 |
| facture_long | 1080 | 514 | 0 | 7 | 0 | 7 | 12 | 540 | 0 |
| doublage_mots | 420 | 113 | 0 | 9 | 0 | 0 | 32 | 266 | 0 |
| couleur_traduite | 540 | 30 | 0 | 3 | 45 | 63 | 0 | 399 | 0 |
| marque_scindee | 480 | 17 | 0 | 8 | 33 | 138 | 0 | 284 | 0 |
| inversion_mots | 360 | 67 | 0 | 7 | 22 | 36 | 6 | 222 | 0 |
| abreviation_globale | 540 | 251 | 0 | 0 | 29 | 16 | 0 | 244 | 0 |
| zero_edition | 600 | 120 | 0 | 6 | 46 | 63 | 0 | 365 | 0 |
| evenement_parentheses | 660 | 398 | 0 | 3 | 0 | 0 | 4 | 255 | 0 |
| conditionnement_multipack | 660 | 417 | 0 | 0 | 0 | 0 | 0 | 243 | 0 |
| accents_globaux | 480 | 173 | 0 | 11 | 34 | 94 | 0 | 168 | 0 |
| **total** | 10710 | **4595** | 2 | 138 | 355 | 1296 | 77 | 4247 | 0 |

- `train_pairs.csv` : **4595 lignes** (30 `origine` + 4565 synthétiques) — cible vague 2 : 4 000-6 000 → ATTEINTE.
- `val_pairs.csv` : **400 lignes** (cible ~400) — passe complète graine 4242 (2617 retenues) puis échantillon déterministe (indices seedés 4242) ; libellés exacts disjoints du train ; couvre 26/26 cibles TRAIN (400 synthétiques + 0 origine).

## Filtrage anti-fuite — v1 + ÉTENDU (contrainte vague 2 n°3)

1. formes interdites TEST (exact + normalisées, règle v1 inchangée) ;
2. formes interdites résiduels §1.3 (règle v1 inchangée) ;
3. conflit d'identité par ÉGALITÉ de forme normalisée (règle v1 inchangée) ;
4. **NOUVEAU — collision par CONTENANCE** : toute variante dont la forme normalisée contient
   (sous-chaîne NON-préfixe) une forme TEST (`collision_test`) ou une forme d'une AUTRE
   identité de la même famille que la cible (`collision_soeur`) est rejetée — c'est le
   garde-fou spécifique des nouvelles typologies (ex. « RED BULL BLEU BOITE 25CL » contient
   « redbullbleu » → ne peut JAMAIS viser « Redbull Seablue ») ;
5. exceptions documentées : la forme contenue est un PRÉFIXE de la cible (marque partagée,
   ex. « coca » pour « cocacherry ») ou la forme du brut d'une paire TRAIN de même cible ;
6. dédoublonnage par libellé EXACT (règle v1 inchangée).

Exception v1 conservée : les lignes `origine` sont les paires TRAIN qualifiées du jeu figé, telles quelles.
Libellés concernés (figurent aussi dans residuels §1.3 comme étiquettes d'AUTRES relations) : « Lion » , « M&Ms » , « Sucette 1 sucette » , « gaufre ».

## Test négatif par injection (preuve exécutable)

[test-négatif] A) formes TEST exactes injectées : 40/40 rejetées (motif 'test')
[test-négatif] B) nouvelles typologies × paires TEST : 960 tirages → 555 rejetés + 405 fidèles à la paire + 0 ambigus
[test-négatif]    fidèles (ex.) : abreviation_globale:« BOW ( 2 PART ) BOITE 25CL »→« bowling » ; abreviation_globale:« BOWL ( 2 PART ) »→« bowling » ; abreviation_globale:« BOWL »→« bowling » ; abreviation_globale:« BOWL 25CL »→« bowling » ; abreviation_globale:« BOWL ( 2 PART ) 33CL X6 »→« bowling »
[test-négatif] C) probes de collision explicites : 10/10 détectés
[test-négatif] D) CSV corrompu par injection : ÉCHEC détecté (2 lignes TEST signalées)
[test-négatif] résultat : OK — ÉCHEC détecté partout

Relançable : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --v2 --test-negatif`

## ENGAGEMENT

- **Zéro libellé des 20 paires TEST** (ni ses formes normalisées, ni par contenance non-préfixe)
  n'apparaît dans `train_pairs.csv` ni `val_pairs.csv` — preuves : `--v2 --verifier` → OK, `--v2 --test-negatif` → OK.
- **Zéro forme d'une identité sœur** (même famille que la cible, contenance non-préfixe) dans les CSV.
- Les 20 paires TEST ne servent qu'à mesurer (S4″ à pareil à pareil), jamais à apprendre.
- Contenu : **libellés produits uniquement** (anonymisation D3) — aucun prix, date, quantité d'achat, fournisseur.

## SHA256 des livrables (stables entre deux exécutions)

```
d6babb64910a908d1fe5860efc68a56e2c7679e45c6250773c8b76d007ca520f  data/eval_t1/p2/v2/train_pairs.csv
5002037a1652b4ab54cbcef2c7dc45c9966d828512dabe80d9e41e307c014e71  data/eval_t1/p2/v2/val_pairs.csv
d6babb64910a908d1fe5860efc68a56e2c7679e45c6250773c8b76d007ca520f  data/eval_t1/p2/v2/kaggle_upload_v2/train_pairs.csv
5002037a1652b4ab54cbcef2c7dc45c9966d828512dabe80d9e41e307c014e71  data/eval_t1/p2/v2/kaggle_upload_v2/val_pairs.csv
f7549ab9be5e347922c70de08863fb8e48d61ac58fa9eb7095d4cb1ad37895fe  data/eval_t1/p2/v2/kaggle_upload_v2/dataset-metadata.json
```

## Dossier Kaggle — PRÊT, NON TRANSMIS

- `kaggle_upload_v2/` : `train_pairs.csv` + `val_pairs.csv` + `dataset-metadata.json` (MÊME id `adrienremond/aeic-t1-p2-pairs`, `isPrivate: true`) — le push créera une
  NOUVELLE VERSION du dataset existant (pas un nouveau dataset), lue telle quelle par le kernel v4
  (noms de fichiers inchangés `train_pairs.csv`/`val_pairs.csv`, colonnes `texte_variante,cible`).
- **Envoi interdit ici** : revue dev-secu OBLIGATOIRE des fichiers modifiés AVANT tout push (contrainte vague 2 n°4).


# Manifeste P2 — paires d'entraînement augmentées (LIBELLÉS SEULS)

> Mission T1-P2 « Entraînement bien fait » · spec `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` §4 palier 2.
> Source : jeu figé `data/eval_t1/` (INTACT, `sha256sum -c data/eval_t1/SHA256SUMS` vérifié).
> Écrit par `scripts/t1_augment.py` — tout va dans `data/eval_t1/p2/` UNIQUEMENT.

## Reproductibilité

- Graines : **train 42** (imposée), **validation 4242** (même générateur, graine différente).
- Aucun horodatage dans les sorties, tris et tirages déterministes : deux exécutions → octets identiques.
- Commande de régénération : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py`
- Preuve anti-triche relançable : `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --verifier`

## Source des augmentations

- **30 paires positives sémantiques du split TRAIN** (`positifs_semantiques.csv`,
  `split.json` groupes train). Les **20 paires TEST ne servent jamais à apprendre** (elles mesurent).
- Chaque paire est reprise telle quelle (typologie `origine`) puis augmentée par tirages :

| typologie | tirages/paire |
|---|---:|
| casse_mele | 16 |
| separateurs | 16 |
| concatenation | 10 |
| faute_1_2_editions | 24 |
| suffixe_gout_couleur | 12 |
| facture_long | 14 |
| doublage_mots | 10 |
| origine (paire réelle) | 1 |

Typologie = erreurs RÉELLES documentées (rapport S4 + bruts du jeu) : casse mélangée, séparateurs
`_-.()` insérés/retirés, concaténation sans espace, faute 1-2 éditions (substitution/insertion/
suppression/transposition + accents + 0↔O + suppression du 1er mot « pepper »), suffixe goût/couleur
(`SUFFIX_STOPWORDS` du port fidèle, miroir de `AliasSuggester::suggest`), libellé facture long autour
de la clé courte (queues/têtes d'emballage + préfixe marque), doublage de mots.

## Volumes réellement obtenus (train)

| typologie | tirées | retenues | filtrées TEST | filtrées résiduel | conflit identité | doublons | vides |
|---|---:|---:|---:|---:|---:|---:|---:|
| origine | 30 | 30 | 0 | 0 | 0 | 0 | 0 |
| casse_mele | 480 | 239 | 0 | 25 | 163 | 53 | 0 |
| separateurs | 480 | 136 | 0 | 24 | 146 | 174 | 0 |
| concatenation | 300 | 79 | 0 | 15 | 82 | 124 | 0 |
| faute_1_2_editions | 720 | 584 | 0 | 9 | 40 | 87 | 0 |
| suffixe_gout_couleur | 360 | 313 | 0 | 0 | 5 | 42 | 0 |
| facture_long | 420 | 288 | 0 | 0 | 1 | 131 | 0 |
| doublage_mots | 300 | 114 | 0 | 0 | 0 | 186 | 0 |
| **total** | 3090 | **1783** | 0 | 73 | 437 | 797 | 0 |

- `train_pairs.csv` : **1783 lignes** (30 `origine` + 1753 synthétiques), colonnes `texte_variante,cible`.
- `val_pairs.csv` : **178 lignes** (~10 % de 1783) — passe complète
  graine 4242 puis échantillon déterministe (indices seedés 4242) parmi les 1174 retenues de la passe ; libellés exacts disjoints du train ; couvre 26/26 cibles TRAIN.

## Filtrage anti-fuite (chaque variante synthétique)

1. forme interdite TEST : les 34 libellés des 20 paires TEST, en exact et en
   formes normalisées (62 formes `normalize_key`/`StockPublic::normalizeKey`) ;
2. forme interdite résiduels §1.3 : 22 libellés, mêmes formes normalisées ;
3. conflit d'identité : toute variante dont la forme normalisée est celle d'une AUTRE identité de
   l'univers (120 formes) est rejetée (elle enseignerait une étiquette fausse) ;
4. dédoublonnage par libellé EXACT, une même entrée ne visant qu'une seule cible.

NB : le dédoublonnage est au libellé exact (et non à la forme normalisée) car l'entrée du modèle
d'embeddings est la chaîne brute : « Lion_Peanut » et « Lion-Peanut » sont deux exemples distincts.
L'anti-triche (TEST / résiduels), elle, s'applique bien en formes normalisées.

**Exception documentée et verrouillée par `--verifier`** : les lignes `origine` sont les paires TRAIN
qualifiées du jeu figé, reprises telles quelles. Certains de leurs libellés figurent aussi dans
`residuels_a_qualifier.csv` comme étiquettes d'AUTRES relations non qualifiées — aucune relation
résiduelle n'est apprise. Libellés concernés : « Lion » , « M&Ms » , « Sucette 1 sucette » , « gaufre ».

## ENGAGEMENT

- **Zéro libellé des 20 paires TEST** (ni ses formes normalisées) n'apparaît dans `train_pairs.csv`
  ni `val_pairs.csv`, ni comme variante ni comme cible — preuve exécutable : `--verifier` → `OK`.
- **Zéro libellé des résiduels §1.3** n'est utilisé comme variante d'apprentissage (seule exception :
  les lignes `origine` du jeu figé, cf. ci-dessus).
- Les 20 paires TEST ne servent qu'à mesurer (rapport S4′ à pareil à pareil), jamais à apprendre.
- Contenu : **libellés produits uniquement** (anonymisation D3) — aucun prix, date, quantité, fournisseur.

## SHA256 des livrables (stables entre deux exécutions)

```
515d41d8a09f8d0721d6bec912a8d8b20fd702011798270a4255af2a9c7cbb52  data/eval_t1/p2/train_pairs.csv
f3fc84f9aa28daf255cd84d81bb6de586cbd603fde101cc39a817311ce76e8d4  data/eval_t1/p2/val_pairs.csv
515d41d8a09f8d0721d6bec912a8d8b20fd702011798270a4255af2a9c7cbb52  data/eval_t1/p2/kaggle_upload/train_pairs.csv
f3fc84f9aa28daf255cd84d81bb6de586cbd603fde101cc39a817311ce76e8d4  data/eval_t1/p2/kaggle_upload/val_pairs.csv
990a1b82a9618f3f4bfc850ff67284a37895559c445d370f8ba152c67eed2ca2  data/eval_t1/p2/kaggle_upload/dataset-metadata.json
```

## Dossier Kaggle — PRÊT, NON TRANSMIS

- `data/eval_t1/p2/kaggle_upload/` : copies de `train_pairs.csv` + `val_pairs.csv` +
  `dataset-metadata.json` (title `aeic-t1-p2-pairs`, dataset **privé** `isPrivate: true`,
  licence `OTHER`). Le champ `id` (`aeic/aeic-t1-p2-pairs`) porte un propriétaire à confirmer
  à l'envoi (aucun `~/.kaggle/kaggle.json` sur ce VPS — auth `access_token`).
- **Envoi interdit ici** : contrainte P2 — relecture dev-secu OBLIGATOIRE avant toute commande `kaggle`.


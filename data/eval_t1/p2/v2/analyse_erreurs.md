# Analyse d'erreurs S4′ — support de l'augmentation v2 (vague 2 « P2-ter »)

> Mission : vague 2, brief A v2 (dev-factures) · 2026-10-27 (VPS `/home/ubuntu/AEIC`).
> Source UNIQUE des chiffres : `data/eval_t1/p2/rapport_S4_prime_brut.md` (run machine du
> fine-tuné P2, ONNX int8, protocole A1–A6) et `data/eval_t1/p2/rapport_S4_prime.md`
> (comparaison à pareil à pareil). Rien n'est mesuré ici — tout est EXTRAIT.
> Cette analyse précède et pilote la génération `scripts/t1_augment.py --v2` (manifeste :
> `manifeste_v2.md`). Jeu figé `data/eval_t1/` INTACT.

## 1. Les 5 paires TEST ratées en recall@3 (A1 = 75,0 %, 15/20)

Chaque ligne : libellé brut → cible, meilleur candidat retenu par le modèle (score cosinus)
vs score de la vraie cible, puis mécanisme de l'erreur et typologie à renforcer.

| # | Paire TEST | Meilleur candidat (score) | Cible (score) | Mécanisme de l'erreur | Typologie à renforcer |
|---|---|---|---|---|---|
| 1 | « MONSTER ULTRA ZERO » → « Monster Blanche » | « Monster Noire » (0,794) | 0,495 | « ULTRA ZERO » est un qualificatif de GAMME (la canette Ultra blanche), pas une couleur : le modèle lit « zero/noire » comme un indice de couleur et ignore que Ultra Zero = édition blanche de Monster | `zero_edition` (qualificatifs amovibles ULTRA/EDITION sur familles énergie) |
| 2 | « MONSTER ULTRA ZERO BTE 50CL » → « Monster Blanche » | « Monster Noire » (0,676) | 0,399 | idem n°1 + emballage « BTE 50CL » qui éloigne encore la cible courte | `zero_edition` + `facture_long`/`conditionnement_multipack` |
| 3 | « RED BULL BLUE BOITE 25CL » → « Redbull Bleu » | « Redbull Blue » (0,785) | 0,565 | la couleur est TRADUITE (« blue » EN vs « Bleu » FR) : le modèle préfère l'identité littérale « Redbull Blue » (variante orthographique) à la traduction « Redbull Bleu » ; marque en 2 mots « RED BULL » vs identité « Redbull » | `couleur_traduite` + `marque_scindee` + `facture_long` |
| 4 | « Redbull blue » → « Redbull Bleu » | « Redbull Seablue » (0,746) | 0,609 | traduction couleur pure ; confusion renforcée par la sœur « Seablue » qui contient « blue » | `couleur_traduite` (le garde-fou `collision_soeur` interdit d'enseigner l'inverse) |
| 5 | « Soiree bowling (2 parties / 1 personne) » → « bowling » | « Soiree bowling ( 2 personnes ) » (0,943) | 0,734 | libellé ÉVÉNEMENT long (parenthèses, slash, quantités) : le modèle colle à l'autre libellé événement quasi identique (même préfixe « Soiree bowling ») au lieu de réduire à la clé courte | `evenement_parentheses` (modèles observés « (2 parties / 1 personne) », « ( 2 personnes ) », « x2 ») |

Les 15 paires TEST réussies ne sont pas détaillées ici (rapport machine, section A1) ;
les rattrapages du fine-tuné (« Bowling ( 2 parties ) », « COCA COLA BOITE FAT 33CL »,
« Gauffre choco ») montrent que les typologies v1 (facture longue, casse, faute) ont déjà
bien appris — la v2 renforce sans les délaisser (budgets v1 ×1,2-2,3).

## 2. Les 57 durs négatifs intra-famille ≥ seuil 0,6618 (A4 = 57/199, 28,6 %)

Répartition par famille (extraction machine du rapport S4′ brut) :

| famille | paires ≥ seuil | mécanisme dominant |
|---|---:|---|
| redbull | 12 | éditions/goûts d'une même marque (« Redbull » vs « red red »/« Ice »/« Winter »/« Bleu »/« zero »…) |
| fanta | 9 | goûts (Citron/Orange/Cassis/Exotique/Mangue) + base « Fanta » vs variantes |
| sucette | 8 | quantités/numéros (« sucette » vs « Sucette 1 »/« 3 »/« 1 sucette »/« 3 sucettes ») |
| minute | 6 | goûts (Orange/Pomme/MultiVitamine) + base « Minute Maid » |
| oasis | 6 | goûts (Tropical/pomme poire/Thémangue) + Ice Tea longs |
| monster | 4 | couleurs (Noire/Verte/Bleue/Rose/Blanche) |
| coca | 3 | base vs variantes (Cola/Zero/sans sucre/Cherry) |
| bowling (+soiree) | 3 | offres numérotées (« 2 parties », « 2personnes », « 1 personne ») |
| pepsi | 2 | « Pepsi » vs « sans sucre »/« Zero » |
| bonbon / chips / gaufre(+gauffre) | 1+1+2 | base vs variante qualifiée (« Bonbon Haribo », « Chips BBQ », « choco ») |

Top 7 (score ≥ 0,85) :

1. « Soiree bowling (2 parties / 1 personne) » / « Soiree bowling ( 2 personnes ) » — 0,9377
2. « Sucette 1 » / « Sucette 1 sucette » — 0,9321
3. « Minute Maid Orange » / « Minute Maid Pomme » — 0,8931
4. « Minute Maid » / « Minute Maid Pomme » — 0,8854
5. « Fanta » / « Fanta Exotique » — 0,8617
6. « Redbull » / « redbull red » — 0,8586
7. « Oasis Ice Tea » / « Oasis ice tea the mangue passion » — 0,8511

Tranches : 7 paires ≥ 0,85 · 19 entre 0,75 et 0,85 · 31 entre 0,6618 et 0,75.

**Pourquoi le modèle se trompe** : ces paires partagent la marque-préfixe et ne diffèrent
que par le SUFFIXE distinctif (goût, couleur, numéro, édition). Le jeu v1 (1 783 paires)
contient peu d'exemples où le suffixe est le SEUL discriminateur : les paires TRAIN
enseignent surtout « variante bruitée → clé » mais rarement « clé A ≠ clé B sœurs ».
La perte MNRL du kernel sépare les identités par NÉGATIFS EN BATCH : plus le train couvre
d'exemples par famille (variantes décorées de cibles SŒURS distinctes dans un même batch),
plus le modèle apprend que le suffixe goût/couleur/numéro EST l'identité. C'est l'effet
recherché en multipliant les typologies de décoration (v1 renforcées + `zero_edition`,
`conditionnement_multipack`, `evenement_parentheses`) sur les 30 paires TRAIN, qui couvrent
les familles redbull (5 paires), monster (4), oasis (2), minute (2), fanta (1), pepsi (1),
coca-cherry (1), sucette (1), bonbon-haribo (1), chips (1), gaufre (2).
**Limite honnête** : MNRL n'accepte que (ancre, positif) — aucune paire négative étiquetée
n'est ajoutée ici ; l'effet sur A4 est INDIRECT (négatifs en batch). Les familles 100 % TEST
(bowling/soiree) n'ont AUCUN ancrage TRAIN possible (split anti-fuite par groupe) : la v2
renforce la TYPOLOGIE de forme (parenthèses/slash/quantités), pas la paire elle-même.

## 3. Vocabulaire réel de l'univers mobilisé par les nouvelles typologies

- **Couleurs FR/EN coexistantes** : « Redbull Bleu » (FR) vs « Redbull Blue » (EN, identité
  distincte !), « Monster Blanche/Noire/Verte/Bleue/Rose » (FR), « Bueno white », « Red Bull
  … WHITE/BLUE/SEA BLUE » (appellations). D'où `couleur_traduite` : traduire UNIQUEMENT les
  mots couleur présents dans la paire (jamais injecter la couleur d'une autre identité —
  garde-fou `collision_soeur` en dernier recours).
- **Marques scindées/rejointes** : « RED BULL ICE BOITE 25CL » (brut) vs « Redbull Ice »
  (cible) ; « MinuteMaid_Pomme »/« minutemaid » vs « Minute Maid … ». D'où `marque_scindee`.
- **Qualificatifs de gamme** : « RED BULL RED EDITION 25CL » (TRAIN réel), « MONSTER ULTRA
  ZERO » (TEST), « TROPICO L'ORIG ». D'où `zero_edition` (ULTRA/EDITION restreints aux
  familles énergie ; équivalences zero/sans sucre/sugar free/light ancrées sur la paire
  TRAIN « Pepsi sans sucre » → « Pepsi Zero »).
- **Abréviations facture** : « OASIS POM CASS FRAM SLIM 33CL » (pomme/cassis/framboise),
  « CRISTALI 5OCL PET » (Cristaline), « MMS » (M&Ms), « L'ORIG » (l'originale). D'où
  `abreviation_globale` (troncature 3-4 lettres MAJ des mots ≥ 6 lettres, style observé).
- **Événements** : « Soiree bowling (2 parties / 1 personne) », « Bowling ( 2 parties ) »,
  « Menu BBQ ». D'où `evenement_parentheses` (modèles à trous autour de la clé TRAIN).
- **Multipacks** : « 25CL X6 », « 33CL X6 » (univers), « BOITE 25CL X6 » — d'où
  `conditionnement_multipack` (6X33CL, X6, PACK DE 6, CARTON 24…).

## 4. Table de correspondance échec → typologies v2

| Échec / dur négatif | Typologies v1 renforcées | Typologies NOUVELLES v2 |
|---|---|---|
| Échecs 1-2 (MONSTER ULTRA ZERO ×2) | facture_long, faute | **zero_edition** |
| Échecs 3-4 (RED BULL BLUE ×2) | facture_long, casse | **couleur_traduite**, **marque_scindee** |
| Échec 5 (Soiree bowling) | — | **evenement_parentheses** |
| Durs négatifs goût/couleur/numéro (49/57) | suffixe_gout_couleur | zero_edition, couleur_traduite, evenement_parentheses (volume → négatifs en batch) |
| Durs négatifs multipack/« 5 cannettes » | facture_long | **conditionnement_multipack** |
| Génération A1/A3 (rattrapages restants) | casse, separateurs, concatenation, faute, doublage | **inversion_mots**, **abreviation_globale**, **accents_globaux** |

## 5. Ce que cette analyse NE promet pas (honnêteté, contrainte 6)

- Aucun ancrage direct sur les groupes TEST `monsterblanche`, `redbullbleu`, `redbull`,
  `bowling`, `bowling2personnes` (split par groupe anti-fuite) : la v2 enseigne des FORMES
  (traduction, qualificatifs, parenthèses) sur des familles TRAIN voisines (monsterbleu,
  monster, redbullice, redbullpeach, redbullred, redbullseablue) et table sur la
  généralisation. Si S4″ ne s'améliore pas, c'est documenté — l'échec d'un run n'est pas
  un échec de vague (contrainte vague 2 n°5).
- A4 (cible 0) ne peut pas être garanti par la seule augmentation MNRL ; l'objectif v2 est
  de réduire le compte (57 → viser la baisse) via des batches plus denses en sœurs.
- La mesure S4″ restera À PAREIL À PAREIL (même jeu figé, même protocole A1-A6, mêmes
  cibles) — aucun seuil retouché, aucune optimisation sur TEST.

STATUT : OK — analyse extraite du rapport S4′ (5 échecs A1 + 57 durs négatifs A4 groupés
par famille), pilotage des 8 nouvelles typologies documenté, limites posées.

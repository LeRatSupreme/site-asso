# Rapport d'augmentation v2 — brief A (dev-factures), vague 2 « P2-ter »

> 2026-10-28 01:20 (VPS `/home/ubuntu/AEIC`, horodaté — contrainte vague 2 n°6).
> Brief : `/tmp/t42_briefs/A_augment_v2.md` · contraintes : `/tmp/t42_briefs/00_CONTRAINTES.md`.
> Livrables : `data/eval_t1/p2/v2/` UNIQUEMENT. Aucun commit/push (consolidation finale).
> Commandes toutes relançables (A6 du protocole, contrainte vague 2 n°6).

## 1. Ce qui a été fait (ordre du brief respecté)

1. **Analyse d'erreurs d'abord** → `analyse_erreurs.md` : les 5 paires TEST ratées en
   recall@3 (avec meilleur candidat/score et mécanisme) + les 57 durs négatifs ≥ 0,6618
   groupés par famille (redbull 12, fanta 9, sucette 8, minute 6, oasis 6, monster 4,
   coca 3, bowling/soiree 3, pepsi 2, bonbon/chips/gaufre 4), extraits du rapport machine
   `p2/rapport_S4_prime_brut.md` — rien de réinventé.
2. **`scripts/t1_augment.py` étendu** (rétro-compatible, voir §3) : 8 nouvelles typologies
   issues de l'analyse — `couleur_traduite` (traduit UNIQUEMENT les couleurs de la paire),
   `marque_scindee` (RED BULL↔Redbull, MinuteMaid↔Minute Maid), `inversion_mots`,
   `abreviation_globale` (style observé POM CASS FRAM / L'ORIG / CRISTALI),
   `zero_edition` (ULTRA/EDITION familles énergie ; zero↔sans sucre↔sugar free↔light),
   `evenement_parentheses` (modèles « (2 parties / 1 personne) », « ( 2 personnes ) », x2),
   `conditionnement_multipack` (6X33CL, X6, PACK DE 6, CARTON 24), `accents_globaux`
   (un accent par mot, voyelles, jamais sur emballage). Toujours TRAIN uniquement.
3. **Généré** : train v2 **4 595 paires** (cible 4 000-6 000 ATTEINTE ; ×2,58 vs v1 1 783),
   val **400** (cible ~400), manifeste + SHA256 ; anti-triche étendu aux nouvelles
   typologies + test négatif par injection ; `kaggle_upload_v2/` prêt (MÊME id
   `adrienremond/aeic-t1-p2-pairs` → le push crée une NOUVELLE VERSION, pas un nouveau
   dataset ; fichiers `train_pairs.csv`/`val_pairs.csv` lus tels quels par le kernel v4).

## 2. Répartition par typologie (comptes RÉELS, extraits du run)

| typologie | retenues | | typologie | retenues |
|---|---:|---|---|---:|
| origine | 30 | | couleur_traduite ✚ | 30 |
| casse_mele | 424 | | marque_scindee ✚ | 17 |
| separateurs | 168 | | inversion_mots ✚ | 67 |
| concatenation | 103 | | abreviation_globale ✚ | 251 |
| faute_1_2_editions | 1214 | | zero_edition ✚ | 120 |
| suffixe_gout_couleur | 556 | | evenement_parentheses ✚ | 398 |
| facture_long | 514 | | conditionnement_multipack ✚ | 417 |
| doublage_mots | 113 | | accents_globaux ✚ | 173 |
| **total** | **4595** | | (✚ = nouvelle v2, total nouvelles 1 473) | |

Table complète (tirées/filtrées par motif, dont 138 `collision_test` + 77 `collision_soeur`
rejetées par le garde-fou étendu) : `manifeste_v2.md` §Volumes.

## 3. Rétro-compatibilité v1 (preuve exécutée APRÈS toutes les modifications)

```
~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py        # sans --v2
sha256sum data/eval_t1/p2/train_pairs.csv data/eval_t1/p2/val_pairs.csv data/eval_t1/p2/manifeste_p2.md
→ 515d41d8…  f3fc84f9…  97d6b0b7…  = IDENTIQUES aux valeurs d'avant modification (diff vs
  instantané pris avant édition du script : aucune différence).
```

NB documenté (préexistant, hors périmètre de modification) : la fonction v1
`_dataset_metadata()` émet le libellé propriétaire historique `aeic/aeic-t1-p2-pairs`
(990a1b82…) alors que le dépôt consolidé (HEAD) porte la correction manuelle post-revue
`adrienremond/aeic-t1-p2-pairs` (f7549ab9…, celle du dataset réellement poussé). La
régénération v1 remet donc ces 2 octets — j'ai restauré l'état HEAD après la preuve
(`git checkout -- …/dataset-metadata.json …/p2/SHA256SUMS`) ; `git status` ne montre plus
que `scripts/t1_augment.py` (modifié) et `data/eval_t1/p2/v2/` (nouveau). La v2, elle,
émet direct`adrienremond/…` = f7549ab9, cohérente avec le dataset poussé.

## 4. Preuves (toutes relançables)

| Preuve | Commande | Résultat |
|---|---|---|
| Syntaxe | `python3 -m py_compile scripts/t1_augment.py` | OK |
| Double-run v2 | `…t1_augment.py --v2` ×2 → diff `v2/SHA256SUMS` | **octets IDENTIQUES** (sorties console identiques aussi) |
| Comptes réels | `wc -l v2/train_pairs.csv v2/val_pairs.csv` | 4 596 / 401 lignes (en-têtes inclus) |
| Anti-triche v1 | `…t1_augment.py --verifier` | OK (1783+178, inchangé) |
| Anti-triche v2 étendu | `…t1_augment.py --v2 --verifier` | **OK** (4595+400 ; zéro forme TEST, zéro résiduel synthétique, zéro collision par contenance) |
| Test négatif A | formes TEST exactes injectées | **40/40 rejetées** (motif `test`) |
| Test négatif B | nouvelles typologies × paires TEST (960 tirages, usage interdit simulé) | **555 rejetés + 405 fidèles à la paire + 0 ambigu** |
| Test négatif C | probes de collision explicites (« RED BULL BLEU BOITE 25CL »→Seablue, « redbull blue edition 25cl », « MONSTER ULTRA ZERO 33CL », « monster ultra bleu », « bowling 2personnes 33CL »…) | **10/10 détectés** |
| Test négatif D | CSV corrompu par 2 lignes TEST | **ÉCHEC détecté** (2 lignes signalées) |
| Jeu figé intact | `sha256sum -c data/eval_t1/SHA256SUMS` avant ET après | **12/12 OK** |
| Kaggle prêt | `cmp v2/*.csv v2/kaggle_upload_v2/*.csv` | copies octet/octet ; metadata id `adrienremond/aeic-t1-p2-pairs` |

Garde-fou ÉTENDU (contrainte vague 2 n°3) — réjection par CONTENANCE : une variante dont
la forme normalisée contient (sous-chaîne non-préfixe) une forme TEST ou une forme d'une
identité SŒUR (même famille que la cible) est rejetée, y compris après retrait d'un
qualificatif insérable (« monster ULTRA bleu » → cœur « monsterbleu ») ; exceptions
documentées : préfixe de la cible (marque partagée « coca »/« cocacherry ») ou forme du
brut TRAIN de même cible. C'est ce garde-fou qui rend les probes C impossibles à faire
passer — la v1 les aurait laissées passer (ex. « redbull blue edition 25cl »).

## 5. Ce qui reste à faire (autres briefs, PAS moi)

- **B_secu_v2** : revue dev-secu des fichiers de `kaggle_upload_v2/` AVANT tout push.
- **C_kaggle_v2** : push NOUVELLE VERSION du dataset `adrienremond/aeic-t1-p2-pairs`
  (2 tentatives max), kernel v4 (epochs ≤ 8, batch conservé), récupération du modèle.
  ATTENTION budget temps : v3 = 823,7 s pour 5 epochs sur 1 783 paires ; v2 = 4 595 paires
  (×2,58) ⇒ ~2 100 s pour 5 epochs, ~3 400 s si 8 epochs — le garde-fou `TIME_BUDGET_S`
  (3 300 s) du kernel v3 est à relever en conséquence dans le kernel v4.
- Mesure S4″ à pareil à pareil + décision de remplacement (MEILLEUR sinon on garde).

## 6. Limites honnêtes

- Les groupes TEST `monsterblanche`, `redbullbleu`, `redbull`, `bowling`,
  `bowling2personnes` n'ont AUCUN ancrage TRAIN possible (split par groupe anti-fuite) :
  la v2 enseigne des FORMES sur familles TRAIN voisines ; généralisation non garantie —
  l'échec éventuel du run est documenté d'avance (contrainte vague 2 n°5).
- A4 (cible 0) : la perte MNRL du kernel n'accepte que (ancre, positif) — aucun négatif
  étiqueté n'est ajouté ; l'effet attendu sur les 57 durs négatifs est INDIRECT (négatifs
  en batch plus denses, 4 595 paires vs 1 783).
- Aucun envoi Kaggle effectué ici (interdit avant revue dev-secu).

STATUT : OK — analyse d'erreurs extraite, 8 typologies nouvelles + 7 renforcées,
train v2 4 595 paires / val 400 (cibles atteintes), anti-triche v1+étendu OK, test négatif
par injection OK (40/40, 10/10, ÉCHEC détecté), double-run SHA256 identiques, jeu figé
12/12 intact, kaggle_upload_v2 prêt (même id, version bump), v1 régénéré à l'identique.

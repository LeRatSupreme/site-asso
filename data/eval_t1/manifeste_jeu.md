# Jeu d'évaluation T1 — manifeste de génération

- Généré le : 2026-10-27 15:13:55 (Europe/Paris)
- Graine : 42 — reproductible : deux exécutions donnent des hachages identiques
- Commande : `php scripts/t1_export_eval.php --seed=42 --negatifs=300`
- Base : `aeic` (production locale, **LECTURE SEULE** — SELECT uniquement)
- Script : `scripts/t1_export_eval.php` — logique pure : `app/core/Compta/T1/EvalDataset.php`
- Tests : `tests/Unit/Compta/T1EvalDatasetTest.php`
- Spec : `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` (§3)

## Compteurs sources réels (relevés à la génération)

- Fusions auditées (`audit_logs`) : 54 `inventory.merge` + 49 `compta.aliases.merge` + 29 `compta.product.merge` + 24 `compta.repair.merge_cle` = **156 événements** (attendu 156, spec §1.1)
- Alias (`product_aliases`) : 96 `label` + 17 `appellation` = **113 lignes** (attendu 113)
- Univers brut : 79 fiches + 67 clés de stock + 77 clés de vente / 103 descriptions distinctes + 56 clés d'achat + 66 comptages + 64 coûts + 39 packs + 76 cibles d'alias + 18 arrêtées

## Volumes obtenus

| Fichier | Lignes | Attendu spec |
|---|---:|---|
| univers.csv | 120 identités (169 libellés bruts) | ~100 (§2 : 80-120) |
| positifs_semantiques.csv | 50 | 60-80 (§3.1) |
| positifs_casse.csv | 100 | transparence |
| durs_negatifs.csv | 199 | « quelques centaines » (§3.2) |
| negatifs.csv | 300 échantillonnées (seed 42) | ~300 |
| residuels_a_qualifier.csv | 13 | ~8+ (§1.3) |
| split.json | 65 groupes train / 28 test — 30 paires sémantiques train, 20 test (min 20) | ~70/30 (§3.4) |

Identités vivantes : 96/120 (règle ci-dessous).

## Règles documentées

- **Forme normalisée** : `StockPublic::normalizeKey` (casse, accents, séparateurs, espaces) — deux libellés de même forme = même identité ; le libellé affiché est le **représentant** déterministe (nb de tables sources, puis occurrences ventes+achats, puis ordre lexicographique).
- **Famille** : 1er token de `AliasSuggester::normalizeKey` du libellé représentant (« Redbull Bleu » → `redbull` ; « Gauffre choco » [typo] → `gauffre`, distinct de `gaufre`).
- **Vivant** : ≥ 1 occurrence réelle (ventes par product_key OU description, achats, ligne dans product_stocks ou inventory_counts) ET absent de `product_discontinued`.
- **Positifs** : fusions (source/from/merged → target/to/entity_id) + alias (`raw_description` → `product_key`) ; dédoublonnage par paire de formes (variantes de casse d'une même fusion sémantique fusionnées) ; casse pure → `positifs_casse.csv` HORS jeu ; paires §1.3 → `residuels_a_qualifier.csv` HORS jeu.
- **Négatifs interdits** : toute paire positive (jeu ou exclue), tout résiduel §1.3, tout near-miss ≤ 2 éditions non fusionné, et « Custom amount »/« Montant personnalisé » (artefacts SumUp équivalents).
- **Durs négatifs** : EXHAUSTIF intra-famille, clés vivantes hors clé parasite « . ».
- **Split** : par groupe = composante d'union-find des paires positives sémantiques (une identité sans paire est son propre groupe) ; remplissage test ~30 % des identités par Fisher-Yates seedé 42, complément déterministe si moins de 20 paires sémantiques en test ; assertion anti-fuite (aucune paire à cheval train/test).

## Anomalies et écarts
1. exclusion documentée : paire « Custom amount »/« Montant personnalisé » (artefacts de paiement SumUp sémantiquement équivalents) interdite en négatif
2. 35 auto-alias ignorés (raw_description === product_key, ex. Bonbon ; Bueno ; Cacolac…) — attendus : alias de synchronisation, aucune paire en résulte
3. clé parasite « . » (spec §1.3) : forme normalisée vide → absente de univers.csv, documentée dans residuels_a_qualifier.csv
4. mojibake double-encodage réparé sur 1 libellé(s) d'audit (ex. « Oasis ThÃ©mangue Passion ») — déterministe (EvalDataset::repairMojibake)
5. 2 paire(s) positive(s) historique(s) écartée(s) du jeu car listées §1.3 (clé recréée après fusion) : à requalifier en D2
6. écart à la spec §3.1 : paires sémantiques mesurées sous la fourchette 60-80 attendue (estimation spec optimiste : ~270 événements bruts mais forte redondance casse/typo + 1 doublon mojibake + exclusions §1.3) — compteurs exacts dans ce manifeste
7. trio « Redbull Bleu »/« Redbull Blue »/« Redbull_Bleue » : lié par fusions auditées (même groupe de split) mais 3 identités vivantes distinctes — aucune paire en dur négatif (interdites positives)
8. « Oasis ice tea » vs « Oasis ice tea the mangue passion » : deux clés vivantes non listées §1.3 → restent durs négatifs — à surveiller en D2

Vérification : `sha256sum -c data/eval_t1/SHA256SUMS` (depuis la racine du dépôt) — les manifestes horodatés ne sont pas hachés.

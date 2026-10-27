# Export Kaggle — dataset T1 rapprochement produits (protocole D3)

Statut : **PRÊT, NON TRANSMIS** (aucune commande `kaggle` exécutée — accord explicite requis).
Régénération : `php scripts/t1_export_eval.php` (VPS, base `aeic` en lecture seule, seed 42).

## Protocole d'anonymisation (D3 — libellés seuls)

- **Aucun** prix, montant, date, quantité, volume (ventes/achats/stock), fournisseur (METRO/Auchan/carrefour), référence de facture, user_id, adresse IP, e-mail : seuls des **libellés produits** sont exportés.
- Prénoms probables masqués : un libellé contenant « … | les creation(s) de X » voit sa partie après « | » remplacée par `[PRENOM]` (cas connu : « Décoration halloween | les creation de ju… »). Pour rester cohérent, la `forme_normalisee` et le `groupe` d'un libellé masqué sont recalculés depuis le libellé masqué. Règle déterministe, testée (`EvalDataset::masquerPrenom`).
- Les `forme_normalisee`/`famille` sont des dérivés déterministes des libellés (aucune donnée personnelle).

## Fichiers

| Fichier | Colonnes | Contenu |
|---|---|---|
| `univers.csv` | `libelle,forme_normalisee,famille,vivant,groupe` | Identités produits (~100) : union fiches + clés vivantes (spec §2). `vivant` = 1 si activité réelle et non arrêté. |
| `positifs.csv` | `libelle_a,libelle_b` | Paires positives sémantiques (fusions auditées + alias), hors variantes de casse pure (spec §3.1). `libelle_a` = libellé brut, `libelle_b` = clé cible. |
| `negatifs.csv` | `libelle_a,libelle_b` | ~300 paires inter-familles négatives échantillonnées (seed 42), jamais positives. |
| `durs_negatifs.csv` | `libelle_a,libelle_b` | Paires intra-famille négatives EXHAUSTIVES (« Redbull Bleu » vs « Redbull Blanche »…) — critère A4. |
| `split.csv` | `libelle,split` | Affectation train|test PAR PRODUIT (anti-fuite, spec §3.4). |

Les paires résiduelles non qualifiées (spec §1.3, décision D2 en attente) sont volontairement ABSENTES de cet export.
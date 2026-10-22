# Dashboard compta — dump des 2 questions comptables

- **Date** : 2026-10-22 (10:22 Paris)
- **Commit déployé** : `406699a` (« Compta : script dump lecture seule (frais sumup, depenses vs achats) »)
- **Lecture seule** : le script `scripts/compta_dump.php` n'émet AUCUN INSERT/UPDATE/DELETE — uniquement des SELECT via les modèles existants (`Sale`, `Expense`, `Purchase`, `Setting`) et l'utilitaire pur `CardFees`. La migration appliquée (`database/migrations/2026_dashboard_settings.sql`) est en `INSERT IGNORE` sur la table `settings` uniquement.
- **Fenêtre par défaut** : 30 derniers jours (2026-09-23 → 2026-10-22), options `--jours=N` ou `--from/--to`, `--json` disponible.

Extrait intégral du dump (30 j, VPS) :

```
=== Dump comptable — AEIC (généré le 2026-10-22 10:22 Paris · LECTURE SEULE) ===
Fenêtre : 2026-09-23 → 2026-10-22 (30 jours)

-- 2. CA --
total : 2335,15 € · CARTE : 1857,30 € · LIQUIDE : 477,85 €

-- 3. FRAIS SUMUP (estimation) --
taux configuré (sumup_fee_rate = « 1.75 ») : 1,75 % · base = ventes CARTE TTC : 1857,30 €
frais estimés sur la période : 35,69 €
rétro-calcul : taux_effectif = frais / base × 100 = 1,9216 %
pour comparaison : 1,75 % × CA total (2335,15 €) = 40,87 €

-- 4. MARGE --
profit : 650,55 € · CA produits : 2286,15 € · marge : 28,5 %

-- 5. DÉPENSES (TTC, table expenses) --
total : 607,83 € (9 ligne(s))
par catégorie : MATIERE 109,92 € · MATERIEL 13,99 € · EVENEMENT 0,00 € · FRAIS 0,00 € · DIVERS 483,92 €

-- 6. ACHATS DE STOCK (table purchases) --
total TTC : 1267,36 € (62 ligne(s), quantité 1228)

-- 7. RÉSULTAT NET --
(a) profit − dépenses = 650,55 − 607,83 = 42,72 €
(b) (CA − achats) − dépenses = (2335,15 − 1267,36) − 607,83 = 459,96 €
```

## Question 1 — le résultat net compte-t-il les produits deux fois ?

**Réponse : NON, structurellement.** Le résultat net affiché par la page est `profit − dépenses` (contre-vérification (a) : 650,55 − 607,83 = **42,72 €**) :

- le **profit** (650,55 €) provient de `Sale::aggregatesBetween` : il déduit déjà, vente par vente, le coût de revient de chaque produit lu dans la table `product_costs` (d'où la marge de 28,5 % sur un CA produits de 2 286,15 €) ;
- les **dépenses** (607,83 €) viennent exclusivement de la table `expenses` (MATIERE 109,92 · MATERIEL 13,99 · EVENEMENT 0,00 · FRAIS 0,00 · DIVERS 483,92) ;
- les **achats de stock** (1 267,36 € TTC sur 30 j) viennent de la table `purchases` et ne sont **jamais sommés** dans le calcul du résultat — c'est la contre-vérification (b), (CA − achats) − dépenses = 459,96 €, qui montre qu'ils vivent à part.

**RISQUE résiduel (humain, pas structurel) : la catégorie MATIERE.** Une dépense saisie en MATIERE pour une course déjà enregistrée dans `purchases` compterait le même euro deux fois. Sur les 30 derniers jours : **MATIERE = 109,92 €** → risque de double comptage maximal de 109,92 € si l'intégralité de cette ligne double un achat. À vérifier à la saisie : soit la course passe dans les achats de stock (kiosque Réappro/purchases), soit en dépense MATIERE, pas les deux.

## Question 2 — base des frais SumUp (35,21 € vs 32,05 €)

**La base des frais est la CA des seules ventes CARTE, jamais le CA total** — et le taux est appliqué puis **arrondi au centime ligne par ligne** (comme SumUp prélève un frais par paiement : `SUM(ROUND(price_ttc × taux / 100, 2))`).

Les trois chiffres du dump (30 j) :

| Calcul | Base | Résultat |
|---|---|---|
| Estimation du site (arrondi ligne par ligne, 1,75 %) | CARTE 1 857,30 € | **35,69 €** (taux_effectif rétro-calculé : **1,9216 %**) |
| 1,75 % plat × base CARTE | CARTE 1 857,30 € | 32,50 € |
| 1,75 % × CA **total** | 2 335,15 € | 40,87 € |

- Le « **32,05 €** » de la question = 1,75 % plat × 1 831,60 € (base CARTE d'une fenêtre glissante antérieure — non retrouvée à l'euro près dans les fenêtres testées, les plus proches étant 1 838,50 € sur 2026-09-07→10-06 et 1 826,95 € sur 2026-09-20→10-19). C'est le calcul « naïf » sans arrondi.
- Le « **35,21 €** » (comme les 35,69 € du dump du jour) = le même taux 1,75 % mais **arrondi au centime à chaque ligne de vente**. Sur des petits tickets (ex. une ligne à 1,50 € : 0,026 € arrondi à 0,03 €, soit 2 %), l'arrondi gonfle systématiquement le taux effectif — de façon très stable : 1,92 % observé sur toutes les fenêtres testées (1,9188 % → 1,9329 %).
- Si la base était le CA **total**, les frais seraient encore plus élevés : 1,75 % × 2 335,15 € = 40,87 €. Le liquide ne paie donc aucun frais SumUp.

Rappel imprimé par le dump : « Les frais SumUp sont estimés sur les seules ventes CARTE de la période (taux configurable sumup_fee_rate, arrondi à la ligne) ; ils n'entrent pas dans le résultat net. »

## Seuils du verdict

Le verdict 3 niveaux du dashboard (`/admin/compta`) compare le résultat net à la période précédente :

- `dashboard_baisse_attention_pct` = **−10** → phrase orange « attention » ;
- `dashboard_baisse_alerte_pct` = **−25** → phrase rouge « alerte » ;
- entre −10 % et +… : neutre/stable ; en hausse : « progresse ».

Valeurs posées par la migration `2026_dashboard_settings.sql` (`INSERT IGNORE`, vérifiées en base le 2026-10-22 : 2 lignes, −10 et −25) et **réglables sans code** dans **Paramètres → groupe « Dashboard compta »** (clés lues par `App\Core\Compta\Dashboard\Hyper::valeur()`).

## Ce qui reste incertain

- **Frais SumUp = estimation.** Un taux moyen unique (1,75 %) ignore les frais majorés des cartes étrangères / Amex / paiement à distance (jusqu'à 2,5 %) et d'éventuels frais fixes par versement. Le taux est configurable (`sumup_fee_rate`) si un mix réel le justifie ; le taux effectif observé (~1,92 %) reflète surtout l'arrondi ligne à ligne, pas un mauvais réglage.
- **MATIERE = risque humain de double saisie** (109,92 € sur 30 j) : le système ne peut pas détecter qu'une dépense MATIERE double un achat déjà compté dans `purchases`.
- **Verdict neutre sur les périodes en cours, par conception** : pas de pourcentage de variation tant que la période n'est pas terminée et entièrement couverte par des imports (`periodeComplete`), pour ne pas comparer un mois incomplet à un mois plein.
- **TVA des ventes non renseignée** : sur la période, toutes les lignes de vente sortent à taux « — » (TVA 0,00 €) — la ventilation par taux ne deviendra utile que lorsque les taux seront saisis à l'import.

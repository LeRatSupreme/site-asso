# Validation finale T1.5 — brief T15-C (dev-tests), 27/10/2026

Toutes les commandes sont relançables depuis la racine du dépôt `/home/ubuntu/AEIC`.
Base : `aeic_test` uniquement (port 3307, méthode officielle sed-port) ; prod jamais
touchée ; jeu figé `data/eval_t1/` vérifié **SHA256 12/12 avant ET après** chaque étape ;
la vraie quarantaine `data/eval_t1/t15/quarantaine.json` (état runtime) n'a jamais été
créée/lue/écrite — les parcours utilisent la redirection `tests/cache/t15/`.

## 1. Lint PHP (liste exhaustive des fichiers touchés T1.5)

```bash
for f in app/core/Compta/T1/T1AutoFusion.php app/core/Compta/T1/T1Quarantaine.php \
  app/core/Compta/T1/T1MergeArchive.php tests/Unit/Compta/T1AutoFusionTest.php \
  tests/Integration/T1AutoFusionIntegrationTest.php \
  app/controllers/Admin/AdminStockController.php app/config/routes.php \
  app/core/Compta/AliasSuggester.php app/models/AuditLog.php app/models/Product.php \
  app/models/ProductAlias.php tests/bootstrap.php tests/Integration/runner.php \
  tests/Integration/T1DecideTest.php views/admin/compta/inventory.php; do php -l "$f"; done
```

**Résultat : 15/15 « No syntax errors detected ».**

## 2. Suite complète (méthode officielle sed-port)

```bash
sed 's|name="DB_HOST" value="127.0.0.1"|name="DB_HOST" value="127.0.0.1:3307"|' phpunit.xml > .phpunit-t1-local.xml
DB_PASS=aeic php phpunit.phar -c .phpunit-t1-local.xml --no-coverage
rm -f .phpunit-t1-local.xml
```

**Résultat : OK — 1357 tests, 7438 assertions, 0 échec** (2 exécutions : initiale et
finale de certification, résultats identiques). Contient notamment les 21 tests purs
`T1AutoFusionTest` + 7 tests `T1AutoFusionIntegrationTest` (dont garde coûts
re-vérifiée à l'application).

## 3. Parcours métier de bout en bout (aeic_test)

Commande unique rejouable : `bash data/eval_t1/t15/parcours_e2e.sh`
(helpers : `php data/eval_t1/t15/parcours_helper.php <sous-commande>`).
Requêtes réelles via `tests/Integration/runner.php` (routeur réel, admin SUPERADMIN,
quarantaine redirigée `tests/cache/t15/`). **Résultat : 60/60 checks OK.**

| # | Livrable exigé | Preuve observée | Où |
|---|---|---|---|
| a | Paire casse pure → fusion auto niveau 1 au chargement | GET `/admin/compta/inventaire` : ventes « pepsi » (2), stock 5 sur « pepsi », « Pepsi » absorbée ; audit `inventory.merge` unique `origine=auto, niveau=1, motif=casse` + **photo pré-fusion** (`archive.source.stock=5` AVANT fusion, compteurs cibles) ; audit `t1.pair_feedback` `decision=fusion, origine=auto` ; écran : carte `#fusions-auto` + « Restaurer la clé » | parcours, section (a), 16 checks |
| b | Paire sémantique sûre → quarantaine 48 h visible | `passage()` : `declarees=1, appliquees=0` ; quarantaine `en_attente` (`source=Fanta Orange, target=Fanta`) ; vente intacte, 0 fusion ; écran : « En quarantaine (48 h, fuseau de Paris) », badge **compte à rebours « dans NN h NN »**, bouton « Annuler (garder séparés) » ; paire retirée de la carte suggestions (liste noire) | section (b), 10 checks |
| c | Veto → jamais fusionnée, listée annulée | POST `/t1/auto/veto` → 302 ; statut `annule` ; feedback `ignorer/auto/veto_quarantaine` ; **maturité simulée +49 h (horloge injectable) : 0 fusion**, vente intacte ; écran : « Annulées (jamais fusionnées…) » + paire barrée | section (c), 6 checks |
| d | Horloge avancée 48h01 → fusion au chargement + restauration | `created_at` reculé de 49 h ≡ horloge +48h01 → **GET inventaire applique la fusion** (stock 3+4=7, variante vidée, ventes repointées) ; audit niveau 2 + photo ; quarantaine `applique` + `audit_id` lié ; POST `/t1/auto/restaurer` → **fiche recréée (2,50 €), alias « self » recréé, stock snapshot 4 rendu, cible rééquilibrée 3 (total préservé)** ; alias distinct non volé (reste sur cible, comportement codé) ; historique ventes sur cible (limite documentée) ; audit `inventory.restore` | section (d), 17 checks |
| e | Coûts divergents > 15 % → jamais en quarantaine, suggestion | lots 0,50 vs 1,00 → `passage(): declarees=0`, quarantaine VIDE, 0 écriture, 0 audit ; paire **présente dans `T1Suggestions::pourInventaire`** (point d'assemblage exact de la carte niveau 3) ; écran : aucune section quarantaine | section (e), 7 checks |
| p | Perf : chargement inventory() × 50 paires en quarantaine | 5 mesures avant / 5 après (médianes) : 227 ms → 283 ms = **+56 ms** (seuil +300 ms ; 2e exécution −47 ms) — aucune régression perceptible | section (p) |
| — | Intégrité | vraie quarantaine repo jamais créée ; SHA256 12/12 ; `git status` sans fichier suivi modifié par la validation | section intégrité |

## 4. Bug métier détecté (PRÉEXISTANT, hors régression T1.5 — à réorienter dev-backend)

`AliasSuggester::groupDuplicates()` (lignes 140/144, inchangées par T1.5 — diff T1.5
limité à la visibilité `SUFFIX_STOPWORDS`) : si une **clé vivante est purement
numérique** (ex. `product_stocks.product_key = '3'`, libellé de vente/scan numérique),
`array_keys($byNorm)` retourne un **int** (clé numérique PHP), et la closure du
union-find `$root(string $n)` reçoit un int → `TypeError` → **page inventaire 500**.

Reproduction (aeic_test) :
```bash
php data/eval_t1/t15/parcours_helper.php seed-base
php data/eval_t1/t15/parcours_helper.php q "INSERT INTO product_stocks (product_key, stock) VALUES ('3', 4)"
# GET /admin/compta/inventaire → 500 : « Argument #1 ($n) must be of type string, int given »
```
Piste de correctif (à valider par dev-backend, pas appliqué — hors périmètre dev-tests) :
caster `$norms = array_map('strval', array_keys($byNorm));`.

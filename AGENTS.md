# Règles du projet AEIC

## Rapprochement des noms de produits (règle anti-régression)

Le même produit existe sous plusieurs orthographes (casse, accents, ponctuation, espacements, alias de saisie) selon la table : `sales.product_key/description`, `product_packs.product_key`, `inventory_counts.product_key`, `product_aliases`, libellés du moteur de prévision. **Interdit de joindre deux tables produits par simple `strtolower` exact** — c'est la cause des bugs « stock introuvable » et « 5 cannettes au lieu d'un pack » (22/10/2026).

- Toute résolution nom → donnée (stock, pack, catégorie…) passe par les résolveurs partagés : `StockBook` (stock théorique) ou `PackResolver` (packs), dans `app/core/Compta/Reappro/` — passes exacte → normalisée (`StockPublic::normalizeKey`) → alias, testées unitairement (`tests/Unit/Reappro/`).
- Ajouter un nouveau lookup produit = enrichir le résolveur correspondant (ou en créer un sur le même modèle), jamais un `map[strtolower($nom)]` ad hoc dans un contrôleur ou une vue.
- Une donnée utilisée par plusieurs pages (demande, stock, pack) doit venir d'UN seul point d'assemblage (ex. `ReapproDemande`, `AnalyticsForecastData::stockBook()`, `ProductPack::resolver()`) — pas recalculée par page.
- Régression bloquée par les tests : si un lookup contournait les résolveurs, `PackResolverTest`/`StockBookTest` + le test d'intégration liste (pack saisi sous une casse différente, arrondi quand même) échouent.

## Git

- À chaque modification de code, faire un commit puis un `git push` immédiatement après (jamais de code modifié non commité/poussé en fin de tâche).
- Messages de commit en français, courts et descriptifs (style des commits existants).
- Branche de travail : `experiments` (poussée vers `origin/experiments`).
- Ne jamais committer : `.env`, `.kilo/`, fichiers temporaires.

## Déploiement VPS

- Après le push, déployer sur le VPS : `ssh ubuntu@51.254.129.80 "cd ~/AEIC && git pull --ff-only origin experiments"`.

## Config serveur (VPS, hors repo)

- PHP mod_php (Apache) : `/etc/php/8.3/apache2/conf.d/99-aeic-uploads.ini` fixe `upload_max_filesize = 12M` et `post_max_size = 14M` — indispensable au scan de photos de factures (le défaut 2 M faisait échouer l'upload avec « erreur 1 »). Après modification : `sudo systemctl reload apache2`.

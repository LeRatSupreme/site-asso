# Migrations AEIC

Ce dossier contient les migrations SQL appliquées **à la main** sur le VPS de
production. Il n'y a **aucun mécanisme automatique** (pas de framework de
migration) : chaque fichier est exécuté via `mysql` quand il est créé, par
exemple :

```
mysql -u aeic -p aeic < database/migrations/<nom_du_fichier>.sql
```

## Installation fraîche

Une installation fraîche n'utilise **que** `database/schema.sql` puis
`database/seed.sql` :

```
mysql -u aeic -p aeic < database/schema.sql
mysql -u aeic -p aeic < database/seed.sql
```

Les migrations de ce dossier ne concernent que les **bases existantes** : le
schéma canonique (`schema.sql`) intègre déjà leur résultat. Ne les rejouez pas
sur une base fraîche.

## ⚠️ Le rejeu en ordre alphabétique n'est PAS SÛR

Les fichiers ne sont pas numérotés séquentiellement : si vous devez appliquer
plusieurs migrations d'un coup, **vérifiez manuellement leur ordre**. Pièges
connus :

1. **`2026_purchases_ht3.sql` doit passer APRÈS `2026_purchases_vat.sql`**
   (et `2026_purchases_ttc3.sql` après les deux). Ordre correct :
   `2026_purchases_vat.sql` → `2026_purchases_ht3.sql` → `2026_purchases_ttc3.sql`.
   L'ordre alphabétique donne l'inverse : `ht3`/`ttc3` modifieraient des
   colonnes que `vat` n'a pas encore créées (`total_ht`) et le passage en
   `DECIMAL(10,3)` serait écrasé.

2. **`2026_wordle_words.sql` (v1) est obsolète** : elle a été remplacée par
   `2026_wordle_v2.sql` (colonnes `length` + `difficulty`, mots de 5 à 7
   lettres) qui supprime et recrée la table. **Elle ne doit plus être jouée** :
   sur une base en v2, ses `INSERT` (sans `length`/`difficulty`) échouent.

3. **`2026_menu_products.sql` doit passer AVANT
   `2026_menu_nouveaux_produits.sql`**, alors que l'ordre alphabétique donne
   l'inverse. La première fait un `DELETE FROM products` puis insère les ~20
   produits de base : jouée après, elle **effacerait les 38 nouveaux produits**
   insérés par la seconde. Ordre correct : `2026_menu_products.sql` →
   `2026_menu_nouveaux_produits.sql`.

## Syntaxe MariaDB uniquement

Certaines migrations utilisent `ADD COLUMN IF NOT EXISTS` (et autres variantes
`IF NOT EXISTS` sur `ALTER TABLE`). Cette syntaxe est **spécifique à MariaDB** :
elle échoue sur MySQL/Percona. Le serveur de production est MariaDB ; ne
rejouez pas ces fichiers sur un MySQL strict sans adaptation.

## Règles d'écriture

- Idempotent quand c'est possible : `CREATE TABLE IF NOT EXISTS`,
  `INSERT IGNORE`, `ADD COLUMN IF NOT EXISTS`.
- Un fichier = un changement, avec un commentaire d'en-tête expliquant le
  contexte et la commande d'application.
- Après toute migration modifiant la structure, mettre à jour
  `database/schema.sql` (voie canonique de réinstallation) pour qu'il reste
  synchronisé.

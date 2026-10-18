-- ============================================================
--  AEIC — Recatégorisation des ventes « Non classé » (doublons stats)
--
--  Problème : les ventes importées via l'API/cron avec un libellé brut
--  absent du mapping (product_aliases.raw_description) gardaient
--  category = NULL -> affichées « Non classé » -> le même produit
--  apparaissait DEUX FOIS dans le tableau produits des stats
--  (ex. « Eau » en Boissons + « Eau » en Non classé, « Red Bull ice »
--  en Redbull + « Red Bull ice » en Non classé).
--
--  La migration complète le mapping (Cacolac, Redbull blue/red,
--  Gauffre événement), rattache les ventes existantes par clé
--  effective (product_key, sinon description TRIMmée), puis comble
--  les catégories vides par clé canonique (même passe 2 que
--  Sale::syncAliasCategories()) : « Eau » -> Boissons,
--  « Red Bull ice » -> Redbull.
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_10_recategorize_non_classes.sql
--  Idempotent : INSERT IGNORE sur l'id, upsert sur l'unicité
--  raw_description, et remplissage limité aux catégories NULL/''.
-- ============================================================

-- 1. Catégorie cafétéria « Événement » (même format d'id que
--    ProductCategory::ensure(), préfixe cat_*). Garde par nom : si
--    « Événement » existe déjà sous un autre id, on ne crée rien
--    (une seule ligne par nom, comme ensure()).
INSERT IGNORE INTO product_categories (id, name, `order`, is_active)
SELECT 'cat_evenement', 'Événement', COALESCE(MAX(c.`order`), 0) + 1, 1
FROM product_categories c
WHERE NOT EXISTS (
    SELECT 1 FROM product_categories e WHERE e.name = 'Événement'
);

-- 2. Mapping : futurs imports (CSV manuel + synchro API/cron) seront
--    catégorisés d'emblée. Libellés bruts EXACTS (sans accent dans
--    « energisante » : c'est le libellé SumUp tel quel).
INSERT INTO product_aliases (id, raw_description, product_key, category) VALUES
    ('al_cacolac',     'Cacolac', 'Cacolac', 'Boissons'),
    ('al_rb_blue',     'Redbull blue', 'Redbull blue', 'Redbull'),
    ('al_rb_red',      'Redbull red', 'Redbull red', 'Redbull'),
    ('al_gauffre_evt', 'Gauffre + boissons (hors boissons energisante)', 'Gauffre + boissons (hors boissons energisante)', 'Événement')
ON DUPLICATE KEY UPDATE product_key = VALUES(product_key), category = VALUES(category);

-- 3. Rattrapage des ventes existantes : correspondance EXACTE sur la
--    clé effective (product_key, sinon description TRIMmée). Les
--    montants personnalisés sont exclus.
UPDATE sales
SET category = 'Boissons'
WHERE is_custom_amount = 0
  AND COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) = 'Cacolac';

UPDATE sales
SET category = 'Redbull'
WHERE is_custom_amount = 0
  AND COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) = 'Redbull blue';

UPDATE sales
SET category = 'Redbull'
WHERE is_custom_amount = 0
  AND COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) = 'Redbull red';

UPDATE sales
SET category = 'Événement'
WHERE is_custom_amount = 0
  AND COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) = 'Gauffre + boissons (hors boissons energisante)';

-- 4. Complément générique par clé canonique (passe 2 de
--    Sale::syncAliasCategories()) : remplit UNIQUEMENT les catégories
--    vides (NULL/'') — n'écrase ni un classement existant, ni un
--    reclassement par variantes de prix. Colle « Eau » -> Boissons,
--    « Red Bull ice » -> Redbull et supprime les doublons « Non classé ».
UPDATE sales s
JOIN (
    SELECT TRIM(product_key) AS k, MAX(TRIM(category)) AS c
    FROM product_aliases
    WHERE product_key IS NOT NULL AND TRIM(product_key) <> ''
      AND category IS NOT NULL AND TRIM(category) <> ''
    GROUP BY TRIM(product_key)
) a ON a.k = COALESCE(NULLIF(TRIM(s.product_key), ''), TRIM(s.description))
SET s.category = a.c
WHERE (s.category IS NULL OR s.category = '') AND s.is_custom_amount = 0;

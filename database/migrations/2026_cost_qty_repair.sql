-- ============================================================
--  AEIC — Compta : quantité achetée par lot + réparation des lots en cours
--
--  1) product_costs.qty_purchased INT NULL : quantité achetée portée
--     par le lot (en unités produits). NULL = pas de suivi (tous les
--     lots antérieurs restent NULL) ; les ventes/pertes la
--     décrémentent côté affichage (ProductCost::create/update
--     l'écrivent).
--  2) Réparation du bug « plusieurs lots en cours » pour un même
--     produit (fusions brutes de product_key, update() de valid_from
--     sans rééquilibrage, close()/reopen sans garde) : parmi les lots
--     ouverts (valid_to IS NULL), seul le plus récent — MAX(valid_from)
--     — reste en cours, les autres sont fermés à la veille de ce
--     valid_from.
--
--  À appliquer : mysql -u aeic -p aeic < database/migrations/2026_cost_qty_repair.sql
--  Idempotent (ADD COLUMN IF NOT EXISTS + UPDATE jointif à effet nul
--  une fois la base réparée).
-- ============================================================

-- 1. Colonne de quantité achetée (réversible : NULL = pas de suivi).
ALTER TABLE product_costs
    ADD COLUMN IF NOT EXISTS qty_purchased INT NULL AFTER cost_price;

-- 2. Réparation : ferme tous les lots ouverts sauf le plus récent de
--    chaque produit. Le produit garde exactement un lot en cours (le
--    MAX(valid_from) ; à valid_from égal, le départage déterministe
--    created_at/id de ProductCost::normalizePeriods tranche à la
--    marge — ici les ex æquo restent ouverts, réglés à l'exécution).
UPDATE product_costs p
JOIN (
    SELECT product_key, MAX(valid_from) AS last_from
      FROM product_costs
     WHERE valid_to IS NULL
     GROUP BY product_key
    HAVING COUNT(*) > 1
) d ON d.product_key = p.product_key
SET p.valid_to = DATE_SUB(d.last_from, INTERVAL 1 DAY)
WHERE p.valid_to IS NULL
  AND p.valid_from < d.last_from;

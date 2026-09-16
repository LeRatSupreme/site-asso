-- ============================================================
--  AEIC — Achats : total HT à 3 décimales.
--
--  Aligné sur unit_cost (DECIMAL(10,3)) : un total comme
--  3 × 0,155 € = 0,465 € doit être stocké exactement
--  (DECIMAL(10,2) l'arrondissait à 0,47 €).
-- ============================================================

ALTER TABLE purchases
    MODIFY total_ht DECIMAL(10,3) NULL;

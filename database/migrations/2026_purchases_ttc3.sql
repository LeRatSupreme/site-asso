-- ============================================================
--  AEIC — Achats : total TTC à 3 décimales.
--
--  Aligné sur total_ht (DECIMAL(10,3)) : le TTC est calculé
--  depuis le HT non arrondi, un montant comme
--  25,152 € HT + TVA 5,5 % = 26,535 € doit être stocké
--  exactement (DECIMAL(10,2) l'arrondissait à 26,54 €).
-- ============================================================

ALTER TABLE purchases
    MODIFY total_ttc DECIMAL(10,3) NOT NULL DEFAULT 0;

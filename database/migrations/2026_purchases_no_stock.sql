-- ============================================================
--  AEIC — Achats : option « hors stock » par ligne.
--
--  no_stock = 1 : la ligne reste un achat réel (compta) et peut
--  toujours créer un lot de coût de revient (update_cost), mais elle
--  n'alimente NI le stock de référence (product_stocks), NI le stock
--  théorique de l'inventaire (Σ achats de Purchase::qtySince, base 0).
--  Cas d'usage : conso bureau, fournitures, essais, invités…
-- ============================================================

ALTER TABLE purchases
    ADD COLUMN no_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER total_ht;

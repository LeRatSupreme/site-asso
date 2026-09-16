-- ============================================================
--  AEIC — Achats : coût unitaire à 3 décimales + gestion TVA.
--
--  E1 : unit_cost passe en DECIMAL(10,3) pour éviter l'arrondi
--       prématuré (ex : 0,155 € arrondi à 0,16 € faussait le total).
--  E2 : TVA sur les achats. vat_rate NULL = prix saisi déjà TTC
--       (comportement historique) ; total_ht NULL = ligne historique
--       (à traiter comme du TTC).
--  Le coût de revient (product_costs.cost_price) est propagé EN TTC
--  pour rester comparable aux prix de vente TTC (bénéfices cohérents).
-- ============================================================

ALTER TABLE purchases
    MODIFY unit_cost DECIMAL(10,3) NOT NULL DEFAULT 0,
    ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER unit_cost,
    ADD COLUMN total_ht DECIMAL(10,2) NULL AFTER total_ttc;

ALTER TABLE product_costs MODIFY cost_price DECIMAL(10,3) NOT NULL;

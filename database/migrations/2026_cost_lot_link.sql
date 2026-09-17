-- ============================================================
--  AEIC — Compta : lien achat -> lot de coût de revient.
--
--  product_costs.purchase_id référence l'achat (purchases.id) qui a
--  créé le lot (option « mettre à jour le coût de revient » cochée).
--  NULL pour les lots créés manuellement (rétrocompatible).
--
--  Ce lien permet la suppression en cascade : supprimer un achat
--  supprime aussi son lot de coût (et réouvre le lot antérieur si le
--  lot supprimé était « en cours ») puis contre-passe le stock de
--  référence (ProductStock::adjust(-qty) fait à la création).
-- ============================================================

ALTER TABLE product_costs
    ADD COLUMN purchase_id VARCHAR(255) NULL AFTER notes,
    ADD KEY idx_pc_purchase (purchase_id);

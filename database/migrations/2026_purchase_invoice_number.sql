-- =====================================================================
--  AEIC — Migration : numéro de facture sur les achats
--  (référence fournisseur optionnelle, partagée par toute une course ;
--   affichée comme référence dans le livre comptable)
-- =====================================================================

ALTER TABLE purchases
    ADD COLUMN invoice_number VARCHAR(255) NULL;

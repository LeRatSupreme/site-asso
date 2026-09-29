-- =====================================================================
--  AEIC — Migration : numéro de facture sur les dépenses
--  (la colonne « supplier » des dépenses devient « invoice_number » ;
--   les données existantes sont conservées)
-- =====================================================================

ALTER TABLE expenses
    CHANGE supplier invoice_number VARCHAR(255) NULL;

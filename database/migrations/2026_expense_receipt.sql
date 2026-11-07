-- =====================================================================
--  AEIC — Migration : justificatif (ticket) sur les dépenses
--
--  Idempotent sur une base à jour (l'erreur « Duplicate column » est
--  attendue et ignorée). À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_expense_receipt.sql
-- =====================================================================

ALTER TABLE expenses
    ADD COLUMN receipt_path VARCHAR(255) NULL AFTER notes;

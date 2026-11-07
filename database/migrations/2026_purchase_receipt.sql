-- ============================================================
--  AEIC — Justificatif des achats (ticket conservé au scan).
--
--  Quand une facture est scannée (page Achats ou livre
--  comptable), le fichier envoyé est conservé comme justificatif
--  et joint aux lignes d'achats créées : il s'affiche ensuite
--  dans la vignette « Derniers tickets & dépenses » du livre.
--  Chemin relatif sous /assets (ex. « uploads/receipts/xx.pdf »),
--  contrôlé par PurchaseSaver::receiptPathOrNull.
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_purchase_receipt.sql
--  Idempotent (ADD COLUMN IF NOT EXISTS, MariaDB).
-- ============================================================

ALTER TABLE purchases
    ADD COLUMN IF NOT EXISTS receipt_path VARCHAR(255) NULL AFTER invoice_number;

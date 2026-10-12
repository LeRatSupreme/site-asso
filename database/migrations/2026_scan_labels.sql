-- ============================================================
--  AEIC — File « libellés factures non rattachés » (scans).
--
--  Quand un scan de facture (METRO…) produit une ligne qu'aucun
--  produit du catalogue ne reconnaît (« EVIEN 50CL » au lieu
--  d'« Evian 50cl »…), le libellé est mémorisé ici avec son
--  nombre d'occurrences. La page « Ajouter une appellation » le
--  propose au rattachement ; l'entrée disparaît dès qu'un alias
--  ou une fiche la résout.
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_scan_labels.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS scan_labels (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    label       VARCHAR(255) NOT NULL UNIQUE,
    occurrences INT NOT NULL DEFAULT 1,
    first_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

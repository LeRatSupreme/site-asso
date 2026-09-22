-- ============================================================
--  AEIC — Drapeau « plus en vente » par produit.
--
--  Produits saisonniers ou discontinués (ex. Redbull Winter) :
--  ignorés par le comptage à l'aveugle, la page Inventaire et le
--  réapprovisionnement. Historique des ventes inchangé.
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_product_discontinued.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS product_discontinued (
    product_key VARCHAR(255) NOT NULL PRIMARY KEY,
    updated_by  VARCHAR(255) NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

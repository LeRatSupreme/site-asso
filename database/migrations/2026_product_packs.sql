-- ============================================================
--  AEIC — Taille de pack d'achat par produit.
--
--  Le réapprovisionnement se commande par packs (12, 24, parfois 32…) :
--  quand un pack est défini pour un produit, « À commander » est arrondi
--  au multiple supérieur du pack (besoin brut 2 → commander 12).
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_product_packs.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS product_packs (
    product_key VARCHAR(255) NOT NULL PRIMARY KEY,
    pack_size   INT NOT NULL DEFAULT 0,
    updated_by  VARCHAR(255) NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

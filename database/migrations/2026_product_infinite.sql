-- ============================================================
--  AEIC — Drapeau « stock infini » par produit.
--
--  Produits que l'association ne réapprovisionne jamais (fournis
--  gracieusement, stock non géré…) : sur la page Réapprovisionnement,
--  l'autonomie affiche « ∞ » et le produit n'est jamais proposé à la
--  commande, même sans comptage inventaire.
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_product_infinite.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS product_infinite (
    product_key VARCHAR(255) NOT NULL PRIMARY KEY,
    updated_by  VARCHAR(255) NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

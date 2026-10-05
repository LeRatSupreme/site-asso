-- ============================================================
--  AEIC — Suivi « stock à zéro depuis quand ? » par produit.
--
--  Alimente l'automatisation du cycle pause/reprise (voir
--  App\Core\Compta\ProductLifecycle::sweep) :
--    - produit en vente à stock 0 (ou négatif) → date de début notée ;
--    - toujours à 0 après 7 jours → mise en pause automatique ;
--    - stock redevient positif → retour en vente automatique (si la
--      pause avait elle-même été posée automatiquement).
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_product_zero_since.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS product_zero_since (
    product_key VARCHAR(255) NOT NULL PRIMARY KEY,
    zero_since  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

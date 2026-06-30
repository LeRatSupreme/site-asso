-- ============================================================
--  AEIC — Module Promotions (menus cafétéria, ventes spéciales)
--  Table : promotions
--  mysql -u aeic -p aeic < database/migrations/2026_promotions.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS promotions (
    id          VARCHAR(255) PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    description TEXT NULL,
    product_key VARCHAR(255) NULL,      -- produit concerné (optionnel)
    old_price   DECIMAL(10,2) NULL,     -- ancien prix
    new_price   DECIMAL(10,2) NOT NULL, -- prix promo
    image       VARCHAR(255) NULL,
    badge       VARCHAR(50) NULL,       -- ex: "PROMO", "NOUVEAU", "-20%"
    starts_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at     DATETIME NULL,          -- NULL = illimité
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_promotions_active (is_active),
    KEY idx_promotions_period (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

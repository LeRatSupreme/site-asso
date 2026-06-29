-- ============================================================
--  AEIC — Stocks saisis sur la page Réappro
--  Table dédiée (découple le stock de la table cafétéria).
--  Clé = product_key canonique (ex: "Bueno", "Coca").
-- ============================================================

CREATE TABLE IF NOT EXISTS product_stocks (
    product_key VARCHAR(255) NOT NULL,
    stock       INT NOT NULL DEFAULT 0,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (product_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

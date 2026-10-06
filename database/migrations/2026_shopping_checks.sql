-- -------------------------------------------------------------------
--  Cases cochées de la Liste de courses : état partagé entre tous les
--  appareils (kiosque + admin), avec trace de qui a coché. Décocher
--  supprime la ligne. (2026_shopping_checks.sql)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shopping_checks (
    product_key VARCHAR(255) NOT NULL PRIMARY KEY,
    checked_by  VARCHAR(255) NOT NULL,
    checked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

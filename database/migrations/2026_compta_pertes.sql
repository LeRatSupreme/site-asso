-- ============================================================
--  AEIC — Journal des pertes : casse, vol, périmé, offert...
-- ============================================================

-- Pertes de stock déduites du stock théorique de l'inventaire
-- (théorique = dernier comptage + achats − ventes − pertes)
CREATE TABLE IF NOT EXISTS losses (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    lost_at     DATE NOT NULL,
    product_key VARCHAR(255) NOT NULL,
    quantity    INT NOT NULL DEFAULT 1,
    reason      VARCHAR(50) NOT NULL DEFAULT 'DIVERS',
    note        VARCHAR(500) NULL,
    created_by  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_losses_product (product_key),
    KEY idx_losses_date (lost_at),
    KEY idx_losses_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

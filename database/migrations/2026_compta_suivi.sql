-- ============================================================
--  AEIC — Suivi comptable avancé : dépenses, achats réels,
--  inventaires, budgets prévisionnels.
-- ============================================================

-- Dépenses de l'association (charges hors coût d'achat matière)
CREATE TABLE IF NOT EXISTS expenses (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    spent_at    DATE NOT NULL,
    category    VARCHAR(100) NOT NULL DEFAULT 'DIVERS',
    label       VARCHAR(255) NOT NULL,
    amount_ttc  DECIMAL(10,2) NOT NULL,
    amount_ht   DECIMAL(10,2) NULL,
    vat         DECIMAL(10,2) NULL,
    supplier    VARCHAR(255) NULL,
    notes       TEXT NULL,
    created_by  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expenses_date (spent_at),
    KEY idx_expenses_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achats réels de réapprovisionnement (base du stock théorique)
CREATE TABLE IF NOT EXISTS purchases (
    id           VARCHAR(255) NOT NULL PRIMARY KEY,
    purchased_at DATE NOT NULL,
    supplier     VARCHAR(255) NULL,
    product_key  VARCHAR(255) NOT NULL,
    quantity     INT NOT NULL DEFAULT 1,
    unit_cost    DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_ttc    DECIMAL(10,2) NOT NULL DEFAULT 0,
    notes        TEXT NULL,
    created_by   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_purchases_product (product_key),
    KEY idx_purchases_date (purchased_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptages physiques d'inventaire (écart compté / théorique)
CREATE TABLE IF NOT EXISTS inventory_counts (
    id              VARCHAR(255) NOT NULL PRIMARY KEY,
    counted_at      DATETIME NOT NULL,
    product_key     VARCHAR(255) NOT NULL,
    counted_qty     INT NOT NULL,
    theoretical_qty INT NOT NULL,
    gap             INT NOT NULL,
    note            VARCHAR(500) NULL,
    created_by      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inv_product (product_key),
    KEY idx_inv_date (counted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budgets prévisionnels (objectif de CA + enveloppes de dépenses)
CREATE TABLE IF NOT EXISTS budgets (
    id             VARCHAR(255) NOT NULL PRIMARY KEY,
    year           SMALLINT NOT NULL,
    month          TINYINT NOT NULL,
    category       VARCHAR(100) NOT NULL,
    planned_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_budget_line (year, month, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

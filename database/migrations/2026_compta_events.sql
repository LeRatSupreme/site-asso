-- ============================================================
--  AEIC — Événements de trésorerie (module Trésorerie & stock)
-- ============================================================

-- Événements suivis par la trésorerie : le nom = libellé exact du
-- bouton SumUp, les ventes importées portant ce nom s'y rattachent.
CREATE TABLE IF NOT EXISTS compta_events (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    date_from  DATE NOT NULL,
    date_to    DATE NOT NULL,
    notes      TEXT NULL,
    created_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ce_date (date_from),
    KEY idx_ce_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coûts attachés à un événement : chaque coût crée aussi une dépense
-- liée (catégorie EVENEMENT), expense_id garde le lien pour la
-- suppression en cascade.
CREATE TABLE IF NOT EXISTS compta_event_costs (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    event_id   VARCHAR(255) NOT NULL,
    spent_at   DATE NOT NULL,
    label      VARCHAR(255) NOT NULL,
    amount_ttc DECIMAL(10,2) NOT NULL,
    expense_id VARCHAR(255) NULL,
    created_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cec_event (event_id),
    CONSTRAINT fk_cec_event FOREIGN KEY (event_id) REFERENCES compta_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

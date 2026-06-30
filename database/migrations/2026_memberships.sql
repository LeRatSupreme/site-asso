-- =====================================================================
--  AEIC — Migration : adhésions / cotisations annuelles (Fonctionnalité 13)
--
--  Idempotent (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_memberships.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS memberships (
    id          VARCHAR(255) PRIMARY KEY,
    user_id     VARCHAR(255) NOT NULL,
    season      VARCHAR(10) NOT NULL,   -- ex: "2026-2027"
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_at     DATETIME NULL,
    status      ENUM('PENDING','PAID','EXPIRED') NOT NULL DEFAULT 'PENDING',
    sumup_ref   VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_membership (user_id, season),
    KEY idx_memberships_season (season),
    KEY idx_memberships_status (status),
    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres liés aux adhésions.
INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_membership_price',   'membership_price',   '5.00', 'text',    'Prix de la cotisation (€)',            'features'),
    ('set_membership_enabled', 'membership_enabled', '1',    'boolean', 'Activer la gestion des adhésions',      'features'),
    ('set_membership_season',  'membership_season',  '',     'text',    'Saison en cours (vide = auto, ex: 2026-2027)', 'features');

-- ============================================================
--  AEIC — Traçabilité de la caisse (liquide)
--  Appliquer AVANT le git pull : mysql -u aeic -p aeic < database/migrations/2026_cash_ledger.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

-- Mouvements manuels de caisse. Les ventes en liquide ne sont PAS
-- dupliquées ici : elles sont calculées en direct depuis la table
-- `sales` (payment_method = 'LIQUIDE') pour rester cohérentes avec
-- l'immuabilité des ventes et la déduplication import CSV / synchro.
--   FOND       : fond de caisse initial / remise en caisse (+)
--   DEPOT      : dépôt à la banque (−)
--   AJUSTEMENT : écart constaté au comptage, appliqué pour réaligner
--                le théorique sur le compté (signé)
CREATE TABLE IF NOT EXISTS cash_movements (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    type       ENUM('FOND','DEPOT','AJUSTEMENT') NOT NULL,
    amount     DECIMAL(10,2) NOT NULL,
    label      VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cash_mov_created (created_at),
    KEY idx_cash_mov_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptages physiques : le théorique du moment est figé pour l'historique,
-- l'écart = compté − théorique (négatif = manquant = vol potentiel).
CREATE TABLE IF NOT EXISTS cash_counts (
    id                 VARCHAR(255) NOT NULL PRIMARY KEY,
    counted_amount     DECIMAL(10,2) NOT NULL,
    theoretical_amount DECIMAL(10,2) NOT NULL,
    ecart              DECIMAL(10,2) NOT NULL,
    label              VARCHAR(255) NOT NULL DEFAULT '',
    created_by         VARCHAR(255) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cash_counts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

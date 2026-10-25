-- ============================================================
--  AEIC — Suivi des migrations appliquées sur la base (bootstrap).
--  La table est remplie par scripts/deploy.sh avec le nom de chaque
--  migration jouée ; ce fichier ne fait qu'assurer son existence.
-- ============================================================

CREATE TABLE IF NOT EXISTS applied_migrations (
    name       VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  AEIC — Kiosques personnalisables
--  Appliquer AVANT le git pull : mysql -u aeic -p aeic < database/migrations/2026_kiosques_personnalises.sql
--  Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

-- Kiosques personnalisables : l'admin peut créer autant de kiosques qu'il
-- veut, chacun avec un nom, un jeton secret (le lien EST
-- l'authentification, cf. Kiosk::sendNoStore()) et la liste des tuiles
-- autorisées stockée en JSON (ids du catalogue App\Core\Compta\KioskPages).
-- Le jeton est indexé UNIQUE : la recherche par jeton passe par l'index,
-- la confirmation se fait côté PHP en hash_equals (cohérence timing).
CREATE TABLE IF NOT EXISTS kiosques (
    id VARCHAR(255) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    token VARCHAR(64) NOT NULL,
    pages TEXT NOT NULL,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uniq_kiosques_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

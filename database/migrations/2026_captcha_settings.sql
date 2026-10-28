-- =====================================================================
--  AEIC — Migration : réglages du CAPTCHA auto-hébergé
--
--  Table dédiée (isolée des settings du site) pour les paramètres du
--  rendu anti-bot : longueur du code, rotation des caractères, bruit.
--  Les valeurs par défaut vivent DANS LE CODE (App\Core\Captcha\
--  CaptchaService::DEFAULTS) : cette table peut rester VIDE, on n'y
--  écrit que ce qui s'écarte des défauts (via scripts/captcha_set_param.php).
--  Toute valeur lue est de toute façon clampée aux bornes strictes
--  (CaptchaService::BOUNDS) côté PHP.
--  Idempotent (CREATE TABLE IF NOT EXISTS).
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_captcha_settings.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS captcha_settings (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    `key`      VARCHAR(100) NOT NULL,
    value      VARCHAR(255) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_captcha_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

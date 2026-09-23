-- =====================================================================
--  AEIC — Migration : table des messages programmés SMS (Free Mobile)
--
--  Remplace la configuration unique (settings sms_report_*) par une
--  liste de messages programmés, chacun avec ses jours, son heure et
--  son modèle. Les destinataires restent partagés (setting sms_recipients).
--  Idempotent. À appliquer :
--      mysql -u aeic -p aeic < database/migrations/2026_sms_reports_table.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS sms_reports (
    id            VARCHAR(64) NOT NULL PRIMARY KEY,
    label         VARCHAR(80) NOT NULL DEFAULT '',
    is_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    days          VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    send_time     TIME NOT NULL DEFAULT '20:00',
    template      TEXT NULL,
    last_sent_day DATE NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

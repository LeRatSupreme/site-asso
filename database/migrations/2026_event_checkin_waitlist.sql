-- ============================================================
--  AEIC — Fonctionnalités 7 (liste d'attente) & 8 (check-in QR)
--  - qr_token   : code unique d'inscription pour le check-in
--  - checked_in : présence marquée le jour J
--  La table event_waitlist existe déjà dans schema.sql.
--  mysql -u aeic -p aeic < database/migrations/2026_event_checkin_waitlist.sql
-- ============================================================

ALTER TABLE event_registrations
    ADD COLUMN IF NOT EXISTS qr_token   VARCHAR(64) NULL UNIQUE,
    ADD COLUMN IF NOT EXISTS checked_in TINYINT(1)  NOT NULL DEFAULT 0;

-- qr_token est UNIQUE : un index est donc déjà créé automatiquement,
-- suffisant pour la recherche par token lors du scan d'entrée.

-- ============================================================
--  AEIC — Rappels d'événements (24h et 1h avant)
--  Suit quels rappels ont déjà été envoyés pour éviter les doublons.
--  mysql -u aeic -p aeic < database/migrations/2026_event_reminders.sql
-- ============================================================

ALTER TABLE event_registrations
    ADD COLUMN IF NOT EXISTS reminder_24h_sent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS reminder_1h_sent  TINYINT(1) NOT NULL DEFAULT 0;

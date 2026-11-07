-- AEIC — Catégories d'événements
-- mysql -u aeic -p aeic < database/migrations/2026_event_categories.sql

ALTER TABLE events ADD COLUMN IF NOT EXISTS category VARCHAR(100) NULL AFTER location;

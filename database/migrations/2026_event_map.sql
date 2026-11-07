-- ============================================================
--  AEIC — Carte par événement
--  Ajoute : show_map (afficher une carte), map_lat, map_lon (coords géocodées).
--  mysql -u aeic -p aeic < database/migrations/2026_event_map.sql
-- ============================================================

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS show_map TINYINT(1) NOT NULL DEFAULT 0 AFTER location,
    ADD COLUMN IF NOT EXISTS map_lat  DECIMAL(10, 7) NULL AFTER show_map,
    ADD COLUMN IF NOT EXISTS map_lon  DECIMAL(10, 7) NULL AFTER map_lat;

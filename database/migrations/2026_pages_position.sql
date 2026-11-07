-- ============================================================
--  AEIC — Ordre d'affichage des pages CMS (drag & drop admin).
--
--  Nouvelle colonne pages.position (INT, NOT NULL DEFAULT 0) :
--  tri croissant, 1 = première. 0 = « jamais positionnée ».
--
--  Le seed ci-dessous numérote les pages existantes pour partir
--  d'un état déterminé : ordre alphabétique du titre (puis id pour
--  départager), numérotation à partir de 1 — ou après le max
--  existant lors d'un rejeu (une page restée à 0 part en fin de
--  liste, un réordonnancement manuel n'est jamais écrasé).
--
--  À appliquer sur une base existante :
--    mysql -u aeic -p aeic < database/migrations/2026_pages_position.sql
--
--  Idempotent / rejouable (syntaxe MariaDB : IF NOT EXISTS) :
--  ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS, seed
--  limité aux lignes WHERE position = 0.
-- ============================================================

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS position INT NOT NULL DEFAULT 0 AFTER is_published;

CREATE INDEX IF NOT EXISTS idx_pages_position ON pages (position);

SET @pages_position_base := COALESCE((SELECT MAX(position) FROM pages), 0);

UPDATE pages p
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (ORDER BY title ASC, id ASC) AS new_position
    FROM pages
    WHERE position = 0
) ranked ON ranked.id = p.id
SET p.position = @pages_position_base + ranked.new_position;

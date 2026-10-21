-- =====================================================================
--  AEIC — Migration v2 : réglages du moteur de recommandations
--  (caps séparés idées / corrections, groupe « recommandations »)
--
--  reco_max_actives est OBSOLÈTE (le cœur l'a remplacé par ces deux
--  caps) : la clé reste en base mais n'est plus lue ni éditable.
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_recommendations_settings_v2.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_reco_max_idees',       'reco_max_idees',       '3', 'text', 'Nombre max d''idées commerciales',        'recommandations'),
    ('set_reco_max_corrections', 'reco_max_corrections', '5', 'text', 'Nombre max de corrections affichées',    'recommandations');

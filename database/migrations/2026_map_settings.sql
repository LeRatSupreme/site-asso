-- ============================================================
--  AEIC — Réglages pour la carte « Où nous trouver »
--  (marqueur + adresse), ajustables dans Admin > Paramètres.
-- ============================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_map_lat',   'map_lat',   '50.9463',               'text', 'Carte — Latitude',  'contact'),
    ('set_map_lon',   'map_lon',   '1.8456',                'text', 'Carte — Longitude', 'contact'),
    ('set_address',   'address',   '19 Rue Louis David, 62100 Calais', 'text', 'Adresse affichée', 'contact');

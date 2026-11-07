-- =====================================================================
--  AEIC — Migration : réglages du moteur de recommandations
--  (groupe « recommandations », page Admin > Paramètres)
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_recommendations_settings.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_reco_marge_min',              'reco_marge_min',               '0.15', 'text', 'Marge minimale (fraction, ex. 0.15 = 15 %)',       'recommandations'),
    ('set_reco_stock_dormant_semaines', 'reco_stock_dormant_semaines',  '8',    'text', 'Seuil stock dormant (semaines)',                   'recommandations'),
    ('set_reco_stock_dormant_min',      'reco_stock_dormant_min',       '10',   'text', 'Stock minimum pour stock dormant (unités)',        'recommandations'),
    ('set_reco_echantillon_min_unites', 'reco_echantillon_min_unites',  '20',   'text', 'Échantillon minimal par règle (unités / 30 j)',    'recommandations'),
    ('set_reco_duo_remise_max',         'reco_duo_remise_max',          '0.10', 'text', 'Remise max d''un duo (fraction)',                  'recommandations'),
    ('set_reco_immobilise_seuil_eur',   'reco_immobilise_seuil_eur',    '30',   'text', 'Seuil stock immobilisé gamme (€)',                 'recommandations'),
    ('set_reco_max_actives',            'reco_max_actives',             '5',    'text', 'Nombre max d''idées actives',                      'recommandations');

-- ============================================================
--  AEIC — Mapping complet des libellés SumUp -> produits canoniques
--  + réapplication aux ventes existantes (consolidation stats).
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_aliases_full.sql
--  Idempotent (réinsertion impossible grâce à l'unicité raw_description).
-- ============================================================

INSERT INTO product_aliases (id, raw_description, product_key, category) VALUES
    -- Boissons : Coca
    ('al_coca_1',  'CocaCola',                          'Coca',        'Boisson'),
    ('al_coca_2',  'Coca-Cola',                         'Coca',        'Boisson'),
    ('al_coca_3',  'Coca Cola',                         'Coca',        'Boisson'),
    ('al_coca_4',  'Coca cherry',                       'Coca',        'Boisson'),
    ('al_coca_5',  'Coca_cherry',                       'Coca',        'Boisson'),
    ('al_coca_6',  'Coca zero',                         'Coca',        'Boisson'),
    ('al_coca_7',  'Coca sans sucre',                   'Coca',        'Boisson'),
    -- Boissons : Red Bull
    ('al_rb_1',    'Red bull ice',                      'Red Bull',    'Boisson'),
    ('al_rb_2',    'Redbull',                           'Red Bull',    'Boisson'),
    ('al_rb_3',    'Redbull_Blanche',                   'Red Bull',    'Boisson'),
    ('al_rb_4',    'Red bull summer',                   'Red Bull',    'Boisson'),
    ('al_rb_5',    'Redbull Myrtille',                  'Red Bull',    'Boisson'),
    ('al_rb_6',    'Redbull Pomme',                     'Red Bull',    'Boisson'),
    ('al_rb_7',    'Redbull_Bleue',                     'Red Bull',    'Boisson'),
    ('al_rb_8',    'Red bull',                          'Red Bull',    'Boisson'),
    -- Boissons : Monster
    ('al_mon_1',   'Monster Blanche',                   'Monster',     'Boisson'),
    ('al_mon_2',   'Monster_Bleue',                     'Monster',     'Boisson'),
    ('al_mon_3',   'Monster Bleue',                     'Monster',     'Boisson'),
    ('al_mon_4',   'Monster rose (punch)',              'Monster',     'Boisson'),
    ('al_mon_5',   'Monster_Rose',                      'Monster',     'Boisson'),
    ('al_mon_6',   'Monster verte (energy)',            'Monster',     'Boisson'),
    -- Boissons : Oasis
    ('al_oas_1',   'Oasis',                             'Oasis',       'Boisson'),
    ('al_oas_2',   'Oasis (ice tea)',                   'Oasis',       'Boisson'),
    ('al_oas_3',   'Oasis (Ice tea / thé mangue passion)', 'Oasis',    'Boisson'),
    ('al_oas_4',   'Oasis (Ice tea / thémangue passion)','Oasis',     'Boisson'),
    ('al_oas_5',   'Oasis ice tea',                     'Oasis',       'Boisson'),
    ('al_oas_6',   'Oasis pomme cassis framboise',      'Oasis',       'Boisson'),
    ('al_oas_7',   'Oasis tropical',                    'Oasis',       'Boisson'),
    -- Boissons : Fanta
    ('al_fan_1',   'Fanta_Orange',                      'Fanta',       'Boisson'),
    ('al_fan_2',   'Fanta_Mangue_Dragon',               'Fanta',       'Boisson'),
    ('al_fan_3',   'Fanta_Exotique',                    'Fanta',       'Boisson'),
    ('al_fan_4',   'Fanta_Citron',                      'Fanta',       'Boisson'),
    ('al_fan_5',   'Fanta_Cassis',                      'Fanta',       'Boisson'),
    -- Boissons : Minute Maid
    ('al_mm_1',    'MinuteMaid_Pomme',                  'Minute Maid', 'Boisson'),
    ('al_mm_2',    'MinuteMaid_Orange',                 'Minute Maid', 'Boisson'),
    -- Boissons : divers
    ('al_crist',   'Cristaline',                        'Cristaline',  'Boisson'),
    ('al_eau',     'eau',                               'Eau',         'Boisson'),
    ('al_lipt_1',  'Lipton',                            'Lipton',      'Boisson'),
    ('al_lipt_2',  'Lipton Ice Tea',                    'Lipton',      'Boisson'),
    ('al_orang',   'Orangina',                          'Orangina',    'Boisson'),
    ('al_perrier','Perrier',                            'Perrier',     'Boisson'),
    ('al_drpep_1', 'Dr Pepper',                         'Dr Pepper',   'Boisson'),
    ('al_drpep_2', 'Pepper',                            'Dr Pepper',   'Boisson'),
    ('al_sch_1',   'Schweppes Agrumes',                 'Schweppes',   'Boisson'),
    ('al_sch_2',   'schweppes',                         'Schweppes',   'Boisson'),
    ('al_pulco',   'Pulco',                             'Pulco',       'Boisson'),
    ('al_fuze',    'FuzeTea',                           'Fuze Tea',    'Boisson'),
    -- Nourriture : Bueno
    ('al_bue_1',   'Bueno',                             'Bueno',       'Nourriture'),
    ('al_bue_2',   'Bueno_white',                       'Bueno',       'Nourriture'),
    ('al_bue_3',   'Kinder Bueno',                      'Bueno',       'Nourriture'),
    -- Nourriture : Chips
    ('al_chip_1',  'Chips BBQ',                         'Chips',       'Nourriture'),
    ('al_chip_2',  'Chips Nature',                      'Chips',       'Nourriture'),
    ('al_chip_3',  'Chips',                             'Chips',       'Nourriture'),
    -- Nourriture : divers
    ('al_bonbon',  'Bonbon',                            'Bonbon',      'Nourriture'),
    ('al_crepe',   'crepe',                             'Crêpe',       'Nourriture'),
    ('al_mister',  'Mister Freeze',                     'Mister Freeze','Nourriture'),
    ('al_lion_1',  'Lion',                              'Lion',        'Nourriture'),
    ('al_lion_2',  'Lion_Peanut',                       'Lion',        'Nourriture'),
    ('al_kitkat',  'KitKat',                            'KitKat',      'Nourriture'),
    ('al_mars',    'Mars',                              'Mars',        'Nourriture'),
    ('al_snick',   'Snickers',                          'Snickers',    'Nourriture'),
    -- Spécial
    ('al_menubbq', 'Menu BBQ',                          'Menu BBQ',    'Spécial'),
    ('al_panini',  'Panini',                            'Panini',      'Spécial')
ON DUPLICATE KEY UPDATE product_key = VALUES(product_key), category = VALUES(category);

-- Réapplication aux ventes : on rattache chaque vente à sa clé canonique
-- via son libellé brut (description). Les montants personnalisés (sans
-- alias) restent inchangés (product_key NULL).
UPDATE sales s
JOIN product_aliases a ON a.raw_description = s.description
SET s.product_key = a.product_key;

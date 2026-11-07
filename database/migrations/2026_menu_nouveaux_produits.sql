-- AEIC — Ajoute à « Notre carte » les nouveaux produits présents dans
-- l'inventaire compta mais absents de la table products.
-- (Variants Red Bull / Monster / Oasis / Fanta / Minute Maid / Coca,
--  nouveaux snacks et sucettes, Panini, Fuze Tea, Pulco, Café…)
--
-- La catégorie = l'emplacement sur la carte : cat_boissons / cat_snacks / cat_special.
-- Le stock affiché sur la carte vient de l'inventaire compta (appariement
-- par nom, voir StockPublic::stockForMenuProduct()) : les noms ci-dessous
-- sont choisis pour matcher exactement les libellés d'inventaire
-- (« Redbull Blanche » ≈ « Red Bull Blanche », casse/accents ignorés).
-- `stock` n'est là que comme point de départ (dernier comptage connu).
--
-- mysql -u aeic -p aeic < database/migrations/2026_menu_nouveaux_produits.sql
--
-- Idempotent : réexécutable sans écraser le stock déjà en base
-- (la clause ON DUPLICATE KEY UPDATE ne touche pas `stock`).
-- Sont volontairement EXCLUS : « custom amount » et « Montant personnalisé »
-- (options de paiement SumUp, pas des produits vendus).

-- Catégories (déjà présentes, au cas où)
INSERT IGNORE INTO product_categories (id, name, description, `order`, is_active) VALUES
    ('cat_boissons', 'Boissons', 'Sodas, jus, eau, energy', 1, 1),
    ('cat_snacks',   'Snacks',   'Barres, bonbons, chips',   2, 1),
    ('cat_special',  'Spécial',  'Menus et plats spéciaux',  3, 1);

-- Nouveaux produits
INSERT INTO products (id, name, description, price, category_id, stock, is_available, is_active, `order`) VALUES
    -- Boissons : déclinaisons Red Bull
    ('prod_redbull_ice',      'Red Bull Ice',            'Red Bull édition Ice',            1.50, 'cat_boissons', 30, 1, 1, 21),
    ('prod_redbull_abricot',  'Red Bull Abricot',        'Red Bull saveur abricot',         1.50, 'cat_boissons', 34, 1, 1, 22),
    ('prod_redbull_peach',    'Red Bull Peach',          'Red Bull saveur pêche',           1.50, 'cat_boissons',  0, 1, 1, 23),
    ('prod_redbull_seablue',  'Red Bull Sea Blue',       'Red Bull édition Sea Blue',       1.50, 'cat_boissons',  8, 1, 1, 24),
    ('prod_redbull_myrtille', 'Red Bull Myrtille',       'Red Bull saveur myrtille',        1.50, 'cat_boissons',  0, 1, 1, 25),
    ('prod_redbull_summer',   'Red Bull Summer',         'Red Bull édition Summer',         1.50, 'cat_boissons',  0, 1, 1, 26),
    ('prod_redbull_pomme',    'Red Bull Pomme',          'Red Bull saveur pomme',           1.50, 'cat_boissons',  0, 1, 1, 27),
    ('prod_redbull_bleue',    'Red Bull Bleue',          'Red Bull édition bleue',          1.50, 'cat_boissons',  8, 1, 1, 28),
    ('prod_redbull_blanche',  'Red Bull Blanche',        'Red Bull édition blanche',        1.50, 'cat_boissons', 22, 1, 1, 29),
    -- Boissons : déclinaisons Monster
    ('prod_monster_verte',    'Monster Verte',           'Monster édition verte',           1.75, 'cat_boissons',  0, 1, 1, 30),
    ('prod_monster_rose',     'Monster Rose',            'Monster édition rose',            1.75, 'cat_boissons',  2, 1, 1, 31),
    ('prod_monster_bleu',     'Monster Bleu',            'Monster édition bleue',           1.75, 'cat_boissons',  0, 1, 1, 32),
    ('prod_monster_blanche',  'Monster Blanche',         'Monster édition blanche',         1.75, 'cat_boissons',  0, 1, 1, 33),
    ('prod_monster_noire',    'Monster Noire',           'Monster édition noire',           1.75, 'cat_boissons',  0, 1, 1, 34),
    -- Boissons : déclinaisons Oasis
    ('prod_oasis_tropical',   'Oasis Tropical',          'Oasis saveur tropical',           1.00, 'cat_boissons',  7, 1, 1, 35),
    ('prod_oasis_icetea',     'Oasis Ice Tea',           'Oasis ice tea',                   1.00, 'cat_boissons',  7, 1, 1, 36),
    ('prod_oasis_themangue',  'Oasis Thémangue Passion', 'Oasis thé mangue passion',        1.00, 'cat_boissons',  0, 1, 1, 37),
    ('prod_oasis_pommecassis','Oasis Pomme Cassis Framboise', 'Oasis pomme cassis framboise', 1.00, 'cat_boissons', 0, 1, 1, 38),
    -- Boissons : déclinaisons Minute Maid
    ('prod_minutemaid_pomme', 'Minute Maid Pomme',       'Pur jus pomme',                   1.00, 'cat_boissons',  4, 1, 1, 39),
    ('prod_minutemaid_orange','Minute Maid Orange',      'Pur jus orange',                  1.00, 'cat_boissons',  0, 1, 1, 40),
    -- Boissons : déclinaisons Fanta
    ('prod_fanta_orange',     'Fanta Orange',            'Soda orange',                     1.00, 'cat_boissons',  0, 1, 1, 41),
    ('prod_fanta_citron',     'Fanta Citron',            'Soda citron',                     1.00, 'cat_boissons',  0, 1, 1, 42),
    ('prod_fanta_cassis',     'Fanta Cassis',            'Soda cassis',                     1.00, 'cat_boissons',  0, 1, 1, 43),
    ('prod_fanta_exotique',   'Fanta Exotique',          'Soda exotique',                   1.00, 'cat_boissons',  0, 1, 1, 44),
    ('prod_fanta_mangue',     'Fanta Mangue Dragon',     'Soda mangue dragon fruit',        1.00, 'cat_boissons',  0, 1, 1, 45),
    -- Boissons : déclinaisons Coca
    ('prod_coca_zero',        'Coca Zero',               'Soda sans sucres',                1.00, 'cat_boissons', 15, 1, 1, 46),
    ('prod_coca_cherry',      'Coca Cherry',             'Soda saveur cerise',              1.00, 'cat_boissons',  6, 1, 1, 47),
    -- Boissons : autres
    ('prod_fuzetea',          'Fuze Tea',                'Thé glacé',                       1.00, 'cat_boissons',  0, 1, 1, 48),
    ('prod_pulco',            'Pulco',                   'Citron concentré',                1.00, 'cat_boissons',  0, 1, 1, 49),
    ('prod_cafe',             'Café',                    'Café comptoir',                   1.00, 'cat_boissons',  0, 1, 1, 50),
    -- Snacks
    ('prod_bueno_white',      'Bueno White',             'Kinder Bueno white',              1.00, 'cat_snacks',   55, 1, 1, 51),
    ('prod_mars',             'Mars',                    'Barre chocolatée',                1.00, 'cat_snacks',   20, 1, 1, 52),
    ('prod_snickers',         'Snickers',                'Barre chocolatée',                1.00, 'cat_snacks',    0, 1, 1, 53),
    ('prod_kitkat',           'KitKat',                  'Barre chocolatée',                1.00, 'cat_snacks',    0, 1, 1, 54),
    ('prod_chips_nature',     'Chips Nature',            'Paquet de chips nature',          1.50, 'cat_snacks',    0, 1, 1, 55),
    ('prod_chips_bbq',        'Chips BBQ',               'Paquet de chips BBQ',             1.50, 'cat_snacks',    0, 1, 1, 56),
    ('prod_sucette',          'Sucette',                 'Sucette',                         0.20, 'cat_snacks',    0, 1, 1, 57),
    ('prod_sucette_1',        'Sucette 1',               '1 sucette',                       0.20, 'cat_snacks',    0, 1, 1, 58),
    ('prod_sucette_3',        'Sucette 3',               '3 sucettes',                      0.50, 'cat_snacks',    0, 1, 1, 59),
    -- Spécial
    ('prod_panini',           'Panini',                  'Panini chaud',                    2.00, 'cat_special',   0, 1, 1, 60)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    price = VALUES(price),
    category_id = VALUES(category_id),
    is_active = 1;

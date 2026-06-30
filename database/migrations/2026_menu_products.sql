-- AEIC — Remplit la table products avec les vrais produits vendus
-- (basé sur les ventes SumUp réelles).
-- mysql -u aeic -p aeic < database/migrations/2026_menu_products.sql

-- D'abord vide les produits test
DELETE FROM products;

-- Catégories (si pas déjà)
INSERT IGNORE INTO product_categories (id, name, description, `order`, is_active) VALUES
    ('cat_boissons', 'Boissons', 'Sodas, jus, eau, energy', 1, 1),
    ('cat_snacks',   'Snacks',   'Barres, bonbons, chips',   2, 1),
    ('cat_special',  'Spécial',  'Menus et plats spéciaux',   3, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1;

-- Produits (prix arrondis basés sur les ventes réelles)
INSERT INTO products (id, name, description, price, category_id, stock, is_available, is_active, `order`) VALUES
    -- Boissons
    ('prod_redbull',     'Red Bull',      'Boisson énergisante',           1.50, 'cat_boissons', 0, 1, 1, 1),
    ('prod_bueno',       'Kinder Bueno',  'Barre chocolatée',              1.00, 'cat_snacks',   0, 1, 1, 2),
    ('prod_eau',         'Eau',           'Bouteille d'eau',               0.50, 'cat_boissons', 0, 1, 1, 3),
    ('prod_monster',     'Monster',       'Boisson énergisante',           1.75, 'cat_boissons', 0, 1, 1, 4),
    ('prod_coca',        'Coca-Cola',     'Soda classique',                1.00, 'cat_boissons', 0, 1, 1, 5),
    ('prod_oasis',       'Oasis',         'Jus de fruits',                 1.00, 'cat_boissons', 0, 1, 1, 6),
    ('prod_fanta',       'Fanta',         'Soda orange',                   1.00, 'cat_boissons', 0, 1, 1, 7),
    ('prod_minutemaid',  'Minute Maid',   'Jus de fruits',                 1.00, 'cat_boissons', 0, 1, 1, 8),
    ('prod_lipton',      'Lipton',        'Thé glacé',                     1.00, 'cat_boissons', 0, 1, 1, 9),
    ('prod_orangina',    'Orangina',      'Soda orangé',                   1.00, 'cat_boissons', 0, 1, 1, 10),
    ('prod_drpepper',    'Dr Pepper',     'Soda',                          1.00, 'cat_boissons', 0, 1, 1, 11),
    ('prod_cristaline',  'Cristaline',    'Eau plate',                     0.50, 'cat_boissons', 0, 1, 1, 12),
    ('prod_perrier',     'Perrier',       'Eau gazeuse',                   1.00, 'cat_boissons', 0, 1, 1, 13),
    ('prod_schweppes',   'Schweppes',     'Soda',                          1.00, 'cat_boissons', 0, 1, 1, 14),
    -- Snacks
    ('prod_bonbon',      'Bonbon',        'Bonbons assorted',              0.50, 'cat_snacks',   0, 1, 1, 15),
    ('prod_crepe',       'Crêpe',         'Crêpe sucrée',                  1.00, 'cat_snacks',   0, 1, 1, 16),
    ('prod_chips',       'Chips',         'Paquet de chips',               1.50, 'cat_snacks',   0, 1, 1, 17),
    ('prod_lion',        'Lion',          'Barre chocolatée',              1.00, 'cat_snacks',   0, 1, 1, 18),
    -- Spécial
    ('prod_misterfreeze','Mister Freeze', 'Glace sur bâton',               0.50, 'cat_special',  0, 1, 1, 19),
    ('prod_menubbq',     'Menu BBQ',      'Menu barbecue complet',         5.00, 'cat_special',  0, 1, 1, 20)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    price = VALUES(price),
    category_id = VALUES(category_id),
    is_active = 1;

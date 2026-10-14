-- =====================================================================
--  AEIC — Données initiales (seed)
--  À importer APRÈS schema.sql :  mysql -u aeic -p aeic < database/seed.sql
-- =====================================================================
--
--  ⚠️  COMPTE ADMIN PAR DÉFAUT
--  Le mot de passe par défaut 'changeme123' DOIT être haché en bcrypt.
--  Comme un hash bcrypt ne peut être généré qu'en PHP, le champ
--  `password` ci-dessous contient un PLACEHOLDER NON VALIDE : la connexion
--  est donc volontairement impossible tant que le hash réel n'est pas posé.
--
--  Pour activer le compte admin, sur le VPS, générez et appliquez le hash :
--
--      php -r "echo password_hash('changeme123', PASSWORD_BCRYPT), PHP_EOL;"
--
--  Puis, en remplaçant <HASH> par la valeur obtenue :
--
--      UPDATE users SET password = '<HASH>'
--      WHERE email = 'admin@aeic.local';
--
--  >>> Changez immédiatement ce mot de passe après la première connexion. <<<
-- =====================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------
--  Paramètres du site
-- -------------------------------------------------------------------
INSERT INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_site_name',            'site_name',            'AEIC',                                                       'text',    'Nom du site',      'general'),
    ('set_site_description',     'site_description',     'Association Étudiante Informatique de Calais. Fait par les étudiants, pour les étudiants.', 'text', 'Description', 'general'),
    ('set_contact_email',        'contact_email',        'calais.aeic@gmail.com',                                      'text',    'Email de contact', 'general'),
    ('set_logo_url',             'logo_url',             '',                                                           'text',    'Logo',             'general'),
    ('set_app_url',              'app_url',              '',                                                           'text',    'URL publique du site (liens des e-mails)', 'general'),
    ('set_maintenance_mode',     'maintenance_mode',     'false',                                                      'boolean', 'Mode maintenance', 'features'),
    ('set_orders_enabled',       'orders_enabled',       'true',                                                       'boolean', 'Commandes activées','features'),
    ('set_registrations_enabled','registrations_enabled','true',                                                       'boolean', 'Inscriptions activées','features'),
    ('set_membership_price',     'membership_price',     '0',                                                          'text',    'Prix de la cotisation (€)', 'features'),
    ('set_membership_enabled',   'membership_enabled',   '0',                                                          'boolean', 'Activer la gestion des adhésions', 'features'),
    ('set_membership_season',    'membership_season',    '',                                                           'text',    'Saison en cours (vide = auto, ex: 2026-2027)', 'features'),
    ('set_og_image',             'og_image',             '',                                                           'text',    'Image Open Graph par défaut', 'seo'),
    ('set_twitter_handle',       'twitter_handle',       '',                                                           'text',    'Compte Twitter/X (@handle)', 'seo'),
    ('set_seo_keywords',         'seo_keywords',         '',                                                           'text',    'Mots-clés SEO (meta keywords)', 'seo'),
    ('set_google_site_verification', 'google_site_verification', '',                                                 'text',    'Vérification Google Search Console', 'seo'),
    ('set_csp_directives',       'csp_directives',       '',                                                           'text',    'Directives CSP additionnelles', 'seo'),
    ('set_mailer_from',          'mailer_from',          'noreply@aeic.fr',                                            'text',    'Adresse expéditrice', 'email'),
    ('set_mailer_from_name',     'mailer_from_name',     'AEIC',                                                       'text',    'Nom expéditeur', 'email'),
    ('set_smtp_host',            'smtp_host',            '',                                                           'text',    'Hôte SMTP (vide = mail() natif)', 'email'),
    ('set_smtp_port',            'smtp_port',            '587',                                                        'text',    'Port SMTP', 'email'),
    ('set_smtp_encryption',      'smtp_encryption',      '',                                                           'text',    'Chiffrement SMTP (none / tls / ssl ; vide = auto)', 'email'),
    ('set_smtp_user',            'smtp_user',            '',                                                           'text',    'Utilisateur SMTP', 'email'),
    ('set_smtp_pass',            'smtp_pass',            '',                                                           'text',    'Mot de passe SMTP', 'email'),
    ('set_brevo_api_key',        'brevo_api_key',        '',                                                           'text',    'Clé API Brevo', 'email'),
    ('set_sumup_enabled',        'sumup_enabled',        '0',                                                          'boolean', 'Paiements par lien SumUp activés', 'sumup'),
    ('set_sumup_default_link',   'sumup_default_link',   '',                                                           'text',    'Lien de paiement SumUp par défaut', 'sumup'),
    ('set_sumup_fee_rate',       'sumup_fee_rate',       '1.75',                                                       'text',    'Taux de commission SumUp (% — estimation des frais carte)', 'sumup'),
    ('set_address',              'address',              '',                                                           'text',    'Adresse affichée', 'contact'),
    ('set_map_lat',              'map_lat',              '',                                                           'text',    'Carte — Latitude',  'contact'),
    ('set_map_lon',              'map_lon',              '',                                                           'text',    'Carte — Longitude', 'contact'),
    ('set_facebook_url',         'facebook_url',         '',                                                           'text',    'Page Facebook (URL)', 'social'),
    ('set_instagram_url',        'instagram_url',        '',                                                           'text',    'Profil Instagram (URL)', 'social'),
    ('set_linkedin_url',         'linkedin_url',         '',                                                           'text',    'Page LinkedIn (URL)', 'social'),
    ('set_discord_webhook',      'discord_webhook_url',  '',                                                           'text',    'URL Webhook Discord', 'social'),
    ('set_discord_enabled',      'discord_enabled',      '0',                                                          'boolean', 'Activer les annonces Discord', 'social'),
    ('set_sms_report_enabled',   'sms_report_enabled',   '0',                                                          'boolean', 'Rapport SMS quotidien activé', 'sms'),
    ('set_sms_report_days',      'sms_report_days',      '1,2,3,4,5',                                                  'text',    'Jours d''envoi (1=lundi … 7=dimanche)', 'sms'),
    ('set_sms_report_time',      'sms_report_time',      '20:00',                                                      'text',    'Heure d''envoi', 'sms'),
    ('set_sms_report_template',  'sms_report_template',  '',                                                           'text',    'Modèle du message (variables {date} {ca} {benefice} {qty} {top})', 'sms'),
    ('set_sms_recipients',       'sms_recipients',       '[]',                                                         'text',    'Destinataires Free Mobile (JSON)', 'sms'),
    ('set_sms_report_last_sent', 'sms_report_last_sent', '',                                                           'text',    'Dernier envoi du rapport (garde anti-doublon)', 'sms'),
    ('set_sms_chart_token',      'sms_chart_token',      '',                                                           'text',    'Jeton du graphique SMS (auto-généré)', 'sms'),
    ('set_admin_kiosk_token',    'admin_kiosk_token',    '',                                                           'text',    'Jeton kiosque admins (auto-généré)', 'securite'),
    ('set_reappro_kiosk_token',  'reappro_kiosk_token',  '',                                                           'text',    'Jeton kiosque membres/réappro (auto-généré)', 'securite')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- -------------------------------------------------------------------
--  Utilisateur admin par défaut
--  (mot de passe à définir via password_hash — voir encadré ci-dessus)
-- -------------------------------------------------------------------
INSERT INTO users (id, prenom, nom, email, password, role, is_active) VALUES
    ('user_admin', 'Admin', 'AEIC', 'admin@aeic.local',
     '$2y$10$PLACEHOLDERCHANGEZMOIAVECUNVRAIHASHBCRYPTxxxxxxxxx',
     'ADMIN', 1)
ON DUPLICATE KEY UPDATE prenom = VALUES(prenom), nom = VALUES(nom), role = VALUES(role);

-- -------------------------------------------------------------------
--  Membres du bureau (exemples)
-- -------------------------------------------------------------------
INSERT INTO team_members (id, prenom, nom, role, pole, bio, photo, is_highlight, `order`, is_active) VALUES
    ('tm_president',  'Alex',     'Martin',  'Président',       'bureau',       'Coordonne la vie de l''association et représente l''AEIC.', NULL, 1, 1, 1),
    ('tm_vp',         'Sarah',    'Lopez',   'Vice-présidente', 'bureau',       'Épaulle la présidence et pilote les projets transverses.', NULL, 1, 2, 1),
    ('tm_tresorier',  'Tom',      'Bernard', 'Trésorier',       'bureau',       'Gère les finances, les paiements et la comptabilité.',       NULL, 1, 3, 1),
    ('tm_secretaire', 'Inès',     'Dubois',  'Secrétaire',      'bureau',       'Tient les comptes-rendus et la communication interne.',      NULL, 1, 4, 1),
    ('tm_comm',       'Lucas',    'Petit',   'Resp. communication','communication', 'Anime les réseaux sociaux et la communication de l''asso.', NULL, 0, 5, 1)
ON DUPLICATE KEY UPDATE role = VALUES(role), pole = VALUES(pole);

-- -------------------------------------------------------------------
--  Pages CMS (contenu simple — à enrichir depuis l''admin plus tard)
-- -------------------------------------------------------------------
INSERT INTO pages (id, slug, title, content, meta_title, meta_description, is_published) VALUES
    ('page_presentation',
     'presentation',
     'L''association AEIC',
     '<h2>Notre mission</h2><p>L''AEIC fédère les étudiants en informatique du campus de Calais autour d''événements, de projets et de moments de partage.</p>',
     'L''association — AEIC', 'Qui sommes-nous : mission, valeurs et chiffres clés de l''AEIC.', 1),
    ('page_team',
     'team',
     'L''équipe AEIC',
     '<p>Le bureau de l''AEIC, composé d''étudiants bénévoles.</p>',
     'L''équipe — AEIC', 'Le bureau de l''AEIC.', 1),
    ('page_legal',
     'legal',
     'Mentions légales',
     '<h2>Éditeur</h2><p>AEIC — Association Étudiante Informatique de Calais.</p><h2>Responsable de publication</h2><p>Le bureau de l''AEIC.</p><h2>Hébergement</h2><p>Serveur dédié (VPS).</p>',
     'Mentions légales — AEIC', 'Mentions légales de l''AEIC.', 1),
    ('page_privacy',
     'privacy',
     'Politique de confidentialité',
     '<h2>Données collectées</h2><p>Nom, prénom, adresse e-mail, inscriptions et commandes.</p><h2>Vos droits</h2><p>Droit d''accès, de rectification, d''effacement et d''opposition (RGPD).</p><h2>Contact</h2><p>calais.aeic@gmail.com</p>',
     'Politique de confidentialité — AEIC', 'Protection des données (RGPD).', 1),
    ('page_cgu',
     'cgu',
     'Conditions d''utilisation',
     '<h2>Objet</h2><p>Les présentes conditions régissent l''utilisation du site de l''AEIC et de l''espace membre.</p><h2>Inscription</h2><p>L''inscription est réservée aux étudiants. Les informations fournies doivent être exactes.</p><h2>Responsabilité</h2><p>L''AEIC s''efforce de maintenir le site accessible mais ne saurait être tenue responsable des interruptions de service.</p>',
     'Conditions d''utilisation — AEIC', 'Conditions Générales d''Utilisation du site AEIC.', 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- -------------------------------------------------------------------
--  Cafétéria : catégories & produits d''exemple
-- -------------------------------------------------------------------
INSERT INTO product_categories (id, name, description, `order`, is_active) VALUES
    ('cat_boissons', 'Boissons', 'Sodas, jus, eau.', 1, 1),
    ('cat_snacks',   'Snacks',   'Barres, biscuits, sucré.', 2, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO products (id, name, description, price, category_id, stock, is_available, is_active, `order`) VALUES
    ('prod_coca',   'Coca-Cola',     '33cl',  1.50, 'cat_boissons', 50, 1, 1, 1),
    ('prod_oasis',  'Oasis',         '33cl',  1.50, 'cat_boissons', 40, 1, 1, 2),
    ('prod_eau',    'Cristaline',    '50cl',  1.00, 'cat_boissons', 60, 1, 1, 3),
    ('prod_bueno',  'Kinder Bueno',  '',      1.00, 'cat_snacks',   30, 1, 1, 1),
    ('prod_chips',  'Chips',         'Paquet',1.00, 'cat_snacks',   25, 1, 1, 2)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- -------------------------------------------------------------------
--  Événements d''exemple (publiés)
-- -------------------------------------------------------------------
INSERT INTO events (id, slug, title, excerpt, description, date, location, is_featured, is_published) VALUES
    ('evt_soiree',
     'soiree-integration-2026',
     'Soirée d''intégration',
     'Le rendez-vous de rentrée de tous les étudiants en info.',
     '<p>Soirée, musique, jeux et rencontre — pour bien démarrer l''année ensemble.</p>',
     '2026-09-12 20:00:00',
     'Campus de Calais — Amphi A',
     1, 1),
    ('evt_lan',
     'lan-party-2026',
     'LAN Party',
     'Tournois jeux vidéo toute la nuit.',
     '<p>CS2, League of Legends, Smash... apportez votre PC et vos manettes !</p>',
     '2026-10-04 18:00:00',
     'Salle des associations',
     1, 1),
    ('evt_conference',
     'conference-ia-2026',
     'Conférence IA',
     'Intervenants pro autour de l''IA générative.',
     '<p>Échanges avec des experts du secteur sur les usages et enjeux de l''IA.</p>',
     '2026-11-20 18:30:00',
     'Amphi B',
     0, 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- -------------------------------------------------------------------
--  Wordle : jeu de démarrage (24 FR + 24 EN)
--  Le remplissage complet se fait via scripts/import_wordle_words.php
--  (dictionnaires système) ; ces mots courants suffisent à jouer dès
--  l'installation. Convention : MAJUSCULES A-Z sans accents,
--  5 lettres = facile, 6 = moyen, 7 = difficile (schéma v2).
-- -------------------------------------------------------------------
INSERT IGNORE INTO wordle_words (word, language, length, difficulty) VALUES
    -- FR — facile (5 lettres)
    ('TABLE', 'fr', 5, 'facile'),
    ('VERRE', 'fr', 5, 'facile'),
    ('ECOLE', 'fr', 5, 'facile'),
    ('CHIEN', 'fr', 5, 'facile'),
    ('PLAGE', 'fr', 5, 'facile'),
    ('LIVRE', 'fr', 5, 'facile'),
    ('POMME', 'fr', 5, 'facile'),
    ('VILLE', 'fr', 5, 'facile'),
    -- FR — moyen (6 lettres)
    ('MAISON', 'fr', 6, 'moyen'),
    ('JARDIN', 'fr', 6, 'moyen'),
    ('CHEVAL', 'fr', 6, 'moyen'),
    ('BOUTON', 'fr', 6, 'moyen'),
    ('RAISIN', 'fr', 6, 'moyen'),
    ('BATEAU', 'fr', 6, 'moyen'),
    ('CAMION', 'fr', 6, 'moyen'),
    ('VIOLON', 'fr', 6, 'moyen'),
    -- FR — difficile (7 lettres)
    ('FENETRE', 'fr', 7, 'difficile'),
    ('FROMAGE', 'fr', 7, 'difficile'),
    ('POUSSIN', 'fr', 7, 'difficile'),
    ('TAMBOUR', 'fr', 7, 'difficile'),
    ('PLANCHE', 'fr', 7, 'difficile'),
    ('POIREAU', 'fr', 7, 'difficile'),
    ('POMMIER', 'fr', 7, 'difficile'),
    ('MUSIQUE', 'fr', 7, 'difficile'),
    -- EN — facile (5 lettres)
    ('HOUSE', 'en', 5, 'facile'),
    ('WATER', 'en', 5, 'facile'),
    ('BEACH', 'en', 5, 'facile'),
    ('MUSIC', 'en', 5, 'facile'),
    ('APPLE', 'en', 5, 'facile'),
    ('GREEN', 'en', 5, 'facile'),
    ('SLEEP', 'en', 5, 'facile'),
    ('BREAD', 'en', 5, 'facile'),
    -- EN — moyen (6 lettres)
    ('GARDEN', 'en', 6, 'moyen'),
    ('WINDOW', 'en', 6, 'moyen'),
    ('BOTTLE', 'en', 6, 'moyen'),
    ('ORANGE', 'en', 6, 'moyen'),
    ('SILVER', 'en', 6, 'moyen'),
    ('WINTER', 'en', 6, 'moyen'),
    ('SUMMER', 'en', 6, 'moyen'),
    ('FOREST', 'en', 6, 'moyen'),
    -- EN — difficile (7 lettres)
    ('VILLAGE', 'en', 7, 'difficile'),
    ('PICTURE', 'en', 7, 'difficile'),
    ('TEACHER', 'en', 7, 'difficile'),
    ('MORNING', 'en', 7, 'difficile'),
    ('PRESENT', 'en', 7, 'difficile'),
    ('CHICKEN', 'en', 7, 'difficile'),
    ('LIBRARY', 'en', 7, 'difficile'),
    ('DOLPHIN', 'en', 7, 'difficile');

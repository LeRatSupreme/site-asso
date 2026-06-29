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
    ('set_maintenance_mode',     'maintenance_mode',     'false',                                                      'boolean', 'Mode maintenance', 'features'),
    ('set_orders_enabled',       'orders_enabled',       'true',                                                       'boolean', 'Commandes activées','features'),
    ('set_registrations_enabled','registrations_enabled','true',                                                       'boolean', 'Inscriptions activées','features')
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
     'Politique de confidentialité — AEIC', 'Protection des données (RGPD).', 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);

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

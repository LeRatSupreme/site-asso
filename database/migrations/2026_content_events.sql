-- ============================================================
--  AEIC — Événements « phares » à venir (exemples, publiés)
--  Idempotent (INSERT IGNORE sur l'ID).
--  mysql -u aeic -p aeic < database/migrations/2026_content_events.sql
-- ============================================================

INSERT IGNORE INTO events (id, slug, title, excerpt, description, date, location, is_featured, is_published) VALUES
    ('evt_afterwork_2026',
     'afterwork-rentree-2026',
     'Afterwork de rentrée',
     'Décompressez après les cours et faites-vous des amis autour d''un verre.',
     '<p>Première rencontre conviviale de l''année ! L''AEIC vous invite à un afterwork pour faire connaissance, dans une ambiance détendue. Inscription recommandée.</p>',
     '2026-09-11 18:00:00',
     'IUT de Calais — Hall du département Informatique',
     1, 1),

    ('evt_bbq_2026',
     'barbecue-rentree-2026',
     'Barbecue de rentrée',
     'Un moment de partage et de convivialité en plein air pour toute la communauté.',
     '<p>Rien de tel qu''un barbecue pour bien démarrer l''année ! Viens partager un moment convivial avec tous les étudiants en informatique. Encas et boissons à prix associatif.</p>',
     '2026-09-26 12:30:00',
     'Pelouse de l''IUT de Calais',
     0, 1),

    ('evt_bowling_2026',
     'soiree-bowling-2026',
     'Soirée bowling',
     'Défiez vos camarades sur les pistes pour une soirée compétitive et fun.',
     '<p>Soirée bowling entre étudiants ! Formez vos équipes et venez vous affronter sur les pistes. Inscription à l''avance (places limitées).</p>',
     '2026-10-17 20:00:00',
     'Bowling de Calais',
     0, 1),

    ('evt_nuitinfo_2026',
     'nuit-de-l-info-2026',
     'Nuit de l''Info',
     'L''événement national incontournable : une nuit de programmation collaborative pour de bonnes causes.',
     '<p>Rejoignez-nous pour la Nuit de l''Info ! Une nuit entière de programmation en équipe au service de causes humanitaires et solidaires. Repas et boissons fournis. Ouvert à tous les étudiants en informatique.</p>',
     '2026-12-03 18:00:00',
     'IUT de Calais — Salles informatiques',
     1, 1);

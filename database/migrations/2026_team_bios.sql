-- AEIC — Bios des membres du bureau par poste
-- mysql -u aeic -p aeic < database/migrations/2026_team_bios.sql

UPDATE team_members SET bio = 'Dirige l''association, coordonne les projets et représente l''AEIC auprès de l''IUT et des partenaires.' WHERE role = 'Président';
UPDATE team_members SET bio = 'Gère les finances, les paiements, la comptabilité et le réapprovisionnement de la cafétéria.' WHERE role = 'Trésorier';
UPDATE team_members SET bio = 'Organise les réunions, gère les documents officiels et veille au bon fonctionnement de l''association.' WHERE role = 'Secrétaire';
UPDATE team_members SET bio = 'Assiste le président, coordonne les événements et supervise les pôles de l''association.' WHERE role = 'Vice-Président';
UPDATE team_members SET bio = 'Second le trésorier dans la gestion financière et l''inventaire de la cafétéria.' WHERE role = 'Vice-Trésorier';
UPDATE team_members SET bio = 'Gère la communication de l''AEIC : réseaux sociaux, affiches, annonces Discord et promotion des événements.' WHERE role = 'Chargée de communication';
UPDATE team_members SET bio = 'Membre actif de l''AEIC, participe à l''organisation des événements et à la vie associative.' WHERE role = 'Membre' AND prenom = 'Noa';

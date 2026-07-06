-- AEIC — Ajout du pseudo joueur (classement jeux)
-- mysql -u aeic -p aeic < database/migrations/2026_user_pseudo.sql

ALTER TABLE users
    ADD COLUMN pseudo VARCHAR(50) NULL AFTER nom,
    ADD UNIQUE KEY uniq_users_pseudo (pseudo);

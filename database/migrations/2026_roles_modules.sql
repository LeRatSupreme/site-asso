-- =====================================================================
--  AEIC — Migration : rôles fins par module
--
--  Ajoute les rôles EVENEMENTS, COMMUNICATION, CAFETERIA et JEUX à
--  l'ENUM users.role pour permettre des accès ciblés sans donner le
--  rôle ADMIN (voir app/core/Permissions.php pour la matrice rôle →
--  module).
--
--  Idempotent : l'ALTER ENUM est ré-exécutable sans perte de données
--  (les valeurs existantes sont conservées dans le nouvel ENUM).
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_roles_modules.sql
-- =====================================================================

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'ADMIN',
        'TRESORERIE',
        'EVENEMENTS',
        'COMMUNICATION',
        'CAFETERIA',
        'JEUX',
        'ELEVE'
    ) NOT NULL DEFAULT 'ELEVE';

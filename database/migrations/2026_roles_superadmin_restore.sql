-- =====================================================================
--  AEIC — Migration : restauration du rôle SUPERADMIN dans users.role
--
--  Contexte : la migration 2026_roles_modules.sql avait réduit l'ENUM
--  users.role à 7 valeurs (sans SUPERADMIN) alors que le code l'utilise
--  (app/core/Auth.php, app/core/Permissions.php, app/models/UserPolicy.php).
--  Cette migration réaligne la production sur database/schema.sql, qui
--  définit l'ENUM complet à 8 valeurs.
--
--  Idempotent : le MODIFY conserve les valeurs existantes.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_roles_superadmin_restore.sql
-- =====================================================================

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'SUPERADMIN',
        'ADMIN',
        'TRESORERIE',
        'EVENEMENTS',
        'COMMUNICATION',
        'CAFETERIA',
        'JEUX',
        'ELEVE'
    ) NOT NULL DEFAULT 'ELEVE';

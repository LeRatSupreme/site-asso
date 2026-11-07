-- =====================================================================
--  AEIC — Migration : rôles PRESIDENT (lecture seule), BENEVOL
--  (bénévole de caisse) et câblage du rôle EVENEMENTS.
--
--  Migration ADDITIVE : aucune valeur existante n'est modifiée ou
--  supprimée, les comptes actuels conservent leur rôle (compatible
--  ancien code). Le rôle PRESIDENT est en lecture générale (les POST
--  /admin/* sont refusés par le routeur) ; BENEVOL n'a aucun module
--  (aucun chiffre financier, pages de comptage via guardAdminArea) ;
--  EVENEMENTS, jusque-là mort, ouvre les modules events + content
--  (voir app/core/Permissions.php).
--
--  Sans danger à rejouer : le MODIFY vers un ENUM identique est un
--  no-op (l'ALTER peut simplement être ignoré si déjà appliqué).
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_roles_president_benevol.sql
-- =====================================================================

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'SUPERADMIN',
        'ADMIN',
        'PRESIDENT',
        'TRESORERIE',
        'EVENEMENTS',
        'COMMUNICATION',
        'CAFETERIA',
        'JEUX',
        'BENEVOL',
        'ELEVE'
    ) NOT NULL DEFAULT 'ELEVE';

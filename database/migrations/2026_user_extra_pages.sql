-- ============================================================
--  AEIC — Pages attribuées individuellement (users.extra_pages)
--  Appliquer : mysql -u aeic -p aeic < database/migrations/2026_user_extra_pages.sql
-- ============================================================

-- Pages du groupe « Système » attribuées à un utilisateur précis,
-- au-delà de son rôle (CSV de clés — ex : inventory,costs).
-- Gérées depuis la page Utilisateurs (voir Permissions::extraPages()).
ALTER TABLE users
    ADD COLUMN extra_pages VARCHAR(255) NULL DEFAULT NULL AFTER role;

-- Ne pas rejouer (Duplicate column).

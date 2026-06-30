-- =====================================================================
--  AEIC — Migration : confirmation d'e-mail à l'inscription
--  Ajoute la colonne `email_verified_at` (NULL = compte non confirmé) et
--  la table `verification_tokens` (tokens à usage unique, hachés SHA-256).
--
--  Les comptes déjà existants sont marqués vérifiés (rétrocompatibilité) :
--  aucun blocage de connexion pour les membres actuels.
-- =====================================================================

SET NAMES utf8mb4;

-- Colonne de vérification d'e-mail sur les utilisateurs.
ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER is_active;

-- Comptes préexistants : considérés comme vérifiés.
UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE email_verified_at IS NULL;

-- Table des tokens de confirmation d'e-mail.
CREATE TABLE IF NOT EXISTS verification_tokens (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(255) NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_verify_token (token_hash),
    KEY idx_verify_user (user_id),
    CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

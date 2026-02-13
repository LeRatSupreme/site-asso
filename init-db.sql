-- Script d'initialisation MySQL (optionnel - Docker le fait automatiquement)
-- Ce fichier n'est pas nécessaire car Docker crée automatiquement la base
-- Il est fourni à titre de référence

CREATE DATABASE IF NOT EXISTS asso_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- L'utilisateur est créé automatiquement par Docker avec les variables d'environnement
-- GRANT ALL PRIVILEGES ON asso_db.* TO 'asso_user'@'%';
-- FLUSH PRIVILEGES;

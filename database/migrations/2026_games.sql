-- =====================================================================
--  AEIC — Migration jeux : scores Wordle (FR/EN) + classement
--  À importer avec :  mysql -u aeic -p aeic < database/migrations/2026_games.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_scores (
    id          VARCHAR(255) PRIMARY KEY,
    user_id     VARCHAR(255) NOT NULL,
    game        VARCHAR(50) NOT NULL,       -- 'wordle'
    mode        VARCHAR(20) NOT NULL,      -- 'fr' ou 'en'
    score       INT NOT NULL DEFAULT 0,     -- pour Wordle : streak (série de victoires)
    won         TINYINT(1) NOT NULL DEFAULT 0,
    word        VARCHAR(10) NULL,          -- le mot à deviner
    attempts    INT NULL,                  -- nombre d'essais utilisés
    played_at   DATE NOT NULL,             -- date de la partie (1 partie/jour pour Wordle)
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_game_day (user_id, game, mode, played_at),
    KEY idx_game_user (user_id, game, mode),
    KEY idx_game_mode (game, mode),
    CONSTRAINT fk_gamescore_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

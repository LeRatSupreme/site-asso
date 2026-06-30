-- ============================================================
--  AEIC — Module Sondages
--  Tables : polls, poll_options, poll_votes.
--  mysql -u aeic -p aeic < database/migrations/2026_polls.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS polls (
    id           VARCHAR(255) NOT NULL PRIMARY KEY,
    slug         VARCHAR(255) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    is_multiple  TINYINT(1) NOT NULL DEFAULT 0,
    closes_at    DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_polls_slug (slug),
    KEY idx_polls_published (is_published),
    KEY idx_polls_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_options (
    id       VARCHAR(255) NOT NULL PRIMARY KEY,
    poll_id  VARCHAR(255) NOT NULL,
    label    VARCHAR(255) NOT NULL,
    `order`  INT NOT NULL DEFAULT 0,
    KEY idx_options_poll (poll_id),
    CONSTRAINT fk_options_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_votes (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    poll_id    VARCHAR(255) NOT NULL,
    option_id  VARCHAR(255) NOT NULL,
    user_id    VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vote (poll_id, option_id, user_id),
    KEY idx_votes_poll (poll_id),
    KEY idx_votes_user (user_id),
    CONSTRAINT fk_votes_poll   FOREIGN KEY (poll_id)   REFERENCES polls(id)        ON DELETE CASCADE,
    CONSTRAINT fk_votes_option FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user   FOREIGN KEY (user_id)   REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

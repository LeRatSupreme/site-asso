-- ============================================================
--  AEIC — Module Blog / Actualités (Fonctionnalité 11)
--  Table : articles
--  mysql -u aeic -p aeic < database/migrations/2026_articles.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS articles (
    id           VARCHAR(255) PRIMARY KEY,
    slug         VARCHAR(255) NOT NULL UNIQUE,
    title        VARCHAR(255) NOT NULL,
    excerpt      VARCHAR(500) NULL,
    content      LONGTEXT NOT NULL,
    image        VARCHAR(255) NULL,
    category     VARCHAR(100) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_articles_published (is_published),
    KEY idx_articles_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

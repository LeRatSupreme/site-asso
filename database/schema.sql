-- =====================================================================
--  AEIC — Schéma de base de données (Phase 1, complet §3 + §21 ARCHITECTURE)
--  Moteur : InnoDB | Charset : utf8mb4 / utf8mb4_unicode_ci
--  À importer avec :  mysql -u aeic -p aeic < database/schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------
--  Utilisateurs
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    prenom      VARCHAR(255) NOT NULL,
    nom         VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    password    VARCHAR(255) NULL,
    image       VARCHAR(255) NULL,
    role        ENUM('ADMIN','TRESORERIE','ELEVE') NOT NULL DEFAULT 'ELEVE',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Événements
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    slug          VARCHAR(255) NOT NULL,
    title         VARCHAR(255) NOT NULL,
    excerpt       VARCHAR(500) NULL,
    description   LONGTEXT NULL,
    image         VARCHAR(255) NULL,
    date          DATETIME NOT NULL,
    end_date      DATETIME NULL,
    location      VARCHAR(255) NULL,
    show_map      TINYINT(1) NOT NULL DEFAULT 0,
    map_lat       DECIMAL(10, 7) NULL,
    map_lon       DECIMAL(10, 7) NULL,
    sumup_link    VARCHAR(255) NULL,
    price         DECIMAL(10,2) NULL,
    max_capacity  INT NULL,
    is_featured   TINYINT(1) NOT NULL DEFAULT 0,
    is_published  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_events_slug (slug),
    KEY idx_events_date (date),
    KEY idx_events_published (is_published),
    KEY idx_events_featured (is_featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liste d'attente (événements complets)
CREATE TABLE IF NOT EXISTS event_waitlist (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    event_id    VARCHAR(255) NOT NULL,
    user_id     VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_waitlist_event (event_id),
    CONSTRAINT fk_waitlist_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_waitlist_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variantes d'événement (menus / options)
CREATE TABLE IF NOT EXISTS event_variants (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    event_id    VARCHAR(255) NOT NULL,
    label       VARCHAR(255) NOT NULL,
    required    TINYINT(1) NOT NULL DEFAULT 0,
    `order`     INT NOT NULL DEFAULT 0,
    KEY idx_variants_event (event_id),
    CONSTRAINT fk_variants_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_variant_choices (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    variant_id  VARCHAR(255) NOT NULL,
    label       VARCHAR(255) NOT NULL,
    `order`     INT NOT NULL DEFAULT 0,
    KEY idx_choices_variant (variant_id),
    CONSTRAINT fk_choices_variant FOREIGN KEY (variant_id) REFERENCES event_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inscriptions
CREATE TABLE IF NOT EXISTS event_registrations (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id     VARCHAR(255) NOT NULL,
    event_id    VARCHAR(255) NOT NULL,
    qr_token    VARCHAR(64) NULL UNIQUE,
    checked_in  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_registration_user_event (user_id, event_id),
    KEY idx_registrations_event (event_id),
    CONSTRAINT fk_reg_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_registration_choices (
    id              VARCHAR(255) NOT NULL PRIMARY KEY,
    registration_id VARCHAR(255) NOT NULL,
    variant_id      VARCHAR(255) NOT NULL,
    choice_id       VARCHAR(255) NOT NULL,
    KEY idx_regchoices_reg (registration_id),
    CONSTRAINT fk_regchoices_reg     FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_regchoices_variant FOREIGN KEY (variant_id)       REFERENCES event_variants(id)     ON DELETE CASCADE,
    CONSTRAINT fk_regchoices_choice  FOREIGN KEY (choice_id)        REFERENCES event_variant_choices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Galerie photo par événement
CREATE TABLE IF NOT EXISTS photos (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    event_id    VARCHAR(255) NOT NULL,
    url         VARCHAR(255) NOT NULL,
    caption     VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_photos_event (event_id),
    CONSTRAINT fk_photos_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Membres du bureau
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_members (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    prenom        VARCHAR(255) NOT NULL,
    nom           VARCHAR(255) NOT NULL,
    role          VARCHAR(255) NOT NULL,
    pole          VARCHAR(100) NOT NULL DEFAULT 'bureau',
    bio           TEXT NULL,
    photo         VARCHAR(255) NULL,
    is_highlight  TINYINT(1) NOT NULL DEFAULT 0,
    `order`       INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_team_order (`order`),
    KEY idx_team_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Pages CMS
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
    id               VARCHAR(255) NOT NULL PRIMARY KEY,
    slug             VARCHAR(255) NOT NULL,
    title            VARCHAR(255) NOT NULL,
    content          LONGTEXT NULL,
    meta_title       VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    is_published     TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Paramètres du site
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    `key`       VARCHAR(255) NOT NULL,
    value       TEXT NULL,
    type        VARCHAR(50) NOT NULL DEFAULT 'text',
    label       VARCHAR(255) NULL,
    `group`     VARCHAR(50) NOT NULL DEFAULT 'general',
    UNIQUE KEY uniq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Cafétéria : produits & commandes
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    image       VARCHAR(255) NULL,
    `order`     INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    description   VARCHAR(500) NULL,
    price         DECIMAL(10,2) NOT NULL DEFAULT 0,
    image         VARCHAR(255) NULL,
    category_id   VARCHAR(255) NULL,
    stock         INT NOT NULL DEFAULT 0,
    is_available  TINYINT(1) NOT NULL DEFAULT 1,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    `order`       INT NOT NULL DEFAULT 0,
    KEY idx_products_category (category_id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cafeteria_orders (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id     VARCHAR(255) NULL,
    status      ENUM('PENDING','CONFIRMED','PREPARING','READY','DELIVERED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    total       DECIMAL(10,2) NOT NULL DEFAULT 0,
    notes       VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cafeteria_order_items (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    order_id    VARCHAR(255) NOT NULL,
    product_id  VARCHAR(255) NULL,
    quantity    INT NOT NULL DEFAULT 1,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0,
    KEY idx_items_order (order_id),
    CONSTRAINT fk_items_order   FOREIGN KEY (order_id)   REFERENCES cafeteria_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Bibliothèque de médias
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    url         VARCHAR(255) NOT NULL,
    type        VARCHAR(50) NULL,
    mime_type   VARCHAR(100) NULL,
    alt         VARCHAR(255) NULL,
    size        INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  RGPD : consentements
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consents (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id       VARCHAR(255) NULL,
    email         VARCHAR(255) NULL,
    consent_type  VARCHAR(50) NOT NULL,
    text_version  VARCHAR(100) NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    granted       TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consents_user (user_id),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Journal d'audit
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id     VARCHAR(255) NULL,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id   VARCHAR(255) NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Comptabilité cafétéria : ventes importées (SumUp CSV)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    id               VARCHAR(255) NOT NULL PRIMARY KEY,
    transaction_ref  VARCHAR(50) NOT NULL,
    sold_at          DATETIME NOT NULL,
    payment_method   ENUM('CARTE','LIQUIDE') NOT NULL,
    payment_raw      VARCHAR(50) NULL,
    quantity         INT NOT NULL DEFAULT 1,
    description      VARCHAR(255) NULL,
    product_key      VARCHAR(255) NULL,
    category         VARCHAR(100) NULL,
    sku              VARCHAR(100) NULL,
    currency         VARCHAR(10) NOT NULL DEFAULT 'EUR',
    price_ttc        DECIMAL(10,2) NOT NULL,
    price_ht         DECIMAL(10,2) NULL,
    vat              DECIMAL(10,2) NULL,
    vat_rate         VARCHAR(20) NULL,
    seller_account   VARCHAR(255) NULL,
    is_custom_amount TINYINT(1) NOT NULL DEFAULT 0,
    import_batch_id  VARCHAR(255) NULL,
    imported_at      DATETIME NULL,
    UNIQUE KEY uniq_sale_line (transaction_ref, sold_at, description, quantity, price_ttc),
    KEY idx_sales_sold_at (sold_at),
    KEY idx_sales_product (product_key),
    KEY idx_sales_ref (transaction_ref),
    KEY idx_sales_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping libellés CSV -> produit canonique
CREATE TABLE IF NOT EXISTS product_aliases (
    id               VARCHAR(255) NOT NULL PRIMARY KEY,
    raw_description  VARCHAR(255) NOT NULL,
    product_key      VARCHAR(255) NOT NULL,
    category         VARCHAR(100) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_alias_raw (raw_description),
    KEY idx_alias_product (product_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coûts de revient par lot daté
CREATE TABLE IF NOT EXISTS product_costs (
    id           VARCHAR(255) NOT NULL PRIMARY KEY,
    product_key  VARCHAR(255) NOT NULL,
    cost_price   DECIMAL(10,2) NOT NULL,
    valid_from   DATE NOT NULL,
    valid_to     DATE NULL,
    supplier     VARCHAR(255) NULL,
    notes        TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_costs_product (product_key),
    KEY idx_costs_period (valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traçabilité des imports
CREATE TABLE IF NOT EXISTS import_batches (
    id             VARCHAR(255) NOT NULL PRIMARY KEY,
    filename       VARCHAR(255) NULL,
    period_start   DATE NULL,
    period_end     DATE NULL,
    rows_total     INT NULL,
    rows_inserted  INT NULL,
    rows_skipped   INT NULL,
    imported_by    VARCHAR(255) NULL,
    imported_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_import_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustements de vente (immuabilité financière)
CREATE TABLE IF NOT EXISTS sale_adjustments (
    id          VARCHAR(255) NOT NULL PRIMARY KEY,
    sale_id     VARCHAR(255) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    reason      VARCHAR(500) NULL,
    created_by  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adjustments_sale (sale_id),
    CONSTRAINT fk_adjustments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Réinitialisation de mot de passe (tokens à usage unique)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(255) NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Confirmation d'e-mail à l'inscription (tokens à usage unique)
-- -------------------------------------------------------------------
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

-- -------------------------------------------------------------------
--  Authentification à deux facteurs (TOTP) + codes de récupération
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS two_factor (
    user_id         VARCHAR(255) NOT NULL PRIMARY KEY,
    secret          VARCHAR(255) NOT NULL,
    enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    recovery_codes  TEXT         NULL,
    enabled_at      DATETIME     NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_twofactor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
--  Sondages
-- -------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

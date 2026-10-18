-- ============================================================
--  AEIC — Inventaire tablette SumUp (sessions de relevé d'écarts)
--
--  Nouvelle fonctionnalité « Inventaire tablette » : l'utilisateur
--  saisit les quantités affichées sur la tablette SumUp (point de
--  vente) ; le site calcule l'écart contre son stock théorique
--  (InventoryCount::theoreticalStock — dernier comptage + achats
--  − ventes − pertes). Chaque écart = suspicion de vol/casse,
--  valorisé au coût de revient du lot applicable à la date du
--  relevé (perte € estimée).
--
--  Une session = un relevé complet, conservé en historique
--  permanent : en-tête (inventory_tablet_sessions) + une ligne par
--  produit (inventory_tablet_lines : qty tablette, qty site
--  théorique, écart, coût unitaire, perte €, avis). L'action
--  « corriger » synchronise les stocks (manquants → pertes
--  VOL/CASSE/..., surplus → alignement du stock de référence) et
--  marque les lignes corrigées de façon IRREVOCABLE (corrected=1 :
--  trace conservée, la session ne peut plus être supprimée).
--
--  À lancer sur le VPS :
--    mysql -u aeic -p aeic < database/migrations/2026_inventaire_tablette.sql
--  Idempotent : CREATE TABLE IF NOT EXISTS (le schéma canonique
--  database/schema.sql intègre déjà ces tables pour les
--  installations fraîches).
-- ============================================================

-- En-tête d'une session de relevé tablette (id au format
-- 'tabt_' . bin2hex(random_bytes(10)), généré par InventoryTablet).
CREATE TABLE IF NOT EXISTS inventory_tablet_sessions (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    counted_at  DATE         NOT NULL,
    note        VARCHAR(500) NULL,
    created_by  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tablet_sess_date (counted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes d'une session : une par produit relevé sur la tablette.
-- site_theoretical NULL = stock théorique inconnu (produit jamais
-- compté ni mouvementé) ; gap NULL dans ce cas (rien à comparer).
-- loss_value = max(0, -gap) × unit_cost (NULL si coût manquant ou
-- gap >= 0). corrected=1 : irrévocable (pertes créées / stock
-- aligné), trace conservée à vie.
CREATE TABLE IF NOT EXISTS inventory_tablet_lines (
    id              VARCHAR(32)  NOT NULL PRIMARY KEY,
    session_id      VARCHAR(32)  NOT NULL,
    product_key     VARCHAR(255) NOT NULL,
    tablet_qty      INT          NOT NULL,
    site_theoretical INT         NULL,
    gap             INT          NULL,
    unit_cost       DECIMAL(10,3) NULL,
    loss_value      DECIMAL(10,2) NULL,
    corrected       TINYINT(1)   NOT NULL DEFAULT 0,
    avis            VARCHAR(500) NULL,
    corrected_at    DATETIME     NULL,
    corrected_by    VARCHAR(255) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tablet_line_sess (session_id),
    KEY idx_tablet_line_key (product_key),
    CONSTRAINT fk_tablet_line_sess FOREIGN KEY (session_id)
        REFERENCES inventory_tablet_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  AEIC — Migration : table des recommandations commerciales
--
--  Stocke les idées générées par le moteur de recommandations (R1..R11)
--  et leur cycle de vie : proposée → appliquée / plus tard / ignorée,
--  avec baseline avant application et effet mesuré après coup.
--  Chaque idée est identifiée par une empreinte sha1 (règle + produits) :
--  un re-upsert du même idée ne duplique pas la ligne.
--  Idempotent (CREATE TABLE IF NOT EXISTS).
--
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_recommendations.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS recommendations (
  id VARCHAR(64) NOT NULL PRIMARY KEY,              -- empreinte sha1 (règle + produits)
  regle VARCHAR(4) NOT NULL,                        -- R1..R11
  titre VARCHAR(255) NOT NULL,
  produits_json TEXT NOT NULL,                      -- [{"key":"...","label":"..."}]
  payload_json TEXT NOT NULL,                       -- recommandation complète (format moteur)
  statut ENUM('proposee','appliquee','plus_tard','ignoree') NOT NULL DEFAULT 'proposee',
  motif VARCHAR(255) NULL,                          -- motif d'ignorance
  baseline_json TEXT NULL,                          -- [{date, attendu, lo, hi, facteur}] écrite à l'application
  effet_json TEXT NULL,                             -- {etat, effet, bas, haut, jours, note}
  effet_conclusion VARCHAR(20) NULL,                -- 'concluant'|'non_concluant'
  fenetre_json TEXT NULL,                           -- {from,to,jours_ouverts} contexte d'analyse
  created_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  applied_at DATETIME NULL,
  effet_checked_at DATETIME NULL,
  INDEX idx_reco_statut (statut), INDEX idx_reco_created (created_at), INDEX idx_reco_applied (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  AEIC — Réglages du dashboard compta (/admin/compta) : seuils du
--  verdict « baisse du résultat net » (orange / rouge).
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_dashboard_settings.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_dashboard_baisse_attention_pct', 'dashboard_baisse_attention_pct', '-10', 'text', 'Baisse du résultat net → orange (%)', 'dashboard'),
    ('set_dashboard_baisse_alerte_pct',    'dashboard_baisse_alerte_pct',    '-25', 'text', 'Baisse du résultat net → rouge (%)',  'dashboard');

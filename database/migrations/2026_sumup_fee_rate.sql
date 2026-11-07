-- =====================================================================
--  AEIC — Migration : taux de commission SumUp (estimation des frais CB)
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_sumup_fee_rate.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_sumup_fee_rate', 'sumup_fee_rate', '1.75', 'text', 'Taux de commission SumUp (% — estimation des frais carte)', 'sumup');

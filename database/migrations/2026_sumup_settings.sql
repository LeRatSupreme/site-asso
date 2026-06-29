-- =====================================================================
--  AEIC — Migration : ajout des settings SumUp (paiement par lien)
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_sumup_settings.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_sumup_enabled',      'sumup_enabled',      '0', 'boolean', 'Paiements par lien SumUp activés', 'sumup'),
    ('set_sumup_default_link', 'sumup_default_link', '',  'text',    'Lien de paiement SumUp par défaut', 'sumup');

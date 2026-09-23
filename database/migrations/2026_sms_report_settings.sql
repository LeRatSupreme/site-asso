-- =====================================================================
--  AEIC — Migration : settings du rapport quotidien par SMS (Free Mobile)
--
--  Page admin « Notifications » : envoi d'un SMS (CA du jour, bénéfice,
--  top produits) via l'API Free Mobile, planifié par cron.
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_sms_report_settings.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_sms_report_enabled',   'sms_report_enabled',   '0', 'boolean', 'Rapport SMS quotidien activé', 'sms'),
    ('set_sms_report_days',      'sms_report_days',      '1,2,3,4,5', 'text', 'Jours d''envoi (1=lundi … 7=dimanche)', 'sms'),
    ('set_sms_report_time',      'sms_report_time',      '20:00', 'text', 'Heure d''envoi', 'sms'),
    ('set_sms_report_template',  'sms_report_template',  '', 'text', 'Modèle du message (variables {date} {ca} {benefice} {qty} {top})', 'sms'),
    ('set_sms_recipients',       'sms_recipients',       '[]', 'text', 'Destinataires Free Mobile (JSON)', 'sms'),
    ('set_sms_report_last_sent', 'sms_report_last_sent', '', 'text', 'Dernier envoi du rapport (garde anti-doublon)', 'sms');

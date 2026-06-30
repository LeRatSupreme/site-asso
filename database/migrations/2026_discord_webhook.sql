-- =====================================================================
--  AEIC — Migration : settings Webhook Discord
--
--  Ajoute la configuration du webhook Discord utilisé pour annoncer
--  automatiquement les nouveaux événements et sondages.
--
--  Idempotent (INSERT IGNORE) : n'écrase pas des valeurs déjà saisies.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_discord_webhook.sql
-- =====================================================================

INSERT IGNORE INTO settings (id, `key`, value, type, label, `group`) VALUES
    ('set_discord_webhook', 'discord_webhook_url', '', 'text',    'URL Webhook Discord',      'social'),
    ('set_discord_enabled', 'discord_enabled',     '0', 'boolean', 'Activer les annonces Discord', 'social');

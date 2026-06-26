# Changelog

## [Unreleased]
### Sécurité
- Création d'un compte administrateur dédié.
  - Identifiants (email + mot de passe) : stockés uniquement dans `.env` (non versionné). Aucune valeur réelle n'est divulguée dans ce fichier.
- Les identifiants admin sont désormais lus depuis les variables d'environnement (`ADMIN_EMAIL`, `ADMIN_PASSWORD`) au lieu d'être codés en dur dans le seed.
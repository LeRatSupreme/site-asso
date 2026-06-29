# AEIC — Site (PHP pur)

Site de l'**AEIC — Association Étudiante Informatique de Calais**, développé en
**PHP 8.2+ pur + HTML/CSS vanilla**, sans framework ni Docker.

Ce dépôt contient la **Phase 1 (fondations)** : structure MVC, configuration par
environnement, connexion PDO, routeur, helpers, base Auth/CSRF, charte CSS AEIC,
layout public (navbar 4 menus + footer), schéma SQL complet, seed, et tests
PHPUnit. Les espaces auth / élève / admin arrivent dans les phases suivantes.

> Référence : [`site_apres/ARCHITECTURE.md`](../site_apres/ARCHITECTURE.md) (cahier des charges complet).

---

## 📁 Structure

```
site_final/
├── public/                      # Racine web (DocumentRoot Apache)
│   ├── index.php                # Front controller
│   └── assets/
│       ├── css/base.css         # Charte graphique complète
│       └── img/favicon.svg
├── app/
│   ├── config/
│   │   ├── config.php           # .env, constantes, gestion d'erreurs
│   │   └── database.php         # db(): PDO (singleton) + session
│   ├── core/
│   │   ├── Router.php           # Routeur (GET/POST, {param}, 404/405)
│   │   ├── Controller.php       # render(), json(), flash(), abort()
│   │   ├── Auth.php             # Session / login / rôles
│   │   ├── Csrf.php             # Vérification CSRF
│   │   └── helpers.php          # e(), formatDate(), formatPrice(), url()...
│   ├── controllers/             # Home, Event, Page
│   └── models/                  # Model base, Event, Setting, TeamMember, Page
├── views/                       # Layouts, partials, pages, errors
├── database/
│   ├── schema.sql               # Toutes les tables (§3 + §21)
│   └── seed.sql                 # Settings, admin, équipe, pages, events
├── tests/                       # PHPUnit (Unit/HelpersTest, Unit/RouterTest)
├── config.env.example           # Modèle de configuration
├── composer.json                # Autoload PSR-4 + helpers + phpunit
├── phpunit.xml
└── README.md
```

> `app/`, `views/`, `database/` et `config.env` sont **hors DocumentRoot** :
 ils ne sont jamais servis directement par le web.

---

## 🚀 Déploiement sur le VPS

Stack en place : Apache 2.4 + PHP 8.3 (module) + MariaDB 10.11 sur Ubuntu 24.04.
DocumentRoot : `/var/www/aeic/public`. Domaine : `https://asso.aremond.ovh/`.

### 1. Copier le projet
```bash
# Depuis votre machine
scp -r site_final/* utilisateur@VPS:/tmp/aeic-dist/

# Sur le VPS
sudo mkdir -p /var/www/aeic
sudo cp -r /tmp/aeic-dist/* /var/www/aeic/
sudo chown -R www-data:www-data /var/www/aeic
```

### 2. Configurer l'environnement
```bash
cd /var/www/aeic
sudo cp config.env.example config.env
sudo nano config.env   # renseigner DB_PASS, APP_URL, APP_ENV=prod, APP_DEBUG=false
sudo chown www-data:www-data config.env
sudo chmod 640 config.env
```

### 3. Installer Composer (si tests/dev) et les dépendances
```bash
sudo apt install composer   # si absent
composer install --no-dev --optimize-autoloader
```

### 4. Créer la base et importer le schéma + le seed
```bash
mysql -u aeic -p aeic < /var/www/aeic/database/schema.sql
mysql -u aeic -p aeic < /var/www/aeic/database/seed.sql
```

### 5. Activer le compte admin par défaut
Le seed contient un **hash placeholder non valide** (sécurité). Générez le vrai hash
pour `changeme123`, puis mettez-le en base :
```bash
php -r "echo password_hash('changeme123', PASSWORD_BCRYPT), PHP_EOL;"
# copier la valeur, puis :
mysql -u aeic -p aeic -e "UPDATE users SET password='<HASH>' WHERE email='admin@aeic.local';"
```
**Changez ce mot de passe immédiatement après la première connexion.**

### 6. Permissions uploads + rechargement Apache
```bash
sudo mkdir -p /var/www/aeic/public/assets/uploads
sudo chown -R www-data:www-data /var/www/aeic/public/assets/uploads
sudo systemctl reload apache2
```

### 7. (Apache) S'assurer que `public/` est le DocumentRoot
La directive clé : `DocumentRoot /var/www/aeic/public` et, pour le routing,
`FallbackResource /index.php` (mod_dir) ou un `.htaccess` de réécriture.

---

## 🧪 Tests

```bash
composer install                 # installe phpunit (require-dev)
./vendor/bin/phpunit             # lance toute la suite
./vendor/bin/phpunit --testsuite Unit
```

La base de test déclarée dans `phpunit.xml` est `aeic_test` (**jamais la prod**).
Les tests couvrent : helpers (`e`, `formatDate`, `formatPrice`, `parseFrenchFloat`) et
le routeur (matching, extraction `{slug}`/`{id}`, 404, 405).

---

## 🔐 Sécurité (mesures Phase 1)

- **PDO + requêtes préparées** partout (anti-injection SQL).
- **`e()`** (htmlspecialchars) pour échapper toute sortie dynamique dans les vues (anti-XSS).
- **CSRF** : `csrf_token()` / `csrf_field()` + `Csrf::verify()` (à brancher sur les POST des phases suivantes).
- **Sessions** : `use_strict_mode`, `cookie_httponly`, `SameSite=Lax`, régénération d'ID au login.
- **Config via `config.env`** hors webroot ; aucun credential en dur.
- **Auth** base (login/logout/check/id/role/isAdmin/user) prête pour les phases suivantes.

---

## 🗺️ État & suite (phases)

- **Phase 1 ✅** Fondations (ce dépôt).
- Phase 2 — Pages publiques enrichies (détail événement, cartes, sitemap).
- Phase 3 — Auth + RGPD (inscription/connexion, consentements, rôles).
- Phase 4 — Espace élève. Phase 5 — Espace admin. Phase 6+ — SEO/perf/a11y/emails...

Voir le §26 de `ARCHITECTURE.md` pour le plan complet.

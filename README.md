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

## 🚀 Mise en production (checklist)

1. **Désactiver le debug** — `config.env` : `APP_ENV=prod`, `APP_DEBUG=false`.
   Les erreurs ne sont jamais affichées en détail (`logs/php-error.log`).
2. **HTTPS obligatoire** — certificat TLS actif, accès HTTP redirigé vers HTTPS.
   L'en-tête `Strict-Transport-Security` (HSTS) est envoyé automatiquement sur
   toute réponse servie en HTTPS, y compris les pages d'erreur 4xx/5xx.
3. **En-têtes de sécurité** — `SecurityHeaders` (CSP, HSTS, X-Frame-Options,
   `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`) est
   appliqué sur **toutes** les réponses (page normale, 404/403/500, maintenance).
4. **Configurer le SMTP** — dans *Admin → Paramètres → Emails / SMTP* :
   `smtp_host`, `smtp_port`, `smtp_encryption` (`tls`/`ssl`/`none`/vide),
   `smtp_user`, `smtp_pass`, `mailer_from`. Laisser `smtp_host` vide pour le
   fallback `mail()`. Utiliser le bouton **« Envoyer un e-mail de test »**
   pour valider. (Surcharge possible via `config.env` : `SMTP_*`, `MAILER_*`.)
5. **Permissions** — `config.env` en `0640` (propriétaire `www-data`),
   `public/assets/uploads/` inscriptible par `www-data`, reste du dépôt en
   lecture seule.
6. **Sauvegardes** — planifier `php scripts/backup.php` (dump complet PHP pur)
   en cron quotidien + sauvegarde externe des fichiers `database/backup/*.sql`.
   Restauration via `php scripts/restore.php fichier.sql`.
7. **Compte admin** — générer le hash du mot de passe (voir §5 ci-dessus),
   changer le mot de passe par défaut après la 1re connexion, **activer le 2FA**
   (obligatoire pour ADMIN/Trésorerie).
8. **Monitoring** — `GET /health` (JSON, 200/503) à brancher sur un uptime
   checker ; surveiller `logs/app.log` et `logs/php-error.log`.

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

### Tests d'intégration (route + base)

Les tests d'intégration (`tests/Integration/`) exécutent de **vraies requêtes
HTTP simulées** contre l'application branchée sur `aeic_test`, dans un
sous-processus PHP dédié. CSRF et 2FA sont neutralisés via le flag
`APP_TESTING` (jamais actif en production).

Pré-requis : créer la base `aeic_test` et y importer le schéma :

```bash
mysql -u aeic -p -e "CREATE DATABASE aeic_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u aeic -p aeic_test < database/schema.sql
```

Puis :

```bash
./vendor/bin/phpunit --testsuite Integration
```

Couverture : inscription (RGPD, doublons, mot de passe), connexion, inscription
aux événements (unicité), commande cafétéria (total serveur, stock, produit
indisponible), import comptable SumUp (déduplication), et logique SMTP du Mailer.

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

- **Phase 1 ✅** Fondations (structure, config, PDO, routeur, charte CSS, layout).
- **Phase 2 ✅** Pages publiques (accueil, événements liste/détail, association, équipe, CMS, sitemap).
- **Phase 3 ✅** Authentification & RGPD (inscription/connexion/déconnexion, consentements,
  rôles/middleware, rate limiting login, page CGU, export & suppression/anonymisation des données).
- **Phase 4 ✅** Espace élève (dashboard, profil, inscriptions aux événements avec variantes,
  commandes cafétéria avec panier, workflow de statut, stock atomique).
- **Phase 5 ✅** Espace admin (dashboard, CRUD événements/pages/équipe/médias/paramètres,
  gestion cafétéria + caisse POS, gestion utilisateurs & rôles, mode maintenance).
- **Phase 6 ✅** Socle pro (SEO/OG/JSON-LD/sitemap, perf gzip/cache, a11y,
  e-mails transactionnels + mot de passe oublié).
- **Phase 7 ✅** Sécurité avancée (2FA TOTP obligatoire ADMIN/Trésorerie +
  codes de récupération, en-têtes CSP/HSTS, sauvegarde/restauration, monitoring).
- Phase 8+ — E2E, comptabilité, standards entreprise...

Voir le §26 de `ARCHITECTURE.md` pour le plan complet.

---

## 🔐 Authentification & RGPD (Phase 3)

- **Inscription** (`/register`) : validation serveur (prénom, nom, e-mail valide, mot de passe
  ≥ 8 car. avec lettre + chiffre, confirmation), unicité de l'e-mail, hash bcrypt,
  **consentement RGPD obligatoire** (journalisé dans `consents`).
- **Connexion** (`/login`) : message d'erreur générique (l'e-mail n'est jamais révélé),
  **limitation des tentatives** (5 essais / 10 min par IP via `RateLimiter`),
  refus des comptes désactivés, régénération de l'ID de session.
- **Déconnexion** (`/logout`) : destruction de session + retour accueil.
- **Contrôle d'accès** (`App\Core\Middleware`) : `requireGuest`, `requireLogin`, `requireRole`
  (403 si rôle insuffisant), décision testable via `resolve()`/`isAuthorized()`.
- **Droits RGPD** (`/account/*`, connexion requise) :
  - portabilité : export JSON de toutes les données (`/account/export`) ;
  - effacement : anonymisation du compte + désactivation (`/account/delete`),
    les enregistrements comptables obligatoires sont conservés mais déliés de l'identité.
- **CSRF** : `csrf_field()` dans tous les formulaires POST, vérifié par le routeur.
- **Pages légales** : `/legal`, `/privacy`, `/cgu` (CMS, slug en base).

---

## 🎓 Espace élève (Phase 4)

Routes protégées par connexion (`/eleve/*`, accès ELEVE/TRESORERIE/ADMIN) :

- **Tableau de bord** (`/eleve`) : prochains événements, mes inscriptions, dernières commandes.
- **Profil** (`/eleve/profile`) : édition prénom/nom/e-mail + changement de mot de passe
  (ancien requis).
- **Mes inscriptions** (`/eleve/inscriptions`) : événements inscrits + statut (à venir / passé).
- **Mes commandes** (`/eleve/commandes`) : historique avec détail des lignes et badges de statut.
- **Cafétéria** (`/eleve/cafeteria`) : catalogue par catégorie, **panier en session**
  (ajout/retrait/vidage), validation de commande.

**Inscription événement** (`/events/{slug}`) : bouton dynamique selon l'état (connecté/déjà inscrit),
sélection des **variantes obligatoires**, désinscription possible. Doublon impossible (contrainte unique).

**Commandes cafétéria** — robustesse (§25.3) :
- **total recalculé serveur** (jamais confiance au client) ;
- **décrément de stock atomique** en transaction (`stock >= quantité`, jamais de stock négatif) ;
- produit indisponible / stock insuffisant → commande rejetée, **rien n'est écrit** ;
- **workflow de statut** (`PENDING → CONFIRMED → PREPARING → READY → DELIVERED`,
  `CANCELLED` depuis les états non terminaux) via `OrderWorkflow`.

---

## 🛠️ Espace admin (Phase 5)

Routes protégées par le rôle **ADMIN** (`/admin/*`) — layout dédié, `noindex` :

- **Tableau de bord** (`/admin`) : compteurs (membres, événements, commandes, CA),
  dernières commandes, **journal d'audit** récent.
- **Événements** (`/admin/events`) : CRUD complet (création/édition/suppression),
  publication, **liste des inscrits** par événement.
- **Cafétéria** : CRUD **produits** (`/admin/cafeteria`) et **catégories**,
  **commandes** (`/admin/cafeteria/commandes`) avec changement de statut (workflow),
  **caisse (POS)** (`/admin/cafeteria/pos`) pour les ventes au comptoir
  (total recalculé serveur + décrément de stock).
- **Pages CMS** (`/admin/pages`) : CRUD pages (contenu HTML, SEO meta, publication).
- **Équipe** (`/admin/team`) : CRUD membres du bureau (ordre, mise en avant, pôle).
- **Médias** (`/admin/media`) : upload d'images (validation MIME réelle, 5 Mo max,
  renommage aléatoire), suppression.
- **Paramètres** (`/admin/settings`) : édition des settings regroupés, cache invalidé
  après sauvegarde, **mode maintenance**.

**Gestion utilisateurs & rôles** (`/admin/users`) — sécurité (§10.2) :
- promotion / rétrogradation (ADMIN / Trésorerie / Élève) et activation/désactivation ;
- chaque changement de rôle est **journalisé** (audit log `user.role_change`) ;
- **protection du dernier administrateur** : impossible de rétrograder ou désactiver
  le dernier ADMIN actif (règle pure et testée dans `UserPolicy`) ;
- on ne peut pas modifier son propre rôle ni se désactiver soi-même.

**Mode maintenance** (`maintenance_mode`) : bloque l'accès public (page 503),
l'admin y a toujours accès.

---

## ✉️ E-mails & SEO (Phase 6)

**E-mails transactionnels** (`App\Core\Mailer`) : templates HTML + texte, envoi
via SMTP natif (configuré en admin/`.env`) ou `mail()` de secours.
- **Bienvenue** envoyé à l'inscription ;
- **Commande prête** envoyée quand l'admin passe une commande à `READY` ;
- **Réinitialisation de mot de passe** : flux `/forgot-password` → `/reset-password`
  (token à usage unique, haché SHA-256, expire en 1 h, non divulgation de compte).

**SEO** :
- méta dynamiques, **Open Graph** + **Twitter Cards**, **JSON-LD**
  (`Organization` en accueil, `Event` en page événement) ;
- `og:image` configurable (setting) + image par défaut ;
- **sitemap.xml** dynamique (`/sitemap.xml` : pages statiques + événements + pages CMS) ;
- `robots.txt` (admin/espace membre exclus).

**Performance** (`.htaccess`) : compression gzip/brotli, cache assets 1 an (`immutable`),
en-têtes de sécurité de base (`X-Content-Type-Options`, `X-Frame-Options`,
`Referrer-Policy`, `Permissions-Policy`).

**Accessibilité** : `lang="fr"`, skip-link, `:focus-visible`, navigation ARIA,
sémantique HTML, alt sur images, `loading="lazy"`.

---

## 🛡️ Sécurité avancée & exploitation (Phase 7)

**Authentification à deux facteurs (TOTP, RFC 6238)** — `App\Core\Security\Totp` :
- **2FA obligatoire** pour les rôles ADMIN et TRÉSORERIE
  (`TwoFactorPolicy::requires`), forcé via un guard dans `index.php` ;
- vérification au login (étape `/login/verify`), configuration `/account/2fa/setup`
  (secret + URI otpauth, confirmation par code) ;
- **codes de récupération** à usage unique, stockés hachés (SHA-256) ;
- table `two_factor` (secret + codes + activation).

**En-têtes de sécurité** (`App\Core\Security\SecurityHeaders`) : **CSP**
(configurable), **HSTS** (HTTPS), `X-Frame-Options`, `X-Content-Type-Options`,
`Referrer-Policy`, `Permissions-Policy`.

**Sauvegarde / restauration** (PHP pur, sans `mysqldump`) — `App\Core\Backup\Backup` :
- `php scripts/backup.php [fichier.sql]` : dump complet (structure + données) ;
- `php scripts/restore.php fichier.sql` : restauration multi-requêtes.

**Monitoring** : endpoint **`/health`** (JSON, vérifie la base, 200/503),
`App\Core\Logger` (journal structuré `logs/app.log`).

**Journal d'audit** (`audit_logs`) : chaque action sensible (promotion, statut
commande, activation/désactivation 2FA…) est tracée (qui, quoi, quand, IP).

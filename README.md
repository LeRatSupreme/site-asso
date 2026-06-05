# Site associatif

Projet en ligne : https://aeic.jrcan.dev/

Application web complète pour gérer la vie d'une association étudiante : site public, inscriptions aux événements, cafétéria, commandes, médiathèque, contenus éditables et espace d'administration.

Le projet est construit avec Next.js 15, React 19, Prisma, MySQL et Auth.js. Il peut tourner en local pour le développement ou en production avec Docker Compose.

## Ce que permet l'application

### Côté public

- Page d'accueil configurable depuis l'administration.
- Pages de présentation, équipe, mentions légales et confidentialité.
- Liste des événements publiés.
- Détail d'un événement avec inscription possible pour les utilisateurs connectés.
- Interface responsive avec thème clair/sombre.

### Espace élève

- Création de compte et connexion.
- Tableau de bord personnel.
- Gestion du profil et du mot de passe.
- Suivi des inscriptions aux événements.
- Commande de produits à la cafétéria.
- Historique des commandes.

### Espace administrateur

- Tableau de bord global.
- Gestion des utilisateurs, rôles et statuts de compte.
- Création, publication et modification des événements.
- Gestion des variantes d'inscription aux événements.
- Suivi des inscrits.
- CMS simple pour éditer les pages du site.
- Médiathèque et upload de fichiers.
- Gestion des paramètres du site.
- Gestion de la cafétéria : catégories, produits, stock, disponibilités et commandes.
- Interface de point de vente pour la cafétéria.
- Tableau de bord SumUp pour les paiements, si les clés API sont configurées.

## Stack technique

- Next.js 15 avec App Router
- React 19
- TypeScript
- Tailwind CSS
- Prisma ORM
- MySQL 8
- Auth.js / NextAuth v5
- Radix UI
- TipTap pour l'édition riche
- Docker et Docker Compose pour le déploiement

## Prérequis

Pour lancer le projet en local :

- Node.js 18 ou plus récent
- npm
- Une base MySQL accessible

Pour lancer le projet avec Docker :

- Docker
- Docker Compose

## Installation locale

1. Installer les dépendances :

```bash
npm install
```

2. Créer le fichier d'environnement :

```bash
cp .env.example .env
```

3. Renseigner les variables dans `.env`.

En local, Prisma a besoin d'une variable `DATABASE_URL` pointant vers votre base MySQL, par exemple :

```env
DATABASE_URL="mysql://asso_user:mot_de_passe@localhost:3306/asso_db"
NEXTAUTH_SECRET="un_secret_long_et_securise"
NEXTAUTH_URL="http://localhost:3000"
```

4. Générer le client Prisma :

```bash
npm run db:generate
```

5. Créer ou mettre à jour les tables :

```bash
npm run db:push
```

6. Ajouter les données de départ :

```bash
npm run db:seed
```

7. Démarrer le serveur de développement :

```bash
npm run dev
```

L'application est ensuite disponible sur `http://localhost:3000`.

## Comptes de test

Après `npm run db:seed`, deux comptes sont créés :

| Rôle | Email | Mot de passe |
| --- | --- | --- |
| Administrateur | `admin@asso.fr` | `admin123` |
| Élève | `eleve@asso.fr` | `eleve123` |

Pensez à changer ces identifiants avant toute mise en production.

## Lancement avec Docker

Le fichier `docker-compose.yml` démarre :

- une base MySQL 8 avec volume persistant ;
- un service de migration Prisma ;
- l'application Next.js ;
- phpMyAdmin ;
- les labels Traefik prévus pour un déploiement derrière reverse proxy.

Créer le fichier `.env` :

```bash
cp .env.example .env
```

Puis lancer les services :

```bash
docker compose up -d
```

Voir les logs :

```bash
docker compose logs -f
```

Les données MySQL sont conservées dans le volume Docker `mysql-data`. Les fichiers uploadés sont conservés dans `public/uploads`.

## Variables d'environnement principales

| Variable | Description |
| --- | --- |
| `DATABASE_URL` | URL MySQL utilisée par Prisma en local ou hors Docker. |
| `MYSQL_ROOT_PASSWORD` | Mot de passe root MySQL utilisé par Docker Compose. |
| `MYSQL_DATABASE` | Nom de la base créée par Docker Compose. |
| `MYSQL_USER` | Utilisateur MySQL applicatif. |
| `MYSQL_PASSWORD` | Mot de passe de l'utilisateur MySQL applicatif. |
| `NEXTAUTH_SECRET` | Secret utilisé par Auth.js pour signer les sessions. |
| `NEXTAUTH_URL` | URL publique de l'application. |
| `SUMUP_API_KEY` | Clé API SumUp, optionnelle. |
| `SUMUP_MERCHANT_CODE` | Code marchand SumUp, optionnel. |
| `UPLOAD_DIR` | Dossier d'upload, par défaut `./public/uploads`. |
| `PORT` | Port d'écoute utilisé en production Docker. |

## Scripts utiles

| Commande | Utilisation |
| --- | --- |
| `npm run dev` | Lance le serveur de développement. |
| `npm run build` | Compile l'application pour la production. |
| `npm run start` | Lance l'application compilée. |
| `npm run lint` | Lance le lint Next.js. |
| `npm run db:generate` | Génère le client Prisma. |
| `npm run db:push` | Synchronise le schéma Prisma avec la base. |
| `npm run db:migrate` | Crée une migration Prisma en développement. |
| `npm run db:seed` | Insère les données de départ. |
| `npm run db:studio` | Ouvre Prisma Studio. |

## Structure du projet

```text
app/
├── (public)/        Pages publiques
├── (auth)/          Connexion et inscription
├── (dashboard)/     Espaces élève et administrateur
├── actions/         Server Actions
├── api/             Routes API
├── components/      Composants React partagés
└── lib/             Auth, Prisma, permissions, config et utilitaires

prisma/
├── schema.prisma    Modèles de données
├── seed.ts          Données de départ
└── migrations/      Migrations Prisma

public/uploads/      Fichiers envoyés depuis l'administration
```

## Documentation complémentaire

- `DEPLOY.md` détaille le déploiement Docker, les webhooks et les commandes serveur.
- `DATABASE.md` détaille la configuration MySQL, la persistance et les sauvegardes.

## Notes de sécurité

- Ne jamais versionner le fichier `.env`.
- Générer un vrai `NEXTAUTH_SECRET` avant la production :

```bash
openssl rand -base64 32
```

- Remplacer les comptes de test après le premier déploiement.
- Vérifier les règles Traefik dans `docker-compose.yml` avant d'exposer l'application publiquement.

## Licence

Tous droits réservés.

Ce projet est privé. Aucune utilisation, copie, modification, distribution, publication ou réutilisation du code n'est autorisée sans accord écrit préalable du propriétaire du projet.

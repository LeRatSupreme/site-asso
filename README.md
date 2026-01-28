# Asso Website - Site Associatif Full Admin

Site web associatif complet avec panel d'administration, construit avec Next.js 15 App Router.

## Fonctionnalités

- 🔐 Authentification avec Auth.js (NextAuth v5)
- 👥 Gestion des rôles (ADMIN / ELEVE)
- 📅 Gestion des événements
- ☕ Système de cafétéria (gestion produits, catégories, commandes)
- 📷 Galerie photos
- 📄 CMS intégré pour les pages
- ⚙️ Paramètres administrables
- 📱 Design responsive
- 🌙 Mode sombre

## Prérequis

- Node.js 18+
- MySQL 8+
- npm ou yarn

## Installation

1. Cloner le repository

2. Installer les dépendances :
```bash
npm install
```

3. Configurer les variables d'environnement :
```bash
cp .env.example .env
```

4. Remplir le fichier `.env` avec vos valeurs

5. Générer le client Prisma :
```bash
npm run db:generate
```

6. Créer les tables :
```bash
npm run db:push
```

7. (Optionnel) Seed la base de données :
```bash
npm run db:seed
```

8. Lancer le serveur de développement :
```bash
npm run dev
```

## Structure du projet

```
app/
├─ (public)/          # Pages publiques
├─ (auth)/            # Authentification
├─ (dashboard)/       # Dashboards admin et élève
├─ actions/           # Server Actions
├─ api/               # API Routes
├─ lib/               # Utilitaires
└─ components/        # Composants React
```

## Variables d'environnement

| Variable | Description |
|----------|-------------|
| DATABASE_URL | URL de connexion MySQL |
| NEXTAUTH_SECRET | Secret pour Auth.js |
| NEXTAUTH_URL | URL de l'application |

## Rôles

- **ADMIN** : Accès complet au panel d'administration
- **ELEVE** : Accès au dashboard élève, inscription aux événements, commandes

## Déploiement

Le projet est prêt pour être déployé sur Vercel ou tout autre hébergeur compatible Next.js.

```bash
npm run build
npm run start
```

## Licence

MIT

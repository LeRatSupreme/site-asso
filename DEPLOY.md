# 🚀 Guide de Déploiement - Asso Website

Ce projet est prêt à être déployé via Docker avec déploiement automatique par webhook GitHub.

**✨ Nouveauté : Base de données MySQL incluse dans Docker avec volume persistant !**

## 📋 Prérequis

- Docker et Docker Compose installés
- Accès au serveur avec Git
- ⚠️ **Pas besoin de base de données externe** : MySQL est inclus dans le Docker !

## 🔧 Configuration

### 1. Cloner le repository

```bash
git clone https://github.com/VOTRE_USERNAME/asso-website.git
cd asso-website
```

### 2. Créer le fichier .env

Copier `.env.example` et le renommer en `.env`, puis remplir les variables :

```bash
cp .env.example .env
nano .env
```

Variables obligatoires :

```env
# Base de données MySQL (Docker) - Choisir des mots de passe sécurisés
# ⚠️ DATABASE_URL sera automatiquement construit : mysql://USER:PASSWORD@db:3306/DATABASE
MYSQL_ROOT_PASSWORD="votre_mot_de_passe_root_securise"
MYSQL_DATABASE="asso_db"
MYSQL_USER="asso_user"
MYSQL_PASSWORD="votre_mot_de_passe_securise"

# Auth.js
NEXTAUTH_SECRET="PM0rO30mE0Mt34SD9Q/KKxBBl/OZsZz2EZWICttHiMI="
NEXTAUTH_URL="https://votre-domaine-final.com"

# SumUp API
SUMUP_API_KEY="sup_sk_3VSdIg7hZlCAjaoxspUBQhhsJf6F459Z7"
SUMUP_MERCHANT_CODE="MCMNHAA3"

# Port
PORT=3000
```

### 3. Initialiser et lancer l'application

```bash
# Construire et démarrer tous les services (MySQL + App)
docker-compose up -d
```

Au premier démarrage, Docker va :
1. Créer le conteneur MySQL avec un volume persistant
2. Créer la base de données automatiquement
3. Construire et démarrer l'application Next.js
4. Initialiser le schéma Prisma dans la base de données

Vérifier que tout fonctionne :

```bash
docker-compose ps
docker-compose logs -f
```

L'application sera accessible sur `http://localhost:3000` (ou le port défini dans `.env`).

### 4. Vérifier la base de données

```bash
# Se connecter à MySQL dans le conteneur
docker-compose exec db mysql -u asso_user -p

# Puis dans MySQL :
USE asso_db;
SHOW TABLES;
```

## 🔄 Déploiement automatique avec Webhook

### 1. Configurer le webhook GitHub

1. Aller dans **Settings** > **Webhooks** > **Add webhook**
2. Payload URL : `http://VOTRE_SERVEUR:PORT/webhook`
3. Content type : `application/json`
4. Secret : Générer un secret sécurisé
5. Events : Sélectionner `push` uniquement
6. Activer le webhook

### 2. Installer un serveur webhook

Utiliser [webhook](https://github.com/adnanh/webhook) ou un serveur Node.js simple :

```bash
# Exemple avec webhook (Go)
sudo apt install webhook

# Créer la configuration webhook
cat > /etc/webhook/hooks.json << 'EOF'
[
  {
    "id": "deploy-asso",
    "execute-command": "/chemin/vers/asso-website/deploy.sh",
    "command-working-directory": "/chemin/vers/asso-website",
    "pass-arguments-to-command": [],
    "trigger-rule": {
      "match": {
        "type": "payload-hash-sha256",
        "secret": "VOTRE_SECRET_WEBHOOK",
        "parameter": {
          "source": "header",
          "name": "X-Hub-Signature-256"
        }
      }
    }
  }
]
EOF

# Démarrer le serveur webhook
webhook -hooks /etc/webhook/hooks.json -verbose -port 9000
```

### 3. Tester le déploiement

```bash
# Tester manuellement le script de déploiement
./deploy.sh
```

À chaque push sur la branche `main`, le webhook déclenchera automatiquement `deploy.sh` qui :
1. Récupère les dernières modifications
2. Arrête les conteneurs existants
3. Reconstruit l'image Docker
4. Redémarre les conteneurs
5. Vérifie que tout fonctionne

## 🛠️ Commandes utiles

```bash
# Voir les logs en temps réel
docker-compose logs -f

# Voir uniquement les logs de l'app
docker-compose logs -f app

# Voir uniquement les logs de MySQL
docker-compose logs -f db

# Redémarrer l'application
docker-compose restart

# Arrêter l'application
docker-compose down

# Arrêter ET supprimer les volumes (⚠️ SUPPRIME LA BASE DE DONNÉES)
docker-compose down -v

# Reconstruire l'image
docker-compose build --no-cache

# Accéder au conteneur de l'app
docker-compose exec app sh

# Accéder à MySQL
docker-compose exec db mysql -u asso_user -p

# Voir l'état des conteneurs
docker-compose ps

# Backup de la base de données
docker-compose exec db mysqldump -u asso_user -p asso_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Restaurer la base de données
docker-compose exec -T db mysql -u asso_user -p asso_db < backup.sql
```

## 💾 Volumes et Persistance des Données

Le projet utilise deux volumes Docker pour persister les données :

1. **mysql-data** : Contient toutes les données de la base MySQL
   - Persiste même après `docker-compose down`
   - ⚠️ Supprimé uniquement avec `docker-compose down -v`

2. **./public/uploads** : Contient les fichiers uploadés (photos, documents)
   - Monté directement depuis le dossier du projet
   - Toujours persisté

**Important** : Les données ne sont JAMAIS supprimées lors des mises à jour avec `deploy.sh` !

## 📦 Structure des fichiers

```
.
├── Dockerfile              # Image Docker de l'application
├── docker-compose.yml      # Orchestration des services (App + MySQL)
├── deploy.sh              # Script de déploiement automatique
├── .env.example           # Template des variables d'environnement
├── .dockerignore          # Fichiers à ignorer lors du build
├── DEPLOY.md              # Ce fichier
└── prisma/
    └── schema.prisma      # Schéma de la base de données
```

## 🗄️ Architecture Docker

```
┌─────────────────────────────────────────┐
│         docker-compose.yml              │
├─────────────────────────────────────────┤
│                                         │
│  ┌──────────────┐    ┌──────────────┐  │
│  │   app        │───▶│     db       │  │
│  │  (Next.js)   │    │   (MySQL)    │  │
│  │  Port: 3000  │    │  Port: 3306  │  │
│  └──────────────┘    └──────────────┘  │
│         │                    │         │
│         │                    │         │
│    ┌────▼────┐         ┌─────▼─────┐  │
│    │ uploads │         │mysql-data │  │
│    │ (dossier)│        │  (volume) │  │
│    └─────────┘         └───────────┘  │
│                                         │
└─────────────────────────────────────────┘
```

## 🔐 Sécurité

- ✅ Ne JAMAIS committer le fichier `.env`
- ✅ Utiliser des mots de passe forts pour MySQL
- ✅ Utiliser des secrets forts pour `NEXTAUTH_SECRET`
- ✅ Configurer un pare-feu pour limiter l'accès
- ✅ Utiliser HTTPS en production (avec Nginx + Let's Encrypt)
- ✅ Le port MySQL (3306) n'est PAS exposé à l'extérieur du réseau Docker
- ✅ Faire des backups réguliers de la base de données

## 📝 Notes

- Les uploads sont stockés dans `./public/uploads` (volume Docker)
- La base de données est dans un volume Docker `mysql-data` (données persistantes)
- Le port par défaut est 3000, modifiable dans `.env`
- MySQL n'est accessible QUE depuis le conteneur de l'app (sécurité)
- Les données persistent même après `docker-compose down`

## 🆘 Dépannage

### Le conteneur ne démarre pas

```bash
# Voir les logs détaillés
docker-compose logs

# Vérifier que .env est correct
cat .env

# Reconstruire complètement
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Erreur de connexion à la base de données

```bash
# Vérifier que le conteneur MySQL est démarré
docker-compose ps

# Vérifier les logs MySQL
docker-compose logs db

# Vérifier la connexion depuis l'app
docker-compose exec app ping db

# Se connecter manuellement à MySQL pour tester
docker-compose exec db mysql -u asso_user -p
```

### La base de données est vide / tables manquantes

```bash
# Réinitialiser le schéma Prisma
docker-compose exec app npx prisma db push

# Ou régénérer et pousser
docker-compose exec app npx prisma generate
docker-compose exec app npx prisma db push
```

### Les uploads ne fonctionnent pas

```bash
# Vérifier les permissions du dossier
ls -la public/uploads

# Créer le dossier si nécessaire
mkdir -p public/uploads
chmod 755 public/uploads
```

### Le déploiement automatique ne fonctionne pas

- Vérifier les logs du serveur webhook
- Vérifier que `deploy.sh` est exécutable : `ls -l deploy.sh`
- Tester manuellement : `./deploy.sh`

### Réinitialisation complète (⚠️ SUPPRIME TOUTES LES DONNÉES)

```bash
# Arrêter tout et supprimer volumes
docker-compose down -v

# Supprimer les images
docker rmi $(docker images -q asso-*)

# Redémarrer de zéro
docker-compose up -d
```

## 📞 Support

Pour toute question, contacter l'équipe de développement.

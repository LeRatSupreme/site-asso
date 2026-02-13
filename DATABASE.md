# 📊 Configuration de la Base de Données

## Architecture

Le projet utilise **MySQL dans Docker** avec un volume persistant.

### Fonctionnement

1. **Au démarrage de Docker** :
   - Le conteneur MySQL démarre avec les credentials du `.env`
   - MySQL crée automatiquement la base de données
   - Le volume `mysql-data` est créé pour persister les données

2. **L'application se connecte** :
   - `DATABASE_URL` est construit automatiquement dans `docker-compose.yml`
   - Format : `mysql://USER:PASSWORD@db:3306/DATABASE`
   - Prisma initialise le schéma au premier démarrage

### Variables d'environnement

Dans le `.env` :

```env
MYSQL_ROOT_PASSWORD="root_password"
MYSQL_DATABASE="asso_db"
MYSQL_USER="asso_user"
MYSQL_PASSWORD="user_password"
```

### DATABASE_URL automatique

Docker compose construit automatiquement :
```
mysql://asso_user:user_password@db:3306/asso_db
```

L'application Next.js reçoit cette variable et Prisma l'utilise directement.

## Commandes utiles

### Se connecter à MySQL

```bash
docker-compose exec db mysql -u asso_user -p
# Mot de passe : celui défini dans MYSQL_PASSWORD
```

### Voir les tables

```sql
USE asso_db;
SHOW TABLES;
DESCRIBE User;
```

### Backup

```bash
docker-compose exec db mysqldump -u asso_user -p asso_db > backup.sql
```

### Restaurer

```bash
docker-compose exec -T db mysql -u asso_user -p asso_db < backup.sql
```

## Persistance des données

✅ **Les données persistent** même après :
- `docker-compose down`
- `docker-compose restart`
- Redéploiement avec `deploy.sh`
- Mise à jour du code

⚠️ **Les données sont SUPPRIMÉES** avec :
- `docker-compose down -v` (supprime les volumes)

## Troubleshooting

### "Can't connect to MySQL server"

```bash
# Vérifier que MySQL est démarré
docker-compose ps

# Voir les logs MySQL
docker-compose logs db

# Redémarrer MySQL
docker-compose restart db
```

### "Access denied"

- Vérifier les credentials dans `.env`
- Vérifier que `MYSQL_USER` et `MYSQL_PASSWORD` sont corrects

### Réinitialiser complètement

```bash
# ⚠️ SUPPRIME TOUTES LES DONNÉES
docker-compose down -v
docker-compose up -d
```

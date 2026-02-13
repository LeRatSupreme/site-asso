#!/bin/bash

# Script de déploiement automatique
# Appelé par le webhook GitHub

set -e

echo "🚀 Début du déploiement..."

# Couleurs pour les logs
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Fonction pour afficher les messages
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Vérifier si le fichier .env existe
if [ ! -f .env ]; then
    log_error "Le fichier .env n'existe pas !"
    exit 1
fi

# Récupérer les dernières modifications du dépôt
log_info "Récupération des dernières modifications..."
git pull origin main || {
    log_error "Échec de la récupération du code"
    exit 1
}

# Arrêter les conteneurs existants
log_info "Arrêt des conteneurs existants..."
docker-compose down || log_warning "Aucun conteneur à arrêter"

# Supprimer les anciennes images (optionnel)
log_info "Nettoyage des anciennes images..."
docker image prune -f

# Construire la nouvelle image
log_info "Construction de la nouvelle image Docker..."
docker-compose build --no-cache || {
    log_error "Échec de la construction"
    exit 1
}

# Démarrer les nouveaux conteneurs
log_info "Démarrage des conteneurs..."
docker-compose up -d || {
    log_error "Échec du démarrage"
    exit 1
}

# Attendre que la base de données soit prête
log_info "Attente du démarrage de la base de données..."
sleep 15

# Initialiser le schéma de base de données (si première installation)
log_info "Vérification/Initialisation du schéma de base de données..."
docker-compose exec -T app npx prisma db push --skip-generate || {
    log_warning "Le schéma existe déjà ou erreur Prisma (normal si déjà initialisé)"
}

# Attendre que l'application soit prête
log_info "Attente du démarrage de l'application..."
sleep 10

# Vérifier que le conteneur fonctionne
if docker-compose ps | grep -q "Up"; then
    log_info "✅ Déploiement réussi !"
    
    # Afficher les logs des 20 dernières lignes
    log_info "Derniers logs :"
    docker-compose logs --tail=20
else
    log_error "❌ Le conteneur ne démarre pas correctement"
    docker-compose logs --tail=50
    exit 1
fi

# Nettoyage final
log_info "Nettoyage des ressources inutilisées..."
docker system prune -f

log_info "🎉 Déploiement terminé avec succès !"

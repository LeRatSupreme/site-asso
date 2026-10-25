#!/usr/bin/env bash
# Monte la base de test locale (MariaDB 10.11) pour la suite PHPUnit,
# attend qu'elle soit saine, puis rappelle la commande de test.
#
# Usage : bash scripts/test-db-up.sh
# Le schéma (database/schema.sql) n'est installé que sur un volume vide ;
# pour repartir de zéro : docker compose -f docker-compose.test.yml down -v

set -euo pipefail
cd "$(dirname "$0")/.."

docker compose -f docker-compose.test.yml up -d --wait

cat <<'EOF'

Base de test prête : MariaDB 10.11 sur 127.0.0.1:3307 (base aeic_test).

Lancer la suite complète (~1181 tests) :

  DB_HOST=127.0.0.1:3307 DB_NAME=aeic_test DB_USER=aeic DB_PASS=aeic "/c/Users/adrie/.config/herd/bin/php84/php.exe" vendor/bin/phpunit --no-coverage

Reset complet de la base (le schéma n'est réinstallé que sur volume vide) :

  docker compose -f docker-compose.test.yml down -v

EOF

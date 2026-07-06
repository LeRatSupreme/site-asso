#!/usr/bin/env bash
# =====================================================================
#  AEIC — Sauvegarde (dump MariaDB + uploads)
#
#  Lit les credentials depuis config.env (jamais en dur dans le script).
#  Surcharge possible via les variables d'environnement DB_* / MYSQL_*
#  (utile en cron systemd).
#
#  Sortie : backups/  -> aeic_db_YYYYMMDD-HHMMSS.sql.gz
#                     -> aeic_uploads_YYYYMMDD-HHMMSS.tar.gz
#  Rotation : on conserve les 14 derniers jours de sauvegardes.
#
#  Cron recommandé (chaque nuit à 3h) :
#      0 3 * * *  /var/www/aeic/scripts/backup.sh >> /var/www/aeic/logs/backup.log 2>&1
# =====================================================================
set -euo pipefail

# --- Racine du projet : répertoire parent du dossier scripts/ ----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

CONFIG_FILE="${APP_DIR}/config.env"
BACKUP_DIR="${APP_DIR}/backups"
UPLOADS_DIR="${APP_DIR}/public/assets/uploads"

# --- Lecture d'une clé depuis config.env (ou variable d'environnement) -
read_env() {
    local key="$1"
    # Priorité : variable d'environnement, puis config.env, puis défaut.
    local envval="${!key:-}"
    if [[ -n "${envval}" ]]; then
        printf '%s' "${envval}"   
        return
    fi
    if [[ -f "${CONFIG_FILE}" ]]; then
        local val
        val="$(grep -E "^${key}=" "${CONFIG_FILE}" | tail -n1 | cut -d= -f2- || true)"
        printf '%s' "${val}"
        return
    fi
}

DB_HOST="$(read_env DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="$(read_env DB_NAME)"; DB_NAME="${DB_NAME:-aeic}"
DB_USER="$(read_env DB_USER)"; DB_USER="${DB_USER:-aeic}"
DB_PASS="$(read_env DB_PASS)"

STAMP="$(date +%Y%m%d-%H%M%S)"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

mkdir -p "${BACKUP_DIR}"

echo "=========================================="
echo " AEIC — Sauvegarde du $(date '+%Y-%m-%d %H:%M:%S')"
echo " Base     : ${DB_NAME}@${DB_HOST}"
echo " Sortie   : ${BACKUP_DIR}"
echo "=========================================="

# --- 1) Dump MariaDB --------------------------------------------------
DB_FILE="${BACKUP_DIR}/aeic_db_${STAMP}.sql.gz"

# --defaults-extra-file évite d'exposer le mot de passe en ligne (ps/proc).
EXTRA_FILE="$(mktemp)"
chmod 600 "${EXTRA_FILE}"
cleanup() { rm -f "${EXTRA_FILE}"; }
trap cleanup EXIT

{
    printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' \
        "${DB_HOST}" "${DB_USER}" "${DB_PASS}"
} > "${EXTRA_FILE}"

echo "→ Dump de la base (${DB_NAME})..."
if mysqldump --defaults-extra-file="${EXTRA_FILE}" \
        --single-transaction --quick --routines --triggers \
        --default-character-set=utf8mb4 \
        "${DB_NAME}" | gzip -c > "${DB_FILE}"; then
    DB_SIZE="$(du -h "${DB_FILE}" | cut -f1)"
    echo "  OK : ${DB_FILE} (${DB_SIZE})"
else
    echo "  ERREUR : échec du dump de la base." >&2
    rm -f "${DB_FILE}"
    exit 1
fi

# --- 2) Archive des uploads -------------------------------------------
UP_FILE="${BACKUP_DIR}/aeic_uploads_${STAMP}.tar.gz"
echo "→ Archive des uploads..."
if [[ -d "${UPLOADS_DIR}" ]]; then
    tar -C "${APP_DIR}/public/assets" -czf "${UP_FILE}" uploads 2>/dev/null || true
    if [[ -f "${UP_FILE}" ]]; then
        UP_SIZE="$(du -h "${UP_FILE}" | cut -f1)"
        echo "  OK : ${UP_FILE} (${UP_SIZE})"
    else
        echo "  (aucun upload à archiver)"
    fi
else
    echo "  (dossier uploads absent : ignoré)"
fi

# --- 3) Rotation : suppression des archives de plus de N jours ---------
echo "→ Rotation (conservation ${RETENTION_DAYS} jours)..."
DELETED="$(find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'aeic_db_*.sql.gz' -o -name 'aeic_uploads_*.tar.gz' \) -mtime +${RETENTION_DAYS} -print -delete | wc -l)"
echo "  ${DELETED} fichier(s) supprimé(s) par rotation."

# --- 4) Résumé --------------------------------------------------------
echo ""
echo "Résumé :"
echo "  - Base     : ${DB_FILE} (${DB_SIZE})"
[[ -f "${UP_FILE}" ]] && echo "  - Uploads  : ${UP_FILE} (${UP_SIZE})"
COUNT="$(find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'aeic_db_*.sql.gz' -o -name 'aeic_uploads_*.tar.gz' \) | wc -l)"
echo "  - Total sauvegardes conservées : ${COUNT} fichier(s)"
echo "Terminé à $(date '+%H:%M:%S')."

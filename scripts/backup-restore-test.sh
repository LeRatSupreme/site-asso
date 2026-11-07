#!/usr/bin/env bash
# =====================================================================
#  AEIC — Test de restauration de sauvegarde (script exécuté en LOCAL)
#
#  Vérifie qu'un dump de la base de production est réellement
#  restaurable, sans JAMAIS écrire sur la production :
#    1. mysqldump de la base de prod (lecture seule) vers /tmp sur le
#       VPS ;
#    2. lecture du charset/collation de la prod (information_schema.
#       SCHEMATA) puis création d'une base temporaire aeic_restore_test
#       avec le MÊME charset/collation ;
#    3. import du dump dans aeic_restore_test ;
#    4. comparaison prod vs restaurée : lignes de sales ET nombre de
#       tables (information_schema.tables) ;
#    5. nettoyage systématique via trap EXIT : DROP de la base de test
#       et suppression du dump temporaire, MÊME en cas d'échec.
#
#  Sortie : exit 0 si les deux valeurs correspondent, exit 1 sinon
#  (les valeurs sont affichées). Surcharge possible : VPS_SSH.
# =====================================================================
set -euo pipefail

VPS_SSH="${VPS_SSH:-ubuntu@51.254.129.80}"

echo "=========================================================="
echo " AEIC — Test de restauration de sauvegarde"
echo " VPS : ${VPS_SSH} — $(date '+%Y-%m-%d %H:%M:%S')"
echo "=========================================================="

if ! ssh "${VPS_SSH}" 'bash -s' <<'REMOTE'
set -euo pipefail
cd "$HOME/AEIC"
set -a; . ./config.env; set +a

TEST_DB="aeic_restore_test"
DUMP_FILE="/tmp/aeic_restore_test.sql"

# --- Nettoyage systématique, même en cas d'échec ----------------------
cleanup() {
    echo "→ Nettoyage : suppression de ${TEST_DB} et du dump temporaire..."
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS ${TEST_DB};" >/dev/null 2>&1 || true
    rm -f "${DUMP_FILE}"
}
trap cleanup EXIT

# --- 1) Dump de la base de production (lecture seule) -----------------
echo "→ Dump de la base de production (${DB_NAME})..."
if ! mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" \
        --single-transaction --routines --triggers \
        --default-character-set=utf8mb4 \
        "${DB_NAME}" > "${DUMP_FILE}"; then
    echo "ERREUR : échec du mysqldump de ${DB_NAME}." >&2
    exit 1
fi
echo "   OK : ${DUMP_FILE} ($(du -h "${DUMP_FILE}" | cut -f1))"

# --- 2) Charset/collation de la prod → base temporaire identique ------
echo "→ Lecture du charset/collation de ${DB_NAME} (information_schema)..."
charset_row="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e \
    "SELECT default_character_set_name, default_collation_name FROM information_schema.SCHEMATA WHERE schema_name = '${DB_NAME}';")"
if [[ -z "${charset_row}" ]]; then
    echo "ERREUR : impossible de lire le charset/collation de ${DB_NAME}." >&2
    exit 1
fi
read -r DB_CHARSET DB_COLLATION <<< "${charset_row}"
echo "   Prod : ${DB_CHARSET} / ${DB_COLLATION}"

echo "→ Création de ${TEST_DB} (même charset/collation)..."
if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e \
    "DROP DATABASE IF EXISTS ${TEST_DB}; CREATE DATABASE ${TEST_DB} CHARACTER SET ${DB_CHARSET} COLLATE ${DB_COLLATION};"; then
    echo "ERREUR : échec de la création de ${TEST_DB}." >&2
    exit 1
fi

# --- 3) Import du dump dans la base temporaire ------------------------
echo "→ Import du dump dans ${TEST_DB}..."
if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "${TEST_DB}" < "${DUMP_FILE}"; then
    echo "ERREUR : échec de l'import du dump dans ${TEST_DB}." >&2
    exit 1
fi

# --- 4) Comparaison prod vs restaurée ---------------------------------
echo "→ Comparaison ${DB_NAME} vs ${TEST_DB}..."
prod_sales="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT COUNT(*) FROM sales;" "${DB_NAME}")"
test_sales="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT COUNT(*) FROM sales;" "${TEST_DB}")"
prod_tables="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}';")"
test_tables="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${TEST_DB}';")"

echo ""
echo "Comparaison (production / base restaurée) :"
echo "   Lignes de sales    : ${prod_sales} / ${test_sales}"
echo "   Nombre de tables   : ${prod_tables} / ${test_tables}"

if [[ "${prod_sales}" == "${test_sales}" && "${prod_tables}" == "${test_tables}" ]]; then
    echo ""
    echo "SUCCÈS : la sauvegarde est restaurable à l'identique."
    exit 0
fi

echo "" >&2
echo "ÉCHEC : divergence entre la production et la base restaurée." >&2
exit 1
REMOTE
then
    echo "" >&2
    echo "ERREUR : le test de restauration a ÉCHOUÉ (détails ci-dessus)." >&2
    exit 1
fi

echo ""
echo "Test de restauration : SUCCÈS (production intacte, base de test supprimée)."
exit 0

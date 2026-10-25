#!/usr/bin/env bash
# =====================================================================
#  AEIC — Déploiement sur le VPS (script à exécuter en LOCAL, Git Bash)
#
#  Usage :
#      ./scripts/deploy.sh              → déploiement réel
#      ./scripts/deploy.sh --dry-run    → plan complet, AUCUNE mutation
#
#  Déroulé du mode réel :
#    1. arbre git local propre (sinon arrêt immédiat, exit 1) ;
#    2. git pull --ff-only origin experiments sur le VPS ;
#    3. migrations SQL idempotentes (table applied_migrations) ;
#    4. health check HTTP (https://asso.aremond.ovh/ → 200) ;
#    5. résumé (commits déployés, migrations, health check).
#
#  Migrations SQL — la décision « bootstrap ou normal » est prise UNE
#  SEULE FOIS avant la boucle (jamais fichier par fichier, sinon on
#  rejouerait les migrations historiques) :
#    NORMAL    : table applied_migrations présente → appliquer chaque
#                fichier absent de la table, ignorer les autres ;
#    BOOTSTRAP : table absente → appliquer UNIQUEMENT
#                2026_applied_migrations.sql (création de la table) et
#                enregistrer TOUS les autres fichiers comme déjà
#                appliqués, SANS les exécuter (l'état de la base fait
#                foi). NB : le fichier bootstrap est traité AVANT la
#                boucle car des fichiers le précèdent dans l'ordre
#                alphabétique (2026_10_*, 2026_alias_*) : la table doit
#                exister avant tout enregistrement.
#
#  Marqueurs distants (stdout) : APPLIED<TAB>nom, BOOTSTRAP<TAB>nom,
#  SKIPPED<TAB>nom — les logs verbeux distants passent sur stderr.
#
#  Surcharge possible : VPS_SSH, BRANCH, HEALTH_URL.
# =====================================================================
set -euo pipefail

# --- Racine du projet : répertoire parent du dossier scripts/ ----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

VPS_SSH="${VPS_SSH:-ubuntu@51.254.129.80}"
BRANCH="${BRANCH:-experiments}"
HEALTH_URL="${HEALTH_URL:-https://asso.aremond.ovh/}"
BOOTSTRAP_FILE="2026_applied_migrations.sql"
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage : scripts/deploy.sh [--dry-run]

  (sans option)  Déploiement réel : vérification de l'arbre local,
                 pull --ff-only sur le VPS, migrations idempotentes,
                 health check, résumé.
  --dry-run      Plan complet sans RIEN modifier (lectures seules).
  -h, --help     Cette aide.
USAGE
}

case "${1:-}" in
    --dry-run|-n) DRY_RUN=1 ;;
    -h|--help)    usage; exit 0 ;;
    "")           : ;;
    *)            echo "ERREUR : option inconnue : ${1}" >&2; usage >&2; exit 1 ;;
esac

die()  { echo "ERREUR : ${*}" >&2; exit 1; }
warn() { echo "ATTENTION : ${*}" >&2; }
step() { echo ""; echo "→ ${*}"; }

# Compte les lignes non vides d'une variable (0 si vide).
count_lines() {
    printf '%s\n' "${1:-}" | grep -c '.' || true
}

if [[ "${DRY_RUN}" -eq 0 ]]; then
    # =========================== MODE RÉEL ============================
    echo "=========================================================="
    echo " AEIC — Déploiement réel sur ${VPS_SSH} (branche ${BRANCH})"
    echo " $(date '+%Y-%m-%d %H:%M:%S')"
    echo "=========================================================="

    # --- 1) Arbre git local propre ------------------------------------
    step "Vérification : arbre git local propre..."
    git -C "${APP_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || die "${APP_DIR} n'est pas un dépôt git."
    dirty="$(git -C "${APP_DIR}" status --porcelain)"
    if [[ -n "${dirty}" ]]; then
        printf '%s\n' "${dirty}" | sed 's/^/     /' >&2
        die "l'arbre git local n'est pas propre — committez et poussez (dev-git) avant de déployer."
    fi
    echo "   OK : arbre propre ($(git -C "${APP_DIR}" rev-parse --abbrev-ref HEAD) @ $(git -C "${APP_DIR}" rev-parse --short HEAD))"

    # --- 2) Mise à jour du code sur le VPS ----------------------------
    step "Mise à jour du code VPS : git pull --ff-only origin ${BRANCH}..."
    head_before="$(ssh "${VPS_SSH}" "cd ~/AEIC && git rev-parse HEAD")" \
        || die "impossible de lire le HEAD du VPS (ssh ?)."
    if ! ssh "${VPS_SSH}" "cd ~/AEIC && git pull --ff-only origin ${BRANCH}"; then
        die "échec du git pull --ff-only sur le VPS — divergence probable ; NE PAS forcer (règles projet), inspecter manuellement."
    fi
    head_after="$(ssh "${VPS_SSH}" "cd ~/AEIC && git rev-parse HEAD")" \
        || die "impossible de relire le HEAD du VPS après le pull."
    if [[ "${head_before}" == "${head_after}" ]]; then
        deployed_commits=""
    else
        deployed_commits="$(ssh "${VPS_SSH}" "cd ~/AEIC && git log --oneline ${head_before}..${head_after}")"
    fi
    echo "   OK : VPS ${head_before:0:7} → ${head_after:0:7}"

    # --- 3) Migrations SQL (idempotentes) ------------------------------
    step "Migrations SQL sur le VPS (database/migrations/*.sql)..."
    migrations_out=""
    if ! migrations_out="$(ssh "${VPS_SSH}" 'bash -s' <<'REMOTE'
set -euo pipefail
cd "$HOME/AEIC"
set -a; . ./config.env; set +a

BOOTSTRAP_FILE="2026_applied_migrations.sql"
MIG_DIR="database/migrations"

# --- Décision bootstrap / normal : UNE SEULE FOIS, AVANT la boucle ----
exists="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SHOW TABLES LIKE 'applied_migrations';" "$DB_NAME")"
if [[ -n "$exists" ]]; then
    MODE="normal"
    echo "   Table applied_migrations présente → mode NORMAL." >&2
else
    MODE="bootstrap"
    echo "   Table applied_migrations ABSENTE → mode BOOTSTRAP :" >&2
    echo "   l'état actuel de la base fait foi, les fichiers" >&2
    echo "   historiques seront enregistrés SANS être exécutés." >&2
fi

files_list="$(find "$MIG_DIR" -maxdepth 1 -name '*.sql' | LC_ALL=C sort)"

# --- Mode bootstrap : créer la table AVANT tout enregistrement --------
# (2026_10_*, 2026_alias_* précèdent 2026_applied_migrations.sql dans
#  l'ordre alphabétique : sans cela, leurs INSERT échoueraient.)
if [[ "$MODE" == "bootstrap" ]]; then
    bootstrap_path="${MIG_DIR}/${BOOTSTRAP_FILE}"
    if [[ ! -f "$bootstrap_path" ]]; then
        echo "ERREUR : ${bootstrap_path} introuvable (mode bootstrap)." >&2
        exit 1
    fi
    echo "   Application de ${BOOTSTRAP_FILE} (création de la table)..." >&2
    if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$bootstrap_path"; then
        echo "ERREUR : échec de la migration ${BOOTSTRAP_FILE}." >&2
        exit 1
    fi
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "INSERT IGNORE INTO applied_migrations (name) VALUES ('${BOOTSTRAP_FILE}');" "$DB_NAME"
    printf 'APPLIED\t%s\n' "$BOOTSTRAP_FILE"
fi

while IFS= read -r sql_path; do
    if [[ -z "$sql_path" ]]; then continue; fi
    name="$(basename "$sql_path")"

    if [[ "$MODE" == "bootstrap" ]]; then
        if [[ "$name" == "$BOOTSTRAP_FILE" ]]; then continue; fi
        # Fichier historique : enregistré comme appliqué SANS exécution.
        if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "INSERT IGNORE INTO applied_migrations (name) VALUES ('${name}');" "$DB_NAME"; then
            echo "ERREUR : échec de l'enregistrement (bootstrap) de ${name}." >&2
            exit 1
        fi
        echo "   ${name} : enregistrée sans exécution (historique)." >&2
        printf 'BOOTSTRAP\t%s\n' "$name"
    else
        known="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT name FROM applied_migrations WHERE name = '${name}';" "$DB_NAME")"
        if [[ -n "$known" ]]; then
            printf 'SKIPPED\t%s\n' "$name"
        else
            echo "   Application de ${name}..." >&2
            if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$sql_path"; then
                echo "ERREUR : échec de la migration ${name} — arrêt immédiat." >&2
                exit 1
            fi
            mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "INSERT IGNORE INTO applied_migrations (name) VALUES ('${name}');" "$DB_NAME"
            printf 'APPLIED\t%s\n' "$name"
        fi
    fi
done <<< "$files_list"

echo "   Migrations terminées sans erreur." >&2
REMOTE
    )"; then
        die "échec des migrations sur le VPS — voir le détail ci-dessus."
    fi

    # --- 4) Health check ------------------------------------------------
    step "Health check (${HEALTH_URL})..."
    http_code="$(curl -s -o /dev/null -w "%{http_code}" "${HEALTH_URL}")" || http_code="000"
    if [[ "${http_code}" != "200" ]]; then
        die "health check HTTP ${http_code} (200 attendu) sur ${HEALTH_URL} — site à inspecter immédiatement."
    fi
    echo "   OK : HTTP ${http_code}"

    # --- 5) Résumé ------------------------------------------------------
    applied_names="$(printf '%s\n' "${migrations_out}" | awk -F'\t' '$1 == "APPLIED"   { print $2 }')"
    bootstrap_names="$(printf '%s\n' "${migrations_out}" | awk -F'\t' '$1 == "BOOTSTRAP" { print $2 }')"
    skipped_names="$(printf '%s\n' "${migrations_out}" | awk -F'\t' '$1 == "SKIPPED"   { print $2 }')"

    echo ""
    echo "=========================================================="
    echo " RÉSUMÉ DU DÉPLOIEMENT — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "=========================================================="
    echo " Commits déployés : ${head_before:0:7} → ${head_after:0:7}"
    if [[ -n "${deployed_commits}" ]]; then
        printf '%s\n' "${deployed_commits}" | sed 's/^/     /'
    else
        echo "     (aucun nouveau commit)"
    fi
    echo ""
    echo " Migrations appliquées cette exécution ($(count_lines "${applied_names}")) :"
    if [[ -n "${applied_names}" ]]; then printf '%s\n' "${applied_names}" | sed 's/^/     - /'; else echo "     (aucune)"; fi
    echo ""
    echo " Historiques bootstrapées — enregistrées sans exécution ($(count_lines "${bootstrap_names}")) :"
    if [[ -n "${bootstrap_names}" ]]; then printf '%s\n' "${bootstrap_names}" | sed 's/^/     - /'; else echo "     (aucune)"; fi
    echo ""
    echo " Migrations ignorées — déjà appliquées ($(count_lines "${skipped_names}")) :"
    if [[ -n "${skipped_names}" ]]; then printf '%s\n' "${skipped_names}" | sed 's/^/     - /'; else echo "     (aucune)"; fi
    echo ""
    echo " Health check : HTTP ${http_code} — OK"
    echo " Statut : DÉPLOIEMENT RÉUSSI"
    echo "=========================================================="
else
    # ========================== MODE DRY-RUN ===========================
    echo "=========================================================="
    echo " AEIC — DRY-RUN du déploiement (rien ne sera modifié)"
    echo " $(date '+%Y-%m-%d %H:%M:%S')"
    echo "=========================================================="

    # --- État de l'arbre git local -------------------------------------
    echo ""
    echo "→ État de l'arbre git local (${APP_DIR}) :"
    dirty=""
    dirty="$(git -C "${APP_DIR}" status --porcelain 2>/dev/null)" || dirty=""
    if [[ -z "${dirty}" ]]; then
        echo "     PROPRE — le déploiement réel serait autorisé."
    else
        warn "arbre local NON propre — le déploiement réel s'arrêterait (exit 1) :"
        printf '%s\n' "${dirty}" | sed 's/^/     /'
    fi

    # --- HEAD local / origin / VPS --------------------------------------
    echo ""
    echo "→ Référentiels :"
    local_head=""
    local_head="$(git -C "${APP_DIR}" rev-parse HEAD 2>/dev/null)" || warn "impossible de lire le HEAD local."
    origin_sha=""
    origin_sha="$(git -C "${APP_DIR}" ls-remote origin "refs/heads/${BRANCH}" 2>/dev/null | cut -f1)" || origin_sha=""
    if [[ -z "${origin_sha}" ]]; then warn "impossible de lire origin/${BRANCH} (ls-remote)."; fi

    remote_info=""
    remote_info="$(ssh "${VPS_SSH}" 'bash -s' <<'REMOTE'
set -euo pipefail
cd "$HOME/AEIC"
echo "VPS_HEAD=$(git rev-parse HEAD)"
set -a; . ./config.env; set +a
exists="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SHOW TABLES LIKE 'applied_migrations';" "$DB_NAME" 2>/dev/null)"
if [[ -n "$exists" ]]; then
    echo "TABLE=1"
else
    echo "TABLE=0"
fi
echo "===APPLIED==="
if [[ -n "$exists" ]]; then
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT name FROM applied_migrations ORDER BY name;" "$DB_NAME" 2>/dev/null
fi
echo "===FILES==="
find database/migrations -maxdepth 1 -name '*.sql' | LC_ALL=C sort
REMOTE
)" || warn "impossible de lire l'état du VPS (ssh) — plan des migrations indisponible."

    vps_head=""
    table_flag=""
    applied_remote=""
    files_remote=""
    if [[ -n "${remote_info}" ]]; then
        vps_head="$(printf '%s\n' "${remote_info}" | sed -n 's/^VPS_HEAD=//p' | head -n 1)"
        table_flag="$(printf '%s\n' "${remote_info}" | sed -n 's/^TABLE=//p' | head -n 1)"
        if printf '%s\n' "${remote_info}" | grep -q '^===FILES===$'; then
            applied_remote="$(printf '%s\n' "${remote_info}" | sed -n '/^===APPLIED===$/,/^===FILES===$/p' | sed '1d;$d')"
            files_remote="$(printf '%s\n' "${remote_info}" | sed -n '/^===FILES===$/,$p' | sed '1d')"
        else
            warn "réponse du VPS incomplète (section fichiers manquante)."
        fi
    fi

    echo "     HEAD local  : ${local_head:0:7}"
    if [[ -n "${origin_sha}" ]]; then
        echo "     HEAD origin : ${origin_sha:0:7} (origin/${BRANCH})"
    else
        echo "     HEAD origin : illisible"
    fi
    if [[ -n "${vps_head}" ]]; then
        echo "     HEAD VPS    : ${vps_head:0:7}"
    else
        echo "     HEAD VPS    : illisible"
    fi
    if [[ -n "${local_head}" && -n "${origin_sha}" && "${local_head}" != "${origin_sha}" ]]; then
        warn "HEAD local ≠ origin/${BRANCH} — dev-git doit pousser avant tout déploiement réel."
    fi

    # --- Commits qui seraient déployés ----------------------------------
    echo ""
    echo "→ Commits qui seraient déployés (VPS → origin/${BRANCH}) :"
    planned=""
    if [[ -n "${vps_head}" && -n "${origin_sha}" ]]; then
        if git -C "${APP_DIR}" cat-file -e "${vps_head}^{commit}" 2>/dev/null && \
           git -C "${APP_DIR}" cat-file -e "${origin_sha}^{commit}" 2>/dev/null; then
            planned="$(git -C "${APP_DIR}" log --oneline "${vps_head}..${origin_sha}")"
        else
            warn "un des deux commits n'est pas connu localement — listing impossible."
        fi
    fi
    if [[ -n "${planned}" ]]; then
        printf '%s\n' "${planned}" | sed 's/^/     /'
    elif [[ -n "${vps_head}" && -n "${origin_sha}" ]]; then
        echo "     (aucun — le VPS serait déjà à jour)"
    else
        echo "     (listing indisponible)"
    fi

    # --- Plan des migrations ---------------------------------------------
    echo ""
    echo "→ Plan des migrations (database/migrations/*.sql, tri alphabétique) :"
    if [[ -z "${table_flag}" ]]; then
        warn "état de la table applied_migrations illisible — plan indisponible."
    elif [[ "${table_flag}" != "1" ]]; then
        echo "     Mode BOOTSTRAP (table applied_migrations absente sur le VPS) :"
        n_exec=0
        n_mark=0
        while IFS= read -r f; do
            if [[ -z "$f" ]]; then continue; fi
            name="$(basename "$f")"
            if [[ "$name" == "${BOOTSTRAP_FILE}" ]]; then
                echo "     [BOOTSTRAP-EXEC] ${name} — exécutée (création de la table)"
                n_exec=$((n_exec + 1))
            else
                echo "     [BOOTSTRAP-MARK] ${name} — enregistrée sans exécution (historique)"
                n_mark=$((n_mark + 1))
            fi
        done <<< "${files_remote}"
        echo "     Total : ${n_exec} à exécuter, ${n_mark} à enregistrer sans exécution."
    else
        echo "     Mode NORMAL (table applied_migrations présente sur le VPS) :"
        n_apply=0
        n_skip=0
        while IFS= read -r f; do
            if [[ -z "$f" ]]; then continue; fi
            name="$(basename "$f")"
            if printf '%s\n' "${applied_remote}" | grep -Fxq -- "$name"; then
                echo "     [DÉJÀ APPLIQUÉE — ignorée] ${name}"
                n_skip=$((n_skip + 1))
            else
                echo "     [À APPLIQUER] ${name}"
                n_apply=$((n_apply + 1))
            fi
        done <<< "${files_remote}"
        echo "     Total : ${n_apply} à appliquer, ${n_skip} déjà appliquées (ignorées)."
    fi

    echo ""
    echo "=========================================================="
    echo " Fin du DRY-RUN — rien n'a été modifié (ni dépôt local, ni"
    echo " VPS, ni base de données). Anomalies éventuelles signalées"
    echo " ci-dessus comme avertissements uniquement."
    echo "=========================================================="
fi

exit 0

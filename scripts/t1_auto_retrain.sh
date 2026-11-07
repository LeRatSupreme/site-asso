#!/usr/bin/env bash
# ============================================================================
# AEIC — T1 P2-auto (brief A2, dev-factures)
# Pipeline de ré-entraînement de bout en bout du détecteur de doublons,
# enchaîné par les retours humains (décisions t1.pair_feedback récoltées
# par la logique A1). Prouvé sur tout le pipeline P2-ter existant, dont il
# ne fait que PARAMÉTRER les étapes par les nouveaux retours.
#
# Usage (déclenché en arrière-plan par T1AutoRetrain::passage au chargement
# de la page Inventaire — AUCUN cron ; relançable à la main) :
#   bash scripts/t1_auto_retrain.sh             # pipeline complet
#   bash scripts/t1_auto_retrain.sh --dry-run   # validation : AUCUN envoi
#                                              # Kaggle, AUCUN remplacement,
#                                              # étape 5 simulée avec le
#                                              # modèle courant
#
# Chaîne (contraintes /tmp/p2auto_briefs/00_CONTRAINTES.md) :
#   1. export des paires étiquetées nouvelles (logique A1 en CLI,
#      T1AutoRetrain::recolter — point d'assemblage unique, base LECTURE
#      SEULE) -> data/eval_t1/p2/auto/feedback_pairs.csv ;
#   2. régénération du jeu augmenté v2 ENRICHI (scripts/t1_augment.py --v2
#      --feedback : fusions = positives supplémentaires, ignorées = durs
#      négatifs supplémentaires, anti-triche inchangé, graines 42/4242)
#      -> auto/run_<ts>/ + SHA256SUMS ;
#   3. AUTO-AUDIT SÉCURITÉ AVANT TOUT ENVOI (contrainte n°4) : greps
#      secrets/montants/fournisseurs/emails/IP/téléphones sur les CSV,
#      inventaire kernel = 2 fichiers exactement, diff kernel vs version
#      validée (git) = vide. Échec -> statut echec, log, STOP ;
#   4. Kaggle : datasets version (bump du dataset pairs privé, même id) +
#      kernels push (kernel v4 JAMAIS modifié) + polling (budget 90 min,
#      2 tentatives) + récupération output ;
#   5. conversion int8 locale (pipeline P4 prouvé) + mesure S4 à pareil à
#      pareil + anti-régression du modèle courant -> rapport comparatif
#      rapport_S4_auto.md avec VERDICT (règle conservatrice) ;
#   6. MEILLEUR -> remplacement prod (cache + candidats_fusion.json
#      régénérés + rollback documenté) ; ÉGAL/PIRE -> conservation, run
#      archivé. etat.json + journal.md mis à jour DANS TOUS LES CAS
#      (echec/remplace/consserve — unique écrivain T1AutoRetrain::marquer).
#
# Garanties :
#   - idempotent : flock dédié (plafond 1 run, distinct du verrou d'état
#     A1), log dédié data/eval_t1/p2/auto/retrain.log ;
#   - échec = statut echec + JAMAIS de fusion partielle (le swap du cache
#     et la régénération du livrable sont atomiques de bout en bout, avec
#     restauration immédiate si la régénération échouait) ;
#   - jeu figé data/eval_t1/ racine INTACT (LECTURE SEULE — la boucle
#     écrit dans data/eval_t1/p2/auto/ UNIQUEMENT) ;
#   - aucun envoi Kaggle ne part sans audit passé ; en --dry-run AUCUNE
#     commande kaggle n'est exécutée et le modèle courant reste en place ;
#   - RAM : rien de nouveau au-delà de l'existant — l'entraînement est sur
#     Kaggle, la conversion locale (seul moment lourd) est le pipeline P4
#     déjà prouvé, son pic RSS est journalisé (garde < 500 Mo hors
#     conversion documentée).
#
# Chemins redirigeables (validation sur aeic_test / tests) : AEIC_P2AUTO_DIR
# (dossier de la boucle), AEIC_P2AUTO_LOG, AEIC_P2AUTO_ETAT,
# AEIC_P2AUTO_JOURNAL. Défauts : production.
# ============================================================================
set -euo pipefail
export LC_ALL=C.UTF-8
cd "$(dirname "$0")/.."

# ── Options ─────────────────────────────────────────────────────────────────
DRY_RUN=0
case "${1:-}" in
  --dry-run) DRY_RUN=1 ;;
  "") ;;
  *) echo "usage : $0 [--dry-run]" >&2; exit 64 ;;
esac

AUTO_DIR="${AEIC_P2AUTO_DIR:-data/eval_t1/p2/auto}"
LOG="${AEIC_P2AUTO_LOG:-$AUTO_DIR/retrain.log}"
KERNEL_DIR="data/eval_t1/p2/kernel"
DATASET_ID="adrienremond/aeic-t1-p2-pairs"
KERNEL_ID="adrienremond/aeic-t1-p2-finetune"
PY_T1="$PWD/.venv-t1/bin/python3"
PY_CONV="$PWD/.venv-p2conv/bin/python3"
export PATH="$HOME/.local/bin:$PATH"   # CLI kaggle (~/.local/bin)

mkdir -p "$AUTO_DIR"
exec >>"$LOG" 2>&1
echo
echo "############################################################################"
echo "# P2-auto — $(date -u +%Y-%m-%dT%H:%M:%SZ) — mode : $([ $DRY_RUN -eq 1 ] && echo 'DRY-RUN (aucun envoi Kaggle, aucun remplacement)' || echo 'COMPLET')"

# ── Plafond 1 run (flock propre au script, distinct du verrou d'état A1) ────
exec 200>"$AUTO_DIR/retrain.lock"
if ! flock -n 200; then
  echo "# un run P2-auto est déjà en cours — sortie (idempotent)"
  exit 0
fi

RUN_TS="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="$AUTO_DIR/run_$RUN_TS"
mkdir -p "$RUN_DIR"
CONSOMME=""

TERMINE=0
echec() { # $1 = raison → statut echec + journal + sortie 1
  echo "== ÉCHEC : $1 =="
  php scripts/t1_auto_retrain_etat.php --marquer=echec \
      --detail="$1${CONSOMME:+ (borne export : $CONSOMME)}" || true
  TERMINE=1
  exit 1
}
sur_interruption() {
  if [ "$TERMINE" -eq 0 ]; then
    echec "interruption inattendue du pipeline (ligne ${BASH_LINENO[0]:-?})"
  fi
}
trap sur_interruption EXIT
etape() { echo; echo "== [$RUN_TS] [$(date -u +%H:%M:%SZ)] $1 =="; }

# ── Étape 1 — export des paires étiquetées nouvelles (logique A1, CLI) ──────
etape "1/6 export des paires étiquetées nouvelles (T1AutoRetrain::recolter, base LECTURE SEULE)"
EXPORT_CODE=0
EXPORT_OUT=$(php scripts/t1_auto_export_feedback.php --sortie="$AUTO_DIR/feedback_pairs.csv" 2>&1) || EXPORT_CODE=$?
echo "$EXPORT_OUT"
case $EXPORT_CODE in
  0) ;;
  2) echo "== aucune paire nouvelle : rien à faire (sortie propre, état inchangé) =="
     TERMINE=1; exit 0 ;;
  *) echec "export des paires impossible (base ou écriture)" ;;
esac
CONSOMME=$(printf '%s\n' "$EXPORT_OUT" | grep -oE 'CONSOMME_JUSQUA=.*' | cut -d= -f2 || true)
NB_FUSIONS=$(grep -c '^fusion,' "$AUTO_DIR/feedback_pairs.csv" || true)
NB_IGNORES=$(grep -c '^ignorer,' "$AUTO_DIR/feedback_pairs.csv" || true)
echo "   $NB_FUSIONS fusions + $NB_IGNORES ignorés — borne de consommation : ${CONSOMME:-n/a}"

# ── Étape 2 — régénération du jeu augmenté enrichi des retours ──────────────
etape "2/6 régénération du jeu augmenté (t1_augment.py --v2 --feedback, graines 42/4242)"
[ -x "$PY_T1" ] || echec "venv introuvable : $PY_T1"
if ! "$PY_T1" scripts/t1_augment.py --v2 --feedback "$AUTO_DIR/feedback_pairs.csv" --sortie "$RUN_DIR"; then
  echec "génération augmentée impossible (anti-triche ou volume en échec — rien n'est envoyé)"
fi
sha256sum -c "$RUN_DIR/SHA256SUMS" >/dev/null || echec "SHA256SUMS du run incohérents"

# ── Étape 3 — AUTO-AUDIT SÉCURITÉ (contrainte n°4, AVANT tout envoi) ────────
etape "3/6 auto-audit sécurité : CSV + kernel inchangé (diff vs version validée = vide)"
AUDIT="$RUN_DIR/audit_securite.md"
AUDIT_KO=0
note() { echo "$1" | tee -a "$AUDIT"; }
verifier_grep() { # $1 fichier, $2 motif ERE, $3 description
  local n
  n=$(grep -E -c -- "$2" "$1" 2>/dev/null || true)
  if [ "${n:-0}" -gt 0 ]; then
    note "KO   : $3 — ${n} ligne(s) dans $(basename "$1") (ex. : $(grep -E -m1 -- "$2" "$1" | cut -c1-120))"
    AUDIT_KO=1
  else
    note "OK   : $3 — aucune occurrence dans $(basename "$1")"
  fi
}
{
  echo "# Auto-audit sécurité du run $RUN_TS (contrainte n°4 — exécuté AVANT tout envoi Kaggle)"
  echo
  echo "- date : $(date -u +%Y-%m-%dT%H:%M:%SZ) · mode : $([ $DRY_RUN -eq 1 ] && echo dry-run || echo complet)"
  echo
  echo "## CSV destinés à Kaggle (libellés produits uniquement — protocole D3)"
  echo
} > "$AUDIT"
for csv in "$RUN_DIR/kaggle_upload/train_pairs.csv" "$RUN_DIR/kaggle_upload/val_pairs.csv"; do
  verifier_grep "$csv" '[0-9]+[.,][0-9]{2}' 'montant (NN,NN)'
  verifier_grep "$csv" '€|\beuros?\b|\bEUR\b' 'symbole/motif euro'
  verifier_grep "$csv" '\bMETRO\b|\bAUCHAN\b|[Cc]arrefour|\bFLUNCH\b|\bMONOPRIX\b|[Ii]ntermarch' 'fournisseur (METRO/Auchan/Carrefour/…)'
  verifier_grep "$csv" '[Pp]assw(or)?d|[Mm]ot de passe|secret|[Aa][Pp][Ii][ _-]?[Kk]ey|BEGIN [A-Z ]*PRIVATE KEY|\bBearer\b' 'secret (mot de passe, clé, token)'
  verifier_grep "$csv" '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' 'adresse e-mail'
  verifier_grep "$csv" '(^|[^0-9])([0-9]{1,3}\.){3}[0-9]{1,3}([^0-9]|$)' 'adresse IP'
  verifier_grep "$csv" '\b0[1-9][0-9]{8}\b' 'numéro de téléphone (10 chiffres)'
  verifier_grep "$csv" '\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b' 'date ISO'
done
note ""
note "## Feedback local (jamais poussé vers Kaggle) — greps sur les LIBELLÉS seuls"
note "## (colonnes a/b extraites : le score de similarité 0,NN et decide_le ne sont pas des données métier)"
note ""
python3 - "$AUTO_DIR/feedback_pairs.csv" > "$RUN_DIR/feedback_labels.txt" <<'PYEXTRACT'
import csv, sys
with open(sys.argv[1], newline="", encoding="utf-8") as f:
    for r in csv.DictReader(f):
        print(r.get("a") or "")
        print(r.get("b") or "")
PYEXTRACT
verifier_grep "$RUN_DIR/feedback_labels.txt" '[0-9]+[.,][0-9]{2}' 'montant (NN,NN)'
verifier_grep "$RUN_DIR/feedback_labels.txt" '€|\beuros?\b|\bEUR\b' 'symbole/motif euro'
verifier_grep "$RUN_DIR/feedback_labels.txt" '\bMETRO\b|\bAUCHAN\b|[Cc]arrefour|\bFLUNCH\b|\bMONOPRIX\b|[Ii]ntermarch' 'fournisseur'
verifier_grep "$RUN_DIR/feedback_labels.txt" '[Pp]assw(or)?d|[Mm]ot de passe|secret|[Aa][Pp][Ii][ _-]?[Kk]ey|BEGIN [A-Z ]*PRIVATE KEY|\bBearer\b' 'secret'
verifier_grep "$RUN_DIR/feedback_labels.txt" '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' 'adresse e-mail'
verifier_grep "$RUN_DIR/feedback_labels.txt" '\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b' 'date ISO dans un libellé'
rm -f "$RUN_DIR/feedback_labels.txt"
note ""
note "## Kernel — JAMAIS modifié (contrainte n°4 : seules les DONNÉES changent)"
note ""
NB_KERNEL=$(find "$KERNEL_DIR" -type f | wc -l)
if [ "$NB_KERNEL" = "2" ] \
   && [ -f "$KERNEL_DIR/kernel-metadata.json" ] && [ -f "$KERNEL_DIR/train.py" ] \
   && [ -z "$(find "$KERNEL_DIR" -type f ! -name kernel-metadata.json ! -name train.py)" ]; then
  note "OK   : inventaire kernel = 2 fichiers exactement (kernel-metadata.json, train.py)"
else
  note "KO   : inventaire kernel inattendu ($(find "$KERNEL_DIR" -type f | sed "s|$KERNEL_DIR/||" | sort | tr '\n' ' '))"
  AUDIT_KO=1
fi
if git diff --quiet -- "$KERNEL_DIR" && git diff --cached --quiet -- "$KERNEL_DIR" \
   && [ -z "$(git status --porcelain -- "$KERNEL_DIR")" ]; then
  note "OK   : diff kernel vs version validée (git) = vide"
else
  note "KO   : le kernel diffère de la version validée (git diff/status non vide)"
  AUDIT_KO=1
fi
if python3 -c "import py_compile; py_compile.compile('$KERNEL_DIR/train.py', cfile='$RUN_DIR/kernel_train.pyc', doraise=True)"; then
  note "OK   : syntaxe kernel v4 (py_compile)"
else
  note "KO   : py_compile kernel en échec"
  AUDIT_KO=1
fi
rm -f "$RUN_DIR/kernel_train.pyc"
if jq -e '.isPrivate == true and .id == "adrienremond/aeic-t1-p2-pairs"' "$RUN_DIR/kaggle_upload/dataset-metadata.json" >/dev/null; then
  note "OK   : dataset privé (même id adrienremond/aeic-t1-p2-pairs — bump de version, pas un nouveau dataset)"
else
  note "KO   : dataset-metadata.json incohérent (privé / id)"
  AUDIT_KO=1
fi
note ""
if [ "$AUDIT_KO" = "1" ]; then
  note "VERDICT AUTO-AUDIT : ÉCHEC — aucun envoi Kaggle, statut echec (contrainte n°4)"
  echec "auto-audit sécurité en échec (voir $AUDIT) — aucun envoi effectué"
else
  note "VERDICT AUTO-AUDIT : CONFORME — envoi autorisé"
fi

# ── Étape 4 — Kaggle : dataset (bump de version) + kernel v4 + polling ─────
if [ $DRY_RUN -eq 1 ]; then
  etape "4/6 Kaggle : IGNORÉ en --dry-run (aucune commande kaggle exécutée)"
else
  etape "4/6 Kaggle : datasets version + kernels push (v4 inchangé) + polling (budget 90 min, 2 tentatives)"
  command -v kaggle >/dev/null || echec "CLI kaggle introuvable"
  kaggle_datasets_version() {
    local t=1
    while [ $t -le 2 ]; do
      if kaggle datasets version -p "$RUN_DIR/kaggle_upload" \
          -m "auto $RUN_TS : $NB_FUSIONS fusions + $NB_IGNORES ignores integres (boucle P2-auto)" \
          --dir-mode zip; then return 0; fi
      echo "   datasets version : tentative $t/2 échouée"
      t=$((t + 1)); sleep 30
    done
    return 1
  }
  kaggle_datasets_pret() {
    local limite=$((SECONDS + 600)) s
    while [ $SECONDS -lt $limite ]; do
      s=$(kaggle datasets status "$DATASET_ID" 2>&1 || true)
      case "$s" in *ready*|*Complete*|*COMPLETE*) return 0 ;; esac
      sleep 20
    done
    return 1
  }
  kaggle_kernel_attendre() {
    local limite=$((SECONDS + 5400)) s   # budget 90 min de polling
    while [ $SECONDS -lt $limite ]; do
      s=$(kaggle kernels status "$KERNEL_ID" 2>&1 || true)
      case "$s" in
        *COMPLETE*) return 0 ;;
        *ERROR*|*CANCEL*) echo "   kernel : $s"; return 1 ;;
      esac
      sleep 60
    done
    return 1
  }
  if ! kaggle_datasets_version; then echec "kaggle datasets version en échec (2 tentatives)"; fi
  if ! kaggle_datasets_pret; then echec "dataset pairs jamais prêt après version bump"; fi
  KERNEL_OK=0
  T=1
  while [ $T -le 2 ]; do
    echo "   kernels push (tentative $T/2) — kernel v4 inchangé…"
    if kaggle kernels push -p "$KERNEL_DIR"; then
      if kaggle_kernel_attendre; then
        rm -rf "$RUN_DIR/kernel_output"
        mkdir -p "$RUN_DIR/kernel_output"
        if kaggle kernels output "$KERNEL_ID" -p "$RUN_DIR/kernel_output/"; then
          KERNEL_OK=1; break
        fi
      fi
    fi
    T=$((T + 1)); sleep 60
  done
  [ "$KERNEL_OK" = "1" ] || echec "kaggle kernels push/polling/output en échec (2 tentatives)"
  [ -f "$RUN_DIR/kernel_output/model/model.safetensors" ] || echec "sortie kernel incomplète (model.safetensors absent)"
fi

# ── Étape 5 — conversion int8 + mesure S4 + anti-régression + verdict ──────
MODELE_CANDIDAT="$RUN_DIR/convert/model-int8"
if [ $DRY_RUN -eq 1 ]; then
  etape "5/6 conversion IGNORÉE en --dry-run — mesure S4 SIMULÉE avec le modèle courant (cache prod)"
  MODELE_CANDIDAT="data/eval_t1/.cache/model-int8"
else
  etape "5/6 conversion int8 locale (pipeline P4 prouvé — pic RSS journalisé, contrainte n°8)"
  [ -x "$PY_CONV" ] || echec "venv introuvable : $PY_CONV"
  if ! /usr/bin/time -v "$PY_CONV" scripts/t1_export_onnx.py \
        --entree "$RUN_DIR/kernel_output/model" --sortie "$MODELE_CANDIDAT" \
        > "$RUN_DIR/conversion.log" 2>&1; then
    tail -30 "$RUN_DIR/conversion.log" || true
    echec "conversion int8 en échec (voir $RUN_DIR/conversion.log)"
  fi
  grep -E '^\[(labels|ref|tokenizer|export|prep|int8|fidélité|poids|ok)\]' "$RUN_DIR/conversion.log" | tail -12 || true
  grep -E 'Maximum resident set size' "$RUN_DIR/conversion.log" | tail -1 | sed 's/[[:space:]]\+/ /g; s/^ //' || true
fi
if ! "$PY_T1" scripts/t1_similarity.py --evaluer --model "$MODELE_CANDIDAT" \
      --rapport-sortie "$RUN_DIR/rapport_S4_auto_brut.md" \
      --candidats-sortie "$RUN_DIR/candidats_fusion_auto.json"; then
  echec "mesure S4 du candidat impossible"
fi
if ! "$PY_T1" scripts/t1_similarity.py --evaluer \
      --rapport-sortie "$RUN_DIR/antiregression_prod.md" \
      --candidats-sortie "$RUN_DIR/antiregression_candidats_prod.json"; then
  echec "anti-régression du modèle courant impossible"
fi
if ! python3 scripts/t1_auto_verdict.py \
      --candidat "$RUN_DIR/rapport_S4_auto_brut.md" \
      --prod "$RUN_DIR/antiregression_prod.md" \
      --sortie "$RUN_DIR/rapport_S4_auto.md" \
      --contexte "$([ $DRY_RUN -eq 1 ] && echo 'dry-run (étape 5 simulée avec le modèle courant)' || echo 'complet')"; then
  echec "verdict illisible (rapports S4 incomplets)"
fi
VERDICT=$(grep -oE 'VERDICT : (MEILLEUR|ÉGAL|PIRE)' "$RUN_DIR/rapport_S4_auto.md" | tail -1 | grep -oE '(MEILLEUR|ÉGAL|PIRE)')
echo "   verdict S4 auto : $VERDICT (détail : $RUN_DIR/rapport_S4_auto.md)"

# ── Étape 6 — remplacement (si strictement meilleur) ou conservation ───────
ARGS_RUN=()
if [ -n "$CONSOMME" ]; then ARGS_RUN=(--run="$CONSOMME"); fi
if [ $DRY_RUN -eq 1 ]; then
  etape "6/6 --dry-run : AUCUN remplacement — état marqué « consserve » (paires consommées)"
  php scripts/t1_auto_retrain_etat.php --marquer=consserve "${ARGS_RUN[@]}" \
      --detail="dry-run validé (verdict $VERDICT, aucun envoi Kaggle, aucun remplacement)" \
    || echec "marquage consserve impossible"
  echo "== DRY-RUN TERMINÉ : OK (run archivé dans $RUN_DIR) =="
  TERMINE=1
  exit 0
fi
if [ "$VERDICT" = "MEILLEUR" ]; then
  etape "6/6 verdict MEILLEUR → remplacement du modèle de production (+ rollback documenté)"
  ANCIEN_SHA=$(sha256sum data/eval_t1/.cache/model-int8/model_optimized.onnx | cut -d' ' -f1)
  NOUVEAU_SHA=$(sha256sum "$MODELE_CANDIDAT/model_optimized.onnx" | cut -d' ' -f1)
  rm -rf "$RUN_DIR/rollback_model-int8"
  mkdir -p "$RUN_DIR/rollback_model-int8"
  cp -a data/eval_t1/.cache/model-int8/. "$RUN_DIR/rollback_model-int8/" || echec "archivage rollback impossible"
  cp -a "$MODELE_CANDIDAT/." data/eval_t1/.cache/model-int8/ || echec "installation du nouveau binaire impossible"
  if ! "$PY_T1" scripts/t1_similarity.py --evaluer --rapport-sortie "$RUN_DIR/rapport_S4_auto_prod.md"; then
    # JAMAIS de fusion partielle : restauration immédiate de l'ancien modèle
    cp -a "$RUN_DIR/rollback_model-int8/." data/eval_t1/.cache/model-int8/
    echec "régénération du livrable candidats_fusion.json impossible — rollback effectué"
  fi
  MODELE_VERSION=$(jq -r '.modele' data/eval_t1/candidats_fusion.json)
  cat > "$RUN_DIR/ROLLBACK.md" <<EOF
# Rollback — retour au modèle précédent (une commande)

Le run $RUN_TS a remplacé le modèle de production (verdict MEILLEUR).

- ancien binaire (sha256 $ANCIEN_SHA) archivé : $RUN_DIR/rollback_model-int8/
- nouveau binaire (sha256 $NOUVEAU_SHA) : $MODELE_CANDIDAT

Retour arrière :

    cd $(pwd) && cp -a $RUN_DIR/rollback_model-int8/. data/eval_t1/.cache/model-int8/ \\
      && .venv-t1/bin/python3 scripts/t1_similarity.py --evaluer \\
           --rapport-sortie $RUN_DIR/rollback_rapport.md

(vérification : sha256sum data/eval_t1/.cache/model-int8/model_optimized.onnx → $ANCIEN_SHA)
EOF
  php scripts/t1_auto_retrain_etat.php --marquer=remplace "${ARGS_RUN[@]}" \
      --modele="$MODELE_VERSION" \
    || echec "marquage remplace impossible (modèle installé — vérifier etat.json)"
  echo "== RUN TERMINÉ : modèle REMPLACÉ ($MODELE_VERSION) — rollback : $RUN_DIR/ROLLBACK.md =="
else
  etape "6/6 verdict $VERDICT → CONSERVATION du modèle courant, run archivé"
  php scripts/t1_auto_retrain_etat.php --marquer=consserve "${ARGS_RUN[@]}" \
      --detail="verdict $VERDICT — modèle courant conservé (contrainte n°5)" \
    || echec "marquage consserve impossible"
  echo "== RUN TERMINÉ : modèle CONSERVÉ (verdict $VERDICT) — run archivé dans $RUN_DIR =="
fi
TERMINE=1
exit 0

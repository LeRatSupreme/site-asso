#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Parcours métier E2E T1.5 (brief T15-C — dev-tests), 100 % sur aeic_test.
#
# Rejouable : bash data/eval_t1/t15/parcours_e2e.sh
#
# (a) paire casse pure  → fusion auto niveau 1 AU CHARGEMENT + 2 audits
#                         + photo d'avant-fusion dans details JSON
# (b) paire sémantique  → quarantaine 48 h visible, compte à rebours Paris
# (c) veto              → jamais fusionnée (même à maturité), listée annulée
# (d) horloge +48 h 01  → fusion au chargement + restauration fiche/alias/stock
# (e) coûts divergents  → jamais en quarantaine, reste en suggestion (niveau 3)
# (p) perf              → chargement inventory() avec 50 paires en quarantaine
#
# Base : aeic_test via port 3307 (méthode officielle sed-port).
# Quarantaine : tests/cache/t15/ — la vraie data/eval_t1/t15/quarantaine.json
# n'est JAMAIS touchée (redirection AEIC_T15_QUARANTAINE, cf. helper).
# Jeu figé data/eval_t1 : INTACT (candidats dédiés en /tmp, jamais le fichier).
# ─────────────────────────────────────────────────────────────────────────────
set -u
RACINE="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$RACINE" || exit 1

H="php data/eval_t1/t15/parcours_helper.php"
RUNNER="php tests/Integration/runner.php"

TOTAL=0; OK=0; FAILED=0
check() { # $1 = libellé, $2 = expression shell (0 = succès)
  TOTAL=$((TOTAL+1))
  if eval "$2" >/dev/null 2>&1; then OK=$((OK+1)); printf '  OK   %s\n' "$1"
  else FAILED=1; printf '  KO   %s\n' "$1"; fi
}

# Requête HTTP simulée via le runner des tests d'intégration (routeur RÉEL,
# session admin forcée, CSRF neutralisé par APP_TESTING).
REQ_JSON=""; REQ_CODE=""; REQ_BODY=""
req() { # $1 = METHODE, $2 = chemin, $3 = POST json (optionnel)
  local post_env=()
  [ "${3:-}" != "" ] && post_env=("AEIC_TEST_POST=$3")
  REQ_JSON=$(env \
    DB_HOST=127.0.0.1:3307 DB_NAME=aeic_test DB_USER=aeic DB_PASS=aeic \
    APP_TESTING=true APP_URL=https://example.test \
    AEIC_TEST_METHOD="$1" AEIC_TEST_PATH="$2" AEIC_TEST_SESSION_ID= \
    AEIC_TEST_FORCE_USER=u_parcours "${post_env[@]}" \
    $RUNNER 2>/dev/null | awk '/^--AEIC_TEST_RESPONSE--$/{f=1;next} f')
  REQ_CODE=$(printf '%s' "$REQ_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (int)($j["code"]??0);')
  REQ_BODY=$(printf '%s' "$REQ_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (string)($j["body"]??""), "\n";')
  if [ "$REQ_CODE" -ge 400 ] 2>/dev/null; then
    printf '       [HTTP %s] %s...\n' "$REQ_CODE" "$(printf %s "$REQ_BODY" | tr '\n' ' ' | head -c 300)" >&2
  fi
}

# Extrait le premier audit <action> : champ $2 (dotted path), comparé à $3.
# $3 = '§present' → teste uniquement la présence non vide du champ.
audit_field() { $H audit "$1" | php -r '$rows=json_decode(stream_get_contents(STDIN),true);
$a=$rows[0]??[]; foreach (explode(".", $argv[1]) as $k) { if(!is_array($a)){ $a=null; break; } $a=$a[$k]??null; }
if ($argv[2]==="§present") { echo ($a!==null && $a!=="" && $a!==[]) ? "1" : "0"; }
else { echo ((string)$a === $argv[2]) ? "1" : "0"; }' "$2" "$3"; }

sql_scalar() { $H q "$1" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo (string)(array_values($r[0]??[""])[0] ?? "");'; }

qcount() { $H q "$1" | php -r 'echo (string)count(json_decode(stream_get_contents(STDIN),true));'; }

echo "══════════ T1.5 — Parcours métier E2E (aeic_test) ══════════"

# ── (a) Paire casse pure → fusion niveau 1 au chargement + 2 audits ─────────
echo "── (a) casse pure « Pepsi »/« pepsi » → fusion auto niveau 1 ──"
$H seed-base >/dev/null
$H seed-casse 'Pepsi' 'pepsi' 5 >/dev/null
req GET /admin/compta/inventaire
check "GET inventaire répond 200"                 '[ "$REQ_CODE" = 200 ]'
check "fusion : 2 ventes sur « pepsi »"           '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'pepsi'"'"'")" = 2 ]'
check "fusion : stock 5 sur « pepsi »"            '[ "$(sql_scalar "SELECT stock FROM product_stocks WHERE product_key = '"'"'pepsi'"'"'")" = 5 ]'
check "fusion : « Pepsi » absorbée (0 ligne)"     '[ "$(qcount "SELECT product_key FROM product_stocks WHERE product_key = '"'"'Pepsi'"'"' COLLATE utf8mb4_bin")" = 0 ]'
check "audit inventory.merge écrit (1)"           '[ "$(qcount "SELECT id FROM audit_logs WHERE action = '"'"'inventory.merge'"'"'")" = 1 ]'
check "audit : origine=auto"                      '[ "$(audit_field inventory.merge details.origine auto)" = 1 ]'
check "audit : niveau=1"                          '[ "$(audit_field inventory.merge details.niveau 1)" = 1 ]'
check "audit : motif=casse"                       '[ "$(audit_field inventory.merge details.motif casse)" = 1 ]'
check "audit : source=Pepsi target=pepsi"         '[ "$(audit_field inventory.merge details.source Pepsi)" = 1 ] && [ "$(audit_field inventory.merge details.target pepsi)" = 1 ]'
check "PHOTO pré-fusion présente (archive)"       '[ "$(audit_field inventory.merge details.archive.capture_le §present)" = 1 ]'
check "photo : stock source AVANT fusion = 5"     '[ "$(audit_field inventory.merge details.archive.source.stock 5)" = 1 ]'
check "photo : compteurs ventes source = 0"       '[ "$(audit_field inventory.merge details.archive.source.compteurs.ventes 0)" = 1 ]'
check "audit t1.pair_feedback écrit (1)"          '[ "$(qcount "SELECT id FROM audit_logs WHERE action = '"'"'t1.pair_feedback'"'"'")" = 1 ]'
check "feedback : decision=fusion origine=auto"   '[ "$(audit_field t1.pair_feedback details.decision fusion)" = 1 ] && [ "$(audit_field t1.pair_feedback details.origine auto)" = 1 ]'
check "écran : carte #fusions-auto"               'printf %s "$REQ_BODY" | grep -q "id=\"fusions-auto\""'
check "écran : section appliquées + Restaurer"    'printf %s "$REQ_BODY" | grep -q "Fusions automatiques appliquées" && printf %s "$REQ_BODY" | grep -q "Restaurer la clé"'

# ── (b) Paire sémantique sûre → quarantaine 48 h visible ────────────────────
echo "── (b) « Fanta »/« Fanta Orange » → quarantaine 48 h (Paris) ──"
$H seed-base >/dev/null
$H seed-paire 'Fanta' 'Fanta Orange' 3 4 >/dev/null
$H candidats 'Fanta' 'Fanta Orange' 0.9228 >/dev/null
DECL=$($H passage)
check "passage : 1 déclarée, 0 fusionnée"         'printf %s "$DECL" | php -r '\''$r=json_decode(stream_get_contents(STDIN),true); exit(($r["declarees"]??-1)===1 && ($r["appliquees"]??-1)===0 ? 0:1);'\'''
check "quarantaine : 1 entrée en_attente"         '$H quarantaine | php -r '\''$e=json_decode(stream_get_contents(STDIN),true); exit(count($e)===1 && ($e[0]["statut"]??"")==="en_attente"?0:1);'\'''
check "quarantaine : source=Fanta Orange target=Fanta" '$H quarantaine | php -r '\''$e=json_decode(stream_get_contents(STDIN),true)[0]; exit($e["source"]==="Fanta Orange" && $e["target"]==="Fanta"?0:1);'\'''
check "vente intacte sur Fanta (pas de fusion)"   '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta'"'"'")" = 1 ]'
check "0 audit inventory.merge"                   '[ "$(qcount "SELECT id FROM audit_logs WHERE action = '"'"'inventory.merge'"'"'")" = 0 ]'
req GET /admin/compta/inventaire
check "GET inventaire répond 200"                 '[ "$REQ_CODE" = 200 ]'
check "écran : section quarantaine fuseau Paris"  'printf %s "$REQ_BODY" | grep -q "En quarantaine (48 h, fuseau de Paris)"'
check "écran : compte à rebours « dans NN h NN »" 'printf %s "$REQ_BODY" | grep -Eq "dans [0-9]+ h [0-9]{2}"'
check "écran : bouton Annuler (garder séparés)"   'printf %s "$REQ_BODY" | grep -q "Annuler (garder séparés)"'
check "écran : paire absente des suggestions (liste noire)" 'printf %s "$REQ_BODY" | php -r '\''$b=stream_get_contents(STDIN); $s=strpos($b,"suggestions-rapprochement"); $f=strpos($b,"fusions-auto"); exit($s!==false && $f!==false && strpos(substr($b,$s,$f-$s),"Fanta Orange")===false?0:1);'\'''
QID=$($H quarantaine | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["id"];')

# ── (c) Veto → jamais fusionnée, listée annulée ──────────────────────────────
echo "── (c) veto sur la paire en quarantaine ──"
req POST /admin/compta/inventaire/t1/auto/veto "{\"id\": \"$QID\"}"
check "POST veto répond 302"                      '[ "$REQ_CODE" = 302 ]'
check "quarantaine : statut annule"               '$H quarantaine | php -r '\''exit(json_decode(stream_get_contents(STDIN),true)[0]["statut"]==="annule"?0:1);'\'''
check "feedback négatif : ignorer/auto/veto_quarantaine" '[ "$(audit_field t1.pair_feedback details.decision ignorer)" = 1 ] && [ "$(audit_field t1.pair_feedback details.origine auto)" = 1 ] && [ "$(audit_field t1.pair_feedback details.motif veto_quarantaine)" = 1 ]'
check "maturité simulée (+49 h) : 0 appliquée"     '$H passage "$(php -r '\''echo (new DateTimeImmutable("now", new DateTimeZone("Europe/Paris")))->modify("+49 hours")->format("Y-m-d\TH:i:sP");'\'')" | php -r '\''exit(json_decode(stream_get_contents(STDIN),true)["appliquees"]===0?0:1);'\'''
check "jamais fusionnée : vente intacte"          '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta'"'"'")" = 1 ] && [ "$(qcount "SELECT id FROM audit_logs WHERE action = '"'"'inventory.merge'"'"'")" = 0 ]'
req GET /admin/compta/inventaire
check "écran : section Annulées + paire barrée"   'printf %s "$REQ_BODY" | grep -q "Annulées (jamais fusionnées, traces conservées)" && printf %s "$REQ_BODY" | grep -q "<s>Fanta Orange</s>"'

# ── (d) Horloge avancée 48h01 → fusion AU CHARGEMENT + restauration ──────────
echo "── (d) maturité 48 h 01 → fusion au chargement + restauration ──"
$H seed-base >/dev/null
$H seed-paire 'Fanta' 'Fanta Orange' 3 4 >/dev/null
$H seed-identite 'Fanta Orange' >/dev/null
$H candidats 'Fanta' 'Fanta Orange' 0.9228 >/dev/null
$H passage >/dev/null
QID=$($H quarantaine | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["id"];')
$H reculer "$QID" 49 >/dev/null   # created_at −49 h ≡ horloge avancée de 48h01+
req GET /admin/compta/inventaire
check "GET inventaire répond 200"                 '[ "$REQ_CODE" = 200 ]'
check "fusion APPLIQUÉE au chargement : stock 7"  '[ "$(sql_scalar "SELECT stock FROM product_stocks WHERE product_key = '"'"'Fanta'"'"'")" = 7 ]'
check "cible unique : « Fanta Orange » vidée"     '[ "$(qcount "SELECT product_key FROM product_stocks WHERE product_key = '"'"'Fanta Orange'"'"'")" = 0 ]'
check "ventes repointées sur Fanta (1)"           '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta'"'"'")" = 1 ]'
check "audit inventory.merge niveau=2 origine=auto" '[ "$(audit_field inventory.merge details.niveau 2)" = 1 ] && [ "$(audit_field inventory.merge details.origine auto)" = 1 ]'
check "audit : photo pré-fusion présente"         '[ "$(audit_field inventory.merge details.archive.source.stock 4)" = 1 ]'
check "quarantaine : statut applique + audit lié" '$H quarantaine | php -r '\''$e=json_decode(stream_get_contents(STDIN),true)[0]; exit($e["statut"]==="applique" && !empty($e["audit_id"])?0:1);'\'''
req GET /admin/compta/inventaire
check "écran : paire dans appliquées + Restaurer" 'printf %s "$REQ_BODY" | grep -q "Fusions automatiques appliquées" && printf %s "$REQ_BODY" | grep -q "Restaurer la clé" && printf %s "$REQ_BODY" | grep -q "Fanta Orange"'
AUDIT_ID=$(sql_scalar "SELECT id FROM audit_logs WHERE action = 'inventory.merge' LIMIT 1")
check "audit_id extrait"                          '[ -n "$AUDIT_ID" ]'
req POST /admin/compta/inventaire/t1/auto/restaurer "{\"audit_id\": \"$AUDIT_ID\"}"
check "POST restaurer répond 302"                 '[ "$REQ_CODE" = 302 ]'
check "restauration : stock source 4 recréé"      '[ "$(sql_scalar "SELECT stock FROM product_stocks WHERE product_key = '"'"'Fanta Orange'"'"'")" = 4 ]'
check "restauration : cible rééquilibrée à 3 (total préservé)" '[ "$(sql_scalar "SELECT stock FROM product_stocks WHERE product_key = '"'"'Fanta'"'"'")" = 3 ]'
check "restauration : FICHE recréée (2,50 €)"     '[ "$(sql_scalar "SELECT price FROM products WHERE name = '"'"'Fanta Orange'"'"'")" = "2.50" ]'
check "restauration : ALIAS « self » recréé"      '[ "$(sql_scalar "SELECT product_key FROM product_aliases WHERE raw_description = '"'"'Fanta Orange'"'"'")" = "Fanta Orange" ]'
check "restauration : alias distinct non volé (reste sur cible)" '[ "$(sql_scalar "SELECT product_key FROM product_aliases WHERE raw_description = '"'"'Fanta Orange Zero'"'"'")" = "Fanta" ]'
check "historique des ventes reste sur la cible"  '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta'"'"'")" = 1 ] && [ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta Orange'"'"'")" = 0 ]'
check "audit inventory.restore (entity_id source)" '[ "$(audit_field inventory.restore entity_id "Fanta Orange")" = 1 ]'

# ── (e) Coûts divergents > 15 % → jamais en quarantaine, suggestion ──────────
echo "── (e) coûts divergents (0,50 vs 1,00) → niveau 3 humain ──"
$H seed-base >/dev/null
$H seed-paire 'Fanta' 'Fanta Orange' 3 4 >/dev/null
$H seed-couts 'Fanta' 'Fanta Orange' 0.50 1.00 >/dev/null
$H candidats 'Fanta' 'Fanta Orange' 0.9228 >/dev/null
DECL=$($H passage)
check "passage : 0 déclarée (garde coûts)"        'printf %s "$DECL" | php -r '\''$r=json_decode(stream_get_contents(STDIN),true); exit(($r["declarees"]??-1)===0 && ($r["appliquees"]??-1)===0 ? 0:1);'\'''
check "quarantaine VIDE"                          '$H quarantaine | php -r '\''exit(json_decode(stream_get_contents(STDIN),true)===[]?0:1);'\'''
check "aucune écriture produit (vente intacte)"   '[ "$(qcount "SELECT id FROM sales WHERE product_key = '"'"'Fanta'"'"'")" = 1 ]'
check "0 audit inventory.merge"                   '[ "$(qcount "SELECT id FROM audit_logs WHERE action = '"'"'inventory.merge'"'"'")" = 0 ]'
check "RESTE en suggestion (modèle niveau 3)"     '$H suggestions | php -r '\''$s=json_decode(stream_get_contents(STDIN),true); exit(!empty($s) && $s[0]["source"]==="Fanta Orange" && $s[0]["target"]==="Fanta"?0:1);'\'''
req GET /admin/compta/inventaire
check "GET inventaire répond 200"                 '[ "$REQ_CODE" = 200 ]'
check "écran : AUCUNE section quarantaine"        '! printf %s "$REQ_BODY" | grep -q "En quarantaine (48 h"'

# ── (p) Performance : chargement avec 50 paires en quarantaine ───────────────
echo "── (p) perf chargement inventory() × 50 paires en quarantaine ──"
$H seed-base >/dev/null
$H seed-perf 40 >/dev/null
mesure() { local t0 t1; t0=$(date +%s%N); req GET /admin/compta/inventaire >/dev/null; t1=$(date +%s%N); echo $(( (t1 - t0) / 1000000 )); }
AVANT=""
for i in 1 2 3 4 5; do AVANT="$AVANT $(mesure)"; done
$H quarantaine-perf 50 >/dev/null
check "quarantaine de test : 50 entrées en_attente" '$H quarantaine | php -r '\''exit(count(json_decode(stream_get_contents(STDIN),true))===50?0:1);'\'''
APRES=""
for i in 1 2 3 4 5; do APRES="$APRES $(mesure)"; done
PERF_EXIT=0
PERF=$(printf 'avant=%s\napres=%s\n' "$AVANT" "$APRES" | php -r '
$ln=[]; foreach (file("php://stdin") as $l) { if (preg_match("/^(\w+)=(.*)/", trim($l), $m)) $ln[$m[1]]=array_map("intval", preg_split("/\s+/", trim($m[2]))); }
sort($ln["avant"]); sort($ln["apres"]);
$ma=$ln["avant"][2]; $mp=$ln["apres"][2]; // médiane (5 mesures)
printf("mediane_avant=%dms mediane_apres=%dms delta=%+dms seuil=+300ms %s\n", $ma, $mp, $mp-$ma, ($mp-$ma)<=300?"OK":"DEPASSE");
exit(($mp-$ma)<=300?0:1);') || PERF_EXIT=1
echo "$PERF" | sed 's/^/       /'
check "écart médian ≤ +300 ms avec 50 paires" '[ "$PERF_EXIT" = 0 ]'

# ── Intégrité finale ─────────────────────────────────────────────────────────
echo "── intégrité ──"
check "vraie quarantaine repo JAMAIS créée"       '! test -e data/eval_t1/t15/quarantaine.json'
SUM=$(sha256sum -c data/eval_t1/SHA256SUMS 2>/dev/null | grep -c ": OK")
check "jeu figé intact (SHA256 12/12)"            '[ "$SUM" = 12 ]'

echo "══════════════════════════════════════════"
echo "RÉSULTAT PARCOURS : $OK/$TOTAL checks OK"
[ "$FAILED" = 0 ] && echo "PARCOURS : OK" || echo "PARCOURS : ÉCHEC"
exit $FAILED

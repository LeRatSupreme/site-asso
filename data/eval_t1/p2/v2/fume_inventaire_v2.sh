#!/usr/bin/env bash
# Fumigène écran Inventaire — vague 2 P2-ter (brief V2-D dev-tests, 28/10/2026).
#
# BUT : prouver qu'après le remplacement du modèle prod par v2 (cache
# data/eval_t1/.cache/model-int8 + candidats_fusion.json régénéré), l'écran
# admin Inventaire se rend TOUJOURS correctement en conditions réelles :
# routeur réel (tests/Integration/runner.php), session SUPERADMIN, base de
# test aeic_test (port 3307, méthode sed-port), livrable RÉEL
# data/eval_t1/candidats_fusion.json lu tel quel, quarantaine redirigée vers
# tests/cache/t15/ (jamais la vraie).
#
# Seed minimal : la paire « Minute Maid » / « Minute Maid Pomme » du livrable
# (score v2 0.8526 → badge « ressemblance 85 % ») rendue vivante par une
# vente de chaque côté. Marqueurs grep + garde anti-production incluse.
#
# Relançable depuis la racine du dépôt : bash data/eval_t1/p2/v2/fume_inventaire_v2.sh
set -euo pipefail
cd "$(dirname "$0")/../../../.."   # → racine du dépôt AEIC

SORTIE="$(mktemp /tmp/fume_inventaire_v2.XXXXXX.json)"
trap 'rm -f "$SORTIE"' EXIT

echo "== 1/3 Garde anti-production + seed aeic_test (user + paire vivante du livrable v2) =="
php <<'PHP'
<?php
declare(strict_types=1);
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3307;dbname=aeic_test;charset=utf8mb4',
    'aeic',
    'aeic',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($base !== 'aeic_test') {
    fwrite(STDERR, "REFUS : la base n'est pas aeic_test ($base)\n");
    exit(1);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'users', 'audit_logs', 'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
    'product_costs', 'inventory_counts', 'product_stocks', 'promotions',
    'inventory_tablet_lines', 'product_discontinued', 'product_infinite',
    'product_zero_since', 'product_packs', 'shopping_checks',
    'cafeteria_order_items', 'cafeteria_orders', 'products',
] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
try {
    $pdo->exec('TRUNCATE TABLE scan_labels');
} catch (Throwable) {
}
$pdo->prepare(
    'INSERT INTO users (id, prenom, nom, email, password, role, is_active, email_verified_at)
     VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
)->execute(['u_fume_v2', 'Fume', 'V2', 'fume-v2@exemple.fr', password_hash('Password123456', PASSWORD_BCRYPT), 'SUPERADMIN', 1]);
$vente = $pdo->prepare(
    "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
     VALUES (?,?,?,?,?,?,?,?)"
);
$vente->execute(['s_fume_1', 'FUMEV2A', '2026-10-27 10:00:00', 'CARTE', 1, 'Minute Maid', 'Minute Maid', 2.00]);
$vente->execute(['s_fume_2', 'FUMEV2B', '2026-10-27 10:05:00', 'CARTE', 1, 'Minute Maid Pomme', 'Minute Maid Pomme', 1.80]);
$stock = $pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)');
$stock->execute(['Minute Maid', 6]);
$stock->execute(['Minute Maid Pomme', 4]);
@unlink(dirname(__DIR__, 3) . '/tests/cache/t15/quarantaine.json');
@unlink(dirname(__DIR__, 3) . '/tests/cache/t15/quarantaine.json.lock');
echo "OK base aeic_test seedée : u_fume_v2 SUPERADMIN + paire Minute Maid / Minute Maid Pomme vivante\n";
PHP

echo "== 2/3 Rendu RÉEL GET /admin/compta/inventaire (runner, session SUPERADMIN) =="
DB_HOST=127.0.0.1:3307 DB_NAME=aeic_test DB_USER=aeic DB_PASS=aeic \
AEIC_TEST_METHOD=GET AEIC_TEST_PATH=/admin/compta/inventaire AEIC_TEST_FORCE_USER=u_fume_v2 \
php tests/Integration/runner.php > "$SORTIE"
# Le runner préfixe stdout du marqueur --AEIC_TEST_RESPONSE-- : on n'en garde que le JSON qui suit.
php -r '
$raw = (string) file_get_contents($argv[1]);
$pos = strrpos($raw, "--AEIC_TEST_RESPONSE--");
$json = $pos === false ? $raw : substr($raw, $pos + strlen("--AEIC_TEST_RESPONSE--"));
$r = json_decode(trim($json), true);
if (!is_array($r)) { fwrite(STDERR, "réponse illisible\n"); exit(1); }
echo "code HTTP = ", (int)($r["code"] ?? 0), "\n";
file_put_contents($argv[1] . ".body", (string)($r["body"] ?? ""));
' "$SORTIE"
CORPS="$SORTIE.body"

echo "== 3/3 Marqueurs du rendu =="
ok=0; ko=0
verifier() { # $1 = description, $2 = minimum attendu, $3 = pattern, $4 = [regex]
  local n
  if [ "${4:-}" = "regex" ]; then n=$(grep -Ec "$3" "$CORPS" || true); else n=$(grep -Fc "$3" "$CORPS" || true); fi
  if [ "$n" -ge "$2" ]; then echo "OK   ($n≥$2) $1"; ok=$((ok+1)); else echo "KO   ($n<$2) $1"; ko=$((ko+1)); fi
}
verifier_absent() { # $1 = description, $2 = motif interdit
  local n; n=$(grep -Fc "$2" "$CORPS" || true)
  if [ "$n" = "0" ]; then echo "OK   (0) $1"; ok=$((ok+1)); else echo "KO   ($n occurrences) $1"; ko=$((ko+1)); fi
}
CODE=$(php -r '
$raw = (string) file_get_contents($argv[1]);
$pos = strrpos($raw, "--AEIC_TEST_RESPONSE--");
$json = $pos === false ? $raw : substr($raw, $pos + strlen("--AEIC_TEST_RESPONSE--"));
$r = json_decode(trim($json), true);
echo is_array($r) ? (int)($r["code"] ?? 0) : 0;
' "$SORTIE")
if [ "$CODE" = "200" ]; then echo "OK   ($CODE) code HTTP 200"; ok=$((ok+1)); else echo "KO   ($CODE≠200) code HTTP 200"; ko=$((ko+1)); fi
verifier "carte « Suggestions de rapprochement »"              1 "Suggestions de rapprochement"
verifier "ancre id=suggestions-rapprochement"                  1 'id="suggestions-rapprochement"'
verifier "carte id=t1-suggestions-card"                        1 'id="t1-suggestions-card"'
verifier "badge « ressemblance NN % » (regex, score non figé)" 1 'ressemblance [0-9]{2} ?%' regex
verifier "paire suggérée : Minute Maid Pomme"                  1 "Minute Maid Pomme"
verifier "paire suggérée : Minute Maid"                        1 "Minute Maid"
verifier "formulaires de décision /t1/decide (Fusionner+Ignorer)" 2 '/admin/compta/inventaire/t1/decide'
verifier "CSRF présent sur la page"                            1 'name="_csrf"'
verifier "carte « Fusionner des clés » présente"               1 "Fusionner des clés"
verifier_absent "interdit glossaire : « intelligence artificielle »" "intelligence artificielle"
verifier "score v2 du livrable affiché (85 %)"                 1 "ressemblance 85 %"

echo
if [ "$ko" = "0" ]; then echo "FUMIGÈNE OK : $ok/$ok marqueurs présents, 0 échec"; else echo "FUMIGÈNE KO : $ko échec(s) sur $((ok+ko))"; exit 1; fi

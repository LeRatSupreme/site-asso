<?php

declare(strict_types=1);

/**
 * Parcours E2E T1.5 — helpers CLI (brief T15-C, dev-tests).
 *
 * Base : aeic_test EXCLUSIVEMENT (port 3307, méthode officielle sed-port).
 * Quarantaine utilisée : tests/cache/t15/quarantaine.json (redirection
 * AEIC_T15_QUARANTAINE, identique au bootstrap/runner des tests) — la
 * vraie data/eval_t1/t15/quarantaine.json (état runtime du VPS) n'est
 * JAMAIS lue ni écrite ici.
 *
 * Orchestré par parcours_e2e.sh (rejouable) :
 *   php data/eval_t1/t15/parcours_helper.php <sous-commande> [args…]
 */

$racine = dirname(__DIR__, 3);

define('AEIC_ROOT', $racine);
define('AEIC_VIEWS', $racine . '/views');
define('AEIC_PUBLIC', $racine . '/public');
define('APP_URL', 'https://example.test');
define('APP_DEBUG', false);
define('APP_TESTING', true);
define('AEIC_T15_QUARANTAINE', $racine . '/tests/cache/t15/quarantaine.json');

require $racine . '/vendor/autoload.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1:3307', getenv('DB_NAME') ?: 'aeic_test'),
    getenv('DB_USER') ?: 'aeic',
    getenv('DB_PASS') ?: 'aeic',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
$pdo->exec("SET time_zone = '+00:00'");

if (!function_exists('db')) {
    function db(): PDO
    {
        global $pdo;

        return $pdo;
    }
}
if (!function_exists('env')) {
    function env(string $key, string $default = ''): string
    {
        $v = getenv($key);

        return ($v === false || $v === '') ? $default : $v;
    }
}

use App\Core\Compta\T1\T1AutoFusion;
use App\Core\Compta\T1\T1Quarantaine;
use App\Core\Compta\T1\T1Suggestions;
use App\Models\Model;
use App\Models\ProductKeyMerge;

Model::setTestPdo($pdo);

/** Chemin de la quarantaine de TEST (jamais la vraie). */
function qTest(): string
{
    return AEIC_T15_QUARANTAINE;
}

/** Candidats dédiés du parcours (jamais le jeu figé). */
function cTmp(): string
{
    return sys_get_temp_dir() . '/aeic_t15_parcours_candidats.json';
}

function outJson(mixed $v): void
{
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    // ── Seeds ──────────────────────────────────────────────────────────
    case 'seed-base':
        // Garde anti-production : le parcours ne peut viser QUE aeic_test
        // (même esprit que tests/support/db_guard.php).
        $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($base !== 'aeic_test') {
            fwrite(STDERR, "REFUS : la base n'est pas aeic_test ($base)\n");
            exit(1);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'users', 'audit_logs',
            'sales', 'import_batches', 'purchases', 'losses', 'product_aliases',
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
        )->execute(['u_parcours', 'Parcours', 'T15', 't15-parcours@exemple.fr', password_hash('Password123456', PASSWORD_BCRYPT), 'SUPERADMIN', 1]);
        @unlink(qTest());
        @unlink(qTest() . '.lock');
        echo "OK base aeic_test réinitialisée + user u_parcours (SUPERADMIN) + quarantaine de test vierge\n";
        break;

    case 'seed-casse':
        // [$stockCle, $ventesCle, $stockQty]
        [$stockCle, $ventesCle, $stockQty] = [$argv[2], $argv[3], (int) $argv[4]];
        $pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)')->execute([$stockCle, $stockQty]);
        $sale = $pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        );
        $sale->execute(['s_pc_1', 'T15C1', $ventesCle, $ventesCle]);
        $sale->execute(['s_pc_2', 'T15C2', $ventesCle, $ventesCle]);
        echo "OK casse semée : stock='$stockCle' ($stockQty) / ventes='$ventesCle' (2 lignes)\n";
        break;

    case 'seed-paire':
        // [$base, $variante, $stockBase, $stockVar]
        [$base, $variante, $stockBase, $stockVar] = [$argv[2], $argv[3], (int) $argv[4], (int) $argv[5]];
        $pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES ('s_pc_base', 'T15B', '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        )->execute([$base, $base]);
        $ins = $pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,?)');
        $ins->execute([$base, $stockBase]);
        $ins->execute([$variante, $stockVar]);
        echo "OK paire semée : base='$base' (vente + stock $stockBase) / variante='$variante' (stock $stockVar)\n";
        break;

    case 'seed-identite':
        // [$cle] : fiche cafétéria + alias « self » (absorbé/supprimé à la
        // fusion → recréé par la restauration) + alias distinct (repointé
        // vers la cible, non volé par la restauration — comportement codé).
        $cle = $argv[2];
        $pdo->prepare(
            "INSERT INTO products (id, name, price, stock, is_available, is_active)
             VALUES (?, ?, 2.50, 10, 1, 1)"
        )->execute(['prod_' . md5($cle), $cle]);
        $alias = $pdo->prepare(
            "INSERT INTO product_aliases (id, raw_description, product_key, kind, created_at)
             VALUES (?, ?, ?, 'label', NOW())"
        );
        $alias->execute(['al_pc_self_' . md5($cle), $cle, $cle]);
        $alias->execute(['al_pc_dist_' . md5($cle), $cle . ' Zero', $cle]);
        echo "OK identité semée sur '$cle' : fiche (2,50 €) + alias self + alias '$cle Zero'\n";
        break;

    case 'seed-couts':
        // [$a, $b, $prixA, $prixB]
        $lot = $pdo->prepare(
            "INSERT INTO product_costs (id, product_key, cost_price, valid_from, created_at)
             VALUES (?, ?, ?, '2026-10-01', NOW())"
        );
        $lot->execute(['cost_pc_a', $argv[2], (float) $argv[4]]);
        $lot->execute(['cost_pc_b', $argv[3], (float) $argv[5]]);
        echo "OK coûts semés : $argv[2]=$argv[4] / $argv[3]=$argv[5]\n";
        break;

    case 'seed-perf':
        // [$n] produits sans doublon (états stables entre mesures ;
        // suppose seed-base déjà passé : INSERT uniquement)
        $n = (int) ($argv[2] ?? 40);
        $ins = $pdo->prepare('INSERT INTO product_stocks (product_key, stock) VALUES (?,3)');
        $sale = $pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, 'T15PF', '2026-10-20 10:00:00', 'CARTE', 1, ?, ?, 2.00)"
        );
        for ($i = 0; $i < $n; $i++) {
            $cle = sprintf('Perf%03d', $i);
            $ins->execute([$cle]);
            $sale->execute(['s_pf_' . $i, $cle, $cle]);
        }
        @unlink(qTest());
        @unlink(qTest() . '.lock');
        echo "OK $n produits perf semés (aucun doublon de casse) + quarantaine vierge\n";
        break;

    case 'candidats':
        // [$a, $b, $score] — candidats DÉDIÉS (le jeu figé n'est pas touché)
        file_put_contents(cTmp(), (string) json_encode([
            'candidats' => [['a' => $argv[2], 'b' => $argv[3], 'score' => (float) $argv[4]]],
        ], JSON_UNESCAPED_UNICODE));
        echo 'OK candidats dédiés : ' . cTmp() . " → $argv[2] / $argv[3] @ $argv[4]\n";
        break;

    // ── Moteur / quarantaine / vérifications ───────────────────────────
    case 'passage':
        // [$maintenantIso?] — horloge injectable du moteur pur
        $res = T1AutoFusion::passage(ProductKeyMerge::keyStats(), qTest(), cTmp(), $argv[2] ?? null);
        outJson($res);
        break;

    case 'suggestions':
        // Même point d'assemblage que la carte suggestions de l'écran
        $noires = array_merge(
            \App\Models\AuditLog::entityIdsForAction(T1Suggestions::ACTION_FEEDBACK),
            array_keys((new T1Quarantaine(qTest()))->idsStatuts())
        );
        $sug = T1Suggestions::pourInventaire(cTmp(), AEIC_ROOT . '/data/eval_t1/residuels_a_qualifier.csv', ProductKeyMerge::keyStats(), $noires);
        outJson(array_map(static fn (array $p): array => ['source' => $p['source'], 'target' => $p['target'], 'score' => $p['score']], $sug['modele']));
        break;

    case 'quarantaine':
        outJson((new T1Quarantaine(qTest()))->entrees());
        break;

    case 'reculer':
        // [$id, $heures] : recule created_at ≡ avance l'horloge de $heures h
        $q = new T1Quarantaine(qTest());
        $e = $q->trouver($argv[2]);
        if ($e === null) {
            fwrite(STDERR, "entrée introuvable : $argv[2]\n");
            exit(1);
        }
        $recule = (new DateTimeImmutable((string) $e['created_at']))->modify("-{$argv[3]} hours")->format('Y-m-d\TH:i:sP');
        $q->avecVerrou(static function (array &$data) use ($argv, $recule): void {
            foreach ($data['paires'] as $i => $p) {
                if (($p['id'] ?? '') === $argv[2]) {
                    $data['paires'][$i]['created_at'] = $recule;
                }
            }
        });
        echo "OK created_at reculé de {$argv[3]} h → $recule\n";
        break;

    case 'quarantaine-perf':
        // [$n] entrées en_attente fictives (mesure de perf du chargement)
        $n = (int) ($argv[2] ?? 50);
        $paires = [];
        for ($i = 0; $i < $n; $i++) {
            $paires[] = [
                'source' => sprintf('PerfQ%03d', $i),
                'target' => sprintf('PerfQ%03d Bis', $i),
                'score'  => 0.92,
                'motif'  => T1AutoFusion::MOTIF_PREFIXE,
            ];
        }
        $declarees = (new T1Quarantaine(qTest()))->declarer($paires, T1AutoFusion::maintenantIso());
        echo "OK $declarees paires en quarantaine de test (en_attente, non mûres)\n";
        break;

    case 'q':
        // [$sql] — SELECT libre (vérifications)
        $rows = $pdo->query($argv[2])->fetchAll();
        outJson($rows);
        break;

    case 'audit':
        // [$action] — lignes d'audit (id, entity_id, details JSON décodé)
        $st = $pdo->prepare('SELECT id, user_id, action, entity_type, entity_id, details, created_at FROM audit_logs WHERE action = ? ORDER BY created_at, id');
        $st->execute([$argv[2]]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['details'] = json_decode((string) $r['details'], true);
        }
        outJson($rows);
        break;

    case 'horaire':
        outJson(['maintenantParis' => T1AutoFusion::maintenantIso(), 'fuseau' => T1AutoFusion::FUSEAU]);
        break;

    default:
        fwrite(STDERR, "sous-commande inconnue : $cmd\n");
        exit(1);
}

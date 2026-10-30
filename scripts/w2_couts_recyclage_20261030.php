<?php

declare(strict_types=1);

/**
 * AEIC — Mission W2 « VAGUE COÛTS » (one-off, 30/10/2026).
 * Arbitrage orchestrateur 01a186c3 du 30/10 08:26 — dossier M3 coûts.
 *
 * Domaine CRITIQUE zéro perte / zéro double compte. Chaque opération :
 *  - vérifie l'état RÉEL attendu AVANT d'écrire (refus sans effet de bord) ;
 *  - passe EXCLUSIVEMENT par les modèles centraux (Purchase, ProductCost,
 *    ProductStock) en répliquant les cascades des contrôleurs
 *    (AdminStockController::deletePurchaseLines / PurchaseSaver::createOne
 *    SANS ProductAutoSync — interdiction W3 de créer des fiches) ;
 *  - conserve les montants à l'identique (HT et TTC copiés exactement de
 *    la ligne d'origine, aucun re-arrondi : « le système ne fait que
 *    diviser » la quantité) ;
 *  - snapshot JSONL avant/après par opération dans logs/.
 *
 * Opérations (préconditions verrouillées dans le dossier) :
 *  OP1 gaufre       — achat Auchan purchase_ed5b4f3ab113d30ea955
 *                     (07/10, qty 1, 5,678 HT / 5,990 TTC, hors stock)
 *                     recyclé ÷10 → lot 0,599 €/u (07/10, qty 10) ;
 *  OP2 Gaufre choco — lot manuel 0,389 (08/10, sans purchase_id) remplacé
 *                     par le recyclage ÷10 de l'achat réel Auchan
 *                     purchase_e53d6f004a55db444bce (12/10, 6,626 HT /
 *                     6,990 TTC) → lot 0,699 €/u (12/10, qty 10) ;
 *  OP3 Nesquik      — Option A « au verre » : achat METRO facture 023923
 *                     purchase_b0549f6cdadc8cc06606 (12/10, qty 1 sachet,
 *                     5,700 HT / 6,014 TTC, EN stock) recyclé qty=80
 *                     verres même TTC → lot 0,075 €/verre (12/10).
 *                     La fiche « Verre Nesquik » sera créée en W3 (dev-merge) ;
 *  OP4 périodes     — Oasis Tropical : chevauchement 0,580 (07/09→15/10)
 *                     vs 0,522 (19/09→NULL) refermé proprement (normalizePeriods
 *                     → 0,580 : 07/09→18/09) ; 2 lots dégénérés
 *                     valid_from>valid_to corrigés par close() (bornes
 *                     minimales valid_to=valid_from) : Oasis Tropical 0,140
 *                     (29/06→28/06 → 29/06) et Redbull zero 0,928
 *                     (19/09→18/09 → 19/09).
 *
 * INTERDITS respectés : aucune fusion de clés, aucune création/suppression
 * de fiche produits (ProductAutoSync volontairement NON appelé — la clé
 * « Gaufre choco » n'a pas de fiche et PurchaseSaver::save en créerait
 * une), aucun prix modifié, montants TTC (et HT) des tickets identiques.
 *
 * DRY-RUN par défaut (aucune écriture). --execute applique TOUT en une
 * seule transaction : tout ou rien. Rollback global = restauration du
 * backup préalable (backups/aeic_db_<stamp>.sql.gz, vérifié au démarrage).
 *
 * Usage :
 *   php scripts/w2_couts_recyclage_20261030.php            (dry-run)
 *   php scripts/w2_couts_recyclage_20261030.php --execute  (réel)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\CostLotStock;
use App\Core\Compta\StockPublic;
use App\Models\AuditLog;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\Purchase;

// ── Cibles exactes (one-off : rien d'autre ne doit matcher) ─────────────

const GAUFRE_PURCHASE   = 'purchase_ed5b4f3ab113d30ea955';
const GAUFRE_LOT        = 'cost_3c7ff3dc4abba10e7e7b';
const GAUFRE_PREV_LOT   = 'cost_f9bf709b1150ea1cb85c';
const CHOCO_PURCHASE    = 'purchase_e53d6f004a55db444bce';
const CHOCO_LOT_MANUEL  = 'cost_4e5c8b559301224f443f';
const NESQUIK_PURCHASE  = 'purchase_b0549f6cdadc8cc06606';
const NESQUIK_LOT       = 'cost_a66ec4027fc01ee83486';
const OASIS_LOT_0580    = 'cost_ed3f0d951616612fcd4c';
const OASIS_LOT_0522    = 'cost_1036a5f71a6ed48d31c2';
const OASIS_LOT_DEGEN   = 'cost_bf17c3fa0466533b0cc9';
const REDBULL_DEGEN     = 'cost_9c57b702ee08842a5b2a';
const REDBULL_OPEN      = 'cost_d8f64e042f3666654862';

const KEYS = ['gaufre', 'Gaufre choco', 'Nesquik', 'Oasis Tropical', 'Redbull zero'];

const TOL = 0.0005;

$execute = in_array('--execute', $_SERVER['argv'] ?? [], true);

// ── Garde : base de production aeic uniquement + backup du jour ────────

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR de connexion base : ' . $e->getMessage() . "\n");
    exit(1);
}

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'aeic') {
    fwrite(STDERR, "REFUS : base = « {$dbName} », cette mission one-off cible « aeic » (production).\n");
    exit(1);
}

$backups = glob(__DIR__ . '/../backups/aeic_db_*.sql.gz') ?: [];
$latestBackup = '';
$latestMtime = 0;
foreach ($backups as $b) {
    $m = (int) filemtime($b);
    if ($m > $latestMtime) {
        $latestMtime = $m;
        $latestBackup = basename($b);
    }
}
$backupOk = $latestBackup !== '' && strpos($latestBackup, date('Ymd')) !== false;
if (!$backupOk) {
    fwrite(STDERR, "REFUS : aucun backup du jour trouvé (dernier : " . ($latestBackup ?: 'aucun') . ").\n");
    fwrite(STDERR, "        Lancer d'abord scripts/backup.sh (précondition verrouillée).\n");
    exit(1);
}

// ── Aides ───────────────────────────────────────────────────────────────

/**
 * Snapshot chiffré d'une clé : ventes (count/qty/CA inchangés par définition
 * — jamais touchés), COGS as-of (règle ProductCost::costAt, comme
 * AdminLossController/InventoryTablet), pertes valorisées, lots, achat(s).
 *
 * @return array<string,mixed>
 */
function keyState(string $key): array
{
    $pdo = db();

    $st = $pdo->prepare(
        'SELECT sold_at, quantity, price_ttc, is_custom_amount FROM sales WHERE product_key = ?'
    );
    $st->execute([$key]);
    $n = 0;
    $qty = 0;
    $ca = 0.0;
    $cogs = 0.0;
    $profit = 0.0;
    foreach ($st->fetchAll() as $s) {
        if (!empty($s['is_custom_amount'])) {
            continue; // montant personnalisé : hors bénéfice (ComptaCalc::lineProfit)
        }
        $q = max(1, (int) $s['quantity']);
        $day = substr((string) $s['sold_at'], 0, 10);
        $cost = ProductCost::costAt($key, $day);
        $c = $cost === null ? 0.0 : (float) $cost;
        $n++;
        $qty += $q;
        $ca += (float) $s['price_ttc'] * $q;
        $cogs += $c * $q;
        $profit += ((float) $s['price_ttc']) - ($c * $q);
    }

    $st = $pdo->prepare('SELECT lost_at, quantity FROM losses WHERE product_key = ?');
    $st->execute([$key]);
    $lossN = 0;
    $lossQty = 0;
    $lossCogs = 0.0;
    foreach ($st->fetchAll() as $l) {
        $q = (int) $l['quantity'];
        $day = substr((string) $l['lost_at'], 0, 10);
        $cost = ProductCost::costAt($key, $day);
        $lossN++;
        $lossQty += $q;
        $lossCogs += ($cost === null ? 0.0 : (float) $cost) * $q;
    }

    $lots = [];
    foreach (ProductCost::forProduct($key) as $lot) {
        $lots[] = [
            'id'            => (string) $lot['id'],
            'cost_price'    => (string) $lot['cost_price'],
            'qty_purchased' => $lot['qty_purchased'] === null ? null : (int) $lot['qty_purchased'],
            'valid_from'    => substr((string) $lot['valid_from'], 0, 10),
            'valid_to'      => $lot['valid_to'] === null ? null : substr((string) $lot['valid_to'], 0, 10),
            'purchase_id'   => $lot['purchase_id'] === null ? null : (string) $lot['purchase_id'],
        ];
    }
    $openLots = array_values(array_filter($lots, static fn (array $l): bool => $l['valid_to'] === null));

    $purchases = [];
    $st = $pdo->prepare(
        'SELECT id, purchased_at, quantity, unit_cost, vat_rate, total_ht, total_ttc, no_stock,
                supplier, invoice_number, created_by, receipt_path, notes
           FROM purchases WHERE product_key = ? ORDER BY purchased_at, created_at'
    );
    $st->execute([$key]);
    foreach ($st->fetchAll() as $p) {
        $purchases[] = [
            'id'         => (string) $p['id'],
            'date'       => substr((string) $p['purchased_at'], 0, 10),
            'qty'        => (int) $p['quantity'],
            'unit_cost'  => (string) $p['unit_cost'],
            'vat_rate'   => $p['vat_rate'] === null ? null : (string) $p['vat_rate'],
            'total_ht'   => (string) $p['total_ht'],
            'total_ttc'  => (string) $p['total_ttc'],
            'no_stock'   => (int) $p['no_stock'] === 1,
            'supplier'   => $p['supplier'] === null ? null : (string) $p['supplier'],
            'invoice'    => $p['invoice_number'] === null ? null : (string) $p['invoice_number'],
            'created_by' => $p['created_by'] === null ? null : (string) $p['created_by'],
        ];
    }

    return [
        'key'       => $key,
        'sales'     => ['n' => $n, 'qty' => $qty, 'ca' => round($ca, 2)],
        'cogs'      => round($cogs, 4),
        'profit'    => round($profit, 4),
        'losses'    => ['n' => $lossN, 'qty' => $lossQty, 'cogs' => round($lossCogs, 4)],
        'lots'      => $lots,
        'openLots'  => count($openLots),
        'stock'     => ProductStock::get($key),
        'purchases' => $purchases,
    ];
}

function allState(): array
{
    $out = [];
    foreach (KEYS as $k) {
        $out[$k] = keyState($k);
    }

    return $out;
}

/** Écrit un snapshot JSONL dans logs/ (une ligne par document JSON). */
function writeSnapshot(string $tag, array $state): string
{
    $file = __DIR__ . '/../logs/w2_couts_20261030_' . $tag . '.jsonl';
    $fh = fopen($file, 'wb');
    foreach ($state as $key => $doc) {
        fwrite($fh, json_encode([$tag, $key, $doc], JSON_UNESCAPED_UNICODE) . "\n");
    }
    fclose($fh);

    return $file;
}

function fmt(float $v): string
{
    return number_format($v, 3, ',', ' ');
}

/**
 * @param list<array<string,mixed>> $lots
 */
function lotLine(array $lot): string
{
    $to = $lot['valid_to'] === null ? 'ouvert   ' : $lot['valid_to'];
    $qty = $lot['qty_purchased'] === null ? 'non suivi' : sprintf('%du', $lot['qty_purchased']);

    return sprintf(
        '    %s  %s €/u  %s → %s  qty=%s  purchase=%s',
        (string) $lot['id'],
        (string) $lot['cost_price'],
        (string) $lot['valid_from'],
        (string) $to,
        $qty,
        $lot['purchase_id'] === null ? '—' : (string) $lot['purchase_id']
    );
}

function printState(array $state, string $title): void
{
    echo "--- {$title} ---\n";
    foreach ($state as $k => $s) {
        printf(
            "%-15s ventes n=%d qty=%d CA=%.2f | COGS=%.4f profit=%.4f | pertes n=%d qty=%d val=%.4f | stock=%s | lots ouverts=%d\n",
            $k,
            $s['sales']['n'],
            $s['sales']['qty'],
            $s['sales']['ca'],
            $s['cogs'],
            $s['profit'],
            $s['losses']['n'],
            $s['losses']['qty'],
            $s['losses']['cogs'],
            $s['stock'] === null ? '—' : (string) $s['stock'],
            $s['openLots']
        );
        foreach ($s['lots'] as $lot) {
            echo lotLine($lot), "\n";
        }
    }
}

function assertEq(string $label, string $expected, string $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("ÉCHEC {$label} : attendu « {$expected} », trouvé « {$actual} » — ROLLBACK.");
    }
}

function assertNear(string $label, float $expected, float $actual, float $tol = TOL): void
{
    if (abs($expected - $actual) > $tol) {
        throw new RuntimeException("ÉCHEC {$label} : attendu {$expected}, trouvé {$actual} — ROLLBACK.");
    }
}

function assertOpenLots(string $key, int $expected): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM product_costs WHERE product_key = ? AND valid_to IS NULL');
    $st->execute([$key]);
    $n = (int) $st->fetchColumn();
    if ($n !== $expected) {
        throw new RuntimeException("ÉCHEC lots ouverts {$key} : attendu {$expected}, trouvé {$n} — ROLLBACK.");
    }
}

function assertSalesUnchanged(array $before, array $after, string $opLabel): void
{
    foreach (KEYS as $k) {
        $b = $before[$k]['sales'];
        $a = $after[$k]['sales'];
        if ($b != $a) { // comparaison stricte du triplet n/qty/ca
            throw new RuntimeException(sprintf(
                'ÉCHEC %s — ventes %s modifiées : avant n=%d qty=%d CA=%.2f / après n=%d qty=%d CA=%.2f — ROLLBACK.',
                $opLabel,
                $k,
                $b['n'], $b['qty'], $b['ca'],
                $a['n'], $a['qty'], $a['ca']
            ));
        }
    }
}

function findPurchase(string $id): array
{
    $row = Purchase::find($id);
    if ($row === null) {
        throw new RuntimeException("Achat {$id} introuvable — ROLLBACK.");
    }

    return $row;
}

/**
 * Ré-saisie fidèle : copie EXACTE des montants de la ligne d'origine
 * (aucun re-arrondi — le TTC et le HT font foi), seule la quantité change,
 * puis lot de coût lié (round(TTC/qty, 3)), comme PurchaseSaver::createOne
 * avec update_cost, MAIS sans ProductAutoSync (interdiction W3 de créer
 * des fiches — la clé « Gaufre choco » n'en a pas).
 */
function reessaisie(array $orig, int $newQty, float $expectedLotCost, string $note): array
{
    $newId = Purchase::create([
        'purchased_at'   => substr((string) $orig['purchased_at'], 0, 10),
        'product_key'    => (string) $orig['product_key'],
        'quantity'       => $newQty,
        'total_ht'       => (float) $orig['total_ht'],   // copie exacte
        'vat_rate'       => $orig['vat_rate'] === null ? null : (float) $orig['vat_rate'],
        'total_ttc'      => (float) $orig['total_ttc'],  // copie exacte
        'no_stock'       => !empty($orig['no_stock']),
        'supplier'       => $orig['supplier'] ?? '',
        'invoice_number' => $orig['invoice_number'] ?? '',
        'notes'          => $orig['notes'] ?? '',
        'created_by'     => $orig['created_by'] ?? '',
        'receipt_path'   => $orig['receipt_path'] ?? null,
    ]);
    if ($newId === '') {
        throw new RuntimeException('Ré-saisie ' . $orig['id'] . ' impossible — ROLLBACK.');
    }

    $lotCost = round(((float) $orig['total_ttc']) / $newQty, 3);
    assertNear('coût lot ré-saisi ' . $orig['id'], $expectedLotCost, $lotCost);

    $lotId = ProductCost::create([
        'product_key'   => (string) $orig['product_key'],
        'cost_price'    => $lotCost,
        'valid_from'    => substr((string) $orig['purchased_at'], 0, 10),
        'supplier'      => $orig['supplier'] ?? '',
        'qty_purchased' => $newQty,
        'purchase_id'   => $newId,
        'notes'         => $note,
    ]);
    if ($lotId === '') {
        throw new RuntimeException('Création lot ré-saisi ' . $orig['id'] . ' impossible — ROLLBACK.');
    }

    return ['purchase_id' => $newId, 'lot_id' => $lotId, 'lot_cost' => $lotCost];
}

// ── État initial + snapshot AVANT ───────────────────────────────────────

echo 'Backup du jour vérifié : ', $latestBackup, "\n";
echo ($execute ? "EXÉCUTION réelle\n" : "DRY-RUN — aucune écriture\n");
echo "==============================================================\n";

$before = allState();
printState($before, 'ÉTAT AVANT');
if ($execute) {
    writeSnapshot('00_avant', $before);
}

// ── Vérifications de préconditions (aucune écriture) ───────────────────

// OP1 — gaufre
$g = findPurchase(GAUFRE_PURCHASE);
assertEq('OP1 achat produit', 'gaufre', (string) $g['product_key']);
assertEq('OP1 achat date', '2026-10-07', substr((string) $g['purchased_at'], 0, 10));
assertEq('OP1 achat qty', '1', (string) (int) $g['quantity']);
assertEq('OP1 achat TTC', '5.990', (string) $g['total_ttc']);
assertEq('OP1 achat HT', '5.678', (string) $g['total_ht']);
assertEq('OP1 achat no_stock', '1', (string) (int) $g['no_stock']);
$gl = ProductCost::find(GAUFRE_LOT);
if ($gl === null || (string) $gl['cost_price'] !== '5.990'
    || substr((string) $gl['valid_from'], 0, 10) !== '2026-10-07'
    || $gl['valid_to'] !== null || (string) $gl['purchase_id'] !== GAUFRE_PURCHASE) {
    throw new RuntimeException('OP1 : lot ' . GAUFRE_LOT . ' (5,990 du 07/10, ouvert, lié) introuvable/incorrect — REFUS.');
}

// OP2 — Gaufre choco
$c = findPurchase(CHOCO_PURCHASE);
assertEq('OP2 achat produit', 'Gaufre choco', (string) $c['product_key']);
assertEq('OP2 achat date', '2026-10-12', substr((string) $c['purchased_at'], 0, 10));
assertEq('OP2 achat qty', '1', (string) (int) $c['quantity']);
assertEq('OP2 achat TTC', '6.990', (string) $c['total_ttc']);
assertEq('OP2 achat HT', '6.626', (string) $c['total_ht']);
$cl = ProductCost::find(CHOCO_LOT_MANUEL);
if ($cl === null || (string) $cl['cost_price'] !== '0.389'
    || substr((string) $cl['valid_from'], 0, 10) !== '2026-10-08'
    || $cl['valid_to'] !== null || $cl['purchase_id'] !== null) {
    throw new RuntimeException('OP2 : lot manuel ' . CHOCO_LOT_MANUEL . ' (0,389 du 08/10, sans purchase_id) introuvable/incorrect — REFUS.');
}
$st = db()->prepare('SELECT COUNT(*) FROM product_costs WHERE purchase_id = ?');
$st->execute([CHOCO_PURCHASE]);
if ((int) $st->fetchColumn() !== 0) {
    throw new RuntimeException('OP2 : des lots sont liés à ' . CHOCO_PURCHASE . ' — état inattendu, REFUS.');
}

// OP3 — Nesquik
$n = findPurchase(NESQUIK_PURCHASE);
assertEq('OP3 achat produit', 'Nesquik', (string) $n['product_key']);
assertEq('OP3 achat date', '2026-10-12', substr((string) $n['purchased_at'], 0, 10));
assertEq('OP3 achat qty', '1', (string) (int) $n['quantity']);
assertEq('OP3 achat TTC', '6.014', (string) $n['total_ttc']);
assertEq('OP3 achat HT', '5.700', (string) $n['total_ht']);
assertEq('OP3 achat no_stock', '0', (string) (int) $n['no_stock']);
$nl = ProductCost::find(NESQUIK_LOT);
if ($nl === null || (string) $nl['cost_price'] !== '6.014'
    || substr((string) $nl['valid_from'], 0, 10) !== '2026-10-12') {
    throw new RuntimeException('OP3 : lot ' . NESQUIK_LOT . ' (6,014 du 12/10) introuvable/incorrect — REFUS.');
}
if ($nl['valid_to'] !== null || (string) $nl['purchase_id'] !== NESQUIK_PURCHASE) {
    throw new RuntimeException('OP3 : lot ' . NESQUIK_LOT . ' doit être ouvert et lié à l\'achat — REFUS.');
}

// OP4 — périodes
$o580 = ProductCost::find(OASIS_LOT_0580);
if ($o580 === null || (string) $o580['cost_price'] !== '0.580'
    || substr((string) $o580['valid_from'], 0, 10) !== '2026-09-07'
    || substr((string) $o580['valid_to'], 0, 10) !== '2026-10-15') {
    throw new RuntimeException('OP4 : lot Oasis 0,580 (07/09→15/10) introuvable/incorrect — REFUS.');
}
$o522 = ProductCost::find(OASIS_LOT_0522);
if ($o522 === null || (string) $o522['cost_price'] !== '0.522'
    || substr((string) $o522['valid_from'], 0, 10) !== '2026-09-19'
    || $o522['valid_to'] !== null) {
    throw new RuntimeException('OP4 : lot Oasis 0,522 (19/09→NULL) introuvable/incorrect — REFUS.');
}
$odeg = ProductCost::find(OASIS_LOT_DEGEN);
if ($odeg === null || substr((string) $odeg['valid_from'], 0, 10) !== '2026-06-29'
    || substr((string) $odeg['valid_to'], 0, 10) !== '2026-06-28') {
    throw new RuntimeException('OP4 : lot Oasis dégénéré (29/06→28/06) introuvable/incorrect — REFUS.');
}
$rdeg = ProductCost::find(REDBULL_DEGEN);
if ($rdeg === null || substr((string) $rdeg['valid_from'], 0, 10) !== '2026-09-19'
    || substr((string) $rdeg['valid_to'], 0, 10) !== '2026-09-18') {
    throw new RuntimeException('OP4 : lot Redbull zero dégénéré (19/09→18/09) introuvable/incorrect — REFUS.');
}

echo "Préconditions OP1→OP4 : CONFORMES à l'arbitrage.\n\n";

// ── Plan (dry-run et exécution) ────────────────────────────────────────

echo "OP1 gaufre       : supprimer achat " . GAUFRE_PURCHASE . " (cascade lot 5,990, lot 0,433 réouvert)\n";
echo "                   puis ré-saisie qty=10, TTC 5,990 identique → lot 0,599 €/u (07/10, qty 10).\n";
echo "OP2 Gaufre choco : supprimer lot manuel 0,389 (08/10, sans achat lié) + achat " . CHOCO_PURCHASE . "\n";
echo "                   puis ré-saisie qty=10, TTC 6,990 identique → lot 0,699 €/u (12/10, qty 10).\n";
echo "OP3 Nesquik      : supprimer achat " . NESQUIK_PURCHASE . " (cascade lot 6,014 ; stock −1)\n";
echo "                   puis ré-saisie qty=80 verres, TTC 6,014 identique → lot 0,075 €/verre (12/10,\n";
echo "                   stock +80 : le produit se compte désormais en verres — coordination W3).\n";
echo "OP4 périodes     : normalizePeriods(Oasis Tropical) → 0,580 refermé au 18/09 (veille du 0,522) ;\n";
echo "                   close() des 2 lots dégénérés → bornes valid_to = valid_from (29/06, 19/09).\n";
echo "                   Impact COGS attendu : NUL (règle as-of identique avant/après — vérifié).\n\n";

if (!$execute) {
    echo "DRY-RUN terminé — RIEN n'a été écrit.\n";
    echo "Relancer avec --execute pour appliquer (transaction unique, tout ou rien).\n";
    exit(0);
}

// ── EXÉCUTION : transaction unique, assertions après CHAQUE produit ────

$results = [];
$pdo->beginTransaction();
try {
    // ---- OP1 : GAUFRE ---------------------------------------------------
    $deletedLots = ProductCost::deleteByPurchase(GAUFRE_PURCHASE);
    if ($deletedLots !== 1) {
        throw new RuntimeException("OP1 : deleteByPurchase a supprimé {$deletedLots} lot(s) (attendu 1) — ROLLBACK.");
    }
    if (!Purchase::delete(GAUFRE_PURCHASE)) {
        throw new RuntimeException('OP1 : suppression achat impossible — ROLLBACK.');
    }
    // no_stock=1 : la cascade contrôleur ne touche PAS le stock (vérifié).
    $op1 = reessaisie($g, 10, 0.599, 'W2 30/10 : recyclage pack Auchan 10 gaufres ÷10 (achat '
        . GAUFRE_PURCHASE . ' supprimé, montants HT/TTC identiques — arbitrage 01a186c3).');
    $results['OP1'] = ['deleted_purchase' => GAUFRE_PURCHASE] + $op1;

    $after1 = allState();
    assertSalesUnchanged($before, $after1, 'OP1');
    assertOpenLots('gaufre', 1);
    $lot1 = ProductCost::find($op1['lot_id']);
    assertEq('OP1 lot coût', '0.599', (string) $lot1['cost_price']);
    assertEq('OP1 lot valid_from', '2026-10-07', substr((string) $lot1['valid_from'], 0, 10));
    assertEq('OP1 lot valid_to', '', $lot1['valid_to'] === null ? '' : (string) $lot1['valid_to']);
    assertEq('OP1 lot qty', '10', (string) (int) $lot1['qty_purchased']);
    assertEq('OP1 lot purchase', $op1['purchase_id'], (string) $lot1['purchase_id']);
    $prev = ProductCost::find(GAUFRE_PREV_LOT);
    assertEq('OP1 lot antérieur valid_to', '2026-10-06', substr((string) $prev['valid_to'], 0, 10));
    $np = Purchase::find($op1['purchase_id']);
    assertEq('OP1 ré-saisie TTC', '5.990', (string) $np['total_ttc']);
    assertEq('OP1 ré-saisie HT', '5.678', (string) $np['total_ht']);
    assertEq('OP1 ré-saisie qty', '10', (string) (int) $np['quantity']);
    assertEq('OP1 ré-saisie no_stock', '1', (string) (int) $np['no_stock']);
    assertEq('OP1 stock inchangé', (string) $before['gaufre']['stock'], (string) ProductStock::get('gaufre'));
    // Delta COGS attendu : 13 ventes as-of depuis le 07/10 × (5,990 − 0,599).
    // (La perte VOL du 19/10 se recale séparément : pertes.cogs 5,990 → 0,599.)
    assertNear('OP1 COGS attendu', $before['gaufre']['cogs'] - 13 * (5.990 - 0.599), $after1['gaufre']['cogs'], 0.0011);
    writeSnapshot('01_apres_gaufre', $after1);
    AuditLog::log('w2.costs.recycle', null, 'purchase', $op1['purchase_id'], [
        'mission' => 'W2 coûts 30/10 (arbitrage 01a186c3)',
        'op' => 'OP1 gaufre ÷10',
        'supprime' => ['purchase' => GAUFRE_PURCHASE, 'lot' => GAUFRE_LOT, 'lot_cost' => '5.990'],
        'cree' => ['purchase' => $op1['purchase_id'], 'lot' => $op1['lot_id'], 'lot_cost' => '0.599', 'qty' => 10],
        'montants' => ['total_ht' => '5.678', 'total_ttc' => '5.990', 'inchanges' => true],
        'cogs' => ['avant' => $before['gaufre']['cogs'], 'apres' => $after1['gaufre']['cogs']],
    ]);
    echo "OP1 gaufre OK — lot 0,599 €/u (07/10, qty 10), achat qty=10 TTC 5,990 identique, ventes intactes.\n";

    // ---- OP2 : GAUFRE CHOCO ---------------------------------------------
    if (!ProductCost::delete(CHOCO_LOT_MANUEL)) {
        throw new RuntimeException('OP2 : suppression lot manuel 0,389 impossible — ROLLBACK.');
    }
    $deletedLots = ProductCost::deleteByPurchase(CHOCO_PURCHASE);
    if ($deletedLots !== 0) {
        throw new RuntimeException("OP2 : deleteByPurchase inattendu ({$deletedLots} lots) — ROLLBACK.");
    }
    if (!Purchase::delete(CHOCO_PURCHASE)) {
        throw new RuntimeException('OP2 : suppression achat impossible — ROLLBACK.');
    }
    $op2 = reessaisie($c, 10, 0.699, 'W2 30/10 : recyclage pack Auchan 10 gaufres choco ÷10 (achat '
        . CHOCO_PURCHASE . ' supprimé + lot manuel 0,389 du 08/10 retiré, montants identiques — arbitrage 01a186c3).');
    $results['OP2'] = ['deleted_purchase' => CHOCO_PURCHASE, 'deleted_manual_lot' => CHOCO_LOT_MANUEL] + $op2;

    $after2 = allState();
    assertSalesUnchanged($after1, $after2, 'OP2');
    assertOpenLots('Gaufre choco', 1);
    $lot2 = ProductCost::find($op2['lot_id']);
    assertEq('OP2 lot coût', '0.699', (string) $lot2['cost_price']);
    assertEq('OP2 lot valid_from', '2026-10-12', substr((string) $lot2['valid_from'], 0, 10));
    assertEq('OP2 lot valid_to', '', $lot2['valid_to'] === null ? '' : (string) $lot2['valid_to']);
    assertEq('OP2 lot qty', '10', (string) (int) $lot2['qty_purchased']);
    $nchoco = count(ProductCost::forProduct('Gaufre choco'));
    if ($nchoco !== 1) {
        throw new RuntimeException("OP2 : {$nchoco} lot(s) Gaufre choco restants (attendu 1) — ROLLBACK.");
    }
    $np2 = Purchase::find($op2['purchase_id']);
    assertEq('OP2 ré-saisie TTC', '6.990', (string) $np2['total_ttc']);
    assertEq('OP2 ré-saisie HT', '6.626', (string) $np2['total_ht']);
    assertEq('OP2 ré-saisie qty', '10', (string) (int) $np2['quantity']);
    assertEq('OP2 stock inchangé', (string) $before['Gaufre choco']['stock'], (string) ProductStock::get('Gaufre choco'));
    assertNear('OP2 COGS attendu', $after1['Gaufre choco']['cogs'] + 10 * (0.699 - 0.389), $after2['Gaufre choco']['cogs'], 0.0011);
    writeSnapshot('02_apres_gaufre_choco', $after2);
    AuditLog::log('w2.costs.recycle', null, 'purchase', $op2['purchase_id'], [
        'mission' => 'W2 coûts 30/10 (arbitrage 01a186c3)',
        'op' => 'OP2 Gaufre choco ÷10',
        'supprime' => ['purchase' => CHOCO_PURCHASE, 'lot_manuel' => CHOCO_LOT_MANUEL, 'lot_cost' => '0.389'],
        'cree' => ['purchase' => $op2['purchase_id'], 'lot' => $op2['lot_id'], 'lot_cost' => '0.699', 'qty' => 10],
        'montants' => ['total_ht' => '6.626', 'total_ttc' => '6.990', 'inchanges' => true],
        'cogs' => ['avant' => $after1['Gaufre choco']['cogs'], 'apres' => $after2['Gaufre choco']['cogs']],
    ]);
    echo "OP2 Gaufre choco OK — lot unique 0,699 €/u (12/10, qty 10), ventes intactes.\n";

    // ---- OP3 : NESQUIK (Option A : au verre) ------------------------------
    $deletedLots = ProductCost::deleteByPurchase(NESQUIK_PURCHASE);
    if ($deletedLots !== 1) {
        throw new RuntimeException("OP3 : deleteByPurchase a supprimé {$deletedLots} lot(s) (attendu 1) — ROLLBACK.");
    }
    if (!Purchase::delete(NESQUIK_PURCHASE)) {
        throw new RuntimeException('OP3 : suppression achat impossible — ROLLBACK.');
    }
    // no_stock=0 : cascade contrôleur = contre-passation du stock de référence.
    ProductStock::adjust('Nesquik', -1);
    $op3 = reessaisie($n, 80, 0.075, 'W2 30/10 Option A : sachet Nesquik 800 g vendu AU VERRE (~80 verres) — '
        . 'achat ' . NESQUIK_PURCHASE . ' recyclé qty=80, TTC 6,014 identique. Fiche « Verre Nesquik » : W3 dev-merge.');
    $results['OP3'] = ['deleted_purchase' => NESQUIK_PURCHASE] + $op3;

    $after3 = allState();
    assertSalesUnchanged($after2, $after3, 'OP3');
    assertOpenLots('Nesquik', 1);
    $lot3 = ProductCost::find($op3['lot_id']);
    assertEq('OP3 lot coût', '0.075', (string) $lot3['cost_price']);
    assertEq('OP3 lot valid_from', '2026-10-12', substr((string) $lot3['valid_from'], 0, 10));
    assertEq('OP3 lot valid_to', '', $lot3['valid_to'] === null ? '' : (string) $lot3['valid_to']);
    assertEq('OP3 lot qty', '80', (string) (int) $lot3['qty_purchased']);
    $np3 = Purchase::find($op3['purchase_id']);
    assertEq('OP3 ré-saisie TTC', '6.014', (string) $np3['total_ttc']);
    assertEq('OP3 ré-saisie HT', '5.700', (string) $np3['total_ht']);
    assertEq('OP3 ré-saisie qty', '80', (string) (int) $np3['quantity']);
    assertEq('OP3 ré-saisie no_stock', '0', (string) (int) $np3['no_stock']);
    assertEq('OP3 stock verres', '80', (string) (int) ProductStock::get('Nesquik'));
    assertNear('OP3 COGS inchangé', $after2['Nesquik']['cogs'], $after3['Nesquik']['cogs'], 0.0);
    writeSnapshot('03_apres_nesquik', $after3);
    AuditLog::log('w2.costs.recycle', null, 'purchase', $op3['purchase_id'], [
        'mission' => 'W2 coûts 30/10 (arbitrage 01a186c3)',
        'op' => 'OP3 Nesquik au verre (Option A)',
        'supprime' => ['purchase' => NESQUIK_PURCHASE, 'lot' => NESQUIK_LOT, 'lot_cost' => '6.014'],
        'cree' => ['purchase' => $op3['purchase_id'], 'lot' => $op3['lot_id'], 'lot_cost' => '0.075', 'qty' => 80],
        'montants' => ['total_ht' => '5.700', 'total_ttc' => '6.014', 'inchanges' => true],
        'stock' => ['avant' => 1, 'apres' => 80, 'unite' => 'verres (W3 : fiche « Verre Nesquik »)'],
    ]);
    echo "OP3 Nesquik OK — lot 0,075 €/verre (12/10, qty 80), stock 1 sachet → 80 verres, 0 vente (COGS inchangé).\n";

    // ---- OP4 : NORMALISATION PÉRIODES -------------------------------------
    // a) Chevauchement Oasis Tropical : le plus ancien (0,580) refermé au
    //    18/09 = veille du 0,522 (19/09). Zéro impact as-of (vérifié après).
    ProductCost::normalizePeriods('Oasis Tropical');
    $o580b = ProductCost::find(OASIS_LOT_0580);
    assertEq('OP4a 0,580 valid_to', '2026-09-18', substr((string) $o580b['valid_to'], 0, 10));
    assertEq('OP4a 0,580 valid_from', '2026-09-07', substr((string) $o580b['valid_from'], 0, 10));
    // b) Lot dégénéré Oasis 0,140 : close() → valid_to = valid_from (29/06),
    //    via GREATEST + clamp normalizePeriods (correction minimale 1 jour).
    if (!ProductCost::close(OASIS_LOT_DEGEN)) {
        throw new RuntimeException('OP4b : close() lot dégénéré Oasis impossible — ROLLBACK.');
    }
    $odegB = ProductCost::find(OASIS_LOT_DEGEN);
    assertEq('OP4b 0,140 valid_to', '2026-06-29', substr((string) $odegB['valid_to'], 0, 10));
    assertEq('OP4b 0,140 valid_from', '2026-06-29', substr((string) $odegB['valid_from'], 0, 10));
    $o580c = ProductCost::find(OASIS_LOT_0580);
    assertEq('OP4b 0,580 valid_to stable', '2026-09-18', substr((string) $o580c['valid_to'], 0, 10));
    $o522c = ProductCost::find(OASIS_LOT_0522);
    if ($o522c['valid_to'] !== null) {
        throw new RuntimeException('OP4b : le lot 0,522 ouvert ne doit pas être fermé — ROLLBACK.');
    }
    // c) Lot dégénéré Redbull zero 0,928 : même correction (19/09→19/09).
    if (!ProductCost::close(REDBULL_DEGEN)) {
        throw new RuntimeException('OP4c : close() lot dégénéré Redbull zero impossible — ROLLBACK.');
    }
    $rdegB = ProductCost::find(REDBULL_DEGEN);
    assertEq('OP4c Redbull valid_to', '2026-09-19', substr((string) $rdegB['valid_to'], 0, 10));
    assertEq('OP4c Redbull valid_from', '2026-09-19', substr((string) $rdegB['valid_from'], 0, 10));
    $rOpen = ProductCost::find(REDBULL_OPEN);
    if ($rOpen['valid_to'] !== null) {
        throw new RuntimeException('OP4c : le lot Redbull zero ouvert ne doit pas être fermé — ROLLBACK.');
    }

    $after4 = allState();
    assertSalesUnchanged($after3, $after4, 'OP4');
    // Impact COGS STRICTEMENT NUL (as-of identique) — égalité exacte :
    assertNear('OP4 COGS Oasis inchangé', $after3['Oasis Tropical']['cogs'], $after4['Oasis Tropical']['cogs'], 0.0);
    assertNear('OP4 COGS Redbull inchangé', $after3['Redbull zero']['cogs'], $after4['Redbull zero']['cogs'], 0.0);
    assertNear('OP4 pertes Oasis inchangées', $after3['Oasis Tropical']['losses']['cogs'], $after4['Oasis Tropical']['losses']['cogs'], 0.0);
    assertNear('OP4 pertes Redbull inchangées', $after3['Redbull zero']['losses']['cogs'], $after4['Redbull zero']['losses']['cogs'], 0.0);
    assertOpenLots('Oasis Tropical', 1);
    assertOpenLots('Redbull zero', 1);
    writeSnapshot('04_apres_periodes', $after4);
    AuditLog::log('w2.costs.normalize_periods', null, 'product_cost', OASIS_LOT_0580, [
        'mission' => 'W2 coûts 30/10 (arbitrage 01a186c3)',
        'op' => 'OP4 normalisation périodes',
        'oasis_0580' => ['avant' => ['from' => '2026-09-07', 'to' => '2026-10-15'], 'apres' => ['from' => '2026-09-07', 'to' => '2026-09-18']],
        'oasis_0140_degenere' => ['avant' => ['from' => '2026-06-29', 'to' => '2026-06-28'], 'apres' => ['from' => '2026-06-29', 'to' => '2026-06-29']],
        'redbull_zero_degenere' => ['avant' => ['from' => '2026-09-19', 'to' => '2026-09-18'], 'apres' => ['from' => '2026-09-19', 'to' => '2026-09-19']],
        'impact_cogs' => 'nul (règle as-of identique avant/après, vérifié par snapshot)',
    ]);
    echo "OP4 périodes OK — 0,580 refermé au 18/09, 2 dégénérés corrigés (bornes = valid_from), COGS strictement inchangé.\n";

    // ---- Invariants globaux finaux ---------------------------------------
    foreach (KEYS as $k) {
        assertOpenLots($k, 1);
        $stL = db()->prepare(
            'SELECT COUNT(*) FROM product_costs WHERE product_key = ? AND valid_to IS NOT NULL AND valid_from > valid_to'
        );
        $stL->execute([$k]);
        if ((int) $stL->fetchColumn() !== 0) {
            throw new RuntimeException("Invariant : lot dégénéré restant sur « {$k} » — ROLLBACK.");
        }
    }
    // La perte gaufre (VOL 19/10, qty 1) se recale automatiquement as-of :
    // 5,990 → 0,599 (attendu, inclus dans le delta OP1 vérifié plus haut).
    assertNear('Perte gaufre recalée 5,990→0,599', 0.599, $after4['gaufre']['losses']['cogs'], 0.0001);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    fwrite(STDERR, "TRANSACTION ANNULÉE — base strictement inchangée. Rollback externe possible via {$latestBackup}.\n");
    exit(1);
}

// Invalidation du cache public (comme le contrôleur achats).
StockPublic::invalidate();

// ── État final + snapshot APRÈS ────────────────────────────────────────

$final = allState();
printState($final, 'ÉTAT APRÈS');
$finalFile = writeSnapshot('99_final', $final);

echo "\n==============================================================\n";
echo "TRANSACTION COMMITÉE — les 4 opérations sont appliquées.\n";
echo "Snapshots JSONL : logs/w2_couts_20261030_*.jsonl (avant, après chaque produit, final)\n";
foreach ($results as $op => $r) {
    echo "  {$op} : " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "Cache public invalidé (StockPublic::invalidate).\n";
exit(0);

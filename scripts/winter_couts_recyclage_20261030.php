<?php

declare(strict_types=1);

/**
 * AEIC — Mission WINTER (one-off, 30/10/2026). Dernier item de la file
 * d'arbitrage — dossier Redbull.
 *
 * Anomalie 1 : prix d'achat « Redbull Winter » incohérents — 24 u @1,647 HT
 * (19/09, purchase_0df285c7637428e4ba19) vs 48 u @1,230 HT (25/09,
 * purchase_2db06da56138883de5d3), écart 34 %.
 *
 * ÉTAPE 0 (verdict, lecture seule) — SAISIE ERROR sur la ligne du 19/09 :
 *  - aucune facture numérisée n'existe pour les cours Metro de septembre
 *    (receipt_path NULL partout, uploads/receipts = octobre uniquement) ;
 *  - audit_logs aud_7e0bddf66e95edd25ca95c79 : le 21/09 à 12:33:40 (2 h 11
 *    après la saisie groupée 10:22:24) user_admin corrige MANUELLEMENT le
 *    lot du 19/09 à 1,29765 = 1,230 HT × 1,055 EXACTEMENT — seule valeur
 *    du tarif Metro qui produit ce nombre ;
 *  - le cours du 25/09 (saisi 29/09, même admin) facture Winter à 1,230 HT,
 *    toutes les autres lignes des deux cours à l'identique (Blanche/Ice
 *    1,048, Peach 1,233, Monster 1,268/1,413, Eau 0,155) ;
 *  - bande familiale Redbull 0,880–1,233 HT : 1,647 = outlier +34 % ;
 *  - vente 1,50–3,00 € TTC : au coût 1,737 (=41,694/24) les ventes à 1,50
 *    seraient à perte — motif plausible de la correction du 21/09.
 *  → vrai montant facture 19/09 : 24 × 1,230 = 29,520 HT / 31,144 TTC
 *    (29,520×1,055 = 31,1436 → 31,144, même valeur que 1,230×1,055×24).
 *
 * Correction : recyclage W2 — suppression de l'achat erroné (cascade lots)
 * + ré-saisie aux VRAIS montants facture. Les lots étant déjà corrects
 * (1,298 depuis la correction du 21/09), l'opération est sémantiquement
 * neutre sur les coûts : round(31,144/24, 3) = 1,298 = valeur actuelle,
 * donc COGS/marges STRICTEMENT inchangés. Seul change le montant comptable
 * de l'achat : achats globaux −10,000 HT / −10,550 TTC (documenté, voulu).
 *
 * Le lot ouvert du 25/09 doit être recréé à l'identique (même coût, même
 * période, même purchase_id, qty NULL) : ProductCost::create referme le
 * lot ouvert existant à date-1 (dégénéré) et aucun primitif du modèle ne
 * rouvre un lot — la recréation est la seule voie modèle-centrée.
 *
 * qty_purchased laissés NULL (état actuel des deux lots, non suivis) :
 * la ré-saisie d'époque ne suivait pas les quantités, on n'inscrit pas
 * Winter au suivi de quantités sans décision (mission normalisation).
 *
 * Invariants : ventes/CA globaux identiques ; 1 seul lot ouvert par clé ;
 * périodes strictement consécutives (19/09→24/09, 25/09→NULL) ; montants
 * facture vrais copiés exactement (29,520 / 31,144) ; stock de référence
 * inchangé (contre-passation −24 puis +24) ; stock théorique inchangé
 * (achat du 19/09 antérieur au dernier comptage 25/09) ; COGS Winter
 * strictement identique avant/après.
 *
 * DRY-RUN par défaut. --execute : transaction unique, tout ou rien.
 * Usage :
 *   php scripts/winter_couts_recyclage_20261030.php            (dry-run)
 *   php scripts/winter_couts_recyclage_20261030.php --execute  (réel)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\StockPublic;
use App\Models\AuditLog;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\Purchase;

// ── Cibles exactes (one-off : rien d'autre ne doit matcher) ─────────────

const KEY           = 'Redbull Winter';
const PURCHASE_BAD  = 'purchase_0df285c7637428e4ba19'; // 19/09, 24 u @ 1,647 HT (faux)
const PURCHASE_0925 = 'purchase_2db06da56138883de5d3'; // 25/09, 48 u @ 1,230 HT (bon, intact)
const LOT_0919      = 'cost_72e7a41fec3dd3e874f7';     // 1,298 · 19/09→24/09 · lié achat faux
const LOT_0925      = 'cost_10f556a56337267b5d8d';     // 1,298 · 25/09→NULL · lié achat 25/09

const TRUE_HT  = 29.520; // 24 × 1,230 HT — vrai montant facture
const TRUE_TTC = 31.144; // 24 × 1,230 × 1,055 — vrai montant facture
const BAD_HT   = 39.520; // montant faux actuel
const BAD_TTC  = 41.694;

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
$age = (time() - $latestMtime) / 3600.0;
if ($latestBackup === '' || $age > 24.0) {
    fwrite(STDERR, sprintf(
        "REFUS : backup trop ancien ou absent (dernier : %s, %.1f h). Lancer scripts/backup.sh.\n",
        $latestBackup ?: 'aucun',
        $age
    ));
    exit(1);
}

// ── Aides ───────────────────────────────────────────────────────────────

/**
 * État chiffré de Winter + agrégats globaux (ventes/achats jamais modifiés
 * sauf le montant documenté de l'achat corrigé).
 */
function winterState(): array
{
    $pdo = db();

    $st = $pdo->prepare(
        'SELECT sold_at, quantity, price_ttc FROM sales WHERE product_key = ? AND is_custom_amount = 0'
    );
    $st->execute([KEY]);
    $n = 0;
    $qty = 0;
    $ca = 0.0;
    $cogs = 0.0;
    foreach ($st->fetchAll() as $s) {
        $q = max(1, (int) $s['quantity']);
        $day = substr((string) $s['sold_at'], 0, 10);
        $cost = ProductCost::costAt(KEY, $day);
        $n++;
        $qty += $q;
        $ca += (float) $s['price_ttc'] * $q;
        $cogs += ($cost === null ? 0.0 : (float) $cost) * $q;
    }

    $lots = [];
    foreach (ProductCost::forProduct(KEY) as $lot) {
        $lots[] = [
            'id'            => (string) $lot['id'],
            'cost_price'    => (string) $lot['cost_price'],
            'qty_purchased' => $lot['qty_purchased'] === null ? null : (int) $lot['qty_purchased'],
            'valid_from'    => substr((string) $lot['valid_from'], 0, 10),
            'valid_to'      => $lot['valid_to'] === null ? null : substr((string) $lot['valid_to'], 0, 10),
            'purchase_id'   => $lot['purchase_id'] === null ? null : (string) $lot['purchase_id'],
        ];
    }
    $openLots = count(array_filter($lots, static fn (array $l): bool => $l['valid_to'] === null));

    $purchases = [];
    $st = $pdo->prepare(
        'SELECT id, purchased_at, quantity, unit_cost, vat_rate, total_ht, total_ttc, no_stock, supplier
           FROM purchases WHERE product_key = ? ORDER BY purchased_at, created_at'
    );
    $st->execute([KEY]);
    foreach ($st->fetchAll() as $p) {
        $purchases[] = [
            'id'        => (string) $p['id'],
            'date'      => substr((string) $p['purchased_at'], 0, 10),
            'qty'       => (int) $p['quantity'],
            'unit_cost' => (string) $p['unit_cost'],
            'vat'       => $p['vat_rate'] === null ? null : (string) $p['vat_rate'],
            'total_ht'  => (string) $p['total_ht'],
            'total_ttc' => (string) $p['total_ttc'],
            'no_stock'  => (int) $p['no_stock'] === 1,
            'supplier'  => $p['supplier'] === null ? null : (string) $p['supplier'],
        ];
    }

    // Stock théorique (formule InventoryCount::theoreticalFromLast, comptage 25/09 17:09:52 = 48).
    $st = $pdo->prepare(
        'SELECT 48
           + (SELECT COALESCE(SUM(quantity),0) FROM purchases WHERE product_key = ? AND purchased_at >= ? AND no_stock = 0)
           - (SELECT COALESCE(SUM(quantity),0) FROM sales WHERE (product_key = ? OR description = ?) AND sold_at >= ? AND is_custom_amount = 0)
           - (SELECT COALESCE(SUM(quantity),0) FROM losses WHERE product_key = ? AND lost_at >= ?)'
    );
    $st->execute([KEY, '2026-09-25', KEY, KEY, '2026-09-25 17:09:52', KEY, '2026-09-25']);
    $theoretical = (int) $st->fetchColumn();

    return [
        'sales'       => ['n' => $n, 'qty' => $qty, 'ca' => round($ca, 2)],
        'cogs'        => round($cogs, 4),
        'lots'        => $lots,
        'openLots'    => $openLots,
        'stock_ref'   => ProductStock::get(KEY),
        'theoretical' => $theoretical,
        'purchases'   => $purchases,
    ];
}

function globalState(): array
{
    $pdo = db();

    return [
        'sales'   => [
            'n'  => (int) $pdo->query('SELECT COUNT(*) FROM sales WHERE is_custom_amount = 0')->fetchColumn(),
            'ca' => round((float) $pdo->query('SELECT COALESCE(SUM(price_ttc * quantity),0) FROM sales WHERE is_custom_amount = 0')->fetchColumn(), 2),
        ],
        'purchases' => [
            'n'   => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
            'ht'  => round((float) $pdo->query('SELECT COALESCE(SUM(total_ht),0) FROM purchases')->fetchColumn(), 3),
            'ttc' => round((float) $pdo->query('SELECT COALESCE(SUM(total_ttc),0) FROM purchases')->fetchColumn(), 3),
        ],
        'keys_multi_open' => (int) $pdo->query(
            'SELECT COUNT(*) FROM (SELECT product_key FROM product_costs WHERE valid_to IS NULL GROUP BY product_key HAVING COUNT(*) > 1) t'
        )->fetchColumn(),
    ];
}

function writeSnapshot(string $tag, array $doc): string
{
    $file = __DIR__ . '/../logs/winter_couts_20261030_' . $tag . '.jsonl';
    file_put_contents($file, json_encode([$tag, $doc], JSON_UNESCAPED_UNICODE) . "\n");

    return $file;
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

function assertOpenLots(int $expected): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM product_costs WHERE product_key = ? AND valid_to IS NULL');
    $st->execute([KEY]);
    $n = (int) $st->fetchColumn();
    if ($n !== $expected) {
        throw new RuntimeException("ÉCHEC lots ouverts " . KEY . " : attendu {$expected}, trouvé {$n} — ROLLBACK.");
    }
}

// ── État initial + snapshot AVANT ───────────────────────────────────────

printf("Backup du jour vérifié : %s (%.1f h)\n", $latestBackup, $age);
echo ($execute ? "EXÉCUTION réelle\n" : "DRY-RUN — aucune écriture\n");
echo "==============================================================\n";

$beforeW = winterState();
$beforeG = globalState();
$beforeDoc = ['winter' => $beforeW, 'global' => $beforeG];
echo json_encode($beforeDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
if ($execute) {
    writeSnapshot('00_avant', $beforeDoc);
}

// ── Vérifications de préconditions (aucune écriture) ───────────────────

$bad = Purchase::find(PURCHASE_BAD);
if ($bad === null) {
    throw new RuntimeException('Achat erroné ' . PURCHASE_BAD . ' introuvable — REFUS.');
}
assertEq('achat faux produit', KEY, (string) $bad['product_key']);
assertEq('achat faux date', '2026-09-19', substr((string) $bad['purchased_at'], 0, 10));
assertEq('achat faux qty', '24', (string) (int) $bad['quantity']);
assertEq('achat faux unit_cost', '1.647', (string) $bad['unit_cost']);
assertEq('achat faux vat', '5.50', (string) $bad['vat_rate']);
assertEq('achat faux HT', '39.520', (string) $bad['total_ht']);
assertEq('achat faux TTC', '41.694', (string) $bad['total_ttc']);
assertEq('achat faux no_stock', '0', (string) (int) $bad['no_stock']);
assertEq('achat faux supplier', 'metro', (string) $bad['supplier']);

$good = Purchase::find(PURCHASE_0925);
if ($good === null) {
    throw new RuntimeException('Achat 25/09 ' . PURCHASE_0925 . ' introuvable — REFUS.');
}
assertEq('achat 25/09 qty', '48', (string) (int) $good['quantity']);
assertEq('achat 25/09 unit_cost', '1.230', (string) $good['unit_cost']);
assertEq('achat 25/09 HT', '59.040', (string) $good['total_ht']);
assertEq('achat 25/09 TTC', '62.287', (string) $good['total_ttc']);

$lot19 = ProductCost::find(LOT_0919);
if ($lot19 === null
    || (string) $lot19['cost_price'] !== '1.298'
    || substr((string) $lot19['valid_from'], 0, 10) !== '2026-09-19'
    || substr((string) $lot19['valid_to'], 0, 10) !== '2026-09-24'
    || $lot19['qty_purchased'] !== null
    || (string) $lot19['purchase_id'] !== PURCHASE_BAD) {
    throw new RuntimeException('Lot 19/09 (1,298 · 19/09→24/09 · qty NULL · lié achat faux) introuvable/incorrect — REFUS.');
}
$lot25 = ProductCost::find(LOT_0925);
if ($lot25 === null
    || (string) $lot25['cost_price'] !== '1.298'
    || substr((string) $lot25['valid_from'], 0, 10) !== '2026-09-25'
    || $lot25['valid_to'] !== null
    || $lot25['qty_purchased'] !== null
    || (string) $lot25['purchase_id'] !== PURCHASE_0925) {
    throw new RuntimeException('Lot 25/09 (1,298 · 25/09→NULL · qty NULL · lié achat 25/09) introuvable/incorrect — REFUS.');
}
if (count($beforeW['lots']) !== 2 || $beforeW['openLots'] !== 1) {
    throw new RuntimeException(KEY . ' doit avoir exactement 2 lots dont 1 ouvert — REFUS.');
}
assertOpenLots(1);
if ((int) $beforeW['stock_ref'] !== 48) {
    throw new RuntimeException('Stock de référence Winter attendu 48, trouvé ' . $beforeW['stock_ref'] . ' — REFUS.');
}
if ($beforeG['keys_multi_open'] !== 0) {
    throw new RuntimeException('Des clés ont plusieurs lots ouverts — état global inattendu, REFUS.');
}

echo "Préconditions CONFORMES à l'arbitrage Winter.\n\n";

// ── Plan affiché (dry-run et exécution) ────────────────────────────────

$lotCost19 = round(TRUE_TTC / 24, 3);   // 1.298 — doit rester IDENTIQUE à l'existant
$lotCost25 = round((float) $good['total_ttc'] / 48, 3); // 1.298 — recalculé comme à l'origine
echo "1. Supprimer lot ouvert 25/09 " . LOT_0925 . " (le lot 19/09 est réouvert par cascade)\n";
echo "2. Supprimer achat erroné " . PURCHASE_BAD . " + son lot (cascade, chaîne vide)\n";
echo "3. Contre-passer le stock de référence : 48 − 24 = 24\n";
echo "4. Ré-saisie aux VRAIS montants facture : 24 u, 29,520 HT / 31,144 TTC, TVA 5,5 %, metro\n";
echo "   (stock +24 → 48 ; unit_cost dérivé round(29,520/24,3) = 1,230)\n";
echo "5. Recréer lot 19/09 : {$lotCost19} €/u (round(31,144/24,3)) · 19/09→NULL · qty NULL · lié ré-saisie\n";
echo "6. Recréer lot 25/09 : {$lotCost25} €/u (round(62,287/48,3)) · 25/09→NULL · qty NULL · lié " . PURCHASE_0925 . "\n";
echo "   (le lot 19/09 est refermé au 24/09 par create() : périodes 19/09→24/09 + 25/09→NULL)\n\n";
echo "Effets attendus : COGS/marges Winter strictement inchangés ; ventes/CA globaux identiques ;\n";
echo "achats globaux : HT {$beforeG['purchases']['ht']} → " . ($beforeG['purchases']['ht'] - 10.000)
   . ", TTC {$beforeG['purchases']['ttc']} → " . ($beforeG['purchases']['ttc'] - 10.550) . " (correction documentée) ;\n";
echo "stock de référence 48 inchangé ; stock théorique 13 inchangé ; 1 lot ouvert.\n\n";

if (!$execute) {
    echo "DRY-RUN terminé — RIEN n'a été écrit.\n";
    exit(0);
}

// ── EXÉCUTION : transaction unique, assertions à chaque étape ──────────

$results = [];
$pdo->beginTransaction();
try {
    // 1. Lot ouvert 25/09 supprimé → cascade : lot 19/09 réouvert.
    if (!ProductCost::delete(LOT_0925)) {
        throw new RuntimeException('Suppression lot 25/09 impossible — ROLLBACK.');
    }
    $l19 = ProductCost::find(LOT_0919);
    if ($l19 === null || $l19['valid_to'] !== null) {
        throw new RuntimeException('Le lot 19/09 devait être réouvert par cascade — ROLLBACK.');
    }

    // 2. Achat erroné + son lot (cascade contrôleur deletePurchaseLines).
    $deletedLots = ProductCost::deleteByPurchase(PURCHASE_BAD);
    if ($deletedLots !== 1) {
        throw new RuntimeException("deleteByPurchase a supprimé {$deletedLots} lot(s) (attendu 1) — ROLLBACK.");
    }
    if (!Purchase::delete(PURCHASE_BAD)) {
        throw new RuntimeException('Suppression achat erroné impossible — ROLLBACK.');
    }
    // no_stock = 0 : contre-passation du stock de référence (cascade).
    ProductStock::adjust(KEY, -24);
    assertEq('stock après contre-passation', '24', (string) (int) (ProductStock::get(KEY) ?? -1));

    // 3. Ré-saisie aux vrais montants facture (copie exacte, TTC fait foi).
    $newId = Purchase::create([
        'purchased_at' => '2026-09-19',
        'product_key'  => KEY,
        'quantity'     => 24,
        'total_ht'     => TRUE_HT,
        'vat_rate'     => 5.5,
        'total_ttc'    => TRUE_TTC,
        'no_stock'     => false,
        'supplier'     => 'metro',
        'notes'        => 'Mission Winter 30/10 : recyclage de la saisie erronée purchase_0df285c7637428e4ba19 '
            . '(24 u @1,647 HT, 39,520/41,694 — faux). Vrai montant facture Metro 24×1,230 = 29,520 HT / 31,144 TTC. '
            . 'Preuves : correction lot du 21/09 12:33 (audit aud_7e0bddf66e95edd25ca95c79 → 1,29765 = 1,230×1,055) '
            . 'et cours 25/09 @1,230.',
        'created_by'   => 'user_admin',
    ]);
    if ($newId === '') {
        throw new RuntimeException('Ré-saisie impossible — ROLLBACK.');
    }
    assertEq('stock après ré-saisie (+24)', '48', (string) (int) (ProductStock::get(KEY) ?? -1));
    $np = Purchase::find($newId);
    assertEq('ré-saisie date', '2026-09-19', substr((string) $np['purchased_at'], 0, 10));
    assertEq('ré-saisie qty', '24', (string) (int) $np['quantity']);
    assertEq('ré-saisie unit_cost', '1.230', (string) $np['unit_cost']);
    assertEq('ré-saisie HT', '29.520', (string) $np['total_ht']);
    assertEq('ré-saisie TTC', '31.144', (string) $np['total_ttc']);
    assertEq('ré-saisie vat', '5.50', (string) $np['vat_rate']);
    assertEq('ré-saisie no_stock', '0', (string) (int) $np['no_stock']);
    assertEq('ré-saisie supplier', 'metro', (string) $np['supplier']);

    // 4. Lot 19/09 recréé (coût TTC convention PurchaseSaver : round(TTC/qty,3)).
    $lot19New = ProductCost::create([
        'product_key' => KEY,
        'cost_price'  => $lotCost19,
        'valid_from'  => '2026-09-19',
        'supplier'    => 'metro',
        'purchase_id' => $newId,
        'notes'       => 'Mission Winter 30/10 : recréé à l\'identique (1,298 = round(31,144/24,3), valeur déjà '
            . 'correcte depuis la correction manuelle du 21/09) lors du recyclage de la saisie erronée.',
    ]);
    if ($lot19New === '') {
        throw new RuntimeException('Création lot 19/09 impossible — ROLLBACK.');
    }

    // 5. Lot 25/09 recréé à l'identique (referme le 19/09 au 24/09).
    $lot25New = ProductCost::create([
        'product_key' => KEY,
        'cost_price'  => $lotCost25,
        'valid_from'  => '2026-09-25',
        'supplier'    => 'metro',
        'purchase_id' => PURCHASE_0925,
        'notes'       => 'Mission Winter 30/10 : recréé à l\'identique (1,298 · 25/09→ouvert · lié à '
            . PURCHASE_0925 . ') — étape technique du recyclage (aucun primitif ne rouvre un lot).',
    ]);
    if ($lot25New === '') {
        throw new RuntimeException('Création lot 25/09 impossible — ROLLBACK.');
    }

    // ── Invariants après opération ─────────────────────────────────────
    assertOpenLots(1);
    $lots = ProductCost::forProduct(KEY);
    if (count($lots) !== 2) {
        throw new RuntimeException(count($lots) . ' lots Winter (attendu 2) — ROLLBACK.');
    }
    $l19b = ProductCost::find($lot19New);
    $l25b = ProductCost::find($lot25New);
    assertEq('lot 19/09 coût', '1.298', (string) $l19b['cost_price']);
    assertEq('lot 19/09 valid_from', '2026-09-19', substr((string) $l19b['valid_from'], 0, 10));
    assertEq('lot 19/09 valid_to', '2026-09-24', $l19b['valid_to'] === null ? '' : substr((string) $l19b['valid_to'], 0, 10));
    assertEq('lot 19/09 qty', '', $l19b['qty_purchased'] === null ? '' : (string) $l19b['qty_purchased']);
    assertEq('lot 19/09 purchase', $newId, (string) $l19b['purchase_id']);
    assertEq('lot 25/09 coût', '1.298', (string) $l25b['cost_price']);
    assertEq('lot 25/09 valid_from', '2026-09-25', substr((string) $l25b['valid_from'], 0, 10));
    assertEq('lot 25/09 valid_to', '', $l25b['valid_to'] === null ? '' : (string) $l25b['valid_to']);
    assertEq('lot 25/09 qty', '', $l25b['qty_purchased'] === null ? '' : (string) $l25b['qty_purchased']);
    assertEq('lot 25/09 purchase', PURCHASE_0925, (string) $l25b['purchase_id']);
    if (ProductCost::find(LOT_0919) !== null || ProductCost::find(LOT_0925) !== null) {
        throw new RuntimeException('Anciens lots encore présents — ROLLBACK.');
    }

    $afterW = winterState();
    // Ventes strictement identiques ; COGS strictement identique (as-of inchangé).
    if ($afterW['sales'] != $beforeW['sales']) {
        throw new RuntimeException(sprintf(
            'Ventes Winter modifiées : avant n=%d qty=%d CA=%.2f / après n=%d qty=%d CA=%.2f — ROLLBACK.',
            $beforeW['sales']['n'], $beforeW['sales']['qty'], $beforeW['sales']['ca'],
            $afterW['sales']['n'], $afterW['sales']['qty'], $afterW['sales']['ca']
        ));
    }
    assertNear('COGS Winter strictement inchangé', $beforeW['cogs'], $afterW['cogs'], 0.0);
    assertEq('stock de référence inchangé', '48', (string) (int) ($afterW['stock_ref'] ?? -1));
    assertEq('stock théorique inchangé', (string) $beforeW['theoretical'], (string) $afterW['theoretical']);
    if (count($afterW['purchases']) !== 2) {
        throw new RuntimeException('Winter doit avoir exactement 2 achats — ROLLBACK.');
    }

    $afterG = globalState();
    if ($afterG['sales'] != $beforeG['sales']) {
        throw new RuntimeException('Ventes globales modifiées — ROLLBACK.');
    }
    if ($afterG['purchases']['n'] !== $beforeG['purchases']['n']) {
        throw new RuntimeException('Nombre d\'achats global modifié — ROLLBACK.');
    }
    // Seule différence comptable, documentée : −10,000 HT / −10,550 TTC.
    assertNear('achats globaux HT (−10,000)', $beforeG['purchases']['ht'] - 10.000, $afterG['purchases']['ht'], 0.001);
    assertNear('achats globaux TTC (−10,550)', $beforeG['purchases']['ttc'] - 10.550, $afterG['purchases']['ttc'], 0.001);
    if ($afterG['keys_multi_open'] !== 0) {
        throw new RuntimeException('Une clé a plusieurs lots ouverts après opération — ROLLBACK.');
    }

    // Achat 25/09 strictement intact.
    $goodAfter = Purchase::find(PURCHASE_0925);
    assertEq('achat 25/09 HT intact', '59.040', (string) $goodAfter['total_ht']);
    assertEq('achat 25/09 TTC intact', '62.287', (string) $goodAfter['total_ttc']);

    $results = [
        'supprime' => ['purchase' => PURCHASE_BAD, 'lots' => [LOT_0919, LOT_0925]],
        'cree'     => [
            'purchase' => $newId,
            'lot_19'   => $lot19New,
            'lot_25'   => $lot25New,
        ],
    ];

    writeSnapshot('99_apres', ['winter' => $afterW, 'global' => $afterG]);
    AuditLog::log('winter.costs.recycle', 'user_admin', 'purchase', $newId, [
        'mission'   => 'WINTER coûts 30/10 (file d\'arbitrage dossier Redbull)',
        'anomalie'  => 'saisie erronée 19/09 : 24 u @1,647 HT au lieu de 1,230 HT (écart 34 % vs cours 25/09)',
        'preuves'   => [
            'audit_21_09' => 'aud_7e0bddf66e95edd25ca95c79 : compta.cost.update lot 19/09 → 1,29765 = 1,230×1,055 exact',
            'cours_25_09' => 'purchase_2db06da56138883de5d3 : Winter 48 u @1,230 HT, toutes autres lignes au prix identique du cours 19/09',
            'bande_famille' => 'Redbull 0,880–1,233 HT : 1,647 = outlier +34 % ; facture papier non numérisée (septembre)',
        ],
        'supprime'  => ['purchase' => PURCHASE_BAD, 'montants_faux' => ['ht' => '39.520', 'ttc' => '41.694'], 'lots' => [LOT_0919, LOT_0925]],
        'cree'      => [
            'purchase' => $newId,
            'montants_vrais' => ['ht' => '29.520', 'ttc' => '31.144', 'unit' => '1.230', 'qty' => 24],
            'lots' => [$lot19New => '1,298 · 19/09→24/09 · qty NULL', $lot25New => '1,298 · 25/09→ouvert · qty NULL · recréé à l\'identique'],
        ],
        'invariants' => [
            'ventes_ca' => 'strictement identiques (Winter + globaux)',
            'cogs'      => ['avant' => $beforeW['cogs'], 'apres' => $afterW['cogs'], 'identique' => true],
            'achats_globaux' => ['ht_delta' => -10.0, 'ttc_delta' => -10.55, 'voulu' => 'correction du montant faux'],
            'stock_ref' => '48 inchangé (contre-passation −24 puis +24)',
            'theorique' => $beforeW['theoretical'] . ' inchangé',
            'lots_ouverts' => '1 (périodes 19/09→24/09 + 25/09→NULL)',
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    fwrite(STDERR, "TRANSACTION ANNULÉE — base strictement inchangée. Rollback externe possible via {$latestBackup}.\n");
    exit(1);
}

StockPublic::invalidate();

echo "\n==============================================================\n";
echo "TRANSACTION COMMITÉE — recyclage Winter appliqué.\n";
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
echo "Snapshots : logs/winter_couts_20261030_00_avant.jsonl et _99_apres.jsonl\n";
echo "Cache public invalidé (StockPublic::invalidate).\n";
exit(0);

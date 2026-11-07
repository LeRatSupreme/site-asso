<?php

declare(strict_types=1);

/**
 * AEIC — One-shot : reclassement des ventes « cafe » à 0,70 € (allongé,
 * 2 dosettes) vers la clé canonique « cafe allonge ».
 *
 * Deux effets :
 *  1. renomme rétroactivement les ventes existantes via
 *     Sale::applyPriceVariants() (idempotent : ne touche que product_key
 *     et category, JAMAIS description) ;
 *  2. crée, si elle n'existe pas encore, un lot de coût de revient
 *     « cafe allonge » = 2 × le coût unitaire TTC en cours de « cafe »
 *     (les 2 dosettes de l'allongé), démarrant la veille de la plus
 *     ancienne vente renommée.
 *
 * Les imports futurs (CSV manuel + synchro API cron) appliquent la même
 * règle automatiquement : ce script ne sert qu'au rattrapage rétroactif
 * et à l'amorçage du lot de coût. Re-exécutable sans effet de bord.
 *
 * Usage : php scripts/apply_price_variants.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\PriceVariants;
use App\Core\Compta\StockPublic;
use App\Models\ProductCost;
use App\Models\Sale;

/** Clé de base dont le prix révèle la variante (règle café allongé). */
const BASE_KEY = 'cafe';

/** Clé canonique de la variante café allongé (2 dosettes). */
const VARIANT_KEY = 'cafe allonge';

/** Coût TTC par défaut de la variante si « cafe » n'a aucun lot (2 × 0,163). */
const DEFAULT_VARIANT_COST = 0.326;

/** Date de début du lot si aucune vente renommée n'est trouvée. */
const DEFAULT_VALID_FROM = '2026-01-01';

echo "— Variantes par prix : reclassement « " . BASE_KEY . " » → « " . VARIANT_KEY . " » —\n";

// ── 1. État avant : ventes encore non reclassées (même clause que
//       Sale::applyPriceVariants()).
$before = 0;
try {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM sales
         WHERE LOWER(COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description))) = ?
           AND ABS(price_ttc - ?) < 0.005
           AND is_custom_amount = 0"
    );
    foreach (PriceVariants::RULES as $rule) {
        $stmt->execute([$rule['key'], $rule['price']]);
        $before += (int) $stmt->fetchColumn();
    }
    echo 'Avant : ' . $before . " vente(s) « " . BASE_KEY . " » à 0,70 € encore non reclassée(s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR comptage avant : ' . $e->getMessage() . "\n");
    exit(1);
}

// ── 2. Reclassement rétroactif (idempotent, description intacte).
$renamed = Sale::applyPriceVariants();
echo 'Renommées : ' . $renamed . " vente(s) reclassée(s) vers « " . VARIANT_KEY . " »\n";

// ── 3. Lot de coût de la variante : uniquement si aucun lot n'existe
//       déjà (idempotence). Coût = 2 × le coût unitaire TTC en cours de
//       la clé de base (l'allongé consomme 2 dosettes), arrondi 3 déc.
$existingLots = ProductCost::forProduct(VARIANT_KEY);
if ($existingLots !== []) {
    echo 'Lot de coût : ' . count($existingLots) . " lot(s) « " . VARIANT_KEY . " » déjà présent(s) — rien à créer\n";
} else {
    $baseLot = ProductCost::current(BASE_KEY);
    $unitCost = $baseLot !== null ? (float) $baseLot['cost_price'] : null;
    $costPrice = $unitCost !== null ? round(2 * $unitCost, 3) : DEFAULT_VARIANT_COST;

    // Début de validité : veille de la plus ancienne vente de la variante
    // (elles sont toutes reclassées à ce stade), sinon date de repli.
    $validFrom = DEFAULT_VALID_FROM;
    try {
        $stmt = db()->prepare('SELECT MIN(sold_at) FROM sales WHERE product_key = ?');
        $stmt->execute([VARIANT_KEY]);
        $minSoldAt = (string) $stmt->fetchColumn();
        if ($minSoldAt !== '') {
            $validFrom = date('Y-m-d', strtotime('-1 day', (int) strtotime(substr($minSoldAt, 0, 10))));
        }
    } catch (Throwable) {
        // Repli sur la date par défaut si la lecture échoue.
    }

    $lotId = ProductCost::create([
        'product_key' => VARIANT_KEY,
        'cost_price'  => $costPrice,
        'valid_from'  => $validFrom,
        'supplier'    => 'café',
        'notes'       => 'Lot automatique : café allongé = 2 dosettes (reclassement par prix à l\'import).',
    ]);

    if ($lotId === '') {
        fwrite(STDERR, "ERREUR : impossible de créer le lot de coût « " . VARIANT_KEY . " »\n");
        exit(1);
    }

    echo 'Lot de coût créé : « ' . VARIANT_KEY . ' » à '
        . number_format($costPrice, 3, ',', ' ')
        . " € TTC (2 × " . ($unitCost !== null ? number_format($unitCost, 3, ',', ' ') . ' €' : 'défaut')
        . '), du ' . $validFrom . ", fournisseur « café » (lot manuel, sans achat lié)\n";
}

// ── 4. La ventilation CA/produit a changé : la carte publique suit.
StockPublic::invalidate();

// ── 5. Récapitulatif.
echo "Terminé : journal des ventes, bénéfices par produit et stock public à jour.\n";
exit(0);

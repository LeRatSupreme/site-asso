<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Core\Compta\CashBreakdown;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\CashMovementBreakdown;
use App\Models\Sale;

/**
 * Livre de caisse : solde théorique du liquide et enregistrement des
 * événements manuels (dépôts banque, comptages physiques, fond de caisse).
 *
 * Principe :
 *   solde théorique = Σ ventes LIQUIDE (table `sales`, calculé en direct)
 *                   + Σ cash_movements.amount (FOND + DEPOT + AJUSTEMENT).
 *
 * Les ventes liquide ne sont jamais dupliquées dans le livre : importés
 * par CSV ou par la synchro API, elles alimentent automatiquement la
 * caisse, sans double comptabilisation possible.
 *
 * Un comptage physique (recordCount) fige le théorique du moment et, si
 * un écart est constaté, crée un mouvement AJUSTEMENT signé qui réaligne
 * le théorique sur le compté : l'écart reste visible dans l'historique
 * (détection de vol) sans casser la cohérence du solde.
 */
final class CashLedger
{
    /**
     * Frais bancaires fixes prélevés par la banque sur CHAQUE dépôt
     * d'espèces (uniquement les dépôts — jamais sur le fond de caisse
     * ni les comptages). Le solde de la CAISSE n'est pas affecté : les
     * frais sont pris sur le compte banque ; ils sont comptabilisés
     * automatiquement en dépense catégorie « FRAIS » (voir
     * AdminCashController::deposit).
     */
    public const DEPOSIT_FEE = 3.50;

    /**
     * Solde théorique actuel de la caisse.
     */
    public static function balance(): float
    {
        return round(CashMovement::sum() + Sale::sumByPaymentMethod('LIQUIDE'), 2);
    }

    /**
     * Total des ventes en liquide (part « ventes » du solde).
     */
    public static function salesTotal(): float
    {
        return round(Sale::sumByPaymentMethod('LIQUIDE'), 2);
    }

    /**
     * Total des ventes par carte (hors caisse : jamais encaissées en liquide).
     */
    public static function cardSalesTotal(): float
    {
        return round(Sale::sumByPaymentMethod('CARTE'), 2);
    }

    /**
     * Enregistre un dépôt à la banque (sortie de liquide).
     *
     * Le détail par coupures est OPTIONNEL (rétro-compatibilité des
     * dépôts sans détail) ; s'il est fourni non vide, il doit retomber
     * EXACTEMENT sur le montant : Σ(unit×qty) = amount×100, sinon
     * l'appel est refusé AVANT toute écriture.
     *
     * @return string Id du mouvement créé.
     */
    public static function recordDeposit(
        float $amount,
        string $label,
        ?string $createdBy,
        ?string $createdAt = null,
        ?CashBreakdown $breakdown = null
    ): string {
        if ($breakdown !== null && !$breakdown->isEmpty() && !$breakdown->matchesAmount($amount)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Détail des coupures (%s = %s) incohérent avec le montant saisi (%s).',
                    $breakdown->format(),
                    formatPrice($breakdown->amount()),
                    formatPrice($amount)
                )
            );
        }

        $id = CashMovement::add([
            'type'       => CashMovement::TYPE_DEPOT,
            'amount'     => -round($amount, 2),
            'label'      => $label,
            'created_by' => $createdBy,
            'created_at' => $createdAt,
        ]);
        CashMovementBreakdown::insertFor($id, $breakdown);

        return $id;
    }

    /**
     * Enregistre une PRISE « chez soi » : transfert SIGNÉ caisse <->
     * chez soi, détaillé par coupures (OBLIGATOIRES : le détail est le
     * cœur de la traçabilité du liquide).
     *
     *   prise  (défaut) : PRISE négatif — l'argent sort du tiroir et
     *                     dort chez le membre signataire ;
     *   retour ($return): PRISE positif — l'argent est ramené en caisse
     *                     (« Ramené de chez soi »).
     *
     * Le montant n'est PAS saisi séparément : il est DÉDUIT des
     * coupures (Σ unit×qty, en centimes entiers) — l'invariant
     * ABS(amount) = Σ(unit×qty)/100 est garanti par construction.
     * Mouvement + lignes de coupures créés dans UNE transaction (une
     * prise sans son détail, ou l'inverse, ne doit jamais exister).
     *
     * @return string Id du mouvement créé.
     */
    public static function recordTake(
        CashBreakdown $breakdown,
        string $label,
        ?string $createdBy,
        ?string $createdAt = null,
        bool $return = false
    ): string {
        if ($breakdown->isEmpty()) {
            throw new \InvalidArgumentException('Une prise « chez soi » exige au moins une coupure (détail obligatoire).');
        }

        $total = $breakdown->amount();

        $pdo = CashCount::connection();
        $outer = $pdo->inTransaction();
        if (!$outer) {
            $pdo->beginTransaction();
        }
        try {
            $id = CashMovement::add([
                'type'       => CashMovement::TYPE_PRISE,
                'amount'     => $return ? $total : -$total,
                'label'      => $label,
                'created_by' => $createdBy,
                'created_at' => $createdAt,
            ]);
            CashMovementBreakdown::insertFor($id, $breakdown);
            if (!$outer) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$outer && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /**
     * Enregistre le fond de caisse (entrée de liquide initial / remise).
     *
     * @return string Id du mouvement créé.
     */
    public static function recordFund(float $amount, string $label, ?string $createdBy, ?string $createdAt = null): string
    {
        return CashMovement::add([
            'type'       => CashMovement::TYPE_FOND,
            'amount'     => round($amount, 2),
            'label'      => $label,
            'created_by' => $createdBy,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Enregistre un comptage physique et réaligne le théorique.
     *
     * @return array{count_id:string, ecart:float, adjustment_id:?string}
     *         ecart = compté − théorique (négatif = manquant).
     */
    public static function recordCount(float $counted, string $label, ?string $createdBy, ?string $createdAt = null): array
    {
        $theoretical = self::balance();

        $pdo = CashCount::connection();
        $pdo->beginTransaction();
        try {
            $res = CashCount::add([
                'counted'      => $counted,
                'theoretical'  => $theoretical,
                'label'        => $label,
                'created_by'   => $createdBy,
                'created_at'   => $createdAt,
            ]);

            $adjustmentId = null;
            if ($res['ecart'] !== 0.0) {
                $adjustmentId = CashMovement::add([
                    'type'       => CashMovement::TYPE_AJUSTEMENT,
                    'amount'     => $res['ecart'],
                    'label'      => $label !== '' ? 'Comptage — ' . $label : 'Comptage physique',
                    'created_by' => $createdBy,
                    'created_at' => $createdAt,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return ['count_id' => $res['id'], 'ecart' => $res['ecart'], 'adjustment_id' => $adjustmentId];
    }
}

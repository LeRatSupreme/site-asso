<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\CashCount;
use App\Models\CashMovement;
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
     * @return string Id du mouvement créé.
     */
    public static function recordDeposit(float $amount, string $label, ?string $createdBy, ?string $createdAt = null): string
    {
        return CashMovement::add([
            'type'       => CashMovement::TYPE_DEPOT,
            'amount'     => -round($amount, 2),
            'label'      => $label,
            'created_by' => $createdBy,
            'created_at' => $createdAt,
        ]);
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

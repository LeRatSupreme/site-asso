<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

require_once __DIR__ . '/../db_test_bridge.php';

use App\Core\Compta\Dashboard\Data;
use App\Models\Model;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Unit\TestDatabaseTrait;

/**
 * Facteur CALENDRIER UNIVERITAIRE de Data::analyseReappro (mission E2) :
 * les quantités suggérées (need, to_order, to_order_raw) — et elles
 * SEULES — sont modulées par le facteur injecté :
 *  - facteur 0 (semaine 100 % morte) : plus AUCUNE commande, plus aucune
 *    alerte « reorder » (le bruit de vacances ne réclame pas de commande) ;
 *  - facteur partiel : besoin au prorata, moyennes observées/autonomie
 *    et coûts unitaires INTACTS (order_cost = qté × coût inchangé) ;
 *  - facteur 1.0 (semaine de cours / hors horizon prudent) : strictement
 *    identique au comportement historique.
 *
 * Base `aeic_test` requise (sinon saut) : mêmes conventions que
 * CostLotStockDbTest (PDO injecté + pont db()).
 */
final class ReapproCalendarModulationTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible.');

            return;
        }
        foreach (['sales', 'import_batches', 'inventory_counts', 'product_costs', 'product_packs', 'product_discontinued', 'product_infinite', 'purchases', 'losses', 'product_stocks', 'product_aliases'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return;
            }
        }
        $this->reset($pdo, ['sales', 'import_batches', 'inventory_counts', 'product_costs', 'product_packs', 'product_discontinued', 'product_infinite', 'purchases', 'losses', 'product_stocks', 'product_aliases']);
        Model::setTestPdo($pdo);
        db_test_bridge_enable();

        // 30 ventes de « cola » sur la période d'analyse (3/j × 10 jours
        // espacés) : rythme 30/20 j = 1,5/j → besoin 7 j = ceil(10,5) = 11.
        for ($i = 0; $i < 10; $i++) {
            $jour = sprintf('2026-10-%02d', 5 + 2 * $i);
            for ($j = 1; $j <= 3; $j++) {
                $pdo->prepare(
                    'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
                     VALUES (?,?,?,?,?,1,?,1.50,0,?)'
                )->execute(['cal_' . $i . '_' . $j, 'TCAL' . $i . $j, $jour . ' 12:00:00', 'CARTE', 'Visa - Débit', 'cola', 'Boissons']);
            }
        }
        // Comptage « cola » = 2 APRÈS la dernière vente (théorique = 2,
        // aucune vente postérieure ne vient le réduire) + coût 1,00 €.
        $pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute(['cal_cnt', '2026-10-24 18:00:00', 'cola', 2, 2, 'test']);
        $pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, qty_purchased, valid_from)
             VALUES (?, ?, 1.00, 24, ?)'
        )->execute(['cal_cost', 'cola', '2026-10-01']);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        db_test_bridge_disable();
    }

    /**
     * Ligne « cola » de l'analyse pour un facteur donné.
     *
     * @return array<string,mixed>
     */
    private function ligneCola(array $analyse): array
    {
        foreach ($analyse['rows'] as $row) {
            if (($row['name'] ?? '') === 'cola') {
                return $row;
            }
        }
        self::fail('« cola » absent de l\'analyse réappro.');

        return [];
    }

    /** Facteur 1.0 (semaine de cours / politique prudente) : comportement historique exact. */
    public function test_facteur_1_comportement_historique(): void
    {
        $a = Data::analyseReappro(7, '2026-10-05', '2026-10-24', 20, ['facteur' => 1.0, 'explication' => null]);
        $l = $this->ligneCola($a);

        self::assertSame(1.5, $l['avg_day'], 'Rythme observé : 30 ventes / 20 jours calendaires.');
        self::assertSame(11, $l['need'], 'Besoin brut : ceil(1,5/j × 7 j) = 11.');
        self::assertSame(9, $l['to_order'], 'Besoin 11 − stock théorique 2 = 9.');
        self::assertSame('reorder', $l['state']);
        self::assertTrue($l['is_alert']);
        self::assertGreaterThan(0, $a['alerts']);
        self::assertSame(1.0, $a['calendrier']['facteur']);
    }

    /** Semaine 100 % morte (facteur 0) : aucune commande, aucune alerte, faits observés intacts. */
    public function test_facteur_0_annule_suggestions_et_alertes(): void
    {
        $ref = Data::analyseReappro(7, '2026-10-05', '2026-10-24', 20, ['facteur' => 1.0, 'explication' => null]);
        $mort = Data::analyseReappro(7, '2026-10-05', '2026-10-24', 20, [
            'facteur'     => 0.0,
            'explication' => 'Semaine sans cours du 26/10 au 30/10 : suggestions annulées',
        ]);

        $lRef = $this->ligneCola($ref);
        $l = $this->ligneCola($mort);

        self::assertSame(0, $l['need'], 'Besoin annulé.');
        self::assertSame(0, $l['to_order_raw'], 'Commande brute annulée (pas même de reconstitution).');
        self::assertSame(0, $l['to_order'], 'Commande annulée.');
        self::assertSame('ok', $l['state'], 'Plus d\'état « reorder » pendant une semaine morte.');
        self::assertFalse($l['is_alert']);
        self::assertSame(0, $mort['alerts'], 'Aucune alerte réappro pendant la semaine morte.');

        // Faits observés et coûts unitaires : INTACTS.
        self::assertSame($lRef['avg_day'], $l['avg_day'], 'Rythme observé inchangé.');
        self::assertSame($lRef['autonomy'], $l['autonomy'], 'Autonomie (fait) inchangée.');
        self::assertSame($lRef['unit_cost'], $l['unit_cost'], 'Coût unitaire JAMAIS modulé.');
        self::assertSame($lRef['stock'], $l['stock']);
        self::assertSame(0.0, $mort['calendrier']['facteur']);
        self::assertSame('Semaine sans cours du 26/10 au 30/10 : suggestions annulées', $mort['calendrier']['explication']);
    }

    /** Semaine partielle (facteur 0,6) : besoin au prorata, moyenne et coûts intacts, alerte conservée. */
    public function test_facteur_partiel_prorata_du_besoin(): void
    {
        $a = Data::analyseReappro(7, '2026-10-05', '2026-10-24', 20, [
            'facteur'     => 0.6,
            'explication' => 'Semaine partielle du 02/11 au 06/11 (3 jours de cours sur 5) : suggestions réduites ×0,6',
        ]);
        $l = $this->ligneCola($a);

        // Besoin = ceil(1,5/j × 7 j × 0,6) = ceil(6,3) = 7 → commande 7 − 2 = 5.
        self::assertSame(7, $l['need'], 'Besoin au prorata des jours de cours.');
        self::assertSame(5, $l['to_order']);
        self::assertSame('reorder', $l['state'], 'Alerte conservée : le stock reste bas, la commande est juste plus petite.');
        self::assertTrue($l['is_alert']);
        self::assertSame(1.5, $l['avg_day'], 'Moyenne observée à l\'échelle pleine.');
        self::assertEqualsWithDelta(5.0, (float) $l['order_cost'], 0.001, 'order_cost = qté × coût unitaire inchangé (suivant la quantité, pas une modulation du coût).');
    }

    /** Sans injection : le facteur est calculé (ici, en APP_TESTING, neutre 1.0) et exposé. */
    public function test_sans_injection_facteur_calcule_expose(): void
    {
        if (!(\defined('APP_TESTING') && \constant('APP_TESTING'))) {
            self::markTestSkipped('APP_TESTING non actif.');
        }
        $a = Data::analyseReappro(7, '2026-10-05', '2026-10-24', 20);

        self::assertSame(1.0, $a['calendrier']['facteur'], 'Calendrier vide hors-ligne en test → prudent, aucune modulation.');
        self::assertArrayHasKey('calendrier', $a);
    }
}

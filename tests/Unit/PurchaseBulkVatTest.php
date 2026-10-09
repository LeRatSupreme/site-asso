<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminStockController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests de la résolution du taux de TVA par ligne du POST en lot
 * (AdminStockController::savePurchasesBulk) — méthode pure lineVatRaw(),
 * aucun accès base : factures à TVA mixte (ex. METRO boissons 5,5 % +
 * droguerie 20 %) via le tableau vat_rate[] aligné sur product_key[],
 * et rétrocompatibilité du taux d'en-tête unique (page Achats).
 */
final class PurchaseBulkVatTest extends TestCase
{
    /**
     * Appelle lineVatRaw() (protégée, statique, pure) par réflexion.
     */
    private static function call(?array $lineRates, int $index, string $headerRaw): string
    {
        $method = new ReflectionMethod(AdminStockController::class, 'lineVatRaw');
        $method->setAccessible(true);

        $result = $method->invoke(null, $lineRates, $index, $headerRaw);
        self::assertIsString($result);

        return $result;
    }

    /**
     * Sans tableau vat_rate[] (page Achats actuelle) : le taux d'en-tête
     * s'applique à toutes les lignes, quel que soit l'indice.
     */
    public function test_sans_tableau_taux_en_tete_partout(): void
    {
        self::assertSame('5.5', self::call(null, 0, '5.5'));
        self::assertSame('5.5', self::call(null, 3, '5.5'));
        self::assertSame('', self::call(null, 0, ''), 'En-tête vide : « déjà TTC » (comportement historique).');
    }

    /**
     * Avec tableau vat_rate[] : chaque ligne utilise SON taux.
     */
    public function test_tableau_chaque_ligne_son_taux(): void
    {
        $rates = ['5.5', '20', '0'];
        self::assertSame('5.5', self::call($rates, 0, ''));
        self::assertSame('20', self::call($rates, 1, ''));
        self::assertSame('0', self::call($rates, 2, ''));
    }

    /**
     * Case vide du tableau : retombe sur le taux d'en-tête ; en-tête
     * vide aussi (tableau posté seul) : '' = montant « déjà TTC ».
     */
    public function test_case_vide_retombe_sur_en_tete(): void
    {
        self::assertSame('5.5', self::call(['5.5', '', '20'], 1, '5.5'));
        self::assertSame('', self::call(['5.5', '', '20'], 1, ''), 'Tableau seul, en-tête vide : « déjà TTC ».');
    }

    /**
     * Tableau plus court que product_key[] (ou indice absent) : les
     * lignes au-delà retombent sur le taux d'en-tête.
     */
    public function test_tableau_plus_court_que_les_lignes(): void
    {
        self::assertSame('20', self::call(['5.5'], 1, '20'));
        self::assertSame('', self::call([], 0, ''));
    }

    /**
     * Les valeurs du tableau sont trimées (saisie « 5,5 » avec espace…)
     * avant d'être passées à createOne() qui valide ∈ VAT_RATES.
     */
    public function test_valeurs_trimees(): void
    {
        self::assertSame('5.5', self::call([' 5.5 '], 0, ''));
        self::assertSame('20', self::call(['   '], 0, '20'), 'Case ne contenant que des espaces : traitée comme vide (taux d’en-tête).');
    }
}

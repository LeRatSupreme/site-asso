<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\PurchaseSaver;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de PurchaseSaver (création d'achats en lot, service partagé
 * par la page Achats admin et le livre comptable kiosque) : uniquement
 * les chemins qui N'ATTEIGNENT PAS la base — grille sans aucune ligne
 * renseignée (le service ignore les lignes vides sans bruit et rend
 * « Aucun achat saisi. » AVANT tout effet de bord), avec les deux modes
 * de TVA. Les effets base (créations réelles, lots de coût, synchro
 * carte) restent couverts par les tests compta skippés sans DB.
 */
final class PurchaseSaverTest extends TestCase
{
    /**
     * Grille intégralement vide (POST page Achats sans saisie) : rien
     * n'est créé, aucune erreur, message exact — aucun accès DB (le
     * service rend ce résultat avant toute création/synchro).
     */
    public function test_grille_vide_aucun_achat_saisi(): void
    {
        $res = PurchaseSaver::save([
            'purchased_at'   => '2026-10-09',
            'supplier'       => 'METRO',
            'invoice_number' => '3007 02 0090',
            'amount_basis'   => 'ht',
            'update_cost'    => true,
            'vat_raw'        => '5.5',
            'vat_per_line'   => false,
        ], [
            ['key' => '', 'qty' => '', 'total_raw' => '', 'vat_raw' => '', 'no_stock' => false],
            ['key' => '   ', 'qty' => '2', 'total_raw' => '   ', 'vat_raw' => '', 'no_stock' => false],
        ]);

        self::assertSame(0, $res['inserted']);
        self::assertSame([], $res['errors']);
        self::assertSame('Aucun achat saisi.', $res['message']);
        self::assertSame(0, $res['products']);
        self::assertSame(0, $res['no_stock']);
    }

    /**
     * Aucune ligne du tout (POST dégénéré, ex. kiosque sans tableau) :
     * même verdict que la grille vide.
     */
    public function test_aucune_ligne(): void
    {
        $res = PurchaseSaver::save(['vat_raw' => '', 'vat_per_line' => false], []);

        self::assertSame(0, $res['inserted']);
        self::assertSame([], $res['errors']);
        self::assertSame('Aucun achat saisi.', $res['message']);
        self::assertSame(0, $res['products']);
        self::assertSame(0, $res['no_stock']);
    }

    /**
     * Le mode TVA par ligne (vat_per_line) ne change rien sur une grille
     * vide : les lignes ignorées ne sont jamais validées.
     */
    public function test_grille_vide_en_mode_tva_par_ligne(): void
    {
        $res = PurchaseSaver::save([
            'vat_raw'      => '',
            'vat_per_line' => true,
        ], [
            ['key' => '', 'qty' => '3', 'total_raw' => '', 'vat_raw' => '20', 'no_stock' => true],
        ]);

        self::assertSame(0, $res['inserted']);
        self::assertSame([], $res['errors']);
        self::assertSame('Aucun achat saisi.', $res['message']);
    }

    /**
     * Ligne ne portant QU'UNE clé sans montant (key renseignée,
     * total vide) : PAS « totalement vide » — elle part en validation et
     * échoue (« montant invalide ») sans toucher la base (le refus
     * précède toute création). Même motif que le contrôleur historique.
     */
    public function test_ligne_nom_seul_montant_manquant_rejetee(): void
    {
        $res = PurchaseSaver::save(['vat_raw' => '5.5', 'vat_per_line' => false], [
            ['key' => 'Coca 33cl', 'qty' => '2', 'total_raw' => '', 'vat_raw' => '', 'no_stock' => false],
        ]);

        self::assertSame(0, $res['inserted']);
        self::assertSame(['Coca 33cl : montant invalide'], $res['errors']);
        self::assertSame('Aucun achat enregistré — Coca 33cl : montant invalide', $res['message']);
    }

    /**
     * Quantité invalide (0 ou non entière) : rejetée sans DB, motif
     * exact ; une ligne totalement vide à côté reste silencieuse.
     */
    public function test_ligne_quantite_invalide_rejetee(): void
    {
        $res = PurchaseSaver::save(['vat_raw' => '', 'vat_per_line' => false], [
            ['key' => '', 'qty' => '', 'total_raw' => '', 'vat_raw' => '', 'no_stock' => false],
            ['key' => 'Eau', 'qty' => '0', 'total_raw' => '4,00', 'vat_raw' => '', 'no_stock' => false],
        ]);

        self::assertSame(0, $res['inserted']);
        self::assertSame(['Eau : quantité invalide'], $res['errors']);
        self::assertSame('Aucun achat enregistré — Eau : quantité invalide', $res['message']);
    }

    /**
     * Clé absente mais montant saisi : motif « (sans nom) : produit
     * manquant » (même libellé que le contrôleur historique).
     */
    public function test_ligne_sans_nom_rejetee(): void
    {
        $res = PurchaseSaver::save(['vat_raw' => '', 'vat_per_line' => false], [
            ['key' => '', 'qty' => '2', 'total_raw' => '5,00', 'vat_raw' => '', 'no_stock' => false],
        ]);

        self::assertSame(0, $res['inserted']);
        self::assertSame(['(sans nom) : produit manquant'], $res['errors']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\SumUpCsvParser;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration de l'anti-doublon à l'import des ventes SumUp.
 *
 * Valide que réimporter deux fois le même CSV n'insère aucune ligne en double
 * (la clé unique MySQL (transaction_ref, sold_at, description, quantity,
 * price_ttc) garantit la déduplication via INSERT IGNORE).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class SaleDedupTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        // S'assure que les tables existent (le schéma doit avoir été importé
        // dans aeic_test au préalable).
        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Table sales absente de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, ['sale_adjustments', 'sales', 'import_batches', 'product_aliases']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_reimport_du_meme_csv_n_ajoute_aucune_ligne(): void
    {
        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte"
            . "\n1 juin 2026 09:59,Vente,TAAA3ERTQAP,Visa - Débit,1,Bueno_white,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 10:01,Vente,TAAA3ERUA7D,Mastercard - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 11:27,Vente,TAAA3ESPYBY,Visa - Débit,1,Montant personnalisé,,,EUR,1,0,1,1,0,,Alex";

        $parser = new SumUpCsvParser();
        $parsed = $parser->parse($csv, [ProductAlias::class, 'resolve']);

        // 1er import : 3 lignes insérées.
        $batchId1 = ImportBatch::create(['filename' => 'test.csv']);
        $r1 = Sale::importBatch($batchId1, $parsed['rows']);

        self::assertSame(3, $r1['inserted']);
        self::assertSame(0, $r1['skipped']);
        self::assertSame(3, Sale::count());

        // 2e import du même fichier : 0 insertion, 3 ignorées (doublons).
        $batchId2 = ImportBatch::create(['filename' => 'test.csv']);
        $r2 = Sale::importBatch($batchId2, $parsed['rows']);

        self::assertSame(0, $r2['inserted']);
        self::assertSame(3, $r2['skipped']);
        self::assertSame(3, Sale::count(), 'Aucune ligne ne doit être dupliquée.');
    }

    public function test_lignes_distinctes_d_une_meme_transaction(): void
    {
        // Une transaction peut contenir plusieurs lignes (produits) distinctes :
        // elles doivent toutes être importées (la clé unique intègre description).
        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte"
            . "\n1 juin 2026 10:27,Vente,TAAA3ER3HE9,Visa - Débit,1,Bonbon,Nourriture,,EUR,\"0,5\",0,\"0,5\",\"0,5\",0,,Alex"
            . "\n1 juin 2026 10:27,Vente,TAAA3ER3HE9,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex";

        $parsed = (new SumUpCsvParser())->parse($csv, [ProductAlias::class, 'resolve']);
        $batchId = ImportBatch::create(['filename' => 'multi.csv']);
        $r = Sale::importBatch($batchId, $parsed['rows']);

        self::assertSame(2, $r['inserted']);
        self::assertSame(2, Sale::count());
    }
}

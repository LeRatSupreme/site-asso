<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnalyticsForecastData;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Couche d'extraction du moteur de prévision (AnalyticsForecastData) :
 * flux courant par jour (refs distincts, horaires, paniers), stats produits
 * (médiane, mode, coût as-of aujourd'hui, stock/infini) et audit
 * (ventes à 0 €, doublons d'import ignorés, coûts suspects).
 *
 * Grille de référence : 2026-10-12 (lundi) → 2026-10-15 (jeudi).
 * Le 14 n'a QUE des lignes exclues (Événement, montant libre, 0 €) ;
 * le 15 n'a aucune vente : aucun des deux ne doit sortir du flux.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class AnalyticsForecastDataTest extends TestCase
{
    use TestDatabaseTrait;

    private const FROM = '2026-10-12';
    private const TO = '2026-10-15';

    private ?PDO $pdo = null;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test du moteur de prévision ignoré.');

            return false;
        }

        try {
            $pdo->query('SELECT 1 FROM `sales` LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table `sales` absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->reset($pdo, ['sales', 'import_batches']);
        $this->applyImportDedupMigration($pdo);
        $this->ensureAuxTables($pdo);
        Model::setTestPdo($pdo);
        $this->pdo = $pdo;

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    /**
     * Tables annexes (coûts, stocks, produits infinis) : création best effort
     * si absentes de la base de test, puis purge pour un état propre.
     */
    private function ensureAuxTables(PDO $pdo): void
    {
        $ddl = [
            "CREATE TABLE IF NOT EXISTS product_costs (
                id VARCHAR(255) NOT NULL PRIMARY KEY,
                product_key VARCHAR(255) NOT NULL,
                cost_price DECIMAL(10,3) NOT NULL,
                valid_from DATE NOT NULL,
                valid_to DATE NULL,
                supplier VARCHAR(255) NULL,
                notes TEXT NULL,
                purchase_id VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_costs_product (product_key),
                KEY idx_costs_period (valid_from, valid_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS product_stocks (
                product_key VARCHAR(255) NOT NULL PRIMARY KEY,
                stock INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS product_infinite (
                product_key VARCHAR(255) NOT NULL PRIMARY KEY,
                updated_by VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($ddl as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException) {
                // Déjà présente : non bloquant.
            }
        }
        $this->reset($pdo, ['product_costs', 'product_stocks', 'product_infinite']);
    }

    /** Ligne de vente au format importBatch (cf. AnalyticsTrendTest). */
    private function saleRow(string $ref, string $soldAt, string $description, int $qty, ?string $productKey = null, array $override = []): array
    {
        return array_merge([
            'transaction_ref'  => $ref,
            'sold_at'          => $soldAt,
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
            'quantity'         => $qty,
            'description'      => $description,
            'product_key'      => $productKey,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 2.5 * $qty,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ], $override);
    }

    private function importSales(array $rows): string
    {
        $batchId = ImportBatch::create([
            'filename'     => 'analytics-forecast-test.csv',
            'period_start' => self::FROM,
            'period_end'   => self::TO,
        ]);
        Sale::importBatch($batchId, $rows);

        return $batchId;
    }

    /** Coûts : couvrant (Coca 0,60), coût nul (Café 0), prix<coût (Bières 12), futur seul (Snacks 3 en 2027). */
    private function seedCoutsEtStocks(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from, valid_to) VALUES (?,?,?,?,?)'
        );
        $insert->execute(['pc1', 'coca', 0.6, '2026-01-01', null]);
        $insert->execute(['pc2', 'Café', 0.0, '2026-01-01', null]);
        $insert->execute(['pc3', 'Bières', 12.0, '2026-01-01', '2026-06-30']);
        $insert->execute(['pc4', 'Bières', 12.0, '2027-01-01', null]);
        $insert->execute(['pc5', 'Snacks', 3.0, '2027-01-01', null]);

        $this->pdo->exec("INSERT INTO product_stocks (product_key, stock) VALUES ('coca', 10), ('Snacks', 3)");
        $this->pdo->exec("INSERT INTO product_infinite (product_key) VALUES ('Bières')");
    }

    /**
     * Jeu de ventes :
     * - lun. 12/10 : fd1 = 2 lignes (Café ×2, Bières ×4), fd2 = Coca-cola ×2
     *   (clé « coca »), fd4 = Snacks ×1 (coût uniquement futur) → 3 refs, 22,50 €.
     * - mar. 13/10 : fd3 = Café ×2 à 18h30 → 1 ref, 5 €.
     * - mer. 14/10 : UNIQUEMENT des lignes exclues : Événement, montant libre,
     *   prix 0 € → jour fermé pour le flux courant.
     * - jeu. 15/10 : aucune vente → absent.
     */
    private function seedDonnees(): void
    {
        $this->importSales([
            // Jour 1 : une transaction à 2 lignes + 2 transactions simples.
            $this->saleRow('fd1', '2026-10-12 10:15:00', 'Café', 2),
            $this->saleRow('fd1', '2026-10-12 10:16:00', 'Bières', 4),
            $this->saleRow('fd2', '2026-10-12 11:00:00', 'Coca-cola', 2, 'coca'),
            $this->saleRow('fd4', '2026-10-12 12:00:00', 'Snacks', 1),
            // Jour 2 : une transaction d'une ligne, le soir.
            $this->saleRow('fd3', '2026-10-13 18:30:00', 'Café', 2),
            // Jour 3 : exclus du flux courant.
            $this->saleRow('fde1', '2026-10-14 20:00:00', 'Entrée soirée', 1, null, ['category' => 'Événement', 'price_ttc' => 10.0]),
            $this->saleRow('fdc1', '2026-10-14 20:05:00', 'Don libre', 1, null, ['is_custom_amount' => 1, 'price_ttc' => 7.0]),
            $this->saleRow('fdz1', '2026-10-14 20:10:00', 'Test gratuit', 1, null, ['price_ttc' => 0.0]),
        ]);
    }

    public function test_current_flow_days_jours_ouverts_refs_distincts_et_paniers(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedDonnees();

        $days = AnalyticsForecastData::currentFlowDays(self::FROM, self::TO);

        // Les jours 14 (uniquement exclus) et 15 (sans vente) n'existent pas.
        self::assertSame(['2026-10-12', '2026-10-13'], array_keys($days));
        self::assertArrayNotHasKey('2026-10-14', $days);
        self::assertArrayNotHasKey('2026-10-15', $days);

        // Jour 1 : 3 refs distincts (pas 4 lignes), CA 22,50 €.
        $d1 = $days['2026-10-12'];
        self::assertSame(3, $d1->n, 'n = refs distincts, pas de lignes.');
        self::assertSame(22.5, $d1->ca);
        self::assertCount(3, $d1->baskets, 'Un montant par transaction.');
        self::assertSame(15.0, array_sum($d1->baskets) - 5.0 - 2.5, 'fd1 = 5 + 10 = 15 €.');
        self::assertSame([10 => 1, 11 => 1, 12 => 1], $d1->hourlyN);

        // Jour 2 : 1 ref, panier unique de 5 €, tranche 18h.
        $d2 = $days['2026-10-13'];
        self::assertSame(1, $d2->n);
        self::assertSame(5.0, $d2->ca);
        self::assertSame([5.0], $d2->baskets);
        self::assertSame([18 => 1], $d2->hourlyN);
    }

    public function test_product_stats_exclusions_mediane_couts_et_stocks(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedDonnees();
        $this->seedCoutsEtStocks();

        $stats = AnalyticsForecastData::productStats(self::FROM, self::TO);

        $byKey = [];
        foreach ($stats as $p) {
            $byKey[$p->key] = $p;
        }

        // Seulement les 4 produits du flux courant (exclus : Événement,
        // montant libre, ligne à 0 €).
        $keys = array_keys($byKey);
        sort($keys);
        self::assertSame(['Bières', 'Café', 'Snacks', 'coca'], $keys);

        // Café : 4 unités (pondérées, jours tous normaux), 10 €, médiane
        // 2,5 €, coût 0 → suspect, stock inconnu.
        $cafe = $byKey['Café'];
        self::assertSame('Café', $cafe->label);
        self::assertSame('Non classé', $cafe->category);
        self::assertSame(4.0, $cafe->qty);
        self::assertSame(10.0, $cafe->ca);
        self::assertSame(2.5, $cafe->unitPrice);
        self::assertSame(0.0, $cafe->unitCost);
        self::assertTrue($cafe->costSuspect, 'Coût nul = suspect.');
        self::assertNull($cafe->stock, 'Stock non saisi = inconnu.');

        // Coca-cola : clé canonique, coût 0,60 < prix 2,50 → non suspect,
        // stock référencé = 10.
        $coca = $byKey['coca'];
        self::assertSame(2.0, $coca->qty);
        self::assertSame(5.0, $coca->ca);
        self::assertSame(0.6, $coca->unitCost);
        self::assertFalse($coca->costSuspect);
        self::assertSame(10, $coca->stock);

        // Bières : lot couvrant terminé en 2026-06, retour au lot 12 € du
        // 2026-01 (dernier antérieur) ; prix 2,50 < 12 → suspect ;
        // stock infini → null.
        $bieres = $byKey['Bières'];
        self::assertSame(12.0, $bieres->unitCost);
        self::assertTrue($bieres->costSuspect, 'Prix unitaire < coût = suspect.');
        self::assertNull($bieres->stock, 'Produit « stock infini » = null.');

        // Snacks : uniquement un lot FUTUR → coût inconnu (null) = suspect
        // (contrat ProductStat : coût null → signalé, à compléter ; cohérent
        // avec test_dataset : sans lots de coûts, TOUS les produits suspects).
        $snacks = $byKey['Snacks'];
        self::assertNull($snacks->unitCost);
        self::assertTrue($snacks->costSuspect, 'Coût inconnu = suspect (à compléter).');
        self::assertSame(3, $snacks->stock);
    }

    /**
     * Régression (fix d7d2131) : productStats() avec PLUSIEURS produits doit
     * trier par CA décroissant via un comparateur usort valide (retour int).
     * L'ancien comparateur typé `bool` levait un TypeError fatal dès le
     * premier appel — invisible en local sans MySQL, 500 en production.
     * Vérifie aussi le contrat complet du DTO ProductStat (types stricts).
     */
    public function test_product_stats_tri_et_dto_complet(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        // 3 produits du flux courant, CA nets distincts :
        // coca 75 € (10 + 20 u.), Café 10 € (2 + 2 u.), Bières 5 € (2 u.).
        $this->importSales([
            $this->saleRow('ft1', '2026-10-12 10:00:00', 'Coca-cola', 10, 'coca'),
            $this->saleRow('ft1', '2026-10-12 10:01:00', 'Coca-cola', 20, 'coca'),
            $this->saleRow('ft2', '2026-10-12 11:00:00', 'Café', 2),
            $this->saleRow('ft3', '2026-10-13 12:00:00', 'Café', 2),
            $this->saleRow('ft4', '2026-10-13 12:30:00', 'Bières', 2),
        ]);
        $this->seedCoutsEtStocks();

        $stats = AnalyticsForecastData::productStats(self::FROM, self::TO);

        self::assertNotEmpty($stats, 'Au moins un produit du flux courant.');

        // TRI par CA décroissant : contrat consommé par la vue Réappro.
        for ($i = 1, $n = count($stats); $i < $n; $i++) {
            self::assertGreaterThanOrEqual(
                $stats[$i]->ca,
                $stats[$i - 1]->ca,
                'productStats doit être trié par CA décroissant.'
            );
        }
        self::assertSame(['coca', 'Café', 'Bières'], array_map(static fn ($p) => $p->key, $stats));

        // Types stricts du DTO (le moteur et les vues s'y fient). qty est
        // FLOAT : somme pondérée par le poids d'atypie des jours.
        foreach ($stats as $p) {
            self::assertIsString($p->key);
            self::assertIsString($p->label);
            self::assertIsString($p->category);
            self::assertIsFloat($p->qty);
            self::assertIsFloat($p->ca);
            self::assertTrue($p->unitPrice === null || is_float($p->unitPrice), 'unitPrice : ?float.');
            self::assertTrue($p->unitCost === null || is_float($p->unitCost), 'unitCost : ?float.');
            self::assertIsBool($p->costSuspect);
            self::assertTrue($p->stock === null || is_int($p->stock), 'stock : ?int.');
        }

        // Ordre attendu + classifications de coût non ambiguës. Jours tous
        // « normaux » (aucun z > 3,5) → qty pondérée = somme brute.
        $coca = $stats[0];
        self::assertSame(30.0, $coca->qty);
        self::assertSame(75.0, $coca->ca);
        self::assertSame(2.5, $coca->unitPrice);
        self::assertFalse($coca->costSuspect, 'Coût 0,60 < prix 2,50 : sain.');
        self::assertTrue($stats[1]->costSuspect, 'Café : coût nul = suspect.');
        self::assertTrue($stats[2]->costSuspect, 'Bières : prix 2,50 < coût 12 = suspect.');
        self::assertSame(10, $coca->stock, 'Stock référencé.');
        self::assertNull($stats[2]->stock, 'Stock infini = null.');
    }

    /**
     * Fusion des clés quasi-doublonnes : « Café » et « cafe » partagent la
     * même clé normalisée → UNE seule ligne : qty/ca sommés, unitPrice =
     * moyenne pondérée par qty, stock connu conservé, 1ʳᵉ clé comme key et
     * label le plus long. L'audit liste la fusion (« cafe ×2 ») et le
     * produit resté sans stock.
     */
    public function test_product_stats_fusionne_les_cles_quasi_doublonnes(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->importSales([
            // 1ʳᵉ clé rencontrée : « Café » (accent) — 1 u. à 3 €.
            $this->saleRow('fm1', '2026-10-12 10:00:00', 'Café', 1, 'Café', ['price_ttc' => 3.0]),
            // 2ᵉ clé : « cafe » (sans accent) — 2 u. à 4 € (2 €/u).
            $this->saleRow('fm2', '2026-10-12 11:00:00', 'Café expresso', 2, 'cafe', ['price_ttc' => 4.0]),
            // Produit distinct SANS stock connu → cité dans l'audit.
            $this->saleRow('fm3', '2026-10-12 12:00:00', 'Thé vert', 1, 'Thé', ['price_ttc' => 2.0]),
        ]);
        // Stock : seul « cafe » est connu → c'est lui qui est conservé.
        $this->pdo->exec("INSERT INTO product_stocks (product_key, stock) VALUES ('cafe', 10)");

        $stats = AnalyticsForecastData::productStats(self::FROM, self::TO);

        self::assertCount(2, $stats, '« Café » et « cafe » fusionnent en une ligne ; « Thé » reste seul.');
        $p = null;
        foreach ($stats as $s) {
            if ($s->key === 'Café') {
                $p = $s;
            }
        }
        self::assertNotNull($p);
        self::assertSame('Café expresso', $p->label, 'Label le plus long conservé.');
        self::assertSame(3.0, $p->qty, 'Quantités sommées (1 + 2).');
        self::assertSame(7.0, $p->ca, 'CA sommé (3 + 4).');
        self::assertEqualsWithDelta((1 * 3.0 + 2 * 2.0) / 3.0, $p->unitPrice, 0.0001, 'Prix = moyenne pondérée par qty.');
        self::assertSame(10, $p->stock, 'Le stock connu est conservé.');
        self::assertTrue($p->costSuspect, 'OU logique des suspects (aucun coût ici).');

        // L'audit expose la fusion et les produits sans stock (label,
        // 5 max) ; le produit fusionné a un stock → seul « Thé vert » liste.
        $audit = AnalyticsForecastData::audit(self::FROM, self::TO);
        self::assertSame(['cafe ×2'], $audit['cles_fusionnees']);
        self::assertSame(['Thé vert'], $audit['produits_sans_stock']);
        self::assertSame(1, $audit['n_produits_sans_stock']);
    }

    /**
     * Pondération par l'atypie (F9) : un jour à pic (60 unités d'un coup)
     * reçoit le poids 0.3 (Hyper::W_ATYPICAL) → la qty pondérée du produit
     * reste sous la somme brute, et le CA suit la même pondération.
     * Attendu exact : 10 + 12 + 10 + 60×0.3 = 50 unités (au lieu de 92),
     * CA pondéré = 25 + 30 + 25 + 150×0.3 = 125 € (au lieu de 230 €).
     */
    public function test_product_stats_pese_les_jours_atypiques(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->importSales([
            // Trois jours normaux (CA 25, 30, 25 €) puis un PIC (150 €) :
            // la MAD des CA est non nulle et |z| du pic ≫ 3.5 → poids 0.3.
            $this->saleRow('fw1', '2026-10-12 10:00:00', 'Café', 10, 'Café', ['price_ttc' => 25.0]),
            $this->saleRow('fw2', '2026-10-13 10:00:00', 'Café', 12, 'Café', ['price_ttc' => 30.0]),
            $this->saleRow('fw3', '2026-10-14 10:00:00', 'Café', 10, 'Café', ['price_ttc' => 25.0]),
            $this->saleRow('fw4', '2026-10-15 10:00:00', 'Café', 60, 'Café', ['price_ttc' => 150.0]),
        ]);

        $stats = AnalyticsForecastData::productStats(self::FROM, self::TO);

        self::assertCount(1, $stats);
        $p = $stats[0];
        self::assertLessThan(92.0, $p->qty, 'La qty pondérée doit être sous la somme brute (92).');
        self::assertEqualsWithDelta(10.0 + 12.0 + 10.0 + 60.0 * 0.3, $p->qty, 0.0001, 'Pic pondéré 0.3.');
        self::assertEqualsWithDelta(25.0 + 30.0 + 25.0 + 150.0 * 0.3, $p->ca, 0.0001, 'CA pondéré comme la qty.');
        // La médiane des prix unitaires reste inchangée (toutes lignes à 2,5 €).
        self::assertSame(2.5, $p->unitPrice);
    }

    public function test_audit_ventes_zero_doublons_et_suspects(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedDonnees();
        $this->seedCoutsEtStocks();

        // Ré-import identique : toutes les lignes sont écartées par l'anti-
        // doublon (INSERT IGNORE) puis comptées via ImportBatch::finalize,
        // comme le fait le contrôleur d'import.
        $batchId = ImportBatch::create([
            'filename'     => 'analytics-forecast-test-replay.csv',
            'period_start' => self::FROM,
            'period_end'   => self::TO,
        ]);
        $result = Sale::importBatch($batchId, [
            $this->saleRow('fd1', '2026-10-12 10:15:00', 'Café', 2),
            $this->saleRow('fd1', '2026-10-12 10:16:00', 'Bières', 4),
            $this->saleRow('fd2', '2026-10-12 11:00:00', 'Coca-cola', 2, 'coca'),
            $this->saleRow('fd4', '2026-10-12 12:00:00', 'Snacks', 1),
            $this->saleRow('fd3', '2026-10-13 18:30:00', 'Café', 2),
            $this->saleRow('fde1', '2026-10-14 20:00:00', 'Entrée soirée', 1, null, ['category' => 'Événement', 'price_ttc' => 10.0]),
            $this->saleRow('fdc1', '2026-10-14 20:05:00', 'Don libre', 1, null, ['is_custom_amount' => 1, 'price_ttc' => 7.0]),
            $this->saleRow('fdz1', '2026-10-14 20:10:00', 'Test gratuit', 1, null, ['price_ttc' => 0.0]),
        ]);
        self::assertSame(0, $result['inserted'], 'Ré-import : aucune nouvelle ligne.');
        self::assertSame(8, $result['skipped']);
        ImportBatch::finalize($batchId, $result['inserted'], $result['skipped']);

        $audit = AnalyticsForecastData::audit(self::FROM, self::TO);

        self::assertSame(1, $audit['ventes_zero'], 'La ligne de test à 0 €.');
        self::assertSame(8, $audit['doublons_ignores'], 'Somme rows_skipped des lots de la fenêtre.');

        // Cohérence avec productStats : exactement les suspects attendus.
        $stats = AnalyticsForecastData::productStats(self::FROM, self::TO);
        $expected = [];
        foreach ($stats as $p) {
            if ($p->costSuspect) {
                $expected[] = $p->label;
            }
        }
        sort($expected);
        $actual = $audit['produits_cout_suspect'];
        sort($actual);
        self::assertSame($expected, $actual, 'audit et productStats cohérents.');
        self::assertContains('Café', $actual);
        self::assertContains('Bières', $actual);
        self::assertNotContains('Coca-cola', $actual);
    }

    public function test_dataset_assemble_jours_produits_et_audit(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedDonnees();

        $dataset = AnalyticsForecastData::dataset(self::FROM, self::TO);

        self::assertSame(['2026-10-12', '2026-10-13'], array_keys($dataset->days));
        self::assertNotEmpty($dataset->products);
        self::assertSame(1, $dataset->audit['ventes_zero']);
        self::assertSame(0, $dataset->audit['doublons_ignores'], 'Aucun compteur finalisé → 0.');
        // Sans lots de coûts : coût inconnu (null) → TOUS les produits suspects.
        self::assertCount(4, $dataset->audit['produits_cout_suspect']);
    }

    public function test_first_sale_date_delegue_a_analytics(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }
        $this->seedDonnees();

        self::assertSame('2026-10-12', AnalyticsForecastData::firstSaleDate());
    }
}

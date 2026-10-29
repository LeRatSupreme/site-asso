<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\CatalogueQc;
use App\Models\Model;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle qualité du catalogue (CatalogueQc::anomalies) — point
 * d'assemblage UNIQUE partagé par l'outil assistant
 * « catalogue_anomalies » et la veille quotidienne
 * scripts/catalogue_watchdog.php.
 *
 * Chaque famille d'anomalie est détectée sur des fixtures aeic_test :
 *  (a) fiche carte ACTIVE sans AUCUNE vente (même via alias) ;
 *  (b) clé de vente récente sans fiche carte active (alias crédité,
 *      fiche inactive repérée) ;
 *  (c) catégorie VIDE (0 produit, 0 alias) ;
 *  (d) QUASI-DOUBLONS de catégories (distance 1 / même forme) ;
 *  (e) PRIX SUSPECTS : prix <= 0, prix 1,00 € avec coût > 1 €
 *      (défaut Surfizz), prix sous le coût ;
 *  (f) fiche DORMANTE avec stock théorique immobilisé.
 *
 * Plus le cas SAIN (0 anomalie sur un catalogue cohérent) et les
 * LIMITES de sortie compacte (listes plafonnées à 20 items, totaux
 * exacts au-delà).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class CatalogueQcTest extends TestCase
{
    use TestDatabaseTrait;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test CatalogueQc ignoré.');

            return;
        }

        foreach (['products', 'product_categories', 'product_aliases', 'sales', 'product_costs', 'inventory_counts', 'purchases', 'losses'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return;
            }
        }

        // CatalogueQc lit via les modèles (Product, ProductCategory,
        // ProductAlias, Sale, ProductCost) et le livre de stock UNIQUE
        // (AnalyticsForecastData::stockBook → InventoryCount/Purchase/
        // Sale/Loss) : TOUT passe par Model::pdo() → la PDO de test.
        $this->reset($pdo, [
            'products', 'product_categories', 'product_aliases', 'sales',
            'product_costs', 'inventory_counts', 'purchases', 'losses',
        ]);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    // -----------------------------------------------------------------
    //  (a) Fiches actives jamais vendues
    // -----------------------------------------------------------------

    /** Fiche active, aucune vente, aucun achat : candidate [SÛR] en tête. */
    public function test_fiche_active_sans_aucune_vente_detectee(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Orangina ancien', 'cat1', 2.50);

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['fiches_jamais_vendues']);
        $item = $r['fiches_jamais_vendues'][0] ?? null;
        self::assertNotNull($item, 'La fiche jamais vendue doit être listée.');
        self::assertSame('Orangina ancien', $item['produit']);
        self::assertSame('Boissons', $item['categorie']);
        self::assertSame(2.5, $item['prix_eur']);
        self::assertTrue($item['jamais_achetee'], 'Aucun lot de coût connu → jamais achetée.');
        self::assertNull($item['jours_depuis_premier_achat']);
        self::assertArrayHasKey('stock', $item, 'Le champ stock est toujours présent (null = aucune donnée d\'inventaire).');
        self::assertNull($item['stock'], 'Aucun comptage/achat → stock inconnu, pas inventé.');

        // Une fiche jamais vendue n'est ni dormante (couverte par (a)) ni
        // une clé de vente sans fiche : aucune autre famille ne sort.
        foreach (['cles_sans_fiche', 'categories_vides', 'quasi_doublons_categories', 'prix_suspects', 'fiches_dormantes'] as $type) {
            self::assertSame(0, $r['totaux'][$type], "Type $type ne doit rien signaler ici.");
        }
    }

    /** Fiche jamais vendue MAIS déjà achetée : l'ancienneté porte le premier lot. */
    public function test_fiche_never_vendue_achetee_porte_l_anciennete_du_premier_lot(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Sirop ancien', 'cat1', 3.00);
        $this->seedLot('l1', 'Sirop ancien', 2.00, $this->jourParis(40));

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['fiches_jamais_vendues']);
        $item = $r['fiches_jamais_vendues'][0];
        self::assertFalse($item['jamais_achetee'], 'Un lot de coût est connu.');
        self::assertSame(40, $item['jours_depuis_premier_achat'], 'Proxy d\'ancienneté = date du premier lot.');
    }

    // -----------------------------------------------------------------
    //  (b) Clés de vente récentes sans fiche carte
    // -----------------------------------------------------------------

    /** Clé vendue récemment sans fiche : signalée avec son urgence. */
    public function test_cle_de_vente_recente_sans_fiche_detectee(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Chocolat chaud gourmand', 'Boissons', 4, 2.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['cles_sans_fiche']);
        $item = $r['cles_sans_fiche'][0];
        self::assertSame('Chocolat chaud gourmand', $item['cle']);
        self::assertSame(4, $item['quantite_totale']);
        self::assertSame($this->jourParis(2), $item['derniere_vente']);
        self::assertSame(2, $item['jours_depuis_derniere_vente']);
        self::assertFalse($item['fiche_inactive'], 'Aucune fiche du tout, même inactive.');
    }

    /** Fiche désactivée : le cas particulier est marqué (à réactiver, pas à recréer). */
    public function test_cle_de_vente_sur_fiche_inactive_marquee(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Ice tea vieux', 'cat1', 2.00, false); // inactif
        $this->seedVente('s1', 'T1', $this->jourParis(5) . ' 12:00:00', 'Ice tea vieux', 'Boissons', 2, 2.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['cles_sans_fiche']);
        self::assertTrue($r['cles_sans_fiche'][0]['fiche_inactive'], 'La fiche existe mais désactivée : à réactiver.');
        self::assertSame(0, $r['totaux']['fiches_jamais_vendues'], 'Une fiche INACTIVE n\'est jamais « jamais vendue ».');
    }

    /** Règle anti-régression : une vente sous un ALIAS crédite la fiche de sa clé canonique. */
    public function test_vente_sous_alias_credite_la_fiche_canonique(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Coca Zéro', 'cat1', 2.00);
        $this->seedAlias('a1', 'Coca light', 'Coca zero', 'Boissons');
        // Vente sous le LIBELLÉ ALIAS (casse différente), pas sous la clé canonique.
        $this->seedVente('s1', 'T1', $this->jourParis(3) . ' 12:00:00', 'coca LIGHT', 'Boissons', 2, 2.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(0, $r['totaux']['cles_sans_fiche'], 'L\'alias mène à la fiche active : pas d\'anomalie.');
        self::assertSame(0, $r['totaux']['fiches_jamais_vendues'], 'La vente via alias crédite la fiche canonique.');
    }

    // -----------------------------------------------------------------
    //  (c) Catégories vides
    // -----------------------------------------------------------------

    /** Catégorie sans produit NI alias : signalée ; habitée ou citée par un alias : non. */
    public function test_categorie_vide_detectee_habitee_et_citee_epargnees(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedCategorie('cat2', 'Ancienne gamme');
        $this->seedCategorie('cat3', 'Cité par mapping');
        $this->seedProduit('p1', 'Coca cola', 'cat1', 2.00);
        // Aucun produit ne pointe sur cat3, mais un alias la cite par nom.
        $this->seedAlias('a1', 'pepsi max', 'pepsi', 'Cité par mapping');

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['categories_vides']);
        $item = $r['categories_vides'][0];
        self::assertSame('Ancienne gamme', $item['categorie']);
        self::assertTrue($item['active']);
    }

    // -----------------------------------------------------------------
    //  (d) Quasi-doublons de catégories
    // -----------------------------------------------------------------

    /** « Boissons » / « Boissond » (distance 1) : la paire absorbable est signalée. */
    public function test_quasi_doublon_par_faute_de_frappe_detecte(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedCategorie('cat2', 'Boissond');

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['quasi_doublons_categories']);
        self::assertSame(
            ['Boissond', 'Boissons'],
            $r['quasi_doublons_categories'][0]['categories'],
            'Paire triée alphabétiquement, telle que ensure() l\'absorberait.'
        );
    }

    /** Même forme normalisée (casse/accents) : doublon absorbable aussi. */
    public function test_quasi_doublon_meme_forme_normalisee_detecte(): void
    {
        $this->seedCategorie('cat1', 'Snacks');
        $this->seedCategorie('cat2', 'SNACKS');

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['quasi_doublons_categories'], '« Snacks » ≡ « SNACKS » une fois normalisés.');
    }

    /** Deux catégories vraiment différentes : aucune paire. */
    public function test_categories_reellement_distinctes_non_signalees(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedCategorie('cat2', 'Snacks');
        $this->seedCategorie('cat3', 'Eau'); // court mais loin de tout

        $r = CatalogueQc::anomalies();

        self::assertSame(0, $r['totaux']['quasi_doublons_categories']);
    }

    // -----------------------------------------------------------------
    //  (e) Prix suspects
    // -----------------------------------------------------------------

    /** Les trois motifs : prix <= 0, 1,00 € avec coût supérieur (Surfizz), prix sous le coût. */
    public function test_prix_suspects_trois_motifs(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Produit gratuit', 'cat1', 0.00);
        $this->seedProduit('p2', 'Surfizz', 'cat1', 1.00);
        $this->seedLot('l2', 'Surfizz', 1.267, $this->jourParis(30));
        $this->seedProduit('p3', 'Vente à perte', 'cat1', 0.80);
        $this->seedLot('l3', 'Vente à perte', 1.200, $this->jourParis(30));
        $this->seedProduit('p4', 'Prix sain', 'cat1', 3.00);
        $this->seedLot('l4', 'Prix sain', 1.000, $this->jourParis(30));

        $r = CatalogueQc::anomalies();

        self::assertSame(3, $r['totaux']['prix_suspects']);
        $motifs = [];
        foreach ($r['prix_suspects'] as $item) {
            $motifs[$item['produit']] = $item;
        }
        self::assertSame('prix_nul_ou_negatif', $motifs['Produit gratuit']['motif']);
        self::assertSame('prix_1_eur_cout_superieur', $motifs['Surfizz']['motif'], 'Prix 1,00 € avec un lot courant à 1,267 € : défaut type Surfizz.');
        self::assertSame(1.267, $motifs['Surfizz']['cout_unitaire_eur'], 'Coût courant = lot as-of ProductCost::costAt.');
        self::assertSame('prix_sous_cout', $motifs['Vente à perte']['motif']);
        self::assertArrayNotHasKey('Prix sain', $motifs, 'Prix > coût : pas d\'anomalie.');
    }

    /** Prix 1,00 € SANS lot de coût connu : non signalé (rien à comparer). */
    public function test_prix_1_eur_sans_cout_connu_non_signale(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Nouveau produit', 'cat1', 1.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(0, $r['totaux']['prix_suspects'], 'Sans coût connu, le motif « coût supérieur » ne peut pas sortir.');
    }

    // -----------------------------------------------------------------
    //  (f) Fiches dormantes avec stock
    // -----------------------------------------------------------------

    /** Vende il y a 60 jours avec 8 unités au livre : marchandise immobilisée. */
    public function test_fiche_dormante_avec_stock_detectee(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Vieux soda', 'cat1', 2.00);
        $this->seedVente('s1', 'T1', $this->jourParis(60) . ' 12:00:00', 'Vieux soda', 'Boissons', 5, 2.00);
        // Comptage récent : théorique = 8 (la vente est antérieure au comptage).
        $this->seedComptage('c1', 'Vieux soda', $this->jourParis(1) . ' 10:00:00', 8);
        // Témoin non dormant : vendu hier, stock vivant.
        $this->seedProduit('p2', 'Coca frais', 'cat1', 2.00);
        $this->seedVente('s2', 'T2', $this->jourParis(1) . ' 12:00:00', 'Coca frais', 'Boissons', 3, 2.00);
        $this->seedComptage('c2', 'Coca frais', $this->jourParis(1) . ' 10:00:00', 6);

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['fiches_dormantes']);
        $item = $r['fiches_dormantes'][0];
        self::assertSame('Vieux soda', $item['produit']);
        self::assertSame(8, $item['stock'], 'Stock théorique du livre UNIQUE (dernier comptage).');
        self::assertSame($this->jourParis(60), $item['derniere_vente']);
        self::assertSame(60, $item['jours_sans_vente']);
    }

    /** Dormante mais SANS stock connu : non signalée (rien d'immobilisé). */
    public function test_fiche_dormante_sans_stock_non_signalee(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Vieux soda sec', 'cat1', 2.00);
        $this->seedVente('s1', 'T1', $this->jourParis(60) . ' 12:00:00', 'Vieux soda sec', 'Boissons', 5, 2.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(0, $r['totaux']['fiches_dormantes'], 'Aucune donnée d\'inventaire → stock inconnu → non signalé.');
        self::assertSame(0, $r['totaux']['fiches_jamais_vendues'], 'Elle a bien été vendue un jour.');
    }

    // -----------------------------------------------------------------
    //  Cas sain + limites de sortie
    // -----------------------------------------------------------------

    /** Catalogue cohérent : aucune anomalie, structure de sortie stable. */
    public function test_catalogue_sain_aucune_anomalie(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $this->seedProduit('p1', 'Coca cola', 'cat1', 2.50);
        $this->seedLot('l1', 'Coca cola', 1.000, $this->jourParis(30));
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Coca cola', 'Boissons', 3, 2.50);
        $this->seedComptage('c1', 'Coca cola', $this->jourParis(1) . ' 10:00:00', 6);

        $r = CatalogueQc::anomalies();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) $r['genere_le'], 'Horodatage Paris compact.');
        foreach (array_keys(CatalogueQc::TYPES) as $type) {
            self::assertSame(0, $r['totaux'][$type], "Catalogue sain : $type = 0.");
            self::assertSame([], $r[$type], "Catalogue sain : aucune liste $type.");
        }
    }

    /** Sortie compacte : listes plafonnées à 20 items, totaux exacts au-delà. */
    public function test_troncature_vingt_items_totaux_exacts(): void
    {
        $this->seedCategorie('cat1', 'Stock invendu');
        for ($i = 1; $i <= 25; $i++) {
            $this->seedProduit('p' . $i, 'Invendu ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'cat1', 2.00);
        }

        $r = CatalogueQc::anomalies();

        self::assertSame(25, $r['totaux']['fiches_jamais_vendues'], 'Les totaux restent EXACTS au-delà de la limite.');
        self::assertCount(CatalogueQc::LIMITE_PAR_TYPE, $r['fiches_jamais_vendues'], 'La liste est plafonnée à LIMITE_PAR_TYPE (20).');
        // Aucun libellé tronqué ici (courts) : items exploitables tels quels.
        self::assertSame('Invendu 01', $r['fiches_jamais_vendues'][0]['produit']);
    }

    /** Libellé ultra-long : tronqué à LIBELLE_MAX caractères (sortie compacte). */
    public function test_libelle_tres_long_tronque(): void
    {
        $this->seedCategorie('cat1', 'Boissons');
        $nom = 'Boisson au nom extraordinairement long qui depasse largement la limite d affichage du rapport';
        $this->seedProduit('p1', $nom, 'cat1', 2.00);

        $r = CatalogueQc::anomalies();

        self::assertSame(1, $r['totaux']['fiches_jamais_vendues']);
        $produit = (string) $r['fiches_jamais_vendues'][0]['produit'];
        self::assertLessThanOrEqual(60, mb_strlen($produit), 'Libellé plafonné à 60 caractères.');
        self::assertStringEndsWith('…', $produit, 'La troncature est marquée par une ellipse.');
        self::assertStringStartsWith('Boisson au nom extraordinairement long', $produit);
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures (aeic_test)
    // -----------------------------------------------------------------

    /** Connexion directe à aeic_test pour les INSERT de fixtures. */
    private function pdo(): PDO
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::fail('PDO aeic_test perdue en cours de test.');
        }

        return $pdo;
    }

    private function seedCategorie(string $id, string $nom): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO product_categories (id, name, `order`, is_active) VALUES (?,?,0,1)');
        $stmt->execute([$id, $nom]);
    }

    private function seedProduit(string $id, string $nom, string $categorieId, float $prix, bool $actif = true): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO products (id, name, price, category_id, is_active) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$id, $nom, $prix, $categorieId, $actif ? 1 : 0]);
    }

    private function seedVente(string $id, string $ref, string $quand, string $cle, string $categorie, int $qty, float $prix): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, category, price_ttc)
             VALUES (?,?,?,\'CARTE\',?,?,?,?,?)'
        );
        $stmt->execute([$id, $ref, $quand, $qty, $cle, $cle, $categorie, $prix]);
    }

    private function seedComptage(string $id, string $cle, string $quand, int $qty): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap)
             VALUES (?,?,?,?,?,0)'
        );
        $stmt->execute([$id, $quand, $cle, $qty, $qty]);
    }

    private function seedAlias(string $id, string $libelle, string $cleCanonique, string $categorie): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO product_aliases (id, raw_description, product_key, category, kind) VALUES (?,?,?,?,\'label\')'
        );
        $stmt->execute([$id, $libelle, $cleCanonique, $categorie]);
    }

    private function seedLot(string $id, string $cle, float $coutUnitaire, string $validFrom): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        );
        $stmt->execute([$id, $cle, $coutUnitaire, $validFrom]);
    }

    /** Jour Paris « aujourd'hui − N jours », format AAAA-MM-JJ. */
    private function jourParis(int $moinsJours): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            ->modify(sprintf('-%d days', $moinsJours))
            ->format('Y-m-d');
    }
}

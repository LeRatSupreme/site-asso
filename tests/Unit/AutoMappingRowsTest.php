<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminComptaController;
use App\Core\Compta\StockPublic;
use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\ProductKeyMerge;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * CONTRAT autoMappingRows() (assemblage de l'écran « Auto-détection
 * des doublons », voir le docblock d'AdminComptaController) :
 *
 *  - 'rows' : une ligne par libellé des ventes (résolution RÉELLE en
 *    plus des champs historiques raw/occurrences/suggested/canonical/
 *    already, inchangés — le formulaire et aliasesApply() restent
 *    intacts). Une ligne handled (alias exact OU repli normalisé vers
 *    une clé VIVANTE) est déjà couverte : readonly, hors POST.
 *  - 'covered' : groupes des libellés handled PAR CLÉ RÉSOLUE (une
 *    seule entrée par clé), occurrences ADDITIONNÉES, sources
 *    individuelles listées (label, occurrences, via), tri par
 *    occurrences décroissantes puis clé.
 *
 * Fixtures de ventes (occurrences = COUNT(*) par description dans
 * Sale::allDescriptions : quelques lignes par libellé suffisent à
 * vérifier le groupage logique, inutile d'en écrire 80) :
 *  - « Coca cherry » ×5 ET « Coca_cherry » ×3 avec alias exact pour
 *    la première graphie seule → l'autre doit être couverte par le
 *    repli normalisé ;
 *  - « Kit kat » ×2 / « KitKat » ×1 : couverture par résolution
 *    normalisée SANS ligne d'alias propre pour « Kit kat » ;
 *  - « redbull ice » ×2 / « Redbull Ice » ×1 (bug de bord corrigé :
 *    la clé vivante est testée par normalizeKey, deux graphies ci
 *    de la même clé ne créent pas de faux « à mapper ») ;
 *  - « Snickers poivre » ×4 : inconnu, non résolu → seul éditable.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class AutoMappingRowsTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');

            return;
        }
        $this->pdo = $pdo;

        // Les 7 tables de l'union des clés vivantes (ProductKeyMerge::
        // allKeys) + sales : fixture hermétique, aucun état hérité.
        $tables = ['sales', 'product_aliases', 'purchases', 'losses',
            'inventory_counts', 'product_stocks', 'product_discontinued'];
        try {
            foreach ($tables as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables compta absentes de aeic_test : importez database/schema.sql.');

            return;
        }

        $this->applyAliasKindMigration($pdo);
        $this->reset($pdo, $tables);
        Model::setTestPdo($pdo);
        $this->seed();
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    // -----------------------------------------------------------------
    //  rows : non-handled = les inconnus uniquement (éditables)
    // -----------------------------------------------------------------

    public function test_rows_non_handled_uniquement_le_libelle_inconnu(): void
    {
        $mapping = AdminComptaController::autoMappingRows($this->descriptions());

        $nonHandled = [];
        foreach ($mapping['rows'] as $row) {
            if ($row['handled'] === false) {
                $nonHandled[] = $row;
            }
        }

        self::assertCount(
            1,
            $nonHandled,
            'Seul le libellé inconnu reste à mapper : les libellés couverts (alias ou repli normalisé) ne sont plus éditables.'
        );
        $row = $nonHandled[0];
        self::assertSame('Snickers poivre', $row['raw']);
        self::assertSame(4, $row['occurrences']);
        self::assertSame('', $row['resolved_to'], 'Un inconnu n\'a pas de clé résolue.');
        self::assertNull($row['via'], 'Un inconnu n\'est résolu par aucune voie.');
        self::assertFalse($row['handled']);
        self::assertFalse($row['already']);
    }

    // -----------------------------------------------------------------
    //  handled : détection via alias EXACT et via repli NORMALISÉ
    // -----------------------------------------------------------------

    public function test_handled_detecte_via_alias_exact_et_via_repli_normalise(): void
    {
        $rows = $this->rowsByRaw($this->descriptions());

        // « Coca cherry » : alias exact → voie 'alias'.
        self::assertTrue($rows['Coca cherry']['handled'], 'Graphie avec alias exact : couverte.');
        self::assertSame('alias', $rows['Coca cherry']['via']);
        self::assertSame('Coca Cherry', $rows['Coca cherry']['resolved_to']);

        // « Coca_cherry » : PLUS de ligne d'alias propre (consolidée) —
        // résolution réelle par repli normalisé → couverte quand même.
        self::assertTrue($rows['Coca_cherry']['handled'], 'Graphie absorbée : couverte par le repli normalisé.');
        self::assertSame('normalisé', $rows['Coca_cherry']['via']);
        self::assertSame('Coca Cherry', $rows['Coca_cherry']['resolved_to']);

        // « Kit kat » : couvert SANS alias propre, par normalisation
        // vers la ligne d'alias « KitKat ».
        self::assertTrue($rows['Kit kat']['handled'], '« Kit kat » couvert sans ligne d\'alias propre.');
        self::assertSame('normalisé', $rows['Kit kat']['via']);
        self::assertSame('KitKat', $rows['Kit kat']['resolved_to']);

        // « KitKat » : alias exact (identité).
        self::assertTrue($rows['KitKat']['handled']);
        self::assertSame('alias', $rows['KitKat']['via']);
        self::assertSame('KitKat', $rows['KitKat']['resolved_to']);
    }

    // -----------------------------------------------------------------
    //  covered : groupage par clé résolue, occurrences additionnées,
    //  sources individuelles listées
    // -----------------------------------------------------------------

    public function test_covered_groupe_par_cle_avec_occurrences_additionnees_et_sources(): void
    {
        $covered = AdminComptaController::autoMappingRows($this->descriptions())['covered'];

        // Une seule entrée par clé résolue.
        $byKey = [];
        foreach ($covered as $group) {
            $byKey[$group['resolved_to']] = $group;
        }
        self::assertSame(
            ['Coca Cherry', 'KitKat', 'redbull ice'],
            array_keys($byKey),
            'Une entrée covered par clé résolue (clés de l\'alphabet des fixtures).'
        );

        // Groupe Coca Cherry : 5+3 → 8, sources individuelles listées
        // avec leur voie (détail dépliable de la vue).
        $coca = $byKey['Coca Cherry'];
        self::assertSame(8, $coca['occurrences'], 'Occurrences du groupe ADDITIONNÉES : 5+3 → 8.');
        self::assertSame(
            [
                ['label' => 'Coca cherry', 'occurrences' => 5, 'via' => 'alias'],
                ['label' => 'Coca_cherry', 'occurrences' => 3, 'via' => 'normalisé'],
            ],
            $coca['labels'],
            'Chaque graphie source reste listée individuellement avec ses occurrences et sa voie.'
        );

        // Groupe KitKat : 2 (normalisé, sans alias propre) + 1 (alias).
        $kitkat = $byKey['KitKat'];
        self::assertSame(3, $kitkat['occurrences']);
        self::assertSame(
            [
                ['label' => 'Kit kat', 'occurrences' => 2, 'via' => 'normalisé'],
                ['label' => 'KitKat', 'occurrences' => 1, 'via' => 'alias'],
            ],
            $kitkat['labels']
        );

        // Groupe redbull : les deux graphies ci des ventes n'en font
        // qu'une (GROUP BY en collation ci) → une seule source à 3.
        $redbull = $byKey['redbull ice'];
        self::assertSame(3, $redbull['occurrences'], '2 + 1 ventes des deux graphies ci → 3.');
        self::assertCount(1, $redbull['labels']);
        self::assertSame(3, $redbull['labels'][0]['occurrences']);
        self::assertSame('normalisé', $redbull['labels'][0]['via']);
        self::assertSame(0, strcasecmp('redbull ice', (string) $redbull['labels'][0]['label']));
    }

    public function test_covered_trie_par_occurrences_decroissantes_puis_cle(): void
    {
        $covered = AdminComptaController::autoMappingRows($this->descriptions())['covered'];

        // Le plus gros regroupement en tête (8), puis à égalité (3 et 3)
        // le tie-break naturel sur la clé : « KitKat » avant « redbull ice ».
        self::assertSame(
            ['Coca Cherry', 'KitKat', 'redbull ice'],
            array_column($covered, 'resolved_to'),
            'Tri occurrences DESC puis clé : 8 en tête, 3=3 départagé par la clé.'
        );
    }

    // -----------------------------------------------------------------
    //  Bug de bord corrigé : clé vivante testée par normalizeKey —
    //  deux graphies ci de la même clé ne créent pas de faux « à mapper »
    // -----------------------------------------------------------------

    public function test_bug_de_bord_deux_graphies_ci_de_la_meme_cle_pas_de_faux_a_mapper(): void
    {
        // Préambule du bug : l'UNION des clés vivantes déduplique en
        // collation ci — l'alias porte « redbull ice » (minuscules)
        // mais l'achat (premier bloc de l'UNION) porte « Redbull Ice » :
        // la graphie survivante est donc différente de la clé résolue.
        // Une recherche EXACTE de clé vivante échouerait (« redbull
        // ice » absent au sens binaire) ; la comparaison NORMALISÉE
        // (StockPublic::normalizeKey) doit couvrir.
        $keys = ProductKeyMerge::allKeys();
        self::assertContains('Redbull Ice', $keys, 'La graphie de l\'achat survit à la déduplication ci de l\'UNION.');
        self::assertNotContains('redbull ice', $keys, 'La graphie minuscule de l\'alias est dédupliquée (ci) : la garde ne doit PAS la retrouver telle quelle.');

        $mapping = AdminComptaController::autoMappingRows($this->descriptions());

        // Les deux graphies des ventes ci-groupées = UNE ligne, handled.
        $redbullRows = array_values(array_filter(
            $mapping['rows'],
            static fn (array $r): bool => strcasecmp((string) $r['raw'], 'redbull ice') === 0
        ));
        self::assertCount(1, $redbullRows, '« redbull ice » et « Redbull Ice » : une seule ligne (GROUP BY ci).');
        self::assertTrue($redbullRows[0]['handled'], 'La clé vivante « Redbull Ice » couvre via normalizeKey : pas de faux « à mapper ».');
        self::assertSame('normalisé', $redbullRows[0]['via']);
        self::assertSame('redbull ice', $redbullRows[0]['resolved_to']);
        self::assertSame('redbullice', StockPublic::normalizeKey((string) $redbullRows[0]['resolved_to']));
    }

    // -----------------------------------------------------------------
    //  Non-régression : champs historiques des rows inchangés
    // -----------------------------------------------------------------

    public function test_champs_historiques_des_rows_inchanges(): void
    {
        $rows = AdminComptaController::autoMappingRows($this->descriptions())['rows'];

        // Forme : les 5 champs historiques TOUJOURS présents (le
        // formulaire et aliasesApply() les lisent), + les 3 nouveaux.
        foreach ($rows as $row) {
            self::assertSame(
                ['raw', 'occurrences', 'suggested', 'canonical', 'already', 'resolved_to', 'via', 'handled'],
                array_keys($row),
                'La forme d\'une ligne ne doit pas perdre les champs historiques.'
            );
        }

        $byRaw = [];
        foreach ($rows as $row) {
            $byRaw[(string) $row['raw']] = $row;
        }

        // Occurrences : COUNT(*) par description, inchangé.
        self::assertSame(5, $byRaw['Coca cherry']['occurrences']);
        self::assertSame(3, $byRaw['Coca_cherry']['occurrences']);
        self::assertSame(2, $byRaw['Kit kat']['occurrences']);
        self::assertSame(1, $byRaw['KitKat']['occurrences']);
        self::assertSame(4, $byRaw['Snickers poivre']['occurrences']);

        // suggested : heuristique déterministe inchangée (AliasSuggester).
        self::assertSame('coca cherry', $byRaw['Coca cherry']['suggested']);
        self::assertSame('coca cherry', $byRaw['Coca_cherry']['suggested']);
        self::assertSame('kit kat', $byRaw['Kit kat']['suggested']);
        self::assertSame('kitkat', $byRaw['KitKat']['suggested']);
        self::assertSame('snickers poivre', $byRaw['Snickers poivre']['suggested']);

        // canonical : mapping existant (clé PHP sensible à la casse,
        // exacte sur le libellé) sinon suggestion.
        self::assertSame('Coca Cherry', $byRaw['Coca cherry']['canonical'], 'Alias existant : la cible présélectionne.');
        self::assertSame('coca cherry', $byRaw['Coca_cherry']['canonical'], 'Sans ligne d\'alias : la suggestion.');
        self::assertSame('KitKat', $byRaw['KitKat']['canonical']);
        self::assertSame('kit kat', $byRaw['Kit kat']['canonical']);

        // already : vrai seulement pour un libellé EXACT d'une ligne d'alias.
        self::assertTrue($byRaw['Coca cherry']['already']);
        self::assertTrue($byRaw['KitKat']['already']);
        self::assertFalse($byRaw['Coca_cherry']['already']);
        self::assertFalse($byRaw['Kit kat']['already']);
        self::assertFalse($byRaw['Snickers poivre']['already']);
    }

    public function test_rows_tries_par_canonique_puis_occurrences_decroissantes(): void
    {
        $rows = AdminComptaController::autoMappingRows($this->descriptions())['rows'];

        // Tri historique inchangé : clé canonique suggérée (naturel, ci)
        // puis occurrences décroissantes — « Coca cherry » (5) devant
        // « Coca_cherry » (3) à canonique égal (strnatcasecmp = 0).
        self::assertSame(
            ['Coca cherry', 'Coca_cherry', 'Kit kat', 'KitKat', 'Snickers poivre'],
            array_values(array_filter(
                array_column($rows, 'raw'),
                static fn (string $raw): bool => strcasecmp($raw, 'redbull ice') !== 0
            )),
            'Tri inchangé : canonique asc (« Coca cherry »/« Coca_cherry » à égalité ci → occurrences desc), puis les autres.'
        );

        // La ligne redbull (graphie ci-groupée) se glisse entre KitKat
        // et snickers poivre : canonique « redbull ice ».
        $canonicals = array_column($rows, 'canonical');
        $posRed = (int) array_search('redbull ice', $canonicals, true);
        $posSn  = (int) array_search('snickers poivre', $canonicals, true);
        self::assertGreaterThan(0, $posRed);
        self::assertGreaterThan($posRed, $posSn, '« redbull ice » avant « snickers poivre » (r < s).');
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures (aeic_test)
    // -----------------------------------------------------------------

    private function seed(): void
    {
        // Ventes : quelques lignes par libellé — occurrences =
        // COUNT(*) par description dans Sale::allDescriptions, un
        // échantillon suffit à vérifier le groupage logique.
        foreach (['Coca cherry' => 5, 'Coca_cherry' => 3,
                  'Kit kat' => 2, 'KitKat' => 1,
                  'redbull ice' => 2, 'Redbull Ice' => 1,
                  'Snickers poivre' => 4] as $label => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->addSale($label);
            }
        }

        // Alias : EXACT pour une seule graphie de chaque groupe —
        // « Coca_cherry » (absorbée) et « Kit kat » restent SANS ligne.
        ProductAlias::save(['raw_description' => 'Coca cherry', 'product_key' => 'Coca Cherry']);
        ProductAlias::save(['raw_description' => 'KitKat', 'product_key' => 'KitKat']);
        // Graphie avec séparateur : force le repli NORMALISÉ pour
        // « redbull ice », et porte une cible en minuscules.
        ProductAlias::save(['raw_description' => 'RedBull_Ice', 'product_key' => 'redbull ice']);

        // Clés vivantes :
        //  - « Coca Cherry » (stock) : la cible du repli normalisé existe ;
        //  - « Redbull Ice » (achat, PREMIER bloc de l'UNION des clés) :
        //    survit à la déduplication ci au détriment de la graphie
        //    minuscule portée par l'alias — cœur du bug de bord.
        $this->pdo->prepare(
            'INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)'
        )->execute(['Coca Cherry', 24]);
        $this->pdo->prepare(
            'INSERT INTO purchases (id, purchased_at, product_key, quantity) VALUES (?, CURDATE(), ?, 1)'
        )->execute(['pur_' . bin2hex(random_bytes(6)), 'Redbull Ice']);
    }

    private function addSale(string $description): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, NOW(), 'CARTE', 1, ?, NULL, 1.0)"
        )->execute(['sal_' . bin2hex(random_bytes(6)), 'tx_' . bin2hex(random_bytes(8)), $description]);
    }

    /** @return list<array<string,mixed>> Sortie de Sale::allDescriptions() (même code). */
    private function descriptions(): array
    {
        $rows = $this->pdo->query(
            'SELECT description, COUNT(*) AS occurrences, MAX(sold_at) AS last_seen
             FROM sales
             WHERE description IS NOT NULL AND description <> ""
               AND description NOT LIKE "%Montant personnalisé%"
             GROUP BY description
             ORDER BY occurrences DESC, last_seen DESC'
        )->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,array<string,mixed>> Lignes indexées par libellé brut exact. */
    private function rowsByRaw(array $descriptions): array
    {
        $rows = AdminComptaController::autoMappingRows($descriptions)['rows'];
        $byRaw = [];
        foreach ($rows as $row) {
            $byRaw[(string) $row['raw']] = $row;
        }

        return $byRaw;
    }
}

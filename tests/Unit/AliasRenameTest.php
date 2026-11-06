<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * RENOMMAGE DU « LIBELLÉ D'ORIGINE » D'UN ALIAS (ProductAlias::rename,
 * grille « Alias existants » de /admin/compta/aliases) :
 *
 *  - a. casse/accents seuls (ou libellé neuf) → simple renommage, la
 *    cible produit / catégorie / kind sont CONSERVÉS, l'ancienne
 *    écriture reste résolvable (repli normalisé de resolve()) ;
 *  - b. forme NORMALISÉE d'un autre alias de MÊME produit → FUSION :
 *    l'alias existant fait foi (SA cible, SON libellé), la ligne
 *    renommée disparaît, trace « alias.fusion » (chemins exact et
 *    normalisé) ;
 *  - c. libellé d'un produit DIFFÉRENT (alias d'autre cible, ou clé
 *    canonique d'un autre produit) → REFUS : AUCUNE écriture, le
 *    motif est retourné pour l'UI (SB3 — fini l'écrasement silencieux).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class AliasRenameTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test AliasRename ignoré.');

            return;
        }
        $this->pdo = $pdo;

        try {
            foreach (['product_aliases', 'sales', 'products', 'product_categories', 'audit_logs'] as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables alias absentes de aeic_test : importez database/schema.sql.');

            return;
        }

        $this->reset($pdo, ['product_aliases', 'sales', 'products', 'product_categories', 'audit_logs']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    // -----------------------------------------------------------------
    //  a. Simple renommage (casse/accents ou libellé neuf)
    // -----------------------------------------------------------------

    public function test_rename_casse_pure_renomme_sans_toucher_la_cible(): void
    {
        $id = ProductAlias::save([
            'raw_description' => 'Redbull ice',
            'product_key'     => 'Redbull',
            'category'        => 'Boissons',
        ]);

        $r = ProductAlias::rename($id, 'RedBull Ice', 'user_test');

        self::assertSame('renamed', $r['status']);
        self::assertSame('RedBull Ice', $r['new']);
        self::assertSame('Redbull', $r['product_key'], 'La cible produit est conservée.');

        $row = ProductAlias::find($id);
        self::assertSame('RedBull Ice', (string) $row['raw_description']);
        self::assertSame('Redbull', (string) $row['product_key']);
        self::assertSame('Boissons', (string) $row['category'], 'La catégorie est conservée.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());

        // Les deux écritures restent résolvables (repli normalisé).
        self::assertSame('Redbull', ProductAlias::resolve('RedBull Ice'));
        self::assertSame('Redbull', ProductAlias::resolve('redbull ice'));
    }

    public function test_rename_libelle_identique_ne_reecrit_rien(): void
    {
        $id = $this->addAlias('al_same', 'Coca zero', 'Coca');

        $r = ProductAlias::rename('al_same', '  Coca zero  ');

        self::assertSame('unchanged', $r['status']);
        $row = ProductAlias::find($id);
        self::assertSame('Coca zero', (string) $row['raw_description']);
    }

    /** Renommer vers l'orthographe canonique de SON produit : autorisé. */
    public function test_rename_vers_cle_canonique_de_son_produit_autorise(): void
    {
        $this->addAlias('al_rb', 'RB ice', 'Redbull');

        $r = ProductAlias::rename('al_rb', 'RedBull', 'user_test');

        self::assertSame('renamed', $r['status']);
        $row = ProductAlias::find('al_rb');
        self::assertSame('RedBull', (string) $row['raw_description']);
        self::assertSame('Redbull', (string) $row['product_key'], 'La cible est inchangée (alias identité).');
    }

    // -----------------------------------------------------------------
    //  b. Fusion d'orthographe (même produit)
    // -----------------------------------------------------------------

    public function test_rename_fusion_normalisee_l_existant_fait_foi_et_trace(): void
    {
        $this->addAlias('al_a', 'RB ice', 'Redbull');
        $this->addAlias('al_d', 'redbull_ice', 'Redbull', 'Boissons');

        // « RedBull Ice » normalise comme « redbull_ice » (casse + espace
        // vs underscore) : FUSION, même produit.
        $r = ProductAlias::rename('al_a', 'RedBull Ice', 'user_test');

        self::assertSame('fused', $r['status']);
        self::assertSame('Redbull', $r['product_key'], 'Cible conservée : celle de l\'alias existant.');
        self::assertSame('al_d', $r['id'], 'La ligne survivante est la ligne existante.');

        $row = ProductAlias::find('al_d');
        self::assertSame('redbull_ice', (string) $row['raw_description'], 'L\'orthographe de la ligne existante fait foi.');
        self::assertSame('Redbull', (string) $row['product_key']);
        self::assertSame('Boissons', (string) $row['category']);
        self::assertNull(ProductAlias::find('al_a'), 'La ligne renommée disparaît.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());
        self::assertSame('Redbull', ProductAlias::resolve('RedBull Ice'));

        // Trace « alias.fusion » (mécanisme upsert de save()).
        $brut = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'alias.fusion'")->fetchColumn();
        self::assertNotFalse($brut, 'La fusion est tracée dans le journal d\'audit.');
        /** @var array<string,mixed> $details */
        $details = json_decode((string) $brut, true);
        self::assertSame('redbull_ice', (string) $details['conserve']);
        self::assertSame('RedBull Ice', (string) $details['absorbe']);
        self::assertSame('Redbull', (string) $details['cible_avant']);
        self::assertSame('Redbull', (string) $details['cible_apres']);
    }

    /** Variante EXACTE (casse seule) : la trace est posée par rename(). */
    public function test_rename_fusion_exacte_trace_aussi(): void
    {
        $this->addAlias('al_a', 'RB ice', 'Redbull');
        $this->addAlias('al_d', 'Redbull Ice', 'Redbull');

        $r = ProductAlias::rename('al_a', 'Redbull Ice', 'user_test');

        self::assertSame('fused', $r['status']);
        self::assertSame('al_d', $r['id']);
        self::assertNull(ProductAlias::find('al_a'), 'La ligne renommée disparaît.');

        $brut = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'alias.fusion'")->fetchColumn();
        self::assertNotFalse($brut, 'La fusion exacte est tracée elle aussi (jamais de suppression silencieuse).');
        /** @var array<string,mixed> $details */
        $details = json_decode((string) $brut, true);
        self::assertSame('Redbull Ice', (string) $details['conserve']);
        self::assertSame('RB ice', (string) $details['absorbe'], 'La ligne absorbée est celle qui disparaît.');
        self::assertSame('Redbull', (string) $details['cible_apres']);
    }

    // -----------------------------------------------------------------
    //  c. Refus : produit différent
    // -----------------------------------------------------------------

    public function test_rename_refuse_le_libelle_d_un_autre_produit(): void
    {
        $this->addAlias('al_e', 'monster bleu', 'Monster');
        $this->addAlias('al_f', 'Monster Blue', 'Redbull');

        $r = ProductAlias::rename('al_e', 'Monster Blue', 'user_test');

        self::assertSame('refused', $r['status']);
        self::assertStringContainsString('Redbull', $r['message'], 'Le motif nomme le produit en conflit.');
        self::assertStringContainsString('Monster', $r['message']);

        // AUCUNE écriture : les deux lignes sont intactes.
        $e = ProductAlias::find('al_e');
        self::assertSame('monster bleu', (string) $e['raw_description']);
        self::assertSame('Monster', (string) $e['product_key']);
        $f = ProductAlias::find('al_f');
        self::assertSame('Monster Blue', (string) $f['raw_description']);
        self::assertSame('Redbull', (string) $f['product_key'], 'La cible de l\'alias existant n\'est PAS écrasée.');
        self::assertSame('Redbull', ProductAlias::resolve('Monster Blue'));
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'alias.fusion'")->fetchColumn(),
            'Un refus ne laisse aucune trace de fusion.'
        );
    }

    public function test_rename_refuse_la_cle_canonique_d_un_autre_produit(): void
    {
        $this->pdo->exec("INSERT INTO products (id, name, price) VALUES ('p_schweppes', 'Schweppes', 1.00)");
        $this->addAlias('al_g', 'produit generique', 'Monster');

        $r = ProductAlias::rename('al_g', 'Schweppes', 'user_test');

        self::assertSame('refused', $r['status']);
        self::assertStringContainsString('Schweppes', $r['message']);

        $g = ProductAlias::find('al_g');
        self::assertSame('produit generique', (string) $g['raw_description'], 'Aucune écriture pour la ligne refusée.');
        self::assertSame('Monster', (string) $g['product_key']);
    }

    public function test_rename_refuse_libelle_vide(): void
    {
        $this->addAlias('al_v', 'Coca zero', 'Coca');

        $r = ProductAlias::rename('al_v', '   ');

        self::assertSame('refused', $r['status']);
        self::assertSame('Coca zero', (string) ProductAlias::find('al_v')['raw_description']);
    }

    public function test_rename_ligne_inconnue_est_signalee_sans_ecriture(): void
    {
        $r = ProductAlias::rename('al_fantome', 'Nouveau libellé');

        self::assertSame('missing', $r['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures (aeic_test)
    // -----------------------------------------------------------------

    /**
     * Insère une ligne d'alias telle quelle (contourne la garde
     * anti-doublons de save(), pour simuler l'héritage).
     */
    private function addAlias(string $id, string $raw, string $target, ?string $category = null): string
    {
        $this->pdo->prepare(
            "INSERT INTO product_aliases (id, raw_description, product_key, category, kind)
             VALUES (?,?,?,?,'label')"
        )->execute([$id, $raw, $target, $category]);

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\KioskPages;
use App\Models\Kiosque;
use App\Models\Setting;
use PHPUnit\Framework\TestCase;

/**
 * Tests des OPTIONS de visibilité des kiosques personnalisés
 * (catalogue, normalisation, garde centralisée tokenOptions et
 * tokenIsFullData piloté par les options).
 *
 *  - méthodes PURES : normalizeOptions, catalogue (toujours exécutées) ;
 *  - intégration sur aeic_test : saute automatiquement si la base ou
 *    la table kiosques est indisponible (pré-migration).
 */
final class KioskPagesOptionsTest extends TestCase
{
    use TestDatabaseTrait;

    private ?\PDO $pdo = null;

    /** @var list<string> ids de kiosques créés par le test (nettoyage). */
    private array $createdIds = [];

    private function newKiosque(string $name, array $pages, array $options): array
    {
        $id = Kiosque::create($name, $pages, 'test', $options);
        $this->createdIds[] = $id;

        return Kiosque::find($id);
    }

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test des options ignoré.');

            return false;
        }
        $this->pdo = $pdo;

        try {
            $pdo->query('SELECT 1 FROM kiosques LIMIT 1');
            $pdo->query('SELECT 1 FROM settings LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Tables kiosques/settings absentes de aeic_test : importez database/schema.sql.');

            return false;
        }

        return true;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            try {
                $del = $this->pdo->prepare('DELETE FROM kiosques WHERE id = ?');
                foreach ($this->createdIds as $id) {
                    $del->execute([$id]);
                }
                $this->pdo->prepare('DELETE FROM kiosques WHERE id LIKE "kq_test%"')->execute();
            } catch (\Throwable) {
                // silencieux.
            }
            foreach (['admin_kiosk_token', 'reappro_kiosk_token'] as $key) {
                try {
                    Setting::set($key, '');
                } catch (\Throwable) {
                    // silencieux.
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Méthodes pures (sans base)
    // ---------------------------------------------------------------

    public function testCatalogueOptionsTroisIdsStables(): void
    {
        $catalog = KioskPages::optionCatalog();
        self::assertCount(3, $catalog);
        self::assertSame(['benefice', 'paiements', 'couts'], KioskPages::optionIds());
        foreach ($catalog as $opt) {
            self::assertNotEmpty($opt['label']);
            self::assertNotEmpty($opt['desc']);
        }
    }

    public function testNormalizeOptionsNeGardeQueLesIdsConnus(): void
    {
        // Entrée forgée : ids valides mélangés à des inconnus + doublons.
        $posted = ['couts', 'benefice', 'pirater', 'benefice', 'paiements', ''];
        self::assertSame(['benefice', 'paiements', 'couts'], KioskPages::normalizeOptions($posted));
    }

    public function testNormalizeOptionsReordonneSelonLeCatalogue(): void
    {
        self::assertSame(
            ['benefice', 'paiements', 'couts'],
            KioskPages::normalizeOptions(['couts', 'paiements', 'benefice'])
        );
    }

    public function testNormalizeOptionsRejetteLesNonTableaux(): void
    {
        self::assertSame([], KioskPages::normalizeOptions(null));
        self::assertSame([], KioskPages::normalizeOptions('benefice'));
        self::assertSame([], KioskPages::normalizeOptions([]));
    }

    // ---------------------------------------------------------------
    // Intégration (aeic_test)
    // ---------------------------------------------------------------

    public function testTokenOptionsJetonsLegacy(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        Setting::set('admin_kiosk_token', 'adm_tok_opt1');
        Setting::set('reappro_kiosk_token', 'mem_tok_opt1');

        $admin = KioskPages::tokenOptions('adm_tok_opt1');
        self::assertTrue($admin['benefice']);
        self::assertTrue($admin['paiements']);
        self::assertTrue($admin['couts']);

        $member = KioskPages::tokenOptions('mem_tok_opt1');
        self::assertFalse($member['benefice']);
        self::assertFalse($member['paiements']);
        self::assertFalse($member['couts']);
    }

    public function testTokenOptionsKiosquePiloteParSesOptions(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $k = $this->newKiosque('kq_test options', ['jour'], ['benefice']);
        self::assertNotNull($k);
        self::assertSame(['benefice'], $k['options']);

        $opts = KioskPages::tokenOptions($k['token']);
        self::assertTrue($opts['benefice']);
        self::assertFalse($opts['paiements']);
        self::assertFalse($opts['couts']);
    }

    public function testTokenOptionsKiosqueSansOptionsDetachees(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $k = $this->newKiosque('kq_test vide', ['jour'], []);
        self::assertNotNull($k);
        self::assertSame([], $k['options']);

        // Liste vide = choix EXPLICITE : tout masqué.
        $opts = KioskPages::tokenOptions($k['token']);
        self::assertFalse($opts['benefice']);
        self::assertFalse($opts['paiements']);
        self::assertFalse($opts['couts']);
    }

    public function testTokenOptionsKiosqueAnterieurComportementHistorique(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id = 'kq_test_old_' . bin2hex(random_bytes(4));
        // Colonne options laissée à NULL : kiosque créé AVANT la
        // fonctionnalité — bénéfice/paiements visibles, coûts selon la
        // tuile pleine (historique tokenIsFullData).
        $this->pdo->prepare(
            'INSERT INTO kiosques (id, name, token, pages, created_by) VALUES (?,?,?,?,?)'
        )->execute([$id, 'kq_test ancien', bin2hex(random_bytes(10)), '["reappro-lite","jour"]', 'test']);

        $k = Kiosque::find($id);
        self::assertNotNull($k);
        self::assertNull($k['options']);

        $opts = KioskPages::tokenOptions($k['token']);
        self::assertTrue($opts['benefice']);
        self::assertTrue($opts['paiements']);
        self::assertFalse($opts['couts']); // tuile pleine absente : pas de coûts
    }

    public function testTokenIsFullDataPiloteParLOptionCouts(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $k = $this->newKiosque('kq_test couts on', ['reappro-lite'], ['couts']);
        self::assertTrue(KioskPages::tokenIsFullData($k['token'], 'reappro'));

        // Kiosque piloté par ses options SANS l'option couts : la tuile
        // pleine cochée ne suffit plus — l'option décide seule.
        $k2 = $this->newKiosque('kq_test couts off', ['reappro', 'liste'], ['benefice']);
        self::assertFalse(KioskPages::tokenIsFullData($k2['token'], 'reappro'));
        self::assertFalse(KioskPages::tokenIsFullData($k2['token'], 'liste'));

        // Kiosque antérieur (options NULL) : comportement historique —
        // tuile pleine = coûts visibles, tuile lite seule = masqués.
        $id = 'kq_test_full_' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO kiosques (id, name, token, pages, created_by) VALUES (?,?,?,?,?)'
        )->execute([$id, 'kq_test historique', bin2hex(random_bytes(10)), '["liste"]', 'test']);
        $k3 = Kiosque::find($id);
        self::assertTrue(KioskPages::tokenIsFullData($k3['token'], 'liste'));
        self::assertFalse(KioskPages::tokenIsFullData($k3['token'], 'reappro'));
    }
}

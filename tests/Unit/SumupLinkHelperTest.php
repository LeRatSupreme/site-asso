<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\Setting;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du helper sumup_link() : priorité event > défaut > null.
 *
 * La résolution pure (sans base) est couverte par sumup_resolve_link().
 * La branche « défaut » nécessite la base aeic_test (sinon : skipped).
 */
final class SumupLinkHelperTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        // 1) Résolution pure (sans base) : toujours testable.
        // 2) Résolution via base : saute si aeic_test indisponible.
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        $this->reset($pdo, ['settings']);
        Model::setTestPdo($pdo);
        Setting::clearCache();

        $this->seed($pdo, 'sumup_default_link', '');
        $this->seed($pdo, 'sumup_enabled', '1');
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_resolve_priorite_event_puis_defaut_puis_null(): void
    {
        self::assertSame('https://pay.example/evt', sumup_resolve_link('https://pay.example/evt', 'https://pay.example/def'));
        self::assertSame('https://pay.example/def', sumup_resolve_link('', 'https://pay.example/def'));
        self::assertSame('https://pay.example/def', sumup_resolve_link(null, 'https://pay.example/def'));
        self::assertNull(sumup_resolve_link('', ''));
        self::assertNull(sumup_resolve_link(null, null));
        self::assertNull(sumup_resolve_link('   ', '  '));
    }

    public function test_sumup_link_retourne_le_lien_event_quand_present(): void
    {
        self::assertSame('https://pay.example/evt', sumup_link('https://pay.example/evt'));
    }

    public function test_sumup_link_retourne_le_defaut_si_pas_de_lien_event(): void
    {
        Setting::set('sumup_default_link', 'https://pay.example/def');

        self::assertSame('https://pay.example/def', sumup_link(''));
        self::assertSame('https://pay.example/def', sumup_link(null));
    }

    public function test_sumup_link_retourne_null_si_rien_configure(): void
    {
        self::assertNull(sumup_link(''));
        self::assertNull(sumup_link(null));
    }

    public function test_sumup_link_event_prend_le dessus_sur_defaut(): void
    {
        Setting::set('sumup_default_link', 'https://pay.example/def');

        self::assertSame('https://pay.example/evt', sumup_link('https://pay.example/evt'));
    }

    private function seed(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (id, `key`, value, type, `group`) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['set_' . $key, $key, $value, 'text', 'sumup']);
    }
}

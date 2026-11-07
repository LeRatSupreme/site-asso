<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\Setting;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cache des settings et de la lecture du mode maintenance.
 *
 * Saute si la base aeic_test est indisponible.
 */
final class SettingCacheTest extends TestCase
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

        $this->reset($pdo, ['settings']);
        Model::setTestPdo($pdo);

        // Le cache des settings est statique (partagé entre les tests) : on
        // l'invalide pour que chaque test reparte de l'état fraîchement semé.
        Setting::clearCache();

        $this->seedSetting($pdo, 'site_name', 'AEIC Test');
        $this->seedSetting($pdo, 'maintenance_mode', '0');
        $this->seedSetting($pdo, 'orders_enabled', '1');
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_get_renvoye_la_valeur(): void
    {
        self::assertSame('AEIC Test', Setting::get('site_name'));
        self::assertSame('def', Setting::get('inexistante', 'def'));
    }

    public function test_get_bool_interprete_0_et_1(): void
    {
        self::assertFalse(Setting::getBool('maintenance_mode'));
        self::assertTrue(Setting::getBool('orders_enabled'));
    }

    public function test_cache_est_invalide_apres_set(): void
    {
        // Premier chargement : met en cache maintenance_mode = 0.
        self::assertFalse(Setting::getBool('maintenance_mode'));

        // Mise à jour directe en base (simule un autre processus).
        $this->pdo->prepare("UPDATE settings SET value = '1' WHERE `key` = 'maintenance_mode'")->execute();

        // Le cache renvoie encore l'ancienne valeur (cohérence intra-requête).
        self::assertFalse(Setting::getBool('maintenance_mode'));

        // Après invalidation du cache, la nouvelle valeur est lue.
        Setting::clearCache();
        self::assertTrue(Setting::getBool('maintenance_mode'));
    }

    public function test_set_met_a_jour_et_invalide_le_cache(): void
    {
        self::assertSame('0', Setting::get('maintenance_mode'));

        Setting::set('maintenance_mode', '1');

        self::assertTrue(Setting::getBool('maintenance_mode'));

        // Vérification en base.
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE `key` = 'maintenance_mode'");
        $stmt->execute();
        self::assertSame('1', $stmt->fetchColumn());
    }

    private function seedSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (id, `key`, value, type, `group`) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['set_' . $key, $key, $value, 'string', 'general']);
    }
}

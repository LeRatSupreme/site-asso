<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Core\Compta\Dashboard\Afaire;
use PHPUnit\Framework\TestCase;

/**
 * Afaire::trier — tri déterministe (urgence décroissante puis clé
 * croissante), cap 5 surchargeable via « dashboard_a_faire_max »,
 * badge d'urgence (3 « Urgent », 2 « À traiter », 1 « Quand vous
 * pouvez »), liste vide → vide. Ne construit jamais les items.
 */
final class AfaireTest extends TestCase
{
    /** 7 items en désordre : 2 urgences 3, 4 urgences 2, 1 urgence 1. */
    private static function items(): array
    {
        return [
            DashboardScenario::item('divers', 1),
            DashboardScenario::item('couts', 2),
            DashboardScenario::item('zimport', 2),
            DashboardScenario::item('caisse', 3),
            DashboardScenario::item('rupture', 3),
            DashboardScenario::item('inventaire', 2),
            DashboardScenario::item('aimport', 2),
        ];
    }

    public function test_tri_urgence_desc_puis_cle_asc_avec_cap_5(): void
    {
        $r = Afaire::trier(self::items(), []);

        self::assertSame(['caisse', 'rupture', 'aimport', 'couts', 'inventaire'], array_column($r, 'cle'));
        self::assertSame([3, 3, 2, 2, 2], array_column($r, 'urgence'));
        self::assertSame(['Urgent', 'Urgent', 'À traiter', 'À traiter', 'À traiter'], array_column($r, 'urgence_label'));
        self::assertSame('Texte caisse', $r[0]['texte']);
        self::assertSame('/admin/compta', $r[0]['lien']);
    }

    public function test_cap_surchargeable(): void
    {
        $r = Afaire::trier(self::items(), ['dashboard_a_faire_max' => 2]);
        self::assertSame(['caisse', 'rupture'], array_column($r, 'cle'));

        $r0 = Afaire::trier(self::items(), ['dashboard_a_faire_max' => 0]);
        self::assertSame([], $r0);
    }

    public function test_libelles_d_urgence(): void
    {
        $r = Afaire::trier([
            DashboardScenario::item('a', 3),
            DashboardScenario::item('b', 2),
            DashboardScenario::item('c', 1),
        ], []);

        self::assertSame('Urgent', $r[0]['urgence_label']);
        self::assertSame('À traiter', $r[1]['urgence_label']);
        self::assertSame('Quand vous pouvez', $r[2]['urgence_label']);
    }

    public function test_urgence_hors_plage_libelle_neutre(): void
    {
        $r = Afaire::trier([DashboardScenario::item('x', 9)], []);

        self::assertSame('À traiter', $r[0]['urgence_label']);
    }

    public function test_entree_vide(): void
    {
        self::assertSame([], Afaire::trier([], []));
    }

    public function test_determinisme(): void
    {
        self::assertSame(Afaire::trier(self::items(), []), Afaire::trier(self::items(), []));
    }
}

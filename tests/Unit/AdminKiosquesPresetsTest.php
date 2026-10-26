<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminKiosquesController;
use App\Core\Compta\KioskPages;
use App\Models\Kiosque;
use App\Models\Model;
use PHPUnit\Framework\TestCase;

/**
 * PRESETS de création « en 1 clic » par rôle (page admin Kiosques) :
 *
 *  - les constantes du contrôleur définissent exactement 3 presets
 *    (Trésorier, Président, Resp. cafet) dont toutes les tuiles/options
 *    appartiennent au catalogue KioskPages ;
 *  - Président = tuiles ['jour-membre','president'] + option ['benefice']
 *    SEULEMENT → un kiosque créé avec ce preset a pour tokenOptions
 *    {benefice:true, paiements:false, couts:false} ;
 *  - Resp. cafet = tuiles de comptage/liste/réappro SANS AUCUNE option ;
 *  - Trésorier = toutes les tuiles admin (dont « president ») + toutes
 *    les options ;
 *  - presets() rescale toujours sur le catalogue courant (ordre, ids).
 *
 * Les tests base sont sur aeic_test (sautés si indisponible).
 */
final class AdminKiosquesPresetsTest extends TestCase
{
    use TestDatabaseTrait;

    private ?\PDO $pdo = null;

    /** @var list<string> ids de kiosques créés par le test (nettoyage). */
    private array $createdIds = [];

    /**
     * Preset par sa clé (via presets(), LE point d'assemblage).
     *
     * @return array{key:string,emoji:string,label:string,desc:string,pages:list<string>,options:list<string>}
     */
    private function preset(string $key): array
    {
        foreach (AdminKiosquesController::presets() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        self::fail('Preset « ' . $key . ' » introuvable dans presets().');
    }

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test des presets ignoré.');

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

        Model::setTestPdo($pdo);

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
            } catch (\Throwable) {
                // silencieux.
            }

            // Dernier geste, APRÈS le nettoyage (le DELETE a besoin du PDO
            // modèle) : détacher pour que les tests suivants retombent sur
            // db() (même mécanique que KioskPagesOptionsTest).
            Model::setTestPdo(null);
        }
    }

    // ---------------------------------------------------------------
    // Constantes (méthodes pures, sans base)
    // ---------------------------------------------------------------

    /**
     * Exactement 3 presets, dans cet ordre, avec libellés humains non vides.
     */
    public function testTroisPresetsDansLOrdreTresorierPresidentCafet(): void
    {
        $presets = AdminKiosquesController::presets();

        self::assertCount(3, $presets, 'La page doit proposer exactement 3 presets de rôle.');
        self::assertSame(
            ['tresorier', 'president', 'cafet'],
            array_column($presets, 'key'),
            'Ordre et clés des presets : Trésorier, Président, Resp. cafet.'
        );
        foreach ($presets as $preset) {
            foreach (['emoji', 'label', 'desc'] as $champ) {
                self::assertNotSame('', trim((string) $preset[$champ]), 'Champ « ' . $champ . ' » du preset ' . $preset['key'] . ' non vide.');
            }
            self::assertNotSame([], $preset['pages'], 'Un preset coche au moins une tuile.');
        }
    }

    /**
     * Toutes les tuiles et options des constantes appartiennent au
     * catalogue : un preset ne peut jamais cocher une case qui n'existe
     * pas (et presets() rétablit l'ordre du catalogue).
     */
    public function testToutesLesTuilesEtOptionsDesPresetsSontAuCatalogue(): void
    {
        $validPages   = KioskPages::validIds();
        $validOptions = KioskPages::optionIds();

        foreach (AdminKiosquesController::PRESETS as $raw) {
            foreach ($raw['pages'] as $page) {
                self::assertContains($page, $validPages, 'Tuile « ' . $page . ' » du preset ' . $raw['key'] . ' inconnue au catalogue.');
            }
            foreach ($raw['options'] as $option) {
                self::assertContains($option, $validOptions, 'Option « ' . $option . ' » du preset ' . $raw['key'] . ' inconnue au catalogue.');
            }
        }

        foreach (AdminKiosquesController::presets() as $preset) {
            $attendu = array_values(array_intersect($validPages, $preset['pages']));
            self::assertSame($attendu, $preset['pages'], 'presets() réordonne les tuiles selon le catalogue (' . $preset['key'] . ').');
        }
    }

    /**
     * PRESET PRÉSIDENT : tuiles ['jour-membre','president'] et OPTION
     * ['benefice'] uniquement — jamais paiements ni coûts. C'est le
     * contrat attendu côté tokenOptions (cf. test en base ci-dessous).
     */
    public function testPresetPresidentJourMembreEtPresidentBeneficeSeulement(): void
    {
        $preset = $this->preset('president');

        self::assertSame(['jour-membre', 'president'], $preset['pages'], 'Preset Président : Vue Président + récap membres, rien d\'autre.');
        self::assertSame(['benefice'], $preset['options'], 'Preset Président : SEULE l\'option bénéfice, ni paiements ni coûts.');
    }

    /**
     * PRESET RESP. CAFET : comptages + liste + réappro allégés, SANS
     * aucune option de visibilité (liste vide explicite = tout masqué).
     */
    public function testPresetRespCafetComptagesSansChiffreFinancier(): void
    {
        $preset = $this->preset('cafet');

        self::assertSame(['inventaire', 'liste-lite', 'reappro-lite'], $preset['pages'], 'Preset Resp. cafet : tuiles de saisie uniquement (variantes lite).');
        self::assertSame([], $preset['options'], 'Preset Resp. cafet : AUCUNE donnée sensible visible.');
    }

    /**
     * PRESET TRÉSORIER : toutes les tuiles admin du catalogue (dont la
     * Vue Président) + toutes les options — la parité des données
     * financières complètes.
     */
    public function testPresetTresorierToutesTuilesAdminToutesOptions(): void
    {
        $preset = $this->preset('tresorier');

        $attendues = array_values(array_intersect(KioskPages::validIds(), KioskPages::LEGACY_ADMIN_PAGES));
        self::assertSame($attendues, $preset['pages'], 'Preset Trésorier : toutes les tuiles admin (dont president).');
        self::assertContains('president', $preset['pages'], 'La Vue Président fait partie du preset Trésorier.');
        self::assertSame(['benefice', 'paiements', 'couts'], $preset['options'], 'Preset Trésorier : toutes les options cochées.');
    }

    // ---------------------------------------------------------------
    // Contrat en base (aeic_test)
    // ---------------------------------------------------------------

    /**
     * Un kiosque créé avec le PRESET PRÉSIDENT (ce que soumet le
     * formulaire pré-rempli) a exactement les tuiles cochées et pour
     * tokenOptions {benefice:true, paiements:false, couts:false}.
     */
    public function testKiosqueCreeAvecLePresetPresident(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $preset = $this->preset('president');

        $id = Kiosque::create($preset['label'], $preset['pages'], 'test', $preset['options']);
        $this->createdIds[] = $id;
        $k = Kiosque::find($id);

        self::assertNotNull($k, 'Le kiosque Président doit être relu en base.');
        self::assertSame(['jour-membre', 'president'], $k['pages'], 'Tuiles stockées : exactement celles du preset.');
        self::assertSame(['benefice'], $k['options'], 'Options stockées : benefice uniquement.');

        self::assertSame(
            ['benefice' => true, 'paiements' => false, 'couts' => false],
            KioskPages::tokenOptions($k['token']),
            'Preset Président → tokenOptions = {benefice:true, paiements:false, couts:false}.'
        );

        self::assertTrue(KioskPages::tokenAllows($k['token'], 'president'), 'La Vue Président est autorisée à ce jeton.');
        self::assertTrue(KioskPages::tokenAllows($k['token'], 'jour-membre'), 'Le récap membres est autorisé à ce jeton.');
        self::assertFalse(KioskPages::tokenAllows($k['token'], 'ledger'), 'Le livre comptable reste refusé à ce jeton.');
    }

    /**
     * Un kiosque créé avec le PRESET RESP. CAFET n'affiche AUCUNE donnée
     * sensible (options toutes à false) et n'ouvre que ses tuiles.
     */
    public function testKiosqueCreeAvecLePresetRespCafet(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $preset = $this->preset('cafet');

        $id = Kiosque::create($preset['label'], $preset['pages'], 'test', $preset['options']);
        $this->createdIds[] = $id;
        $k = Kiosque::find($id);

        self::assertNotNull($k);
        self::assertSame(['inventaire', 'liste-lite', 'reappro-lite'], $k['pages']);
        self::assertSame([], $k['options'], 'Options explicites vides : tout masqué.');

        $opts = KioskPages::tokenOptions($k['token']);
        self::assertFalse($opts['benefice']);
        self::assertFalse($opts['paiements']);
        self::assertFalse($opts['couts'], 'Resp. cafet : aucun chiffre financier, même avec la tuile réappro cochée (variante lite).');
        self::assertTrue(KioskPages::tokenAllows($k['token'], 'inventaire'));
        self::assertFalse(KioskPages::tokenAllows($k['token'], 'president'), 'La Vue Président n\'est pas ouverte au Resp. cafet.');
    }
}

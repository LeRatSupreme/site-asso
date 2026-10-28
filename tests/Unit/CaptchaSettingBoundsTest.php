<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Captcha\CaptchaService;
use PHPUnit\Framework\TestCase;

/**
 * Tests des bornes strictes du CAPTCHA (pur, SANS base de données) :
 * clampSetting de part et d'autre des bornes, clé inconnue, et valeurs
 * aberrantes « venues de la base » (injectées dans le constructeur) qui
 * doivent quand même produire un service dans les bornes.
 */
final class CaptchaSettingBoundsTest extends TestCase
{
    /** @var array<string,int> Valeurs exactement aux bornes : inchangées. */
    private const AUX_BORNES = [
        'code_length'          => 8,
        'rotation_max_degrees' => 35,
        'noise_lines'          => 8,
        'noise_intensity'      => 100,
    ];

    public function test_clamp_aux_bornes_reste_inchange(): void
    {
        foreach (self::AUX_BORNES as $key => $value) {
            self::assertSame($value, CaptchaService::clampSetting($key, $value), "Clamp à la borne pour {$key}");
        }

        foreach (CaptchaService::BOUNDS as $key => $bounds) {
            self::assertSame($bounds['min'], CaptchaService::clampSetting($key, $bounds['min']));
        }
    }

    public function test_clamp_au_dela_de_la_borne_max(): void
    {
        self::assertSame(8, CaptchaService::clampSetting('code_length', 12));
        self::assertSame(8, CaptchaService::clampSetting('code_length', 1000));
        self::assertSame(35, CaptchaService::clampSetting('rotation_max_degrees', 360));
        self::assertSame(8, CaptchaService::clampSetting('noise_lines', 99));
        self::assertSame(100, CaptchaService::clampSetting('noise_intensity', 500));
    }

    public function test_clamp_en_deca_de_la_borne_min(): void
    {
        self::assertSame(4, CaptchaService::clampSetting('code_length', 1));
        self::assertSame(4, CaptchaService::clampSetting('code_length', -50));
        self::assertSame(0, CaptchaService::clampSetting('rotation_max_degrees', -10));
        self::assertSame(0, CaptchaService::clampSetting('noise_lines', -1));
        self::assertSame(0, CaptchaService::clampSetting('noise_intensity', -100));
    }

    public function test_clamp_accepte_les_flottants(): void
    {
        self::assertSame(6, CaptchaService::clampSetting('code_length', 5.7));
        self::assertSame(0, CaptchaService::clampSetting('noise_intensity', -0.4));
    }

    public function test_cle_inconnue_leve_une_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CaptchaService::clampSetting('parametre_inconnu', 5);
    }

    /**
     * Valeurs aberrantes « lues en base » : le constructeur doit les
     * clampér — le service reste utilisable et dans les bornes.
     */
    public function test_valeurs_aberrantes_sont_clampees_au_construction(): void
    {
        $service = new CaptchaService([
            'code_length'          => 999,
            'rotation_max_degrees' => -77,
            'noise_lines'          => 100,
            'noise_intensity'      => 100000,
        ]);

        $settings = $this->settingsEffectifs($service);

        self::assertSame(8, $settings['code_length']);
        self::assertSame(0, $settings['rotation_max_degrees']);
        self::assertSame(8, $settings['noise_lines']);
        self::assertSame(100, $settings['noise_intensity']);
    }

    /**
     * Un rendu effectué avec des paramètres aberrants reste valide (le
     * clamp a eu lieu AVANT le rendu).
     */
    public function test_rendu_survit_a_des_valeurs_aberrantes(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD non disponible.');
        }

        $service = new CaptchaService([
            'code_length'          => 0,
            'rotation_max_degrees' => 90,
            'noise_lines'          => 50,
            'noise_intensity'      => -5,
        ]);

        $png = $service->render('TEST');

        self::assertStringStartsWith("\x89PNG", $png);
        self::assertGreaterThan(1024, strlen($png));
    }

    /**
     * @return array<string,int>
     */
    private function settingsEffectifs(CaptchaService $service): array
    {
        $ref = new \ReflectionProperty(CaptchaService::class, 'settings');
        $ref->setAccessible(true);
        /** @var array<string,int> $settings */
        $settings = $ref->getValue($service);

        return $settings;
    }
}

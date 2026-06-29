<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du limitation de tentatives (rate limiter), sur un dossier temporaire.
 */
final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aeic-rl-' . bin2hex(random_bytes(4));
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function test_sous_le_seuil_n_est_pas_bloque(): void
    {
        $rl = new RateLimiter($this->dir);

        $rl->hit('ip1');
        $rl->hit('ip1');

        self::assertFalse($rl->tooManyAttempts('ip1', 5, 600));
    }

    public function test_atteint_le_seuil_est_bloque(): void
    {
        $rl = new RateLimiter($this->dir);

        for ($i = 0; $i < 5; $i++) {
            $rl->hit('ip2');
        }

        self::assertTrue($rl->tooManyAttempts('ip2', 5, 600));
    }

    public function test_clear_reouvre_l_acces(): void
    {
        $rl = new RateLimiter($this->dir);

        for ($i = 0; $i < 5; $i++) {
            $rl->hit('ip3');
        }
        self::assertTrue($rl->tooManyAttempts('ip3', 5, 600));

        $rl->clear('ip3');
        self::assertFalse($rl->tooManyAttempts('ip3', 5, 600));
    }

    public function test_cles_differentes_sont_independantes(): void
    {
        $rl = new RateLimiter($this->dir);

        for ($i = 0; $i < 5; $i++) {
            $rl->hit('ipA');
        }

        self::assertTrue($rl->tooManyAttempts('ipA', 5, 600));
        self::assertFalse($rl->tooManyAttempts('ipB', 5, 600));
    }

    public function test_tentatives_expirees_ne_comptent_plus(): void
    {
        $rl = new RateLimiter($this->dir);

        // Fenêtre de 0 seconde : toute tentative "passée" est immédiatement expirée.
        $rl->hit('ip4');
        $rl->hit('ip4');
        $rl->hit('ip4');

        self::assertFalse($rl->tooManyAttempts('ip4', 2, 0));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                $this->rrmdir($file);
            } else {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
